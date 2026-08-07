<?php
/**
 * PanCheck PHP Version
 * 迅雷云盘检测器
 * API: https://api-pan.xunlei.com/drive/v1/share
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class XunleiChecker extends BaseChecker
{
    private $deviceID = '5505bd0cab8c9469b98e5891d9fb3e0d';
    private $clientID = 'ZUBzD9J_XPXfn7f7';
    private $clientVersion = '1.10.0.2633';
    private $packageName = 'com.xunlei.browser';

    public function __construct()
    {
        parent::__construct('xunlei', 3, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取 share_id
        $shareID = $this->extractShareID($link);
        if (!$shareID) {
            return CheckResult::failure('链接格式无效：无法提取 share_id', 0);
        }

        // 提取 pwd
        $passCode = $this->extractPassCode($link);

        // 获取 captcha token
        $action = 'get:/drive/v1/share';
        $metas = array(
            'username' => '',
            'phone_number' => '',
            'email' => '',
            'package_name' => 'pan.xunlei.com',
            'client_version' => '1.92.10',
            'user_id' => '0',
        );

        $captchaToken = $this->getCaptchaToken($action, $metas);

        // 调用分享 API
        $apiUrl = "https://api-pan.xunlei.com/drive/v1/share?share_id=" . urlencode($shareID) .
                  "&pass_code=" . urlencode($passCode) .
                  "&limit=100&pass_code_token=&page_token=&thumbnail_size=SIZE_SMALL";

        $headers = array(
            'Accept: */*',
            'content-type: application/json',
            'origin: https://pan.xunlei.com',
            'referer: https://pan.xunlei.com/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'Accept-Encoding: gzip, deflate',
            'X-Client-Id: ' . $this->clientID,
            'X-Device-Id: ' . $this->deviceID,
        );

        if ($captchaToken) {
            $headers[] = 'X-Captcha-Token: ' . $captchaToken;
        }

        $response = $this->doRequestGzip($apiUrl, $headers);

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            // 请求失败，可能是网络问题
            return CheckResult::failure('请求失败: ' . $response['error'], $duration);
        }

        $body = $response['body'];

        // 解析 JSON 响应
        $data = json_decode($body, true);

        if (!$data) {
            return CheckResult::failure('解析响应失败', $duration);
        }

        // 检查 share_status
        // OK: 正常访问
        // PASS_CODE_EMPTY: 需要密码（链接有效，只是需要密码才能查看内容）
        if (isset($data['share_status'])) {
            if ($data['share_status'] === 'OK' || $data['share_status'] === 'PASS_CODE_EMPTY') {
                return CheckResult::success($duration);
            }
            $shareStatusText = isset($data['share_status_text']) ? $data['share_status_text'] : '分享状态: ' . $data['share_status'];
            return CheckResult::failure($shareStatusText, $duration);
        }

        // 检查错误信息
        if (isset($data['error_code'])) {
            $errorCode = (int)$data['error_code'];
            $errorMsg = isset($data['error']) ? $data['error'] : '未知错误';

            // error_code 为 9 表示被限制（IsRateLimited）
            // error_code 为 3 或其他值表示失效链接
            return CheckResult::failure("错误 {$errorCode}: {$errorMsg}", $duration);
        }

        return CheckResult::failure('响应格式异常：缺少 share_status 字段', $duration);
    }

    /**
     * 从链接中提取 share_id
     */
    private function extractShareID($shareURL)
    {
        if (preg_match('/pan\.xunlei\.com\/s\/([^?#]+)/', $shareURL, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 从链接中提取 pwd
     */
    private function extractPassCode($link)
    {
        if (preg_match('/[?&]pwd=([^&#]+)/', $link, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 获取 captcha token
     */
    private function getCaptchaToken($action, $metas)
    {
        list($timestamp, $captchaSign) = $this->getCaptchaSign();

        if (!is_array($metas)) {
            $metas = array();
        }
        $metas['timestamp'] = $timestamp;
        $metas['captcha_sign'] = $captchaSign;
        $metas['client_version'] = $this->clientVersion;
        $metas['package_name'] = $this->packageName;

        $requestBody = array(
            'action' => $action,
            'captcha_token' => '',
            'client_id' => $this->clientID,
            'device_id' => $this->deviceID,
            'meta' => $metas,
            'redirect_uri' => 'xlaccsdk01://xunlei.com/callback?state=harbor',
        );

        $jsonBody = json_encode($requestBody);
        $tokenAPI = 'https://xluser-ssl.xunlei.com/v1/shield/captcha/init';

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $tokenAPI,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json;charset=UTF-8',
                'Content-Type: application/json',
                'User-Agent: ANDROID-com.xunlei.browser/1.10.0.2633 networkType/WIFI appid/22062 deviceName/Xiaomi_M2004j7ac deviceModel/M2004J7AC OSVersion/13 protocolVersion/301 platformVersion/10 sdkVersion/233100 Oauth2Client/0.9 (Linux 4_9_337-perf-sn-uotan-gd9d488809c3d3d) (JAVA 0)',
                'x-device-id: ' . $this->deviceID,
                'x-client-id: ' . $this->clientID,
                'x-client-version: ' . $this->clientVersion,
            ),
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data && isset($data['captcha_token'])) {
            return $data['captcha_token'];
        }
        return null;
    }

    /**
     * 获取验证码签名
     */
    private function getCaptchaSign()
    {
        $timestamp = strval(intval(microtime(true) * 1000));
        $str = $this->clientID . $this->clientVersion . $this->packageName . $this->deviceID . $timestamp;

        $algorithms = array(
            'uWRwO7gPfdPB/0NfPtfQO+71',
            'F93x+qPluYy6jdgNpq+lwdH1ap6WOM+nfz8/V',
            '0HbpxvpXFsBK5CoTKam',
            'dQhzbhzFRcawnsZqRETT9AuPAJ+wTQso82mRv',
            'SAH98AmLZLRa6DB2u68sGhyiDh15guJpXhBzI',
            'unqfo7Z64Rie9RNHMOB',
            '7yxUdFADp3DOBvXdz0DPuKNVT35wqa5z0DEyEvf',
            'RBG',
            'ThTWPG5eC0UBqlbQ+04nZAptqGCdpv9o55A',
        );

        foreach ($algorithms as $algorithm) {
            $str = md5($str . $algorithm);
        }

        $sign = '1.' . $str;
        return array($timestamp, $sign);
    }

    /**
     * 发送 HTTP 请求并处理 gzip/deflate 编码
     */
    private function doRequestGzip($url, $headers)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $this->getTimeout(),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => 'gzip, deflate',  // 自动处理压缩
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return array(
            'success' => $error === '' && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $response,
            'error' => $error,
        );
    }
}
