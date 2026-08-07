<?php
/**
 * PanCheck PHP Version
 * 使用示例
 */

require_once __DIR__ . '/src/CheckResult.php';
require_once __DIR__ . '/src/LinkCheckerInterface.php';
require_once __DIR__ . '/src/BaseChecker.php';
require_once __DIR__ . '/src/Checkers/BaiduChecker.php';
require_once __DIR__ . '/src/Checkers/AliyunChecker.php';
require_once __DIR__ . '/src/Checkers/QuarkChecker.php';
require_once __DIR__ . '/src/Checkers/Pan115Checker.php';
require_once __DIR__ . '/src/Checkers/Pan123Checker.php';
require_once __DIR__ . '/src/Checkers/UCChecker.php';
require_once __DIR__ . '/src/Checkers/TianyiChecker.php';
require_once __DIR__ . '/src/Checkers/XunleiChecker.php';
require_once __DIR__ . '/src/Checkers/CMCCChecker.php';
require_once __DIR__ . '/src/CheckerFactory.php';

use PanCheck\CheckerFactory;

// 创建检测器工厂
$factory = new CheckerFactory();

echo "========================================\n";
echo "    PanCheck PHP 版本 - 使用示例\n";
echo "========================================\n\n";

// 示例 1: 自动识别并检测
echo "【示例 1】自动识别平台并检测\n";
$testLinks = [
    'https://pan.baidu.com/s/1CVdXz22ze32wEmHuWKU7wA?pwd=78p2',
    'https://www.aliyundrive.com/s/2example',
    'https://pan.quark.cn/s/abc123',
];

foreach ($testLinks as $link) {
    echo "\n检测链接: {$link}\n";
    $result = $factory->autoCheck($link);

    if ($result['success']) {
        echo "平台: {$result['platform']}\n";
        echo "状态: " . ($result['valid'] ? '✅ 有效' : '❌ 失效') . "\n";
        echo "耗时: {$result['duration']}ms\n";
        if (!$result['valid']) {
            echo "原因: {$result['failureReason']}\n";
        }
    } else {
        echo "错误: {$result['error']}\n";
    }
}

// 示例 2: 使用指定平台检测器
echo "\n\n【示例 2】使用指定平台检测器\n";
$baiduChecker = $factory->getChecker('baidu');
if ($baiduChecker) {
    $link = 'https://pan.baidu.com/s/1test';
    echo "\n检测百度网盘链接: {$link}\n";
    $result = $baiduChecker->check($link);
    echo "状态: " . ($result->valid ? '✅ 有效' : '❌ 失效') . "\n";
    echo "耗时: {$result->duration}ms\n";
    if (!$result->valid) {
        echo "原因: {$result->failureReason}\n";
    }
}

// 示例 3: 批量检测
echo "\n\n【示例 3】批量检测多个链接\n";
$links = [
    'https://pan.baidu.com/s/1example',
    'https://pan.quark.cn/s/test123',
    'https://www.aliyundrive.com/s/example',
];

echo "\n开始批量检测...\n";
foreach ($links as $link) {
    $result = $factory->autoCheck($link);
    if ($result['success']) {
        $status = $result['valid'] ? '✅' : '❌';
        echo "{$status} [{$result['platform']}] {$link}\n";
    } else {
        echo "⚠️  {$link} - {$result['error']}\n";
    }
}

echo "\n========================================\n";
echo "检测完成！\n";
