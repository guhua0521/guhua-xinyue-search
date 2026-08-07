<?php

namespace app\model;

use app\model\QfShop;

/**
 * 分站配置模型
 */
class SiteConf extends QfShop
{
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    /**
     * 获取分站全部配置（键值对）
     *
     * @param int $siteId
     * @return array
     */
    public function getSiteConf($siteId)
    {
        $list = $this->where('site_id', $siteId)->select()->toArray();
        $conf = [];
        foreach ($list as $item) {
            $conf[$item['conf_key']] = $item['conf_value'];
        }
        return $conf;
    }

    /**
     * 保存分站配置（存在则更新，不存在则新增）
     *
     * @param int    $siteId
     * @param string $key
     * @param string $value
     * @return void
     */
    public function saveConf($siteId, $key, $value)
    {
        $row = $this->where('site_id', $siteId)->where('conf_key', $key)->find();
        $now = time();
        if ($row) {
            $this->where('id', $row['id'])->update([
                'conf_value' => $value,
                'update_time' => $now,
            ]);
        } else {
            $this->insert([
                'site_id' => $siteId,
                'conf_key' => $key,
                'conf_value' => $value,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
    }
}
