<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use app\model\Site as SiteModel;
use app\model\SiteConf as SiteConfModel;

/**
 * 分站核心服务
 *
 * 负责：分站识别（域名/标识）、分站配置加载与合并、分站权限判断
 */
class SiteService
{
    /**
     * 分站可编辑配置白名单（基础功能）
     * 分站管理员只能修改以下配置，无法触碰接口、转存等高级配置
     */
    public static $editableKeys = [
        // 基础信息
        'app_name',          // 网站名称
        'app_subname',       // 网站宣传语
        'logo',              // 网站LOGO
        'app_icon',          // 网站icon
        'app_links',         // 顶部其他外链
        'qcode',             // 群二维码
        'footer_dec',        // 底部介绍
        'footer_copyright',  // 底部版权
        // 联系我们
        'contact_qq',        // 联系QQ
        'contact_wechat',    // 联系微信
        'contact_qqgroup',   // 联系QQ群
        'contact_phone',     // 联系电话
        'contact_desc',      // 联系说明
        // 广告位
        'ad_home_top',
        'ad_home_bottom',
        'ad_list_top',
        'ad_list_bottom',
        'ad_detail_top',
        'ad_detail_bottom',
        'ad_footer',
        // 进站弹窗广告
        'ad_popup_enable',
        'ad_popup_title',
        'ad_popup_image',
        'ad_popup_content',
        'ad_popup_link',
        'ad_popup_btn',
        // 前端样式
        'home_bg',
        'home_background',
        'home_color',
        'home_theme',
        'other_background',
        'home_css',
        // SEO
        'app_title',
        'app_keywords',
        'app_description',
        'seo_statistics',
        // 搜索提示
        'search_tips',
        'search_bg',
    ];

    /**
     * 分站管理员后台允许访问的 API（控制器 => 允许的方法）
     * 其余接口一律拒绝，确保分站无法修改接口配置
     *
     * @return array
     */
    public static function siteAdminAllowRoutes()
    {
        return [
            'conf'    => ['getbaseconfig', 'updatebaseconfig'],
            'attach'  => ['uploadimage', 'uploadfile'],
            'admin'   => ['getmyinfo', 'updatemyinfo', 'motifypassword', 'logout'],
            'system'  => ['getcaptcha', 'clean'],
            'index'   => ['getdashboard'],
        ];
    }

    /**
     * 是否为超级管理员（主站管理员）
     *
     * @param array|null $admin
     * @return bool
     */
    public static function isSuperAdmin($admin = null)
    {
        if (empty($admin) || !isset($admin['site_id'])) {
            return true;
        }
        return intval($admin['site_id']) === 0;
    }

    /**
     * 根据域名获取分站
     *
     * @param string $domain
     * @return array|null
     */
    public static function getSiteByDomain($domain)
    {
        if (empty($domain)) {
            return null;
        }
        $domain = self::normalizeDomain($domain);
        if (empty($domain)) {
            return null;
        }
        $cacheKey = 'site_domain_' . md5($domain);
        $site = Cache::get($cacheKey);
        if ($site === false || $site === null) {
            $site = Db::name('site')->where('site_domain', $domain)->find();
            if ($site) {
                Cache::set($cacheKey, $site, 300);
            } else {
                Cache::set($cacheKey, 0, 60);
            }
        }
        return $site ?: null;
    }

    /**
     * 根据分站标识获取分站
     *
     * @param string $key
     * @return array|null
     */
    public static function getSiteByKey($key)
    {
        if (empty($key)) {
            return null;
        }
        $cacheKey = 'site_key_' . md5($key);
        $site = Cache::get($cacheKey);
        if ($site === false || $site === null) {
            $site = Db::name('site')->where('site_key', $key)->find();
            if ($site) {
                Cache::set($cacheKey, $site, 300);
            } else {
                Cache::set($cacheKey, 0, 60);
            }
        }
        return $site ?: null;
    }

