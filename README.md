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

```
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

比真正的litespeed好一点是可以在入口就去redis拿session，做颗粒度更细的缓存策略。~~不过还没做~~

~~没在没装fof/redis的环境下测试过，不能跑别来找我。~~

lsphp和swoole互斥，不为了lsphp的性能提升，没必要专门为了缓存把网关换成litespeed，毕竟免费版open litespeed网关限制是真多。

# 效果和局限性
峰值性能提升不算大，相比优化得很好的fpm/lsphp+jit+opcache提升也就100毫秒左右500->400ms,如果再手动解决一下合并不了的n+1查询还能再快50ms。
co模式并发可以提升很多，无缓存长时间维持（一个小时以上）rps15+cpu100%不被打崩（对于flarum这种重cpu应用来说这已经很不容易了），并且内存占用非常少。

## 压测
关掉全部缓存机制，完整鉴权和序列化的纯动态请求。主机是gb5单核450分的netlab洋垃圾主机。

### co模式
<img width="3731" height="392" alt="image" src="https://github.com/user-attachments/assets/15594e4c-9ed5-4934-8c64-be38a3aa2f70" />
<img width="3816" height="480" alt="image" src="https://github.com/user-attachments/assets/27cfad1a-533a-4430-b690-b0c95450621f" />
<img width="1223" height="173" alt="image" src="https://github.com/user-attachments/assets/ca49b3fa-af5f-4944-815e-1a8f81d4439c" />


## 总结
co版本重写了Serializer依赖的api，并发执行序列化，合并能够合并的查询语句，可以一定程度缓解n+1问题，装了n+1查询问题严重的插件的站点可能能多个数百毫秒的提升（合并部分可以合并的请求），空闲基本可以跑到峰值的300ms。但是如果自己照着日志把follow tags、follow users，fof badgegs，fof poll等插件的n+1查询改了，就几乎没有提升了。而co模式相比woker模式本身有隔离作用域和各种反射的额外开销，所以具体用哪种模式还要根据实际情况。

总的来说即使很大程度上解决了序列化过程中的n+1问题，视图层计算过重也难以避免，所以有条件还是要上amd的u，
对实际用户体验替身较大的场景是，降低每次请求的数据量，比如调低每一页的贴数，拆分原本重型的请求，让用户不用等容器启动的时间，直接拿到刚组装出来的几个帖子内容。

站点负载不大的情况下，可以拆分请求，让几个小请求并行，这样可以用上cpu的多核性能，提升单个用户的体验。


因为swoole节省了容器启动的部分时间，小型请求的初始水位降低，用户就能得到几乎及时的反馈。

对于flarum真正的性能问题，cpu过于密集，和n+1查询，能做的有限。

重要的还是配合拆分请求，减轻单次请求的业务量，降低首字节返回和提高请求频率，让用户体感快一点。后端已经n+1了，该常驻的常驻后，把请求拆成在前端n+1的办法的额外开销居然挺小的…

```
觉得flarum本身“很轻”，空闲时也能让用户等个一两秒也没关系的别用哈。
类似的思路两年前就有人搞出来了，人家在群里提了句就被你们追着喷，一直没人肯分享优化思路真是活78该。
```
