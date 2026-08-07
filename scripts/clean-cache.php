#!/usr/bin/env php
<?php
/**
 * ThinkPHP 文件缓存过期清理脚本
 *
 * 遍历 runtime/cache 下的缓存文件，读取文件头部的过期秒数，
 * 删除已过期条目；无法识别头部的旧文件超过7天一并清理。
 * 用法：php clean-cache.php [缓存目录]
 */

$cacheDir = $argv[1] ?? '/var/www/xinyue-search/runtime/cache';
$now = time();
$deleted = 0;
$checked = 0;

if (!is_dir($cacheDir)) {
    echo "缓存目录不存在: $cacheDir\n";
    exit(1);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$filesToDelete = [];
$dirsToDelete = [];

foreach ($it as $f) {
    if ($f->isDir()) {
        $dirsToDelete[] = $f->getPathname();
        continue;
    }
    if (substr($f->getFilename(), -4) !== '.php') {
        continue;
    }
    $checked++;
    $file = $f->getPathname();
    $content = @file_get_contents($file);
    if ($content === false) {
        continue;
    }

    $expired = false;
    // 读取头部 TTL（缓存文件头 // 后的12位数字，如 000000000060）
    if (preg_match('#/\*{2}[0-9]{12}#', substr($content, 0, 60), $m)) {
        $ttl = (int) substr($m[0], 2);
        if ($ttl > 0 && $f->getMTime() + $ttl < $now) {
            $expired = true;
        }
    } else {
        // 无法识别头部：超过7天视为过期
        if ($f->getMTime() < $now - 7 * 86400) {
            $expired = true;
        }
    }

    if ($expired) {
        if (@unlink($file)) {
            $deleted++;
        }
    }
}

// 清理空目录（从深到浅）
usort($dirsToDelete, function ($a, $b) {
    return substr_count($b, DIRECTORY_SEPARATOR) <=> substr_count($a, DIRECTORY_SEPARATOR);
});
foreach ($dirsToDelete as $dir) {
    if (@is_dir($dir) && count(scandir($dir)) <= 2) {
        @rmdir($dir);
    }
}

echo "缓存清理完成：检查 $checked 个文件，删除 $deleted 个过期缓存\n";
