flarum的非侵入式swoole容器，做了以下几件事：
1. di container常驻。
2. 权限组常驻（需要配合插件或者直接在根目录extend.php写一下相关方法）
3. n+1序列化协程并行合并n+1查询语句（需要co模式）
4. 性能监控，开启debug后可以看看是什么插件在n+1（co模式）

# 版本要求
自用php8.5+swoole6.2+flarum1.8.x。
如果用开启协程版本，要1.8以上，Tobscure\JsonApi已经内置于flarum/core的。
flarum1.x运行环境~~历史悠久~~，运行环境多种多样，我也没在别的版本测试过，能不能用还请自己测试。

# 使用步骤

1. 安装swoole
```
pecl install swoole
```
2. 把非侵入式入口文件flarum-swoole.php或者flarum-swoole-co.php放在flarum根目录（和vendor文件夹平级）

3. 启动

```
php flarum-swoole.php start
```

如果成功，swoole会运行在并监听/tmp/flarum.sock，可以根据需要改成端口通信，在nginx等网关配置反代即可。

推荐supervisor环境运行，启动脚本教考：

```bash
#!/bin/bash

# 1. 无差别强杀残留进程和 Socket 占用
pkill -9 -f 'flarum-swoole.php' 2>/dev/null
fuser -k -9 /tmp/flarum.sock 2>/dev/null

# 2. 超时检测循环 (最多等 5 秒)
for i in 1 2 3 4 5; do
    # 如果查不到 socket 占用，立刻跳出循环
    if ! ss -xln | grep -Fq '/tmp/flarum.sock'; then
        break
    fi
    sleep 1
done

# 3.清理残留文件
rm -f /tmp/flarum.sock /tmp/flarum-swoole.pid

# 4. 切换用户并执行最终启动
exec su -c 'cd /xxx/flarum && php flarum-swoole.php start'
```

# 缓存

如果有fof/redis和litespeed cache插件，这个脚本可以读取redis设置，代替litespeed网关做缓存。

比真正的litespeed好一点是，在入口就去redis拿session，做颗粒度更细的缓存策略。

~~做完才想起来都直接拿session了应该不再需要litespeed了，但是还没测试过~~

~~没在没装fof/redis的环境下测试过，不能跑别来找我。~~

lsphp和swoole互斥，不为了lsphp的性能提升，没必要专门为了缓存把网关换成litespeed，毕竟免费版open litespeed网关限制是真多。

# 权限组静态化

找自己的插件入口文件写进去，不会用就不要用。
```php
use Flarum\User\Access\Gate;
use Flarum\User\Event\Saving;
use Flarum\User\User;

class SwooleMemoryGate extends Gate
{

    // 全局静态变量，在 Swoole Worker 内存中常驻
    public static $permissionsMap = null;
    public function allows(User $actor, string $ability, $model): bool
    {
        // ── 优化1：Policy 查找结果按 model 类名缓存 ──
        // 缓存的是「哪些 Policy 对象负责哪个 Model」，与用户/权限组无关，worker 级只读安全
        static $policyCache = [];
        $cacheKey = $model
            ? (is_string($model) ? $model : get_class($model))
            : '__global__';
        if (!array_key_exists($cacheKey, $policyCache)) {
            if ($model) {
                $classes = is_string($model)
                    ? [$model]
                    : array_merge(class_parents($model), [get_class($model)]);
                $policies = [];
                foreach ($classes as $class) {
                    $policies = array_merge($policies, $this->getPolicies($class));
                }
            } else {
                $policies = $this->getPolicies(\Flarum\User\Access\AbstractPolicy::GLOBAL);
            }
            $policyCache[$cacheKey] = $policies;
        }
        $appliedPolicies = $policyCache[$cacheKey];
        // 执行所有适用的 Policy
        $results = [];
        foreach ($appliedPolicies as $policy) {
            $results[] = $policy->checkAbility($actor, $ability, $model);
        }
        // ── 优化2：$results 为空（大多数普通权限路径）时跳过 criteria 循环 ──
        if (!empty($results)) {
            foreach (static::EVALUATION_CRITERIA_PRIORITY as $criteria => $decision) {
                if (in_array($criteria, $results, true)) {
                    return $decision;
                }
            }
        }
        // === 核心优化：拦截数据库查询，改为纯内存匹配 ===
        if ($actor->isAdmin()) {
            return true;
        }
        // 静态闭包：整个 Worker 生命周期只绑定一次
        static $getPermissionsProp = null;
        static $getGroupIds = null;
        static $setPermissionsProp = null;
        if ($getPermissionsProp === null) {
            $getPermissionsProp = \Closure::bind(
                function ($a) { return $a->permissions; },
                null, User::class
            );
            $getGroupIds = \Closure::bind(function ($a) {
                $ids = [\Flarum\Group\Group::GUEST_ID];
                if ($a->is_email_confirmed) {
                    $ids = array_merge($ids, [\Flarum\Group\Group::MEMBER_ID], $a->groups->pluck('id')->all());
                }
                foreach (static::$groupProcessors as $processor) {
                    $ids = $processor($a, $ids);
                }
                return $ids;
            }, null, User::class);
            $setPermissionsProp = \Closure::bind(
                function ($a, $perms) { $a->permissions = $perms; },
                null, User::class
            );
        }
        // 仅在当前请求的 actor 实例首次到达时计算，后续 allows() 直接走 hasPermission()
        if (is_null($getPermissionsProp($actor))) {
            // $permissionsMap 已由 workerStart 预加载
            // 极端情况下 fallback
            if (self::$permissionsMap === null) {
                $map = [];
                foreach (\Flarum\Group\Permission::get() as $p) {
                    $map[$p->group_id][] = $p->permission;
                }
                self::$permissionsMap = $map;
            }
            $groupIds = $getGroupIds($actor);
            $actorPermissions = [];
            foreach ($groupIds as $gId) {
                if (isset(self::$permissionsMap[$gId])) {
                    foreach (self::$permissionsMap[$gId] as $perm) {
                        $actorPermissions[] = $perm;
                    }
                }
            }
            $setPermissionsProp($actor, array_unique($actorPermissions));
        }
        return $actor->hasPermission($ability);
    }
}

// 2. 编写一个服务提供者，用于替换系统默认的 Gate
class SwoolePermissionOptimizationProvider extends AbstractServiceProvider
{
    public function boot()
    {
        // 覆盖 Flarum 的 Gate 实例
        User::setGate($this->container->makeWith(SwooleMemoryGate::class, [
            'policyClasses' => $this->container->make('flarum.policies')
        ]));
    }
}
return [
      
     (new Extend\ServiceProvider())
        ->register(SwoolePermissionOptimizationProvider::class),
        ………………
```



