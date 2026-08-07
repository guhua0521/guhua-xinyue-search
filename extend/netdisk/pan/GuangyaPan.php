<?php
namespace netdisk\pan;

class GuangyaPan extends BasePan
{
    private $accessToken = '';
    private $refreshToken = '';
    private $apiDomain = 'https://api.guangyapan.com';
    private $accountDomain = 'https://account.guangyapan.com';
    private $clientId = 'aMe-8VSlkrbQXpUR';

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->initCredentials();
    }

    private function initCredentials()
    {
        $authData = $this->getAuthData();
        if (!empty($authData['access_token'])) {
            $this->accessToken = $authData['access_token'];
            $this->refreshToken = $authData['refresh_token'] ?? '';
        }
    }

    private function getAuthFile()
    {
        return app()->getConfigPath() . 'guangya_auth.json';
    }

    private function getAuthData()
    {
        $authFile = $this->getAuthFile();
        if (file_exists($authFile)) {
            $content = file_get_contents($authFile);
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    private function saveAuthData($data)
    {
        $authFile = $this->getAuthFile();
        file_put_contents($authFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function buildHeaders($needAuth = true)
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Origin: https://www.guangyapan.com',
            'Referer: https://www.guangyapan.com/',
        ];
        if ($needAuth && !empty($this->accessToken)) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        return $headers;
    }

    private function refreshAccessToken()
    {
        if (empty($this->refreshToken)) {
            return false;
        }

        $urlData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
        ];

        $res = curlHelper(
            $this->accountDomain . '/v1/auth/token',
            'POST',
            json_encode($urlData),
            ['Content-Type: application/json', 'Accept: application/json']
        );

        if (!empty($res['body'])) {
            $data = json_decode($res['body'], true);
            if (!empty($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                if (!empty($data['refresh_token'])) {
                    $this->refreshToken = $data['refresh_token'];
                }

                $authData = $this->getAuthData();
                $authData['access_token'] = $data['access_token'];
                $authData['refresh_token'] = $this->refreshToken;
                $authData['expires_in'] = $data['expires_in'] ?? 3600;
                $authData['token_type'] = $data['token_type'] ?? 'Bearer';
                $authData['scope'] = $data['scope'] ?? 'user';
                $this->saveAuthData($authData);

                return true;
            }
        }
        return false;
    }

    private function apiRequest($url, $method = 'POST', $data = null, $retry = true)
    {
        $headers = $this->buildHeaders(true);
        $body = $data !== null ? json_encode($data) : null;

        $res = curlHelper($url, $method, $body, $headers);

        if (!empty($res['error'])) {
            \think\facade\Log::error('GuangyaPan apiRequest curl error: ' . $res['error'] . ' url: ' . $url);
            return ['code' => -1, 'msg' => '请求失败: ' . $res['error']];
        }

        $httpCode = $res['detail']['http_code'] ?? 0;
        $response = json_decode($res['body'] ?? '', true);
        if ($response === null) {
            \think\facade\Log::error('GuangyaPan apiRequest json decode failed, body: ' . ($res['body'] ?? ''));
            return ['code' => -1, 'msg' => '响应解析失败'];
        }

        if ($retry && $httpCode == 401) {
            if ($this->refreshAccessToken()) {
                return $this->apiRequest($url, $method, $data, false);
            }
        }

        return $response;
    }

    public function getFiles($pdir_fid = 0)
    {
        if (empty($this->accessToken)) {
            return jerr2('光鸭未登录，请检查cookie配置');
        }

        $parentId = ($pdir_fid === 0 || $pdir_fid === '0') ? '' : $pdir_fid;
        $urlData = [
            'parentId' => $parentId,
            'pageSize' => 50,
            'orderBy' => 3,
            'sortType' => 1,
        ];

        $res = $this->apiRequest($this->apiDomain . '/nd.bizuserres.s/v1/file/get_file_list', 'POST', $urlData);

        if (empty($res)) {
            return jerr2('获取文件列表失败: 无响应');
        }

        $code = $res['code'] ?? null;
        if ($code !== null && $code != 0 && $code != 200) {
            $msg = $res['msg'] ?? '获取文件列表失败';
            return jerr2($msg);
        }

        if (empty($res['data']) || empty($res['data']['list'])) {
            return jok2('获取成功', []);
        }

        return jok2('获取成功', $res['data']['list']);
    }

    public function transfer($share_id)
    {
        if (empty($this->accessToken)) {
            return jerr2('光鸭未登录，请检查cookie配置');
        }

        $shareAccessToken = $this->getShareAccessToken($share_id);
        if (empty($shareAccessToken)) {
            return jerr2('获取分享访问令牌失败');
        }

        $shareFiles = $this->listShareRootFiles($shareAccessToken);
        if (empty($shareFiles)) {
            return jerr2('分享内容为空或获取失败');
        }

        if ($this->isType == 1) {
            $title = $shareFiles[0]['fileName'] ?? '光鸭分享资源';
            $urls = [
                'title' => $title,
                'share_url' => $this->url,
            ];
            return jok2('检验成功', $urls);
        }

        $authData = $this->getAuthData();
        $toParentId = $authData['guangya_file'] ?? '';
        if ($this->expired_type == 2) {
            $toParentId = $authData['guangya_file_time'] ?? '';
        }
        if (empty($toParentId)) {
            $toParentId = '';
        }

        $shareFileIds = [];
        $title = $shareFiles[0]['fileName'] ?? '光鸭分享资源';
        foreach ($shareFiles as $file) {
            if (!empty($file['fileId'])) {
                $shareFileIds[] = $file['fileId'];
            }
        }

        if (empty($shareFileIds)) {
            return jerr2('无可转存的文件');
        }

        $beforeFiles = $this->listUserFiles($toParentId);

        $restoreResult = $this->restoreShare($shareAccessToken, $shareFileIds, $toParentId);
        if (!$restoreResult && strpos($this->lastError, '已转存') === false && strpos($this->lastError, '重复') === false) {
            return jerr2($this->lastError ?: '转存失败');
        }

        sleep(1);
        $afterFiles = $this->listUserFiles($toParentId);

        $userFileIds = $this->findNewFileIds($beforeFiles, $afterFiles, $title);
        if (empty($userFileIds)) {
            \think\facade\Log::error('GuangyaPan transfer: cannot find transferred files in user account, title: ' . $title);
            return jerr2('转存后未找到文件');
        }

        $shareResult = $this->createShare($userFileIds, $title);
        if (empty($shareResult)) {
            return jerr2('创建分享链接失败');
        }

        $share = [
            'share_url' => $shareResult['shareUrl'] ?? '',
            'title' => $title,
            'fid' => $userFileIds[0] ?? '',
        ];

        return jok2('转存成功', $share);
    }

    private function listUserFiles($parentId = '')
    {
        $urlData = [
            'parentId' => $parentId,
            'pageSize' => 200,
            'orderBy' => 3,
            'sortType' => 1,
        ];

        $res = $this->apiRequest($this->apiDomain . '/nd.bizuserres.s/v1/file/get_file_list', 'POST', $urlData);

        if (!empty($res['data']['list'])) {
            return $res['data']['list'];
        }
        return [];
    }

    private function findNewFileIds($beforeFiles, $afterFiles, $title)
    {
        $beforeIds = [];
        foreach ($beforeFiles as $f) {
            $beforeIds[$f['fileId']] = true;
        }

        $newFileIds = [];
        foreach ($afterFiles as $f) {
            if (empty($beforeIds[$f['fileId']])) {
                $newFileIds[] = $f['fileId'];
            }
        }

        if (!empty($newFileIds)) {
            return $newFileIds;
        }

        foreach ($afterFiles as $f) {
            if (isset($f['fileName']) && $f['fileName'] === $title) {
                $newFileIds[] = $f['fileId'];
            }
        }

        return $newFileIds;
    }

    private function getShareAccessToken($shareId)
    {
        $urlData = [
            'shareId' => $shareId,
        ];

        $headers = $this->buildHeaders(false);
        $res = curlHelper(
            $this->apiDomain . '/nd.bizuserres.s/v1/get_share_access_token',
            'POST',
            json_encode($urlData),
            $headers
        );

        if (!empty($res['error'])) {
            \think\facade\Log::error('GuangyaPan getShareAccessToken curl error: ' . $res['error']);
            return '';
        }

        $data = json_decode($res['body'] ?? '', true);
        if (!empty($data['data']['accessToken'])) {
            return $data['data']['accessToken'];
        }

        \think\facade\Log::error('GuangyaPan getShareAccessToken failed, shareId: ' . $shareId . ', response: ' . ($res['body'] ?? ''));
        return '';
    }

    private function listShareRootFiles($shareAccessToken)
    {
        $urlData = [
            'pageSize' => 50,
            'accessToken' => $shareAccessToken,
            'parentId' => '',
            'orderBy' => 0,
            'sortType' => 0,
        ];

        $headers = $this->buildHeaders(false);
        $res = curlHelper(
            $this->apiDomain . '/nd.bizuserres.s/v1/get_share_page_files_list',
            'POST',
            json_encode($urlData),
            $headers
        );

        if (!empty($res['error'])) {
            \think\facade\Log::error('GuangyaPan listShareRootFiles curl error: ' . $res['error']);
            return [];
        }

        $data = json_decode($res['body'] ?? '', true);
        if (!empty($data['data']['list'])) {
            return $data['data']['list'];
        }

        \think\facade\Log::error('GuangyaPan listShareRootFiles empty, response: ' . ($res['body'] ?? ''));
        return [];
    }

    private function restoreShare($shareAccessToken, $fileIds, $toParentId)
    {
        $urlData = [
            'accessToken' => $shareAccessToken,
            'fileIds' => $fileIds,
            'parentId' => $toParentId,
        ];

        $res = $this->apiRequest($this->apiDomain . '/nd.bizuserres.s/v1/restore_share', 'POST', $urlData);

        if (empty($res)) {
            $this->lastError = '转存失败: 无响应';
            return false;
        }

        $code = $res['code'] ?? null;
        $msg = $res['msg'] ?? '';

        if ($msg === 'success' || !empty($res['data'])) {
            return true;
        }

        if (strpos($msg, '已转存') !== false || strpos($msg, '重复') !== false) {
            $this->lastError = $msg;
            return false;
        }

        \think\facade\Log::error('GuangyaPan restoreShare failed: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->lastError = '转存失败: ' . ($msg ?: '未知错误');
        return false;
    }

    private function createShare($fileIds, $title)
    {
        $urlData = [
            'fileIds' => $fileIds,
            'title' => $title,
            'validateDuration' => 0,
            'shareType' => 0,
            'autoFillCode' => false,
            'trafficLimit' => '0',
            'maxRestoreCount' => 0,
            'downloadType' => 1,
        ];

        $res = $this->apiRequest($this->apiDomain . '/nd.bizuserres.s/v1/share_file', 'POST', $urlData);

        if (!empty($res['data'])) {
            return $res['data'];
        }

        \think\facade\Log::error('GuangyaPan createShare failed: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        return null;
    }

    public function deletepdirFid($filelist)
    {
        if (empty($this->accessToken)) {
            return jerr2('光鸭未登录，请检查cookie配置');
        }

        $urlData = [
            'fileIds' => $filelist,
        ];

        $this->apiRequest($this->apiDomain . '/nd.bizuserres.s/v1/file/delete_file', 'POST', $urlData);
    }
}
