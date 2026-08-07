<?php

namespace app\admin\controller;

use think\App;
use app\admin\QfShop;
use app\model\Feedback as FeedbackModel;

/**
 * 搜索记录管理（真实数据，来自 qf_feedback 表）
 */
class Searchlog extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new FeedbackModel();
    }

    /**
     * 搜索记录列表
     *
     * @return void
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $map = [];
        if (input('keyword')) {
            $map[] = ['content', 'like', '%' . input('keyword') . '%'];
        }
        // 只显示搜索记录（内容带 [网盘类型] 前缀）
        $map[] = ['content', 'like', '[%'];

        $page = max(1, intval(input('page_no', 1)));
        $size = max(1, intval(input('page_size', 15)));
        $total = $this->model->where($map)->count();
        $list = $this->model->where($map)
            ->order('id desc')
            ->page($page, $size)
            ->select()
            ->toArray();
        foreach ($list as &$item) {
            $item['keyword'] = preg_replace('/^\s*\[[^\]]*\]\s*/', '', $item['content']);
            $item['pan_type'] = '';
            if (preg_match('/^\s*\[([^\]]*)\]/', $item['content'], $m)) {
                $item['pan_type'] = $m[1];
            }
            $ct = $item['create_time'];
            if (!is_numeric($ct)) {
                $ct = strtotime((string) $ct) ?: 0;
            }
            $item['create_time_text'] = $ct ? date('Y-m-d H:i:s', $ct) : '';
        }
        unset($item);
        return jok('获取成功', [
            'total' => $total,
            'per_page' => $size,
            'current_page' => $page,
            'last_page' => max(1, ceil($total / $size)),
            'data' => $list,
        ]);
    }

    /**
     * 删除搜索记录（支持批量）
     *
     * @return void
     */
    public function delete()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $ids = input('id');
        if (empty($ids)) {
            return jerr('请选择要删除的记录', 400);
        }
        $idList = array_filter(explode(',', $ids), 'is_numeric');
        if ($idList) {
            $this->model->where('id', 'in', $idList)->delete();
        }
        return jok('删除成功');
    }

    /**
     * 清空搜索记录
     *
     * @return void
     */
    public function clear()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $this->model->where('content', 'like', '[%')->delete();
        return jok('搜索记录已清空');
    }
}