# 效果和局限性
峰值性能提升不算大，相比优化得很好的fpm/lsphp+jit+opcache提升也就100毫秒左右500->400ms,如果再手动解决一下合并不了的n+1查询还能再快50ms。
co模式并发可以提升很多，无缓存长时间维持（一个小时以上）rps15+cpu100%不被打崩（对于flarum这种重cpu应用来说这已经很不容易了），并且内存占用非常少。

## 压测
关掉全部缓存机制，完整鉴权和序列化的纯动态请求。主机是gb5单核450分的netlab洋垃圾主机。
### woker模式
<img width="3687" height="444" alt="image" src="https://github.com/user-attachments/assets/26064f92-8089-4888-8dd4-7cd3f05000e8" />
<img width="3644" height="472" alt="image" src="https://github.com/user-attachments/assets/8d583bcf-c6f9-4625-bbab-a453fb5cac59" />
<img width="1231" height="210" alt="image" src="https://github.com/user-attachments/assets/7b82c3e6-3b71-4695-ba1b-fe9f5feec240" />

### co模式
<img width="3731" height="392" alt="image" src="https://github.com/user-attachments/assets/15594e4c-9ed5-4934-8c64-be38a3aa2f70" />
<img width="3816" height="480" alt="image" src="https://github.com/user-attachments/assets/27cfad1a-533a-4430-b690-b0c95450621f" />
<img width="1223" height="173" alt="image" src="https://github.com/user-attachments/assets/ca49b3fa-af5f-4944-815e-1a8f81d4439c" />


## 总结
co版本重写了Serializer依赖的api，并发执行序列化，合并能够合并的查询语句，可以一定程度缓解n+1问题，空闲基本可以跑到峰值的300ms。

但是如果自己照着日志把follow tags、follow users，fof badgegs，fof poll等插件的n+1查询改了，就几乎没有提升了。

而co模式相比woker模式本身有隔离作用域和各种反射的额外开销，所以具体用哪种模式还要根据实际情况。

压测中woker模式的表现甚至还要略好一些，明明co模式要难得多，一顿操作猛如虎，郁闷…

但其实这两者压测表现差不多就说明co模式更好，因为我本来就通过extend优化了大量n+1查询，并且像co对于不想费这个力气的玩家肯定是co模式胜。

总的来说即使很大程度上解决了序列化过程中的n+1问题，但是不论用哪种模式，不缓存的话真正的瓶颈始终在cpu。

视图层计算过重难以避免，所以有条件还是要上amd的u。


一个验证可行的优化思路是：

站点负载不大的情况下，拆分请求，让几个小请求并行，比如调低每一页的贴数，拆分原本重型的请求，让用户不用等容器启动的时间，直接拿到刚组装出来的几个帖子内容。这样可以用上cpu的多核性能，提升单个用户的体验。在后端n+1和计算问题严重的情况下，该常驻的常驻后，把请求拆成在前端n+1的办法的额外开销居然挺小的……因为swoole节省了容器启动的部分时间（不论co模式还是woker模式），小型请求的初始水位降低，用户就能得到几乎及时的反馈（前提是你的机器线路够好）。对于flarum真正的性能问题，cpu过于密集，能做的有限。


```
觉得flarum本身“很轻”，空闲时也能让用户等个一两秒也没关系的别用哈。
类似的思路两年前就有人搞出来了，人家在群里提了句就被你们追着喷，一直没人肯分享优化思路真是活78该。
```
