<?php
/**
 * PanCheck PHP Version
 * 天翼云盘检测器
 * API: https://cloud.189.cn/api/open/share/getShareInfoByCodeV2.action
 * 注意：API返回XML格式
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class TianyiChecker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('tianyi', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享码
        $shareCode = $this->extractShareCode($link, '/\/t\/([a-zA-Z0-9]+)/');
        if (!$shareCode) {
            $shareCode = $this->extractShareCode($link, '/\/s\/([a-zA-Z0-9]+)/');
        }

        if (!$shareCode) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        // 提取访问码（如果有）
        $accessCode = $this->extractAccessCode($link);

        // 构建分享码参数
        $shareCodeParam = $shareCode;
        if ($accessCode) {
            $shareCodeParam = $shareCode . '（访问码：' . $accessCode . '）';
        }

        // 调用天翼云盘API
        $noCache = microtime(true);
        $apiUrl = 'https://cloud.189.cn/api/open/share/getShareInfoByCodeV2.action?noCache=' . $noCache . '&shareCode=' . urlencode($shareCodeParam);

        $response = $this->doRequest($apiUrl, array(
            'headers' => array(
                'Accept: application/json, text/plain, */*',
                'Referer: https://cloud.189.cn/',
                'sec-ch-ua: "Chromium";v="142", "Google Chrome";v="142", "Not_A Brand";v="99"',
                'sec-ch-ua-mobile: ?0',
                'sec-ch-ua-platform: "Windows"',
                'sec-fetch-dest: empty',
                'sec-fetch-mode: cors',
                'sec-fetch-site: same-origin',
                'sign-type: 1',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            return CheckResult::failure('API请求失败: ' . $response['error'], $duration);
        }

        $body = $response['body'];

        // API返回XML格式，需要解析
        if (strpos($body, '<?xml') !== false) {
            $data = $this->parseXML($body);
            if ($data === null) {
                return CheckResult::failure('无法解析API响应(XML)', $duration);
            }
        } else {
            // 尝试解析JSON（备用）
            $data = json_decode($body, true);
            if (!$data) {
                return CheckResult::failure('无法解析API响应', $duration);
            }
        }

        // 检查API返回状态
        // shareId > 0 表示链接有效
        $shareId = isset($data['shareId']) ? (int)$data['shareId'] : 0;
        if ($shareId > 0) {
            return CheckResult::success($duration);
        }

        // 处理错误
        $errorMsg = isset($data['res_message']) ? $data['res_message'] : '分享已失效';
        $resCode = isset($data['res_code']) ? $data['res_code'] : 0;

        if ($resCode !== 0 && $resCode !== '0') {
            $errorCodes = array(
                1 => '分享不存在',
                2 => '分享已过期',
                3 => '分享已取消',
                4 => '分享内容违规',
                5 => '需要访问码',
                6 => '访问码错误',
            );
            if (isset($errorCodes[(int)$resCode])) {
                $errorMsg = $errorCodes[(int)$resCode];
            }
        }

        return CheckResult::failure($errorMsg, $duration);
    }

    /**
     * 解析XML响应
     */
    private function parseXML($xmlString)
    {
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            return null;
        }

        // 转换为数组
        $data = array();
        foreach ($xml as $key => $value) {
            if (count($value->children()) > 0) {
                // 有子节点，递归处理
                $data[$key] = $this->xmlToArray($value);
            } else {
                $data[$key] = (string)$value;
            }
        }

        return $data;
    }

    /**
     * XML转数组（递归）
     */
    private function xmlToArray($xml)
    {
        $result = array();
        foreach ($xml as $key => $value) {
            if (count($value->children()) > 0) {
                $result[$key] = $this->xmlToArray($value);
            } else {
                $result[$key] = (string)$value;
            }
        }
        return $result;
    }

    /**
     * 提取访问码
     */
    private function extractAccessCode($link)
    {
        if (preg_match('/[?&]code=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        if (preg_match('/[?&]pwd=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        if (preg_match('/[?&]password=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
