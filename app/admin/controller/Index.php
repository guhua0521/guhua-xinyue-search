<?php

namespace app\admin\controller;

use app\admin\QfShop;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Db;
use util\Time;

class Index extends QfShop
{
    /**
     * 系统概况仪表盘数据（真实统计）
     *
     * @return void
     */
    public function getDashboard()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $now = time();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));

        // 搜索量（qf_feedback 记录）
        $todaySearch = Db::name('feedback')->where('create_time', '>=', $todayStart)->count();
        $totalSearch = Db::name('feedback')->count();

        // 资源统计
        $sourceTotal = Db::name('source')->where('status', 1)->where('is_delete', 0)->count();
        $sourceToday = Db::name('source')
            ->where('status', 1)
            ->where('is_delete', 0)
            ->where('create_time', '>=', $todayStart)
            ->count();

        // 近30天搜索趋势
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', $now - $i * 86400);
            $start = strtotime($day . ' 00:00:00');
            $end = strtotime($day . ' 23:59:59');
            $count = Db::name('feedback')
                ->where('create_time', '>=', $start)
                ->where('create_time', '<=', $end)
                ->count();
            $trend[] = ['date' => substr($day, 5), 'count' => $count];
        }

        // 搜索关键词 Top10（去掉 [网盘类型] 前缀）
        $topKeywords = Db::name('feedback')
            ->field('content, COUNT(*) as total')
            ->group('content')
            ->order('total desc')
            ->limit(10)
            ->select()->toArray();
        foreach ($topKeywords as &$kw) {
            $kw['keyword'] = preg_replace('/^\s*\[[^\]]*\]\s*/', '', $kw['content']);
            $kw['content'] = $kw['keyword'];
            unset($kw['keyword']);
        }
        unset($kw);

        // 资源分类分布
        $categoryDist = Db::name('source')
            ->alias('s')
            ->leftJoin('source_category c', 'c.source_category_id = s.source_category_id')
            ->field('IFNULL(c.name, "未分类") as name, COUNT(*) as value')
            ->where('s.status', 1)
            ->where('s.is_delete', 0)
            ->group('s.source_category_id')
            ->select()->toArray();

        // 网盘类型分布（资源自带网盘类型，随资源变化）
        $panTypeMap = [0 => '夸克网盘', 1 => '阿里云盘', 2 => '百度网盘', 3 => 'UC网盘', 4 => '迅雷云盘', 5 => '123云盘', 6 => '115网盘', 7 => '天翼云盘', 8 => '移动云盘', 9 => '磁力链接', 10 => '光鸭网盘'];
        $panTypeDist = Db::name('source')
            ->field('is_type, COUNT(*) as value')
            ->where('status', 1)
            ->where('is_delete', 0)
            ->group('is_type')
            ->select()->toArray();
        foreach ($panTypeDist as &$p) {
            $p['name'] = $panTypeMap[intval($p['is_type'])] ?? ('网盘类型' . $p['is_type']);
            unset($p['is_type']);
        }
        unset($p);

        // 系统信息
        $mysqlVersion = '';
        try {
            $mysqlVersion = Db::query('SELECT VERSION() as v')[0]['v'] ?? '';
        } catch (\Throwable $e) {
        }
        $diskTotal = @disk_total_space(root_path());
        $diskFree = @disk_free_space(root_path());
        $diskUsed = $diskTotal > 0 ? $diskTotal - $diskFree : 0;
        $serverIp = $_SERVER['SERVER_ADDR'] ?? (gethostbyname(gethostname()) ?: '');

        return jok('获取成功', [
            'today_search' => $todaySearch,
            'total_search' => $totalSearch,
            'source_total' => $sourceTotal,
            'source_today' => $sourceToday,
            'trend' => $trend,
            'top_keywords' => $topKeywords,
            'category_dist' => $categoryDist,
            'pan_type_dist' => $panTypeDist,
            'system' => [
                'php_version' => PHP_VERSION,
                'mysql_version' => $mysqlVersion,
                'thinkphp_version' => \think\facade\App::version(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '',
                'domain' => request()->host(),
                'os' => PHP_OS . ' ' . (function_exists('php_uname') ? php_uname('r') : ''),
                'ip' => $serverIp,
                'disk_used' => $diskUsed,
                'disk_total' => $diskTotal,
                'disk_text' => $diskTotal > 0 ? formatBytes($diskUsed) . ' / ' . formatBytes($diskTotal) : '',
                'upload_max' => ini_get('upload_max_filesize'),
                'memory_limit' => ini_get('memory_limit'),
                'server_time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
