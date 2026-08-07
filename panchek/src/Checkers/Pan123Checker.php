<?php
/**
 * PanCheck PHP Version
 * 123网盘检测器
 * API: https://www.123pan.com/api/share/info
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class Pan123Checker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('pan123', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取shareKey（支持多种123网盘域名和格式）
        $shareKey = $this->extractShareKey($link);

        if (!$shareKey) {
            return CheckResult::failure('链接格式无效：无法提取shareKey', 0);
        }

        // 调用123网盘API
        $apiUrl = "https://www.123pan.com/api/share/info?shareKey={$shareKey}";

        $response = $this->doRequest($apiUrl, array(
            'headers' => array(
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        // 403状态码可能是访问限制，但不是链接失效
        if ($response['status'] === 403) {
            return CheckResult::success($duration);
        }

        if (!$response['success']) {
            // 超时或请求错误视为有效，避免误判
            return CheckResult::success($duration);
        }

        $data = json_decode($response['body'], true);

        if (!$data) {
            // JSON解析错误视为有效
            return CheckResult::success($duration);
        }

        // 检查响应 - code为0或HasPwd为true表示有效
        if (isset($data['code'])) {
            // code 0 表示成功
            if ($data['code'] === 0) {
                return CheckResult::success($duration);
            }

            // code 200 也表示成功
            if ($data['code'] === 200) {
                return CheckResult::success($duration);
            }

            // 检查HasPwd字段（有密码的分享也是有效的）
            if (isset($data['data']['HasPwd']) && $data['data']['HasPwd']) {
                return CheckResult::success($duration);
            }

            // 其他错误码
            $errorCodes = array(
                404 => '分享不存在',
                403 => '分享已取消或违规',
                410 => '分享已过期',
                401 => '需要访问密码',
                400 => '请求参数错误',
                500 => '服务器内部错误',
            );

            $message = isset($errorCodes[$data['code']])
                ? $errorCodes[$data['code']]
                : '链接异常 (错误码: ' . $data['code'] . ')';

            return CheckResult::failure($message, $duration);
        }

        // 检查是否有 data.HasPwd 字段
        if (isset($data['data']['HasPwd']) && $data['data']['HasPwd']) {
            return CheckResult::success($duration);
        }

        // 检查是否有 data 字段
        if (isset($data['data']) && $data['data'] !== null) {
            return CheckResult::success($duration);
        }

        // 无法确定状态，返回失效
        return CheckResult::failure('无法确认分享状态', $duration);
    }

    /**
     * 从URL中提取shareKey（支持多种123网盘域名）
     */
    private function extractShareKey($urlStr)
    {
        // 支持多种123网盘域名，包含连字符-
        $patterns = array(
            '/https?:\/\/(?:www\.)?(?:123684|123685|123912|123pan|123592|123865)\.com\/s\/([a-zA-Z0-9-]+)/',
            '/https?:\/\/(?:www\.)?123pan\.cn\/s\/([a-zA-Z0-9-]+)/',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $urlStr, $matches)) {
                return $matches[1];
            }
        }

        // 如果正则匹配失败，尝试从URL路径中提取
        $parsedURL = parse_url($urlStr);
        if ($parsedURL && isset($parsedURL['path'])) {
            $pathParts = explode('/', trim($parsedURL['path'], '/'));
            if (count($pathParts) > 0) {
                $shareKey = end($pathParts);
                // 验证shareKey格式（非空即可）
                if (!empty($shareKey)) {
                    return $shareKey;
                }
            }
        }

        return null;
    }
}
