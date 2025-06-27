<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'monitor:start' => 'app\command\MonitorCommand',
        'advertisement:allmember' => 'app\command\AdvertisementAllMemberBroadcastCommand', // 新增
    ],
];