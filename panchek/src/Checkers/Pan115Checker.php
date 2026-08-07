<?php
/**
 * PanCheck PHP Version
 * 115网盘检测器
 * API: https://115cdn.com/webapi/share/snap
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class Pan115Checker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('pan115', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享码和提取码
        $shareCode = $this->extractShareCode($link, '/115cdn\.com\/s\/([a-zA-Z0-9]+)/');
        if (!$shareCode) {
            $shareCode = $this->extractShareCode($link, '/115\.com\/s\/([a-zA-Z0-9]+)/');
        }

        $receiveCode = $this->extractPassword($link);

        if (!$shareCode) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        if (!$receiveCode) {
            return CheckResult::failure('115网盘需要提取码(password)参数', 0);
        }

        // 调用115网盘API
        $apiUrl = "https://115cdn.com/webapi/share/snap?share_code={$shareCode}&offset=0&limit=20&receive_code={$receiveCode}&cid=";

        $response = $this->doRequest($apiUrl, array(
            'headers' => array(
                'Referer: https://115cdn.com/s/' . $shareCode . '?password=' . $receiveCode,
                'X-Requested-With: XMLHttpRequest',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            return CheckResult::failure('API请求失败: ' . $response['error'], $duration);
        }

        $data = json_decode($response['body'], true);

        if (!$data) {
            return CheckResult::failure('无法解析API响应', $duration);
        }

        // 检查API返回状态
        // state=true 且 errno=0 表示成功
        if (isset($data['state']) && $data['state'] === true && isset($data['errno']) && $data['errno'] === 0) {
            return CheckResult::success($duration);
        }

        // 处理错误
        $errorMsg = isset($data['error']) ? $data['error'] : '分享已失效';
        if (isset($data['errno'])) {
            $errorCodes = array(
                10001 => '分享不存在',
                10002 => '提取码错误',
                10003 => '分享已过期',
                10004 => '分享已取消',
                10005 => '分享内容违规',
                10006 => '文件已被删除',
                41001 => '分享已失效',
            );
            if (isset($errorCodes[$data['errno']])) {
                $errorMsg = $errorCodes[$data['errno']];
            }
        }

        return CheckResult::failure($errorMsg, $duration);
    }

    /**
     * 提取提取码
     */
    private function extractPassword($link)
    {
        if (preg_match('/[?&]password=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        if (preg_match('/[?&]pwd=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
