安装swoole后，把flarum-swoole放在flarum根目录（和vendor文件夹平级）
启动
```
php flarum-swoole.php start
```
如果有fof/redis和litespeed cache插件，这个脚本可以读取redis设置，代替litespeed网关做缓存。

lsphp和swoole互斥，不为了lsphp的性能提升，没必要专门为了缓存把网关换成litespeed，毕竟open litespeed网关真的难用。


提升其实不算很大，单次请求也就快一百毫秒左右，flarum最大的瓶颈还是太多n+1查询。

```
觉得flarum本身很轻后端跑一两秒也没关系的懂哥别用哈。
类似的思路两年前就有人搞出来了，人家不想分享还不是被你们喷走的。
```
