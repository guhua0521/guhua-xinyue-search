<?php
/**
 * PanCheck PHP Version
 * 阿里云盘检测器
 * API: https://api.aliyundrive.com/adrive/v3/share_link/get_share_by_anonymous
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class AliyunChecker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('aliyun', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享 ID
        $shareId = $this->extractShareId($link);
        if (!$shareId) {
            return CheckResult::failure('无法提取分享ID', 0);
        }

        // 调用阿里云盘 API
        $result = $this->getShareInfo($shareId);

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            // 检查是否为频率限制错误
            if (isset($result['is_rate_limited']) && $result['is_rate_limited']) {
                return CheckResult::failure($result['message'], $duration, true);
            }
            return CheckResult::failure($result['message'], $duration);
        }

        return CheckResult::success($duration);
    }

    /**
     * 从URL中提取 share_id
     */
    private function extractShareId($link)
    {
        // 支持 www.alipan.com 和 www.aliyundrive.com
        if (preg_match('/\/s\/([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 获取分享信息
     */
    private function getShareInfo($shareId)
    {
        // 使用新的 API 端点 (v3)
        $apiUrl = "https://api.aliyundrive.com/adrive/v3/share_link/get_share_by_anonymous?share_id={$shareId}";

        $postData = json_encode(array('share_id' => $shareId));

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: ',
                'Origin: https://www.alipan.com',
                'Referer: https://www.alipan.com/',
                'X-Canary: client=web,app=share,version=v2.3.1',
            ),
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return array('success' => false, 'message' => '网络请求失败: ' . $error);
        }

        // 检查 HTTP 状态码
        if ($httpCode === 429) {
            return array(
                'success' => false,
                'message' => 'API频率限制（429错误）',
                'is_rate_limited' => true
            );
        }

        if ($httpCode !== 200) {
            return array('success' => false, 'message' => 'HTTP错误: ' . $httpCode);
        }

        $data = json_decode($response, true);

        if (!$data) {
            return array('success' => false, 'message' => '无法解析响应');
        }

        // 检查错误码
        if (isset($data['code'])) {
            $code = $data['code'];
            $errorMessages = array(
                'ShareNotFound' => '分享不存在',
                'NotFound.Share' => '分享不存在',
                'ShareExpired' => '分享已过期',
                'ShareCancelled' => '分享已取消',
                'ShareForbidden' => '分享内容违规',
                'InvalidPassword' => '需要访问密码',
                'ParamError' => '参数错误',
                'InternalError' => '服务器内部错误',
            );

            if (isset($errorMessages[$code])) {
                return array('success' => false, 'message' => $errorMessages[$code]);
            }

            return array('success' => false, 'message' => '链接异常: ' . $code);
        }

        // 检查是否包含分享标题（表示分享有效）
        if (isset($data['share_title']) || isset($data['file_infos']) || isset($data['files'])) {
            return array('success' => true);
        }

        // 如果没有 code 且响应不为空，认为有效
        if (!empty($data)) {
            return array('success' => true);
        }

        return array('success' => false, 'message' => '无法验证链接');
    }
}
