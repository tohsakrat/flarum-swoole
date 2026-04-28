安装swoole
```
pecl install swoole
```
flarum1.x运行环境~~历史悠久~~，运行环境多种多样，我也没在别的版本测试过，自用php8.5+swoole6.2。

把非侵入式入口文件flarum-swoole.php放在flarum根目录（和vendor文件夹平级）

启动

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
exec su -c 'cd /www/wwwroot/klezik-insi.de/flarum && php flarum-swoole.php start'
```


如果有fof/redis和litespeed cache插件，这个脚本可以读取redis设置，代替litespeed网关做缓存。

比真正的litespeed好一点是可以在入口就去redis拿session，做颗粒度更细的缓存策略。~~不过还没做~~

~~没在没装fof/redis的环境下测试过，不能跑别来找我。~~

~~各种连接保活没测试过，我的探针五秒就会请求一次，无保活环境可能会死~~

lsphp和swoole互斥，不为了lsphp的性能提升，没必要专门为了缓存把网关换成litespeed，毕竟免费版open litespeed网关限制是真多。

提升其实不算很大，单次请求也就快一百毫秒左右，没开启Hook所以其实对并发的提升也不大，swoole的主要作用是加热容器。

提升较大的使用的场景是，降低每次请求的数据量，比如调低每一页的贴数，拆分原本重型的请求，让用户不用等容器启动的时间，直接拿到刚组装出来的几个帖子内容。

因为swoole节省了容器启动的部分时间，小型请求的初始水位降低，用户就能得到几乎及时的反馈。

对于flarum真正的性能问题，序列化过程中大量的n+1查询和串行等待，其实是帮不上忙，该慢还是慢。

至于这部分，除了简化权限+配缓存，或者减少插件，或者重写插件，基本上是万策尽优化不了。~~话说回来不为了插件用flarum干什么~~

重要的还是配合拆分请求，减轻单次请求的业务量，降低首字节返回和提高请求频率，让用户体感快一点。

~~当然如果你还在建站，还没有迁移成本，推荐能不用flarum就别用~~

```
觉得flarum本身“很轻”，让用户等个一两秒也没关系的别用哈。
类似的思路两年前就有人搞出来了，人家在群里提了句就被你们追着喷，现在能用的插件越来越少是flarum应得的。
```
