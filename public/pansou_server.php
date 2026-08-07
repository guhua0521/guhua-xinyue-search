<?php
/**
 * PanSou API 独立代理服务器
 * 运行在独立端口(8081)，避免PHP内置服务器单线程死锁
 * 
 * 启动方式: php -S 127.0.0.1:8081 pansou_server.php
 * 调用方式: http://127.0.0.1:8081/?search=关键词
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

// 支持两种调用方式: ?search=xxx 或 ?kw=xxx
$keyword = isset($_GET['search']) ? $_GET['search'] : (isset($_GET['kw']) ? $_GET['kw'] : '');

if (empty($keyword)) {
    echo json_encode(['code' => 1, 'message' => '缺少搜索关键词', 'data' => ['list' => [], 'total' => 0]]);
    exit;
}

// 调用 PanSou API (端口8888)
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
// curl_close 在 PHP 8.0+ 无效果，8.5 会抛 Deprecated，跳过

if ($response === false || $httpCode !== 200) {
    echo json_encode([
        'code' => 1,
        'message' => 'PanSou API 请求失败: ' . ($error ?: "HTTP $httpCode"),
        'data' => ['list' => [], 'total' => 0]
    ]);
    exit;
}

$result = json_decode($response, true);
if (!$result || ($result['code'] ?? -1) !== 0) {
    echo json_encode(['code' => 1, 'message' => 'PanSou 返回错误', 'data' => ['list' => [], 'total' => 0]]);
    exit;
}

// 展平 merged_by_type 为扁平列表
$flatList = [];
$mergedByType = $result['data']['merged_by_type'] ?? [];

foreach ($mergedByType as $type => $items) {
    foreach ($items as $item) {
        $flatList[] = [
            'title' => $item['note'] ?? '',
            'url' => $item['url'] ?? '',
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
