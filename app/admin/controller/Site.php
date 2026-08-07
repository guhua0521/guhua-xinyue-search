<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use app\admin\QfShop;
use app\model\Site as SiteModel;
use app\service\SiteService;

/**
 * 分站管理（仅主站超级管理员可用）
 *
 * 一键开通分站：自动创建分站、复制基础配置、生成分站管理员账号
 */
class Site extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new SiteModel();
    }

    /**
     * 分站列表
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
        $filter = input('post.');
        if (!empty($filter['site_name'])) {
            $map[] = ['site_name', 'like', '%' . $filter['site_name'] . '%'];
        }
        if (!empty($filter['site_domain'])) {
            $map[] = ['site_domain', 'like', '%' . $filter['site_domain'] . '%'];
        }
        if (isset($filter['site_status']) && $filter['site_status'] !== '') {
            $map[] = ['site_status', '=', $filter['site_status']];
        }
        $order = 'site_id desc';
        if (input('order')) {
            $order = urldecode(input('order'));
        }
        if (input('per_page')) {
            $this->model->per_page = intval(input('per_page'));
        }
        $dataList = $this->model->getListByPage($map, $order, '*');
        // 附带管理员账号与访问地址
        $items = $dataList->toArray();
        foreach ($items['data'] as &$row) {
            $row = $this->decorateSite($row);
        }
        unset($row);
        return jok('数据获取成功', $items);
    }

    /**
     * 分站详情
     *
     * @return void
     */
    public function detail()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr('site_id参数必须填写', 400);
        }
        $item = $this->model->where('site_id', $this->pk_value)->find();
        if (empty($item)) {
            return jerr('分站不存在', 404);
        }
        return jok('数据加载成功', $this->decorateSite($item->toArray()));
    }

    /**
     * 一键开通分站
     *
     * @return void
     */
    public function add()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $result = SiteService::createSite(input('post.'), $errMsg);
        if (!$result) {
            return jerr($errMsg ?? '开通失败', 400);
        }
        // 自动为该分站域名生成 Nginx 配置（用户只需做 DNS 解析）
        $result['nginx'] = $this->syncNginx($result['site_domain'], 'add');
        return jok('分站开通成功', $result);
    }

    /**
     * 修改分站（名称/域名/标识/到期时间/状态/备注/重置密码）
     *
     * @return void
     */
    public function update()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value || !is_numeric($this->pk_value)) {
            return jerr('site_id参数必须填写', 400);
        }
        $site = $this->model->where('site_id', $this->pk_value)->find();
        if (empty($site)) {
            return jerr('分站不存在', 404);
        }
        $siteId = intval($this->pk_value);
        $data = [];
        $oldDomain = (string) $site['site_domain'];

        if (input('site_name') !== null) {
            $name = trim(input('site_name'));
            if (empty($name)) {
                return jerr('分站名称不能为空', 400);
            }
            $data['site_name'] = $name;
        }
        if (input('site_domain') !== null) {
            $domain = SiteService::normalizeDomain(input('site_domain'));
            if (empty($domain)) {
                return jerr('绑定域名不能为空', 400);
            }
            $exists = Db::name('site')->where('site_domain', $domain)->where('site_id', '<>', $siteId)->find();
            if ($exists) {
                return jerr('该域名已被其他分站绑定', 400);
            }
            $data['site_domain'] = $domain;
        }
        if (input('site_key') !== null) {
            $key = strtolower(preg_replace('/[^a-z0-9\-]/i', '', input('site_key')));
            if (strlen($key) < 3) {
                return jerr('分站标识至少3位（字母或数字）', 400);
            }
            $exists = Db::name('site')->where('site_key', $key)->where('site_id', '<>', $siteId)->find();
            if ($exists) {
                return jerr('该分站标识已存在', 400);
            }
            $data['site_key'] = $key;
        }
        if (input('?site_expire')) {
            $data['site_expire'] = intval(input('site_expire'));
        }
        if (input('?site_status')) {
            $data['site_status'] = intval(input('site_status'));
        }
        if (input('site_remark') !== null) {
            $data['site_remark'] = trim(input('site_remark'));
        }
        if (!empty($data)) {
            $data['update_time'] = time();
            $this->model->where('site_id', $siteId)->update($data);
            SiteService::clearSiteCache($site->toArray());
        }

        // 域名变更时同步 Nginx 配置
        $newDomain = $data['site_domain'] ?? $oldDomain;
        $nginxResults = [];
        if ($newDomain !== $oldDomain) {
            $nginxResults[] = $this->syncNginx($oldDomain, 'remove');
        }
        if (!empty($newDomain)) {
            $nginxResults[] = $this->syncNginx($newDomain, 'add');
        }
        $nginxFail = false;
        foreach ($nginxResults as $r) {
            if (empty($r['ok'])) {
                $nginxFail = true;
            }
        }

        // 重置密码
        if (!empty(input('new_password'))) {
            $password = input('new_password');
            if (strlen($password) < 6 || strlen($password) > 16) {
                return jerr('新密码长度需为6-16位', 400);
            }
            $salt = getRandString(4);
            Db::name('admin')->where('site_id', $siteId)->update([
                'admin_password' => encodePassword($password, $salt),
                'admin_salt' => $salt,
                'admin_updatetime' => time(),
            ]);
        }

        return $nginxFail ? jok('分站信息修改成功（Nginx配置更新失败，请手动处理）') : jok('分站信息修改成功');
    }

    /**
     * 禁用分站
     *
     * @return void
     */
    public function disable()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr('site_id参数必须填写', 400);
        }
        if (is_numeric($this->pk_value)) {
            $site = $this->model->where('site_id', $this->pk_value)->find();
            $this->model->where('site_id', $this->pk_value)->update([
                'site_status' => 1,
                'update_time' => time(),
            ]);
            SiteService::clearSiteCache($site ? $site->toArray() : null);
        } else {
            $list = explode(',', $this->pk_value);
            $sites = $this->model->where('site_id', 'in', $list)->select();
            $this->model->where('site_id', 'in', $list)->update([
                'site_status' => 1,
                'update_time' => time(),
            ]);
            foreach ($sites as $site) {
                SiteService::clearSiteCache($site->toArray());
            }
        }
        return jok('分站已禁用');
    }

    /**
     * 启用分站
     *
     * @return void
     */
    public function enable()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr('site_id参数必须填写', 400);
        }
        if (is_numeric($this->pk_value)) {
            $site = $this->model->where('site_id', $this->pk_value)->find();
            $this->model->where('site_id', $this->pk_value)->update([
                'site_status' => 0,
                'update_time' => time(),
            ]);
            SiteService::clearSiteCache($site ? $site->toArray() : null);
        } else {
            $list = explode(',', $this->pk_value);
            $sites = $this->model->where('site_id', 'in', $list)->select();
            $this->model->where('site_id', 'in', $list)->update([
                'site_status' => 0,
                'update_time' => time(),
            ]);
            foreach ($sites as $site) {
                SiteService::clearSiteCache($site->toArray());
            }
        }
        return jok('分站已启用');
    }

    /**
     * 删除分站（同时删除分站配置与分站管理员）
     *
     * @return void
     */
    public function delete()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr('site_id参数必须填写', 400);
        }
        if (is_numeric($this->pk_value)) {
            $site = $this->model->where('site_id', $this->pk_value)->find();
            $domains = $site ? [(string) $site['site_domain']] : [];
            SiteService::deleteSite(intval($this->pk_value));
        } else {
            $list = explode(',', $this->pk_value);
            $sites = $this->model->where('site_id', 'in', $list)->select();
            $domains = [];
            foreach ($sites as $s) {
                $domains[] = (string) $s['site_domain'];
            }
            foreach ($list as $id) {
                if (is_numeric($id)) {
                    SiteService::deleteSite(intval($id));
                }
            }
        }
        // 删除分站时同步移除该域名的 Nginx 配置
        foreach ($domains as $domain) {
            if (!empty($domain)) {
                $this->syncNginx($domain, 'remove');
            }
        }
        return jok('分站已删除');
    }

    /**
     * 同步分站域名的 Nginx 配置（自动生成/移除）
     *
     * @param string $domain
     * @param string $action add|remove
     * @return array
     */
    protected function syncNginx($domain, $action)
    {
        if (empty($domain)) {
            return ['ok' => false, 'message' => '无域名'];
        }
        $cmd = 'sudo -n /usr/local/bin/update-subsite-nginx.sh ' . escapeshellarg($action) . ' ' . escapeshellarg($domain) . ' 2>&1';
        exec($cmd, $out, $code);
        $message = trim(implode("\n", $out));
        return [
            'ok' => $code === 0,
            'message' => $message !== '' ? $message : ($code === 0 ? 'OK' : '执行失败'),
        ];
    }

    /**
     * 补充分站展示信息（管理员账号、访问地址）
     *
     * @param array $row
     * @return array
     */
    protected function decorateSite(array $row)
    {
        $admin = Db::name('admin')->where('site_id', $row['site_id'])->find();
        $row['admin_account'] = $admin['admin_account'] ?? '';
        $row['site_url'] = 'http://' . $row['site_domain'];
        $row['admin_url'] = 'http://' . $row['site_domain'] . '/qfadmin/admin/login';
        // 到期展示状态
        if (intval($row['site_status']) === 0 && $row['site_expire'] > 0 && $row['site_expire'] < time()) {
            $row['site_status'] = 2;
        }
        $expire = $row['site_expire'];
        if (!is_numeric($expire)) {
            $expire = strtotime((string) $expire) ?: 0;
        }
        $create = $row['create_time'];
        if (!is_numeric($create)) {
            $create = strtotime((string) $create) ?: 0;
        }
        $row['site_expire_text'] = $expire ? date('Y-m-d', $expire) : '永久';
        $row['create_time_text'] = $create ? date('Y-m-d H:i', $create) : '';
        return $row;
    }
}
