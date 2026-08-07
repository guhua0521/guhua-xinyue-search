<?php

namespace app\model;

use app\model\QfShop;

/**
 * 分站模型
 */
class Site extends QfShop
{
    protected $pk = 'site_id';
    // 时间字段由业务代码自行写入，关闭自动时间戳避免读取时格式转换
    protected $autoWriteTimestamp = false;
}
