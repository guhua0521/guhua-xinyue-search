<?php
namespace netdisk\pan;

class TianyiPan extends BasePan
{
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->urlHeader = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'content-type: application/json;charset=UTF-8',
            'sec-ch-ua: "Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-site',
            'Referer: https://cloud.189.cn/',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'cookie: ' . Config('qfshop.tianyi_cookie')
        ];
    }

    public function getFiles($pdir_fid = 0)
    {
        $url = "https://cloud.189.cn/api/portal/file/getFileList.action";
        $urlData = [
            'noCache' => time(),
            'pageNum' => 1,
            'pageSize' => 100,
            'mediaType' => 0,
            'folderId' => $pdir_fid,
            'orderBy' => 1,
            'descending' => true,
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if ($res['res_code'] !== 0) {
            return jerr2('天翼网盘未登录，请检查cookie');
        }
        
        return jok2('获取成功', $res['data']['fileListAO']['fileList'] ?? []);
    }

    public function transfer($share_id)
    {
        if(empty($this->code)){
            return jerr2('请提供提取码');
        }

        if($this->isType == 1){
            $urls['title'] = $this->getShareTitle($share_id);
            $urls['share_url'] = $this->url;
            return jok2('检验成功', $urls);
        }

        $cookie = Config('qfshop.tianyi_cookie');
        if(empty($cookie)){
            return jerr2('天翼网盘未登录，请检查cookie');
        }

        $res = $this->getShareInfo($share_id, $this->code);
        if($res['status'] !== 200){
            return jerr2($res['message']);
        }

        $shareData = $res['data'];
        $shareId = $shareData['shareId'] ?? '';
        if(empty($shareId)){
            return jerr2('获取分享信息失败');
        }

        $res = $this->transferToMyCloud($shareId);
        if($res['status'] !== 200){
            return jerr2($res['message']);
        }

        $fileId = $res['data']['fileId'] ?? '';
        if(empty($fileId)){
            return jerr2('转存失败');
        }

        $res = $this->createShare($fileId);
        if($res['status'] !== 200){
            return jerr2($res['message']);
        }

        return jok2('转存成功', $res['data']);
    }

    private function getShareTitle($share_id)
    {
        $url = "https://cloud.189.cn/api/portal/share/getShareInfo.action";
        $urlData = [
            'shareCode' => $share_id,
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['data']['shareName'])){
            return $res['data']['shareName'];
        }
        
        return '未知文件';
    }

    private function getShareInfo($share_id, $code)
    {
        $url = "https://cloud.189.cn/api/portal/share/getShareInfo.action";
        $urlData = [
            'shareCode' => $share_id,
            'shareNo' => $code,
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if($res['res_code'] === 0){
            return jok2('获取成功', $res['data']);
        }
        
        return jerr2($res['res_msg'] ?? '获取分享信息失败');
    }

    private function transferToMyCloud($shareId)
    {
        $url = "https://cloud.189.cn/api/portal/share/saveShare.action";
        $urlData = [
            'shareId' => $shareId,
            'toFolderId' => '-11',
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if($res['res_code'] === 0){
            return jok2('转存成功', $res['data']);
        }
        
        return jerr2($res['res_msg'] ?? '转存失败');
    }

    private function createShare($fileId)
    {
        $url = "https://cloud.189.cn/api/portal/share/createShare.action";
        $urlData = [
            'fileIdList' => [$fileId],
            'shareChannel' => 1,
            'validDay' => 365,
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if($res['res_code'] === 0){
            $shareData = [
                'title' => $res['data']['shareName'] ?? '',
                'share_url' => 'https://cloud.189.cn/t/' . $res['data']['shortCode'],
                'fid' => $fileId,
            ];
            return jok2('分享成功', $shareData);
        }
        
        return jerr2($res['res_msg'] ?? '分享失败');
    }

    public function deletepdirFid($filelist)
    {
        $url = "https://cloud.189.cn/api/portal/file/deleteFiles.action";
        $urlData = [
            'fileIdList' => is_array($filelist) ? $filelist : [$filelist],
        ];
        
        $res = curlHelper($url, "POST", json_encode($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if($res['res_code'] === 0){
            return jok2('删除成功', $res['data']);
        }
        
        return jerr2($res['res_msg'] ?? '删除失败');
    }
}