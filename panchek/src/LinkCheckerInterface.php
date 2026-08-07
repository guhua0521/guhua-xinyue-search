<?php
/**
 * PanCheck PHP Version
 * 链接检测器接口
 */

namespace PanCheck;

interface LinkCheckerInterface
{
    /**
     * 检测链接是否有效
     */
    public function check($link);

    /**
     * 获取平台名称
     */
    public function getPlatform();

    /**
     * 获取并发限制
     */
    public function getConcurrencyLimit();
}
