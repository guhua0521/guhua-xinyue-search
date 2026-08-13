<?php

declare(strict_types=1);

namespace app\qfadmin;

use think\App;
use think\facade\View;

use app\model\Admin as AdminModel;
use app\model\Access as AccessModel;
use app\model\Auth as AuthModel;
use app\model\Node as NodeModel;
use app\model\Group as GroupModel;
use app\model\Conf as ConfModel;

/**
 * 控制器基础类
 */
abstract class QfShop
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;
    protected $module;
    protected $controller;
    protected $action;

    //模型
    protected $AdminModel;
    protected $accessModel;
    protected $authModel;
    protected $nodeModel;
    protected $groupModel;
    protected $confModel;

    //主键key
    protected $pk = '';
    //表名称
    protected $table = '';
    //主键value
    protected $pk_value = '';
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
        $this->module = "qfadmin";
        $this->controller = $this->request->controller() ? $this->request->controller() : "Index";
        $this->action = strtolower($this->request->action()) ? strtolower($this->request->action()) : "index";
        View::assign('controller', strtolower($this->controller));
        View::assign('action', strtolower($this->action));

        $this->table = strtolower($this->controller);
        $this->pk = $this->table . "_id";
        $this->pk_value = input($this->pk);

        $this->adminModel = new AdminModel();
        $this->accessModel = new AccessModel();
        $this->authModel = new AuthModel();
        $this->nodeModel = new NodeModel();
        $this->groupModel = new GroupModel();
        $this->confModel = new ConfModel();


        $configs = $this->confModel->select()->toArray();
        $c = [];
        foreach ($configs as $config) {
            $c[$config['conf_key']] = $config['conf_value'];
        }
        config($c, 'qfshop');
    }
    /**
     * 后台简单的身份判断
     *
     * @return void
     */
    protected function access()
    {
        $callback = "/qfadmin";
        if (strtolower($this->controller) != "index") {
            $callback .= "/" . strtolower($this->controller);
        }
        if ($this->action != "index") {
            $callback .= "/" . $this->action;
        }
        // 按当前访问站点上下文读取对应后台登录Cookie（主站/分站互不冲突）
        $site = \app\service\SiteService::detectSite();
        $siteKey = $site ? (string) $site['site_key'] : '';
        $cookieName = \app\service\SiteService::adminCookieName($siteKey);
        $access_token = cookie($cookieName);
        if (!$access_token) {
            return redirect('/qfadmin/admin/login/?callback=' . urlencode($callback));
        }
        View::assign("access_token", $access_token);
        $this->admin = $this->adminModel->getAdminByAccessToken($access_token);
        if (!$this->admin) {
            return redirect('/qfadmin/admin/login/?callback=' . urlencode($callback));
        }
        // 分站管理员必须从自己的分站后台访问
        $expectedSiteId = $site ? intval($site['site_id']) : 0;
        if (intval($this->admin['site_id']) !== $expectedSiteId) {
            cookie($cookieName, null);
            return redirect('/qfadmin/admin/login/?callback=' . urlencode($callback));
        }
        if ($this->admin['admin_status']  > 0) {
            return $this->error("抱歉，你的帐号已被禁用，暂时无法登录系统！");
        }
        cookie($cookieName, $access_token);
        View::assign('adminInfo', $this->admin);
        View::assign('adminCookieName', $cookieName);
        View::assign('siteContextName', $site ? $site['site_name'] : '');
        $this->group = $this->groupModel->where('group_id', $this->admin['admin_group'])->find();
        if ($this->group) {
            if ($this->group['group_id'] != 1 && $this->group['group_status'] == 1) {
                return $this->error("抱歉，你所在的用户组已被禁用，暂时无法登录系统");
            } else {
                $menuList = $this->authModel->getAdminMenuListByAdminId($this->group['group_id']);
                View::assign('menuList', $menuList);

                $node = $this->nodeModel->where(['node_module' => $this->module, 'node_controller' => strtolower($this->controller), 'node_action' => $this->action])->find();
                // 非菜单页面（如修改密码/修改资料）没有对应节点，给个空节点避免模板报错
                if (!$node) {
                    $node = ['node_id' => 0, 'node_title' => '', 'node_pid' => 0];
                }
                View::assign('node', $node);

                $nodePid = $node['node_pid'] ?? 0;
                if($node && $node['node_pid']==0){
                    View::assign('menu', 0);
                }else{
                    $res = $nodePid ? $this->nodeModel->where('node_id',$nodePid)->find() : null;
                    View::assign('menu', $res['node_pid'] ?? 0);
                }
                $menuLists = [];
                foreach ($menuList as $key => $value) {
                    if($value['node_id'] == $nodePid){
                        $menuLists = $value['subList'] ?? [];
                    }else{
                        foreach (($value['subList'] ?? []) as $k => $v) {
                            if($v['node_id'] == $nodePid){
                                $menuLists = $value['subList'] ?? [];
                            }
                        }
                    }
                }
                View::assign('menuLists', $menuLists);
                View::assign('action', $this->request->action());
            }
        } else {
            return $this->error("抱歉，没有查到你的用户组信息，暂时无法登录系统");
        }
    }
    protected function error($message)
    {
        echo $message;
        die;
    }
}
