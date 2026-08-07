<?php
/**
 * PanCheck PHP Version
 * 中国移动云盘检测器
 * 完全按照Go版本逻辑实现
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class CMCCChecker extends BaseChecker
{
    private $key = 'PVGDwmcvfs1uV3d1';

    public function __construct()
    {
        parent::__construct('cmcc', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享ID
        $shareId = $this->extractShareId($link);
        if (!$shareId) {
            return CheckResult::failure('链接格式无效：无法提取分享ID', 0);
        }

        // 调用中国移动云盘API
        $result = $this->getShareInfo($shareId);

        $duration = intval((microtime(true) - $startTime) * 1000);

        if ($result['error']) {
            // 检查是否为超时错误
            if (strpos($result['message'], 'timeout') !== false ||
                strpos($result['message'], '请求超时') !== false) {
                return CheckResult::failure('请求超时', $duration);
            }
            return CheckResult::failure('检测失败: ' . $result['message'], $duration);
        }

        $response = $result['data'];

        // 提取关键字段
        $resultCode = isset($response['resultCode']) ? $response['resultCode'] : '';
        $desc = isset($response['desc']) ? $response['desc'] : '';
        $data = isset($response['data']) ? $response['data'] : null;

        // 判断链接是否有效：
        // 1. resultCode 必须为 "0"（成功）
        // 2. data 不能为 null（必须包含分享信息）
        if ($resultCode === '0' && $data !== null) {
            return CheckResult::success($duration);
        }

        // 获取失败原因
        $failureMessage = '获取分享信息失败';
        if ($desc !== '') {
            $failureMessage = $desc;
        } elseif ($resultCode !== '') {
            $failureMessage = "错误码: {$resultCode}";
        }

        return CheckResult::failure($failureMessage, $duration);
    }

    /**
     * 从链接中提取分享ID
     */
    private function extractShareId($link)
    {
        // 使用与Go版本相同的正则：
        // https://(?:yun\.139\.com/shareweb/#/w/i/|caiyun\.139\.com/m/i\?)([^&]+)
        if (preg_match('/https:\/\/(?:yun\.139\.com\/shareweb\/\#\/w\/i\/|caiyun\.139\.com\/m\/i\?)([^&]+)/', $link, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 获取分享信息
     */
    private function getShareInfo($shareId)
    {
        // 构建请求体数据
        $requestData = array(
            'getOutLinkInfoReq' => array(
                'account' => '',
                'linkID' => $shareId,
                'passwd' => '',
                'caSrt' => 1,
                'coSrt' => 1,
                'srtDr' => 0,
                'bNum' => 1,
                'pCaID' => 'root',
                'eNum' => 200,
            ),
            'commonAccountInfo' => array(
                'account' => '',
                'accountType' => 1,
            ),
        );

        // 将请求数据转换为JSON字符串
        $jsonStr = json_encode($requestData);

        // 加密请求数据
        $encryptedData = $this->encrypt($jsonStr);

        if ($encryptedData === null) {
            return array('error' => true, 'message' => '加密请求数据失败');
        }

        // 将加密后的数据包装为JSON字符串（关键：使用 JSON_UNESCAPED_SLASHES 与Go版本一致）
        $encryptedJSON = json_encode($encryptedData, JSON_UNESCAPED_SLASHES);

        // 创建HTTP请求
        $apiUrl = 'https://share-kd-njs.yun.139.com/yun-share/richlifeApp/devapp/IOutLink/getOutLinkInfoV6';

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encryptedJSON,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
                'hcy-cool-flag: 1',
                'x-deviceinfo: ||3|12.27.0|chrome|131.0.0.0|5c7c68368f048245e1ce47f1c0f8f2d0||windows 10|1536X695|zh-CN|||',
            ),
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            // 检查是否为超时错误
            if (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false) {
                return array('error' => true, 'message' => '请求超时');
            }
            return array('error' => true, 'message' => $error);
        }

        if ($httpCode !== 200) {
            return array('error' => true, 'message' => 'API返回错误状态码: ' . $httpCode . ', 响应: ' . $response);
        }

        // 解密响应数据
        $decryptedData = $this->decrypt($response);

        if ($decryptedData === null) {
            return array('error' => true, 'message' => '解密响应数据失败');
        }

        // 解析JSON响应
        $data = json_decode($decryptedData, true);

        if ($data === null) {
            return array('error' => true, 'message' => '解析JSON响应失败');
        }

        return array('error' => false, 'data' => $data);
    }

    /**
     * AES-CBC 加密
     */
    private function encrypt($data)
    {
        // 生成16字节的随机IV
        $iv = openssl_random_pseudo_bytes(16);

        // 添加PKCS7填充
        $blockSize = 16;
        $padding = $blockSize - (strlen($data) % $blockSize);
        $data .= str_repeat(chr($padding), $padding);

        // AES-CBC 加密
        $encrypted = openssl_encrypt($data, 'AES-128-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($encrypted === false) {
            return null;
        }

        // 将IV和密文连接，然后Base64编码
        $result = base64_encode($iv . $encrypted);

        return $result;
    }

    /**
     * AES-CBC 解密
     */
    private function decrypt($encryptedText)
    {
        // Base64解码
        $encryptedData = base64_decode($encryptedText);

        if ($encryptedData === false || strlen($encryptedData) < 16) {
            return null;
        }

        // 前16字节作为IV，后面的作为加密数据
        $iv = substr($encryptedData, 0, 16);
        $ciphertext = substr($encryptedData, 16);

        // 检查密文长度必须是块大小的倍数
        if (strlen($ciphertext) % 16 !== 0) {
            return null;
        }

        // AES-CBC 解密
        $decrypted = openssl_decrypt($ciphertext, 'AES-128-CBC', $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($decrypted === false) {
            return null;
        }

        // 去除PKCS7填充
        $paddingLength = ord($decrypted[strlen($decrypted) - 1]);
        if ($paddingLength > 0 && $paddingLength <= 16) {
            $decrypted = substr($decrypted, 0, -$paddingLength);
        }

        return $decrypted;
    }
}
