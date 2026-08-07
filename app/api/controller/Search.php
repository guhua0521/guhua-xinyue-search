<?php

namespace app\api\controller;

use app\api\QfShop;
use app\model\Source as SourceModel;
use app\model\SourceCategory as SourceCategoryModel;

class Search extends QfShop
{
    public function index()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getList(input(''));
        return jok('获取成功',$data);
    }
    
    public function getDetail()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getDetail(input(''));
        return jok('获取成功',$data);
    }
    
    /**
     * 增加资源浏览量
     * 用于前端点击资源列表时调用
     */
    public function incrementViews()
    {
        $id = input('id/d', 0);
        if (empty($id)) {
            return jerr('资源ID不能为空');
        }
        
        $SourceModel = new SourceModel();
        $result = $SourceModel->where('source_id', $id)->inc('page_views')->update();
        
        if ($result !== false) {
            return jok('浏览量增加成功');
        } else {
            return jerr('操作失败');
        }
    }
    
    public function getNew()
    {
        $SourceModel = new SourceModel();
        $data = input('');
        $data['page_size'] = $data['page_size']??20;
        $data = $SourceModel->getNew($data);
        return jok('获取成功',$data);
    }
    
    public function getHot()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getHot(input(''));
        return jok('获取成功',$data);
    }
    
    public function getCategory()
    {
        $SourceCategoryModel = new SourceCategoryModel();
        $data = $SourceCategoryModel->getList(input(''));
        return jok('获取成功',$data);
    }
}
