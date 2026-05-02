flarum的非侵入式swoole容器，做了以下几件事：
1. di container常驻
2，权限组常驻（需要配合插件或者直接在根目录extend.php写一下相关入口）
3. n+1序列化协程并行（需要co版本）
4. 性能监控，开启后可以看看是什么插件在n+1

# 版本要求
自用php8.5+swoole6.2。
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
提升其实不算很大，单次请求也就快一百毫秒左右，没开启Hook所以其实对并发的提升也不大，swoole的主要作用是加热容器。

提升较大的使用的场景是，降低每次请求的数据量，比如调低每一页的贴数，拆分原本重型的请求，让用户不用等容器启动的时间，直接拿到刚组装出来的几个帖子内容,或者让几个小请求并行。

协程版本重写了Serializer依赖的api，并发执行序列化，可以一定程度缓解n+1问题，

因为swoole节省了容器启动的部分时间，小型请求的初始水位降低，用户就能得到几乎及时的反馈。

对于flarum真正的性能问题，cpu过于密集，和n+1查询，能做的有限。

重要的还是配合拆分请求，减轻单次请求的业务量，降低首字节返回和提高请求频率，让用户体感快一点。后端已经n+1了，该常驻的常驻后，把请求拆成在前端n+1的办法的额外开销居然挺小的…

```
觉得flarum本身“很轻”，让用户等个一两秒也没关系的别用哈。
类似的思路两年前就有人搞出来了，人家在群里提了句就被你们追着喷，现在越来越难用是flarum应得的。
```