    /**
     * 识别当前访问的分站
     *
     * 优先级：请求头 X-Site-Key > URL参数 site > Cookie site_key > 绑定域名
     *
     * @return array|null
     */
    public static function detectSite()
    {
        $request = request();

        // 特殊值：site=main / site=clear 表示访问主站，并清除分站Cookie
        $siteParam = $request->param('site', '');
        if (in_array($siteParam, ['main', 'clear'])) {
            cookie('site_key', null);
            return null;
        }

        // 1. 请求头（API 调用方主动指定）
        $siteKey = $request->header('x-site-key', '');
        // 2. URL 参数（本地调试：?site=xxx）
        if (empty($siteKey)) {
            $siteKey = $siteParam;
        }
        // 3. Cookie（设置后同浏览器一直有效）
        if (empty($siteKey)) {
            $siteKey = $request->cookie('site_key', '');
        }
        if (!empty($siteKey)) {
            $site = self::getSiteByKey($siteKey);
            if ($site) {
                // 参数方式访问时写入Cookie，方便后续请求延续
                if ($request->param('site') && !$request->cookie('site_key')) {
                    cookie('site_key', $siteKey, 86400 * 7);
                }
                return $site;
            }
        }

        // 4. 域名绑定
        $site = self::getSiteByDomain($request->host());
        return $site ?: null;
    }

    /**
     * 后台登录Cookie名称
     *
     * 主站固定使用 access_token；分站使用 access_token_分站标识，
     * 保证同一浏览器内多个后台可以同时登录、互不冲突
     *
     * @param string|array|null $siteKey 分站标识或分站记录
     * @return string
     */
    public static function adminCookieName($siteKey = '')
    {
        if (is_array($siteKey)) {
            $siteKey = $siteKey['site_key'] ?? '';
        }
        $siteKey = trim((string) $siteKey);
        if ($siteKey === '') {
            return 'access_token';
        }
        return 'access_token_' . $siteKey;
    }

    /**
     * 加载分站配置并合并到全局配置（优先级：分站配置 > 主站配置）
     *
     * 需要在主站配置 config($c, 'qfshop') 之后调用
     *
     * @return array|null 当前分站
     */
    public static function applySiteConfig()
    {
        $site = self::detectSite();
        if (empty($site)) {
            return null;
        }

        // 到期自动视为禁用
        if ($site['site_expire'] > 0 && $site['site_expire'] < time()) {
            $site['site_status'] = 2;
        }
        config($site, 'site');
        if (intval($site['site_status']) !== 0) {
            return $site;
        }

        $siteConf = (new SiteConfModel())->getSiteConf($site['site_id']);
        $base = config('qfshop') ?: [];
        foreach ($siteConf as $key => $value) {
            $base[$key] = $value;
        }
        config($base, 'qfshop');
        return $site;
    }

    /**
     * 当前访问站点是否可用
     *
     * @return bool true=可用（主站或正常分站）
     */
    public static function siteAvailable()
    {
        $site = config('site');
        if (empty($site)) {
            return true;
        }
        return intval($site['site_status']) === 0;
    }

    /**
     * 一键开通分站
     *
     * @param array $data 开通参数
     * @param string|null $error 错误信息
     * @return array|false 开通结果
     */
    public static function createSite(array $data, &$error = null)
    {
        $siteName = trim($data['site_name'] ?? '');
        $siteDomain = trim($data['site_domain'] ?? '');
        if (empty($siteName)) {
            $error = '分站名称必须填写';
            return false;
        }
        if (empty($siteDomain)) {
            $error = '绑定域名必须填写';
            return false;
        }
        $siteDomain = self::normalizeDomain($siteDomain);
        if (empty($siteDomain) || !preg_match('/^[a-z0-9\-\.:]+$/i', $siteDomain)) {
            $error = '绑定域名格式不正确';
            return false;
        }

        $siteKey = trim($data['site_key'] ?? '');
        if (empty($siteKey)) {
            $siteKey = self::genSiteKey();
        }
        $siteKey = strtolower(preg_replace('/[^a-z0-9\-]/i', '', $siteKey));
        if (strlen($siteKey) < 3) {
            $error = '分站标识至少3位（字母或数字）';
            return false;
        }

        // 唯一性校验
        if (Db::name('site')->where('site_domain', $siteDomain)->find()) {
            $error = '该域名已被绑定';
            return false;
        }
        if (Db::name('site')->where('site_key', $siteKey)->find()) {
            $error = '该分站标识已存在';
            return false;
        }

        // 管理员账号
        $account = trim($data['admin_account'] ?? '');
        if (empty($account)) {
            $account = 'site_' . $siteKey;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $account)) {
            $error = '管理员账号格式不正确（3-32位字母数字下划线）';
            return false;
        }
        if (Db::name('admin')->where('admin_account', $account)->find()) {
            $error = '管理员账号已存在';
            return false;
        }

