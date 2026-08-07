<?php
/**
 * PanCheck PHP Version
 * 夸克网盘检测器
 */

namespace PanCheck\Checkers;

use PanCheck\BaseChecker;
use PanCheck\CheckResult;

class QuarkChecker extends BaseChecker
{
    public function __construct()
    {
        parent::__construct('quark', 3, 30);
    }

    public function check($link)
    {
        $startTime = microtime(true);

        $this->applyRateLimit();

        // 提取分享码
        $shareCode = $this->extractShareCode($link, '/\/s\/([a-zA-Z0-9]+)/');
        if (!$shareCode) {
            return CheckResult::failure('无法提取分享码', 0);
        }

        // 调用夸克网盘 API 检测分享状态
        $apiUrl = "https://drive.quark.cn/1/clouddrive/share/sharepage/token?pr=ucpro&fr=pc&uc_param_str=&__dt=5000&__t=" . time();
        
        $postData = json_encode(array('pwd_id' => $shareCode, 'passcode' => ''));
        
        $response = $this->doRequest($apiUrl, array(
            'method' => 'POST',
            'body' => $postData,
            'headers' => array(
                'Content-Type: application/json',
                'Referer: https://pan.quark.cn/',
                'Origin: https://pan.quark.cn',
            ),
        ));

        $duration = intval((microtime(true) - $startTime) * 1000);

        if (!$response['success']) {
            // 如果 API 调用失败，尝试备用方法：检查页面 HTML
            return $this->checkByPage($link, $duration);
        }

        $data = json_decode($response['body'], true);

        // 检查 API 返回的错误码
        if (isset($data['code'])) {
            // code 0 表示成功
            if ($data['code'] === 0) {
                return CheckResult::success($duration);
            }
            
            // 各种错误码处理
            $errorMessages = array(
                40001 => '分享已失效',
                40002 => '分享不存在',
                40003 => '分享已过期',
                40004 => '分享已取消',
                40005 => '分享内容违规',
                40006 => '文件已被删除',
                40007 => '需要访问密码',
                50001 => '服务器内部错误',
                31001 => '分享已失效或不存在',
            );
            
            $message = isset($errorMessages[$data['code']]) 
                ? $errorMessages[$data['code']] 
                : '链接异常 (错误码: ' . $data['code'] . ')';
                
            return CheckResult::failure($message, $duration);
        }

        // 检查是否有 data 字段
        if (isset($data['data'])) {
            // 进一步检查 data 中的状态
            if (isset($data['data']['_share_ended'])) {
                return CheckResult::failure('分享已结束', $duration);
            }
            return CheckResult::success($duration);
        }

        // API 返回格式不正确，尝试备用方法
        return $this->checkByPage($link, $duration);
    }

    /**
     * 备用方法：通过页面 HTML 检测
     */
    private function checkByPage($link, $duration)
    {
        $shareCode = $this->extractShareCode($link, '/\/s\/([a-zA-Z0-9]+)/');
        $shareUrl = "https://pan.quark.cn/s/{$shareCode}";

        $response = $this->doRequest($shareUrl, array(
            'headers' => array(
                'Referer: https://pan.quark.cn/',
            ),
        ));

        if (!$response['success']) {
            if ($response['status'] === 404) {
                return CheckResult::failure('分享不存在或已失效', $duration);
            }
            return CheckResult::failure('网络请求失败', $duration);
        }

        $body = $response['body'];

        // 检查失效关键词
        $invalidKeywords = array(
            '分享已删除', '分享已失效', '分享不存在',
            '该分享已过期', '分享被取消', '内容违规',
            '文件已被分享者删除', '文件已删除',
            '分享已撤销', '资源已删除',
            '分享已结束', '链接已失效',
        );

        foreach ($invalidKeywords as $keyword) {
            if (strpos($body, $keyword) !== false) {
                return CheckResult::failure('链接已失效: ' . $keyword, $duration);
            }
        }

        // 如果无法确定状态，采用保守策略
        // 检查页面是否包含有效分享的特征
        if (strpos($body, '"code":0') !== false || 
            strpos($body, '"code": 0') !== false) {
            return CheckResult::success($duration);
        }

        // 默认返回失效（保守策略）
        return CheckResult::failure('无法确认分享状态', $duration);
    }
}
