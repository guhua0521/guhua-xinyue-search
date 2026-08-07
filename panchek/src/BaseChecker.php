<?php
/**
 * PanCheck PHP Version
 * 基础检测器
 */

namespace PanCheck;

abstract class BaseChecker implements LinkCheckerInterface
{
    /** @var string 平台类型 */
    protected $platform;

    /** @var int 并发限制 */
    protected $concurrencyLimit;

    /** @var int 超时时间（秒） */
    protected $timeout;

    /** @var float 上次请求时间 */
    protected $lastRequestTime = 0;

    /** @var int 请求延迟（毫秒） */
    protected $requestDelayMs = 0;

    /**
     * 构造函数
     */
    public function __construct($platform, $concurrencyLimit = 5, $timeout = 30)
    {
        $this->platform = $platform;
        $this->concurrencyLimit = $concurrencyLimit;
        $this->timeout = $timeout;
    }

    /**
     * 获取平台名称
     */
    public function getPlatform()
    {
        return $this->platform;
    }

    /**
     * 获取并发限制
     */
    public function getConcurrencyLimit()
    {
        return $this->concurrencyLimit;
    }

    /**
     * 获取超时时间
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * 设置请求延迟
     */
    public function setRequestDelay($delayMs)
    {
        $this->requestDelayMs = $delayMs;
    }

    /**
     * 应用限流
     */
    protected function applyRateLimit()
    {
        if ($this->requestDelayMs > 0) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            if ($elapsed < $this->requestDelayMs) {
                usleep(($this->requestDelayMs - $elapsed) * 1000);
            }
        }
        $this->lastRequestTime = microtime(true);
    }

    /**
     * 创建 HTTP 客户端 (curl)
     */
    protected function createHttpClient()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ));
        return $ch;
    }

    /**
     * 发送 HTTP 请求
     */
    protected function doRequest($url, $options = array())
    {
        $ch = $this->createHttpClient();
        curl_setopt($ch, CURLOPT_URL, $url);

        if (isset($options['method']) && $options['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (isset($options['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
            }
        }

        if (isset($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = intval((microtime(true) - $startTime) * 1000);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return array(
            'success' => $error === '' && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $response,
            'error' => $error,
            'duration' => $duration,
        );
    }

    /**
     * 检查是否包含失效关键词
     */
    protected function containsInvalidKeywords($text, $keywords)
    {
        $text = strtolower($text);
        foreach ($keywords as $keyword) {
            if (strpos($text, strtolower($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 提取分享码
     */
    protected function extractShareCode($link, $pattern)
    {
        if (preg_match($pattern, $link, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
