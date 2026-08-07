<?php

declare(strict_types=1);

namespace app\index;

use think\App;
use EasyWeChat\Factory;
use app\model\Conf as ConfModel;
use app\model\User as UserModel;

/**
 * 控制器基础类
 */
abstract class QfShop
{
    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        $this->confModel = new ConfModel();

        $configs = $this->confModel->select()->toArray();
        $c = array_column($configs, 'conf_value', 'conf_key');
        config($c, 'qfshop');
        // 分站配置覆盖（分站继承主站全部功能，仅覆盖分站自己的基础配置）
        \app\service\SiteService::applySiteConfig();
    }
}
