安装swoole后，放在flarum根目录（和vendor文件夹平级）
启动命令
```
php flarum-swoole.php start
```
如果有fof/redis和litespeed cache插件，本身可以代替litespeed的功能（litespeed捆绑lsphp和swoole互斥，不为了lsphp的性能提升没必要专门为了缓存把网关换成litespeed，毕竟那玩意真的难用）
觉得flarum本身很轻后端跑一两秒也没关系的懂哥别用哈
