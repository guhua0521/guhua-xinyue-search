<?php
/**
 * PanSou API 代理脚本
 * 将 PanSou 的分组结果展平为扁平列表，供心悦搜索后台调用
 * 
 * 调用方式: /pansou_proxy.php?search=关键词
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

$keyword = isset($_GET['search']) ? $_GET['search'] : '';
if (empty($keyword)) {
    echo json_encode(['code' => 1, 'message' => '缺少搜索关键词', 'data' => ['list' => [], 'total' => 0]]);
    exit;
}

// 调用 PanSou API
$pansouUrl = 'http://127.0.0.1:8888/api/search';
$postData = json_encode(['kw' => $keyword]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $pansouUrl,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    echo json_encode(['code' => 1, 'message' => 'PanSou API 请求失败: ' . ($error ?: "HTTP $httpCode"), 'data' => ['list' => [], 'total' => 0]]);
    exit;
}

$result = json_decode($response, true);
if (!$result || $result['code'] !== 0) {
    echo json_encode(['code' => 1, 'message' => 'PanSou 返回错误', 'data' => ['list' => [], 'total' => 0]]);
    exit;
}

// 可选: 按网盘类型过滤 (quark/baidu/aliyun/uc/xunlei)
$filterType = isset($_GET['type']) ? $_GET['type'] : '';

// 展平 merged_by_type 为扁平列表
$flatList = [];
$mergedByType = $result['data']['merged_by_type'] ?? [];

foreach ($mergedByType as $type => $items) {
    // 如果指定了类型过滤，只返回匹配的
    if ($filterType && $type !== $filterType) {
        continue;
    }
    foreach ($items as $item) {
        $url = $item['url'] ?? '';
        $title = $item['note'] ?? '';
        // 过滤掉空文件：没有链接或没有标题的
        if (empty($url) || empty($title)) {
            continue;
        }
        $flatList[] = [
            'title' => $title,
            'url' => $url,
            'type' => $type,
            'source' => $item['source'] ?? '',
            'password' => $item['password'] ?? '',
            'datetime' => $item['datetime'] ?? '',
            'images' => $item['images'] ?? [],
        ];
    }
}

echo json_encode([
    'code' => 0,
    'message' => 'success',
    'data' => [
        'list' => $flatList,
        'total' => count($flatList),
    ]
], JSON_UNESCAPED_UNICODE);
