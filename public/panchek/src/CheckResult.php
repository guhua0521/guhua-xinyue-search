<?php
/**
 * PanCheck PHP Version
 * 检测结果类
 */

namespace PanCheck;

class CheckResult
{
    /** @var bool 链接是否有效 */
    public $valid;

    /** @var string 失效原因 */
    public $failureReason;

    /** @var int 检测耗时（毫秒） */
    public $duration;

    /** @var bool 是否被限流 */
    public $isRateLimited;

    /**
     * 构造函数
     */
    public function __construct($valid, $failureReason = '', $duration = 0, $isRateLimited = false)
    {
        $this->valid = $valid;
        $this->failureReason = $failureReason;
        $this->duration = $duration;
        $this->isRateLimited = $isRateLimited;
    }

    /**
     * 创建成功的结果
     */
    public static function success($duration = 0)
    {
        return new self(true, '', $duration, false);
    }

    /**
     * 创建失败的结果
     */
    public static function failure($reason, $duration = 0, $isRateLimited = false)
    {
        return new self(false, $reason, $duration, $isRateLimited);
    }
}
