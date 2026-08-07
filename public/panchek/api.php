<?php
/**
 * PanCheck PHP Version
 * HTTP API 接口
 * 兼容 PHP 7.2+
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => 'PHP Error: ' . $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ));
    exit;
});

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => 'PHP Exception: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ));
    exit;
});

try {
    if (PHP_VERSION_ID < 70200) {
        throw new Exception('需要 PHP 7.2 或更高版本，当前版本: ' . PHP_VERSION);
    }

    if (!extension_loaded('curl')) {
        throw new Exception('需要安装 curl PHP 扩展');
    }

    // 加载所有文件
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
    require_once __DIR__ . '/src/Checkers/GuangyaChecker.php';
    require_once __DIR__ . '/src/CheckerFactory.php';

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('error' => '只接受 POST 请求'));
        exit;
    }

    $input = file_get_contents('php://input');
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(array('error' => '请求体为空'));
        exit;
    }

    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(array('error' => 'JSON解析错误: ' . json_last_error_msg()));
        exit;
    }

    if (!$data || !isset($data['links']) || !is_array($data['links'])) {
        http_response_code(400);
        echo json_encode(array('error' => '请求格式错误，需要提供 links 数组'));
        exit;
    }

    $links = $data['links'];
    if (empty($links)) {
        http_response_code(400);
        echo json_encode(array('error' => 'links 数组不能为空'));
        exit;
    }

    if (count($links) > 100) {
        http_response_code(400);
        echo json_encode(array('error' => '一次最多检测 100 个链接'));
        exit;
    }

    $factory = new PanCheck\CheckerFactory();

    $startTime = microtime(true);
    $results = array();
    $validCount = 0;
    $invalidCount = 0;
    $errorCount = 0;

    foreach ($links as $link) {
        $result = $factory->autoCheck($link);

        if ($result['success']) {
            if ($result['valid']) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        } else {
            $errorCount++;
        }

        $results[] = array(
            'link' => $link,
            'platform' => isset($result['platform']) ? $result['platform'] : null,
            'valid' => isset($result['valid']) ? $result['valid'] : null,
            'failureReason' => isset($result['failureReason']) ? $result['failureReason'] : null,
            'duration' => isset($result['duration']) ? $result['duration'] : 0,
            'error' => isset($result['error']) ? $result['error'] : null,
        );
    }

    $totalDuration = intval((microtime(true) - $startTime) * 1000);

    $response = array(
        'success' => true,
        'summary' => array(
            'total' => count($links),
            'valid' => $validCount,
            'invalid' => $invalidCount,
            'error' => $errorCount,
            'duration' => $totalDuration,
        ),
        'results' => $results,
    );

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ));
}