        $password = $data['admin_password'] ?? '';
        if (empty($password)) {
            $password = get_randstr(10);
        }
        if (strlen($password) < 6 || strlen($password) > 16) {
            $error = '管理员密码长度需为6-16位';
            return false;
        }

        $now = time();
        $ip = '127.0.0.1';
        try {
            $ip = request()->ip();
        } catch (\Throwable $e) {
            // 命令行环境下无请求对象
        }
        $siteId = Db::name('site')->insertGetId([
            'site_name' => $siteName,
            'site_domain' => $siteDomain,
            'site_key' => $siteKey,
            'site_status' => isset($data['site_status']) ? intval($data['site_status']) : 0,
            'site_expire' => intval($data['site_expire'] ?? 0),
            'site_remark' => trim($data['site_remark'] ?? ''),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        // 复制主站默认配置到分站（仅白名单内配置）
        self::copyDefaultConf($siteId);

        // 创建分站管理员
        $salt = getRandString(4);
        Db::name('admin')->insert([
            'admin_account' => $account,
            'admin_password' => encodePassword($password, $salt),
            'admin_salt' => $salt,
            'admin_name' => $siteName . '管理员',
            'admin_idcard' => '',
            'admin_truename' => '',
            'admin_email' => '',
            'admin_money' => 0,
            'admin_group' => 2,
            'site_id' => $siteId,
            'admin_ipreg' => $ip,
            'admin_status' => 0,
            'admin_createtime' => $now,
            'admin_updatetime' => $now,
        ]);

        return [
            'site_id' => $siteId,
            'site_name' => $siteName,
            'site_domain' => $siteDomain,
            'site_key' => $siteKey,
            'admin_account' => $account,
            'admin_password' => $password,
        ];
    }

    /**
     * 将主站白名单配置复制为分站初始配置
     *
     * @param int $siteId
     * @return void
     */
    public static function copyDefaultConf($siteId)
    {
        $keys = self::$editableKeys;
        $mainConf = Db::name('conf')->whereIn('conf_key', $keys)->column('conf_value', 'conf_key');
        $siteConfModel = new SiteConfModel();
        foreach ($keys as $key) {
            // logo / 网站icon / 未搜索提示图 / 大图背景 分站默认留空，由分站管理员自行设置，不继承主站
            if (in_array($key, ['logo', 'app_icon', 'search_bg', 'home_bg'])) {
                $mainConf[$key] = '';
            }
            $siteConfModel->saveConf($siteId, $key, $mainConf[$key] ?? '');
        }
    }

    /**
     * 删除分站（连同配置与管理员）
     *
     * @param int $siteId
     * @return void
     */
    public static function deleteSite($siteId)
    {
        Db::name('site_conf')->where('site_id', $siteId)->delete();
        Db::name('admin')->where('site_id', $siteId)->delete();
        Db::name('site')->where('site_id', $siteId)->delete();
        Cache::clear();
    }

    /**
     * 清除分站识别缓存（禁用/启用/修改后立即生效）
     *
     * @param array $site 分站记录
     * @return void
     */
    public static function clearSiteCache($site)
    {
        if (empty($site)) {
            return;
        }
        Cache::delete('site_domain_' . md5(self::normalizeDomain($site['site_domain'])));
        if (!empty($site['site_key'])) {
            Cache::delete('site_key_' . md5($site['site_key']));
        }
    }

    /**
     * 域名标准化：去除协议、路径、www，转小写
     *
     * @param string $domain
     * @return string
     */
    public static function normalizeDomain($domain)
    {
        $domain = trim((string) $domain);
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('~[/\?#].*$~', '', $domain);
        $domain = strtolower($domain);
        $domain = preg_replace('/^www\./', '', $domain);
        return $domain;
    }

    /**
     * 生成分站标识
     *
     * @return string
     */
    protected static function genSiteKey()
    {
        $prefix = 's' . substr((string) time(), -5);
        $key = $prefix . get_randstr(4);
        if (Db::name('site')->where('site_key', $key)->find()) {
            return self::genSiteKey();
        }
        return $key;
    }
}
