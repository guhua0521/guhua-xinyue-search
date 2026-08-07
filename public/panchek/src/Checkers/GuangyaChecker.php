<?php

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class GuangyaChecker extends BaseChecker
{
    private $apiDomain = 'https://api.guangyapan.com';

    public function __construct()
    {
        parent::__construct('guangya', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        $shareId = $this->extractShareId($link);
        if (!$shareId) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        $apiUrl = $this->apiDomain . '/nd.bizuserres.s/v1/get_share_access_token';

        $postData = json_encode(array('shareId' => $shareId));

        $response = $this->doRequest($apiUrl, array(
            'method' => 'POST',
            'body' => $postData,
            'headers' => array(
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Origin: https://www.guangyapan.com',
                'Referer: https://www.guangyapan.com/',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            return $this->checkByPage($link, $duration);
        }

        $data = json_decode($response['body'], true);
        if ($data === null) {
            return $this->checkByPage($link, $duration);
        }

        $code = isset($data['code']) ? $data['code'] : null;
        $msg = isset($data['msg']) ? $data['msg'] : '';

        if (!empty($data['data']['accessToken'])) {
            return CheckResult::success($duration);
        }

        if ($msg === 'success') {
            return CheckResult::success($duration);
        }

        $errorMap = array(
            200 => '分享链接错误',
            201 => '分享不存在',
            202 => '分享已过期',
            203 => '分享已取消',
            204 => '分享内容违规',
            205 => '需要访问密码',
        );

        if ($code !== null && isset($errorMap[$code])) {
            return CheckResult::failure($errorMap[$code], $duration);
        }

        if ($code !== null && $code != 0) {
            return CheckResult::failure($msg ?: '链接异常 (错误码: ' . $code . ')', $duration);
        }

        return $this->checkByPage($link, $duration);
    }

    private function extractShareId($link)
    {
        if (preg_match('/guangyapan\.com\/s\/([a-zA-Z0-9_-]+)/', $link, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function checkByPage($link, $duration)
    {
        $shareId = $this->extractShareId($link);
        if (!$shareId) {
            return CheckResult::failure('无法提取分享码', $duration);
        }

        $shareUrl = 'https://www.guangyapan.com/s/' . $shareId;

        $response = $this->doRequest($shareUrl, array(
            'headers' => array(
                'Referer: https://www.guangyapan.com/',
            ),
        ));

        if (!$response['success']) {
            if ($response['status'] === 404) {
                return CheckResult::failure('分享不存在或已失效', $duration);
            }
            return CheckResult::failure('网络请求失败', $duration);
        }

        $body = $response['body'];

        $invalidKeywords = array(
            '分享已删除', '分享已失效', '分享不存在',
            '该分享已过期', '分享被取消', '内容违规',
            '链接已失效', '资源已删除', '分享已过期',
        );

        foreach ($invalidKeywords as $keyword) {
            if (strpos($body, $keyword) !== false) {
                return CheckResult::failure('链接已失效: ' . $keyword, $duration);
            }
        }

        return CheckResult::failure('无法确认分享状态', $duration);
    }
}
