<?php
namespace netdisk\pan;

class MagnetPan extends BasePan
{
    public function __construct($config = [])
    {
        parent::__construct($config);
    }

    public function getFiles($pdir_fid = 0)
    {
        return jerr2('磁力链接不支持文件列表获取');
    }

    public function transfer($share_id)
    {
        if(empty($this->url)){
            return jerr2('请提供磁力链接');
        }

        if($this->isType == 1){
            $urls['title'] = $this->getMagnetTitle($this->url);
            $urls['share_url'] = $this->url;
            return jok2('检验成功', $urls);
        }

        $urls['title'] = $this->getMagnetTitle($this->url);
        $urls['share_url'] = $this->url;
        return jok2('转存成功', $urls);
    }

    private function getMagnetTitle($magnetUrl)
    {
        if(preg_match('/&dn=([^&]+)/', $magnetUrl, $matches)){
            $title = urldecode($matches[1]);
            if(!empty($title)){
                return $title;
            }
        }
        
        if(preg_match('/magnet:\?xt=urn:btih:([a-fA-F0-9]{40})/', $magnetUrl, $matches)){
            return '磁力资源_' . substr($matches[1], 0, 8);
        }
        
        return '磁力资源';
    }

    public function deletepdirFid($filelist)
    {
        return jerr2('磁力链接不支持删除操作');
    }
}
