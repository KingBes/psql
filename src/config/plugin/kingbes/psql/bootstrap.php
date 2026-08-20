<?php

// psql 的 webman 插件启动引导（安装时发布到 config/plugin/kingbes/psql/bootstrap.php）。
// webman 启动时会自动遍历 config/plugin/*/*/bootstrap.php 并依次调用其 start()。

return [
    \Kingbes\Psql\Bridge\Webman\Bootstrap::class,
];