<?php

namespace app\admin\controller;

use think\App;
use think\facade\Filesystem;
use app\admin\QfShop;
use app\model\Feedback as FeedbackModel;

class Feedback extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        $this->model = new FeedbackModel();
    }


    /**
     * 获取列表接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function getList()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //从请求中获取筛选数据的数组
        $map = $this->getDataFilterFromRequest();
        //从请求中获取排序方式
        $order = $this->getorderfromRequest();
        //设置Model中的 per_page
        $this->setGetListPerPage();
        //查询数据
        $dataList = $this->model->getListByPage($map, $order, $this->selectList);
        return jok('数据获取成功', $dataList);
    }

    /**
     * 获取统计
     *
     * @return void
     */
    public function getStats()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }

        // 获取当前时间戳
        $now = time();
        
        // 今日开始时间
        $todayStart = strtotime(date('Y-m-d 00:00:00', $now));
        
        // 本周开始时间（周一）
        $weekDay = date('w', $now);
        $weekDay = $weekDay == 0 ? 7 : $weekDay;
        $weekStart = strtotime(date('Y-m-d 00:00:00', $now - ($weekDay - 1) * 86400));
        
        // 本月开始时间
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));

        // 今日记录
        $todayCount = $this->model
            ->where('create_time', '>=', $todayStart)
            ->count();

        // 本周记录
        $weekCount = $this->model
            ->where('create_time', '>=', $weekStart)
            ->count();

        // 本月记录
        $monthCount = $this->model
            ->where('create_time', '>=', $monthStart)
            ->count();

        // 总记录
        $totalCount = $this->model->count();

        $data = [
            'today' => $todayCount,
            'week' => $weekCount,
            'month' => $monthCount,
            'total' => $totalCount,
        ];

        return jok('获取成功', $data);
    }

    /**
     * 清空所有数据
     *
     * @return void
     */
    public function clearAll()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }

        // 清空数据表
        $this->model->where('id', '>', 0)->delete();

        return jok('清空成功');
    }

    /**
     * 批量添加测试记录
     *
     * @return void
     */
    public function batchAdd()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $content = input('content', '[夸克] 哪吒之魔童闹海');
        $count = input('count', 100);
        $day = input('day', 'yesterday'); // yesterday 或 today

        // 限制数量
        $count = min(max((int)$count, 1), 1000);

        // 计算时间戳
        if ($day === 'yesterday') {
            $baseTime = strtotime('yesterday');
        } else {
            $baseTime = strtotime('today');
        }

        $data = [];
        for ($i = 0; $i < $count; $i++) {
            // 在一天内随机分布
            $randomOffset = mt_rand(0, 86399); // 0-24小时的秒数
            $createTime = $baseTime + $randomOffset;
            
            $data[] = [
                'content' => $content,
                'create_time' => $createTime,
                'update_time' => $createTime,
            ];
        }

        // 批量插入
        $this->model->insertAll($data);

        return jok('成功添加 ' . $count . ' 条记录');
    }
    
}
