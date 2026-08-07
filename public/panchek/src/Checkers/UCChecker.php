<?php
/**
 * PanCheck PHP Version
 * UC网盘检测器
 * UC网盘没有公开的API，使用页面内容检测
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class UCChecker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('uc', 5, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享码
        $shareCode = $this->extractShareCode($link, '/drive\.uc\.cn\/s\/([a-zA-Z0-9]+)/');

        if (!$shareCode) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        // UC网盘分享页面
        $shareUrl = "https://drive.uc.cn/s/{$shareCode}";

        $response = $this->doRequest($shareUrl, array(
            'headers' => array(
                'Referer: https://drive.uc.cn/',
                'User-Agent: Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.101 Mobile Safari/537.36',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            if ($response['status'] === 404) {
                return CheckResult::failure('分享不存在或已失效', $duration);
            }
            // 超时或连接错误视为有效，避免误判
            return CheckResult::success($duration);
        }

        $body = strtolower($response['body']);

        // 检查失效关键词
        $invalidKeywords = array(
            '分享已删除', '分享不存在', '分享已失效',
            '分享已过期', '分享被取消', '内容违规',
            '文件已被删除', '资源已删除', '链接已失效',
            '分享已撤销', '分享已结束',
        );

        foreach ($invalidKeywords as $keyword) {
            if (strpos($body, strtolower($keyword)) !== false) {
                return CheckResult::failure('链接已失效: ' . $keyword, $duration);
            }
        }

        // 检查是否包含有效分享的特征
        $validKeywords = array(
            'file_list', 'share_list', 'uc-data',
            'file-name', 'file-size', '分享文件',
        );

        foreach ($validKeywords as $keyword) {
            if (strpos($body, strtolower($keyword)) !== false) {
                return CheckResult::success($duration);
            }
        }

        // 如果无法确定状态，采用保守策略返回失效
        return CheckResult::failure('无法确认分享状态', $duration);
    }
}
