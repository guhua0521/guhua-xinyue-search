<?php
/**
 * PanCheck PHP Version
 * 百度网盘检测器
 * 参考 Go 版本的实现
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class BaiduChecker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('baidu', 3, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        // 应用限流
        $this->applyRateLimit();

        // 规范化URL
        $normalizedLink = $this->normalizeBaiduURL($link);
        if (!$normalizedLink) {
            return CheckResult::failure('URL格式无效', 0);
        }

        // 提取分享ID (surl)
        $surl = $this->extractBaiduShareID($normalizedLink);
        if (!$surl) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        // 提取密码
        $password = $this->extractPassword($normalizedLink);

        // 获取shorturl（去掉首字符）
        $shorturl = $this->getShorturl($surl);

        // 构建share/list API URL
        $apiURL = "https://pan.baidu.com/share/list?web=5&app_id=250528&desc=1&showempty=0&page=1&num=20&order=time&shorturl={$shorturl}&root=1&view_mode=1&channel=chunlei&clienttype=0";

        $bdclnd = '';

        // 如果URL中带有提取码，先验证提取码
        if ($password) {
            $randsk = $this->verifyPassCode($normalizedLink, $shorturl, $password);
            if ($randsk === null) {
                $duration = intval((microtime(true) - $startTime) * 1000);
                return CheckResult::failure('提取码验证失败', $duration);
            }
            $bdclnd = $randsk;
        }

        // 调用share/list API
        $result = $this->callShareListAPI($apiURL, $normalizedLink, $bdclnd);

        $duration = intval((microtime(true) - $startTime) * 1000);

        if ($result === null) {
            return CheckResult::failure('API请求失败', $duration, true);
        }

        $errno = isset($result['errno']) ? $result['errno'] : null;
        $errMsg = isset($result['errmsg']) ? $result['errmsg'] : '';

        // 如果errno=0，表示链接有效
        if ($errno === 0 || $errno === '0') {
            return CheckResult::success($duration);
        }

        // 根据errno判断失败原因
        $failureReason = $this->getFailureReason($errno, $errMsg);
        $isRateLimited = ($errno == -62); // -62表示请求接口受限

        return CheckResult::failure($failureReason, $duration, $isRateLimited);
    }

    /**
     * 规范化百度网盘URL
     */
    private function normalizeBaiduURL($link)
    {
        $cleaned = trim($link);

        // 找到 https://pan.baidu.com/s/ 的位置
        $startIdx = strpos($cleaned, 'https://pan.baidu.com/s/');
        if ($startIdx === false) {
            $startIdx = strpos($cleaned, 'http://pan.baidu.com/s/');
        }
        if ($startIdx === false) {
            return null;
        }

        // 找到URL结束位置
        $endIdx = $startIdx;
        $len = strlen($cleaned);
        while ($endIdx < $len) {
            $char = $cleaned[$endIdx];
            // 遇到空白字符或"提取码"等关键词，停止
            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t") {
                break;
            }
            $remaining = substr($cleaned, $endIdx);
            if (strpos($remaining, '提取码') === 0 || strpos($remaining, '密码') === 0) {
                break;
            }
            $endIdx++;
        }

        // 提取URL部分
        $urlStr = substr($cleaned, $startIdx, $endIdx - $startIdx);
        return trim($urlStr);
    }

    /**
     * 从URL中提取分享ID (surl)
     */
    private function extractBaiduShareID($shareURL)
    {
        // 处理 /s/ 格式
        if (preg_match('/\/s\/([a-zA-Z0-9_-]+)/', $shareURL, $matches)) {
            $surl = $matches[1];
            // 移除可能的查询参数部分
            if (($idx = strpos($surl, '?')) !== false) {
                $surl = substr($surl, 0, $idx);
            }
            return $surl;
        }

        // 处理 /share/init?surl= 格式
        if (preg_match('/[?&]surl=([a-zA-Z0-9_-]+)/', $shareURL, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 获取shorturl（去掉首字符的surl）
     */
    private function getShorturl($surl)
    {
        if (strlen($surl) > 1) {
            return substr($surl, 1);
        }
        return $surl;
    }

    /**
     * 提取访问密码
     */
    private function extractPassword($link)
    {
        if (preg_match('/[?&]pwd=([a-zA-Z0-9]+)/', $link, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * 验证提取码
     */
    private function verifyPassCode($shareURL, $shorturl, $password)
    {
        // 第一步：先访问分享页面获取必要的 Cookie
        $initURL = "https://pan.baidu.com/share/init?surl={$shorturl}";
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $initURL,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ),
        ));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        // 从响应头中提取 Cookie
        $cookies = array();
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $response, $matches)) {
            foreach ($matches[1] as $cookie) {
                $cookies[] = $cookie;
            }
        }
        
        // 构建 Cookie 字符串
        $cookieStr = implode('; ', $cookies);
        
        // 第二步：发送验证请求
        $apiURL = "https://pan.baidu.com/share/verify?surl={$shorturl}&channel=chunlei&web=1&app_id=250528&clienttype=0";

        $postData = http_build_query(array(
            'pwd' => $password,
            'vcode' => '',
            'vcode_str' => '',
        ));

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $apiURL,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIE => $cookieStr,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array(
                'Referer: ' . $initURL,
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/javascript, */*; q=0.01',
            ),
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return null;
        }

        $result = json_decode($response, true);
        if (!$result) {
            return null;
        }

        $errno = isset($result['errno']) ? $result['errno'] : null;
        if ($errno !== 0 && $errno !== '0') {
            return null;
        }

        if (isset($result['randsk']) && $result['randsk'] !== '') {
            return $result['randsk'];
        }

        return null;
    }

    /**
     * 调用share/list API
     */
    private function callShareListAPI($apiURL, $refererURL, $bdclnd)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $apiURL,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh,en-GB;q=0.9,en-US;q=0.8,en;q=0.7,zh-CN;q=0.6',
                'Connection: keep-alive',
                'Referer: ' . $refererURL,
            ),
        ));

        // 如果提供了bdclnd，设置Cookie
        if ($bdclnd) {
            curl_setopt($ch, CURLOPT_COOKIE, 'BDCLND=' . $bdclnd);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return null;
        }

        $result = json_decode($response, true);
        if (!$result) {
            // 尝试手动解析errno
            if (preg_match('/"errno":\s*(-?\d+)/', $response, $matches)) {
                return array('errno' => intval($matches[1]), 'errmsg' => '');
            }
            return null;
        }

        return $result;
    }

    /**
     * 根据errno获取失败原因
     */
    private function getFailureReason($errno, $errMsg)
    {
        if ($errMsg) {
            return "分享链接无效 (errno: {$errno}, err_msg: {$errMsg})";
        }

        // 根据常见错误码提供更友好的提示
        switch ($errno) {
            case -12:
                return "缺少提取码 (errno: -12)";
            case -9:
                return "提取码错误 (errno: -9)";
            case -62:
                return "请求接口受限 (errno: -62)";
            case -8:
                return "分享文件已过期 (errno: -8)";
            case -130:
                return "需要提取码 (errno: -130)";
            case 2:
                return "分享已失效 (errno: 2)";
            default:
                return "分享链接无效 (errno: {$errno})";
        }
    }
}
