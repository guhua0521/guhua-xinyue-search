<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;

/**
 * 重置今日搜索统计命令
 * 每天 0点05分执行，将今日搜索数重置为0
 */
class ResetTodaySearch extends Command
{
    protected function configure()
    {
        $this->setName('reset:today-search')
            ->setDescription('重置今日搜索统计为0');
    }

    protected function execute(Input $input, Output $output)
    {
        $today = date('Y-m-d');
        $todayKey = 'search_stats:today:' . $today;
        
        // 获取当前今日搜索数
        $currentCount = Cache::store('file')->get($todayKey);
        
        // 重置为0（今日搜索从0开始）
        Cache::store('file')->set($todayKey, 0, 86400);
        
        $output->info("今日搜索统计已重置为0");
        $output->info("日期: {$today}");
        $output->info("重置前值: " . ($currentCount ?? '未设置'));
        
        return 0;
    }
}
