<?php
/**
 * PanCheck PHP Version
 * 检测器工厂
 */

namespace PanCheck;

use PanCheck\Checkers\BaiduChecker;
use PanCheck\Checkers\AliyunChecker;
use PanCheck\Checkers\QuarkChecker;
use PanCheck\Checkers\Pan115Checker;
use PanCheck\Checkers\Pan123Checker;
use PanCheck\Checkers\UCChecker;
use PanCheck\Checkers\TianyiChecker;
use PanCheck\Checkers\XunleiChecker;
use PanCheck\Checkers\CMCCChecker;
use PanCheck\Checkers\GuangyaChecker;

class CheckerFactory
{
    /** @var LinkCheckerInterface[] */
    private $checkers = array();

    public function __construct()
    {
        $this->registerDefaultCheckers();
    }

    /**
     * 注册默认检测器
     */
    private function registerDefaultCheckers()
    {
        $this->register(new BaiduChecker());
        $this->register(new AliyunChecker());
        $this->register(new QuarkChecker());
        $this->register(new Pan115Checker());
        $this->register(new Pan123Checker());
        $this->register(new UCChecker());
        $this->register(new TianyiChecker());
        $this->register(new XunleiChecker());
        $this->register(new CMCCChecker());
        $this->register(new GuangyaChecker());
    }

    /**
     * 注册检测器
     */
    public function register(LinkCheckerInterface $checker)
    {
        $this->checkers[$checker->getPlatform()] = $checker;
    }

    /**
     * 获取检测器
     */
    public function getChecker($platform)
    {
        return isset($this->checkers[$platform]) ? $this->checkers[$platform] : null;
    }

    /**
     * 检测链接
     */
    public function check($platform, $link)
    {
        $checker = $this->getChecker($platform);
        if (!$checker) {
            return array(
                'success' => false,
                'error' => '不支持的网盘平台: ' . $platform,
            );
        }

        $result = $checker->check($link);
        return array(
            'success' => true,
            'platform' => $platform,
            'valid' => $result->valid,
            'failureReason' => $result->failureReason,
            'duration' => $result->duration,
            'isRateLimited' => $result->isRateLimited,
        );
    }

    /**
     * 自动识别平台并检测
     */
    public function autoCheck($link)
    {
        $platform = $this->detectPlatform($link);
        if (!$platform) {
            return array(
                'success' => false,
                'error' => '无法识别网盘平台',
            );
        }

        $checker = $this->getChecker($platform);
        if (!$checker) {
            return array(
                'success' => false,
                'error' => '该平台检测器不可用: ' . $platform,
            );
        }

        $result = $checker->check($link);
        return array(
            'success' => true,
            'platform' => $platform,
            'valid' => $result->valid,
            'failureReason' => $result->failureReason,
            'duration' => $result->duration,
            'isRateLimited' => $result->isRateLimited,
        );
    }

    /**
     * 识别网盘平台
     */
    public function detectPlatform($link)
    {
        $patterns = array(
            'baidu' => '/pan\.baidu\.com/',
            'aliyun' => '/(aliyundrive|alipan)\.com/',
            'quark' => '/pan\.quark\.cn/',
            'pan115' => '/115cdn\.com/',
            'pan123' => '/(123pan|123684|123685|123912|123592|123865)\.com/',
            'uc' => '/drive\.uc\.cn/',
            'tianyi' => '/cloud\.189\.cn/',
            'xunlei' => '/pan\.xunlei\.com/',
            'cmcc' => '/(yun\.139\.com|caiyun\.139\.com)/',
            'guangya' => '/guangyapan\.com/',
        );

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $link)) {
                return $platform;
            }
        }

        return null;
    }

    /**
     * 获取所有支持的平台
     */
    public function getSupportedPlatforms()
    {
        return array_keys($this->checkers);
    }
}
