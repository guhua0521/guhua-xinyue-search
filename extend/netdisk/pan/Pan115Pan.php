<?php
namespace netdisk\pan;

class Pan115Pan extends BasePan
{
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->urlHeader = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'sec-ch-ua: "Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-site',
            'Referer: https://115cdn.com/',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'cookie: ' . Config('qfshop.pan115_cookie')
        ];
    }

    public function getFiles($pdir_fid = 0)
    {
        $url = "https://webapi.115.com/files";
        $queryParams = [
            'aid' => 1,
            'cid' => $pdir_fid,
            'show_dir' => 1,
            'limit' => 100,
            'o' => 'user_ptime',
            'asc' => 0,
            'fc' => 0,
        ];
        
        $res = curlHelper($url, "GET", '', $this->urlHeader, $queryParams)['body'];
        $res = json_decode($res, true);
        if(isset($res['state']) && $res['state'] === false){
            return jerr2('115未登录，请检查cookie');
        }
        
        return jok2('获取成功', $res['data'] ?? []);
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

        $cookie = Config('qfshop.pan115_cookie');
        if(empty($cookie)){
            return jerr2('115未登录，请检查cookie');
        }

        $res = $this->getShareInfo($share_id, $this->code);
        if($res['status'] !== 200){
            return jerr2($res['message']);
        }

        $shareData = $res['data'];
        $pickCode = $shareData['pick_code'] ?? '';
        if(empty($pickCode)){
            return jerr2('获取分享信息失败');
        }

        $res = $this->transferToMyCloud($share_id, $pickCode);
        if($res['status'] !== 200){
            return jerr2($res['message']);
        }

        $fileId = $res['data']['file_id'] ?? '';
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
        $url = "https://webapi.115.com/share/snap";
        $queryParams = [
            'share_code' => $share_id,
        ];
        
        $res = curlHelper($url, "GET", '', $this->urlHeader, $queryParams)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['data']['file_name'])){
            return $res['data']['file_name'];
        }
        
        return '未知文件';
    }

    private function getShareInfo($share_id, $code)
    {
        $url = "https://webapi.115.com/share/sharinginfo";
        $queryParams = [
            'share_code' => $share_id,
            'receive_code' => $code,
        ];
        
        $res = curlHelper($url, "GET", '', $this->urlHeader, $queryParams)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['state']) && $res['state'] === true){
            return jok2('获取成功', $res['data']);
        }
        
        return jerr2($res['error_msg'] ?? '获取分享信息失败');
    }

    private function transferToMyCloud($share_id, $pickCode)
    {
        $url = "https://webapi.115.com/share/sharelink";
        $urlData = [
            'share_code' => $share_id,
            'pickcode' => $pickCode,
        ];
        
        $res = curlHelper($url, "POST", http_build_query($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['state']) && $res['state'] === true){
            return jok2('转存成功', $res['data']);
        }
        
        return jerr2($res['error_msg'] ?? '转存失败');
    }

    private function createShare($fileId)
    {
        $url = "https://webapi.115.com/share/send";
        $urlData = [
            'file_id' => $fileId,
            'user_id' => $this->getUserId(),
        ];
        
        $res = curlHelper($url, "POST", http_build_query($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['state']) && $res['state'] === true){
            $shareData = [
                'title' => $res['data']['file_name'] ?? '',
                'share_url' => 'https://115cdn.com/s/' . $res['data']['share_code'],
                'fid' => $fileId,
            ];
            return jok2('分享成功', $shareData);
        }
        
        return jerr2($res['error_msg'] ?? '分享失败');
    }

    private function getUserId()
    {
        $url = "https://webapi.115.com/user/info";
        
        $res = curlHelper($url, "GET", '', $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['data']['user_id'])){
            return $res['data']['user_id'];
        }
        
        return '';
    }

    public function deletepdirFid($filelist)
    {
        $url = "https://webapi.115.com/rb/delete";
        $urlData = [
            'fid' => is_array($filelist) ? implode(',', $filelist) : $filelist,
        ];
        
        $res = curlHelper($url, "POST", http_build_query($urlData), $this->urlHeader)['body'];
        $res = json_decode($res, true);
        
        if(isset($res['state']) && $res['state'] === true){
            return jok2('删除成功', $res['data']);
        }
        
        return jerr2($res['error_msg'] ?? '删除失败');
    }
}