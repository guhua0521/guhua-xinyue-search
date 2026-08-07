<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'reset:today-search' => 'app\command\ResetTodaySearch',
        \app\command\CreateSite::class,
    ],
];
