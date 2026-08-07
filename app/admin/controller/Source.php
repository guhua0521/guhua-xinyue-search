<?php

namespace app\admin\controller;

use think\App;
use think\facade\View;
use think\facade\Filesystem;
use think\exception\ValidateException;
use app\admin\QfShop;
use app\model\Source as SourceModel;
use app\model\SourceLog as SourceLogModel;
use app\model\SourceCategory;
use quarkPlugin\QuarkPlugin;

class Source extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $panTypes = config('pan_types');
        $panTypeMap = [];
        foreach ($panTypes as $type) {
            $panTypeMap[$type['id']] = $type['name'];
        }
        View::assign('panTypeMap', $panTypeMap);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [];
        $this->insertFields = [
            //允许添加的字段列表
            "source_category_id",
            "title",
            "description",
            "url",
            "status",
            "is_delete",
            "sort",
            "is_top",
            "vod_content",
            "is_type",
            "account_name"
        ];
        $this->updateFields = [
            //允许更新的字段列表
            "source_category_id",
            "title",
            "description",
            "url",
            "status",
            "is_delete",
            "sort",
            "is_top",
            "vod_content",
            "is_type",
            "account_name"
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "title" => "资源名称必须填写",
            "url" => "资源地址必须填写",
        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "source_id" => "资源ID必须填写",
            "title" => "资源名称必须填写",
            "url" => "资源地址必须填写",
        ];
        $this->model = new SourceModel();
    }

    public function __call($name, $arguments) {
        if ($name == 'SourceLogModel') {
            return new SourceLogModel();
        }
        return parent::__call($name, $arguments);
    }


    /**
     * 获取列表接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $map = $this->getDataFilterFromRequest();
        $map[] = ['is_delete', '=', 0];
        if (!empty(input('source_category_id'))) {
            $map[] = ['source_category_id', '=', input('source_category_id')];
        }
        if (!empty(input('is_type')) && input('is_type') !== '') {
            $map[] = ['is_type', '=', input('is_type')];
        }
        if (!empty(input('account_name'))) {
            $map[] = ['account_name', '=', input('account_name')];
        }
        if (input('is_time') !== '' && input('is_time') !== null) {
            $map[] = ['is_time', '=', input('is_time')];
        }
        empty(input('keyword')) ?: $map[] = ['title|description', 'like', '%' . input('keyword') . '%'];
        $order = $this->getorderfromRequest();
        $this->setGetListPerPage();
        $dataList = $this->model->getListByPage($map, $order, $this->selectList);
        return jok('数据获取成功', $dataList);
    }


    /**
     * 添加接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function add()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //校验Insert字段是否填写
        $error = $this->validateInsertFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Insert数据
        $data = $this->getInsertDataFromRequest();
        //添加这行数据
        $data["update_time"] = time();
        $data["create_time"] = time();
        $data["is_type"] = determineIsType($data["url"]);
        $this->model->insertGetId($data);
        return jok('添加成功');
    }

    /**
     * 修改接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function update()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        //根据主键获取一行数据
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        //校验Update字段是否填写
        $error  = $this->validateUpdateFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Update数据
        $data = $this->getUpdateDataFromRequest();
        //根据主键更新这条数据
        $data["update_time"] = time();
        $data["is_type"] = determineIsType($data["url"]);
        $this->model->where($this->pk, $this->pk_value)->update($data);
        return jok('修改成功');
    }



    /**
     * 删除接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function delete()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "必须填写", 400);
        }

        if (isInteger($this->pk_value)) {
            //根据主键获取一行数据
            $item = $this->getRowByPk();
            if (empty($item)) {
                return jerr("数据查询失败", 404);
            }
            $this->model->where($this->pk, $this->pk_value)->delete();
        } else {
            $list = explode(',', $this->pk_value);
            $this->model->where($this->pk, 'in', $list)->delete();
        }
        return jok('删除成功');
    }

    // 判断文件编码
    function detectFileEncoding($filename)
    {
        $handle = fopen($filename, 'r');
        $firstLine = fread($handle, 1024); // 读取文件的开头一部分内容
        fclose($handle);
        // 尝试使用不同的编码进行解码，并检查是否成功
        if (mb_check_encoding($firstLine, 'UTF-8')) {
            return 'UTF-8';
        } elseif (mb_check_encoding($firstLine, 'GBK')) {
            return 'GBK';
        } else {
            // 如果无法确定编码，则返回默认编码
            return 'UTF-8'; // 或者根据需要返回其他默认编码
        }
    }


    /**
     * Excel导入
     *
     * @return void
     */
    public function imports()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        try {
            $file = request()->file('file');
            if (!$file) {
                return jerr('请选择文件');
            }
            
            $fileInfo = [
                'originalName' => $file->getOriginalName(),
                'extension' => $file->extension(),
                'size' => $file->getSize(),
            ];
            
            \think\facade\Log::info('上传文件信息：' . json_encode($fileInfo, JSON_UNESCAPED_UNICODE));
            
            try {
                validate(['file' => 'filesize:10485760|fileExt:xlsx,xls'])
                    ->check(['file' => $file]);
            } catch (\Exception $e) {
                \think\facade\Log::error('文件验证失败：' . $e->getMessage());
                return jerr('文件验证失败：' . $e->getMessage());
            }
            
            $saveName = Filesystem::putFile('excel', $file);
            
            if (!$saveName) {
                \think\facade\Log::error('文件保存失败：uploads 目录可能不存在或无写入权限');
                return jerr('文件保存失败，请检查 uploads 目录权限');
            }
            
            \think\facade\Log::info('文件保存成功：' . $saveName);

            ini_set("memory_limit", -1);
            set_time_limit(0);

            $file_name = Filesystem::path($saveName);
            
            \think\facade\Log::info('完整文件路径：' . $file_name);
            
            if (!file_exists($file_name)) {
                \think\facade\Log::error('文件不存在：' . $file_name);
                \think\facade\Log::error('尝试查找的路径：' . Filesystem::path(''));
                return jerr('文件不存在');
            }

            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            if ($extension == 'csv') {
                return jerr('转成xlsx格式吧');
                $PHPReader = new \PHPExcel_Reader_CSV();
                $encoding = $this->detectFileEncoding($file_name);
                $PHPReader->setInputEncoding($encoding);
                $PHPReader->setDelimiter(',');
            } elseif ($extension == 'xlsx') {
                $PHPReader = new \PHPExcel_Reader_Excel2007();
            } elseif ($extension == 'xls') {
                $PHPReader = new \PHPExcel_Reader_Excel5();
            } else {
                return jerr('不支持的文件类型');
            }

            $objExcel = $PHPReader->load($file_name);
            $excel_array = $objExcel->getSheet(0)->toArray();
            
            \think\facade\Log::info('Excel 数据行数：' . count($excel_array));
            
            array_shift($excel_array);
            array_shift($excel_array);
            
            \think\facade\Log::info('处理后数据行数：' . count($excel_array));
            
            $data = [];
            $i = 0;
            $existing_data = [];

            $existing_records = $this->model->field('title, is_type')->select()->toArray();
            
            \think\facade\Log::info('现有记录数：' . count($existing_records));
            
            foreach ($existing_records as $record) {
                $existing_data[$record['title'] . '_' . $record['is_type']] = true;
            }

            Filesystem::delete($saveName);

            // 获取前端传来的默认分类
            $default_category_id = input('source_category_id', 0);
            \think\facade\Log::info('前端传来的默认分类ID：' . $default_category_id);
            
            // 加载所有分类到内存，方便快速查找
            $categoryModel = new \app\model\SourceCategory();
            $allCategories = $categoryModel->field('source_category_id, name')->where('status', 0)->select()->toArray();
            $categoryMap = []; // 名称 => ID 的映射
            foreach ($allCategories as $cat) {
                $categoryMap[trim($cat['name'])] = $cat['source_category_id'];
            }
            \think\facade\Log::info('已加载分类数量：' . count($categoryMap));

            foreach ($excel_array as $k => $v) {
                $title = isset($v[0]) ? trim($v[0]) : '';
                $url = isset($v[1]) ? trim($v[1]) : '';
                $excel_category_name = isset($v[2]) ? trim($v[2]) : '';
                $account_name = isset($v[3]) ? trim($v[3]) : '';
                $description = isset($v[4]) ? trim($v[4]) : '';
                $vod_content = isset($v[5]) ? trim($v[5]) : '';

                $is_type = $url ? determineIsType($url) : 0;

                $key = $title . '_' . $is_type;

                if (!isset($existing_data[$key]) && $url) {
                    $record = [];
                    $record['title'] = $title;
                    $record['url'] = $url;
                    $record['is_type'] = $is_type;
                    
                    // 处理分类：优先使用Excel中的分类名称，支持自动创建
                    $source_category_id = 0;
                    if (!empty($excel_category_name)) {
                        // 根据名称查找分类ID
                        if (isset($categoryMap[$excel_category_name])) {
                            // 分类已存在
                            $source_category_id = $categoryMap[$excel_category_name];
                            \think\facade\Log::info('找到已有分类：' . $excel_category_name . ' => ID:' . $source_category_id);
                        } else {
                            // 分类不存在，自动创建
                            $newCategory = [
                                'name' => $excel_category_name,
                                'image' => '',
                                'sort' => 0,
                                'status' => 0,
                                'is_sys' => 0,
                                'is_update' => 1,
                                'is_type' => 0,
                                'create_time' => time(),
                                'update_time' => time(),
                            ];
                            $source_category_id = $categoryModel->insertGetId($newCategory);
                            // 更新缓存
                            $categoryMap[$excel_category_name] = $source_category_id;
                            \think\facade\Log::info('自动创建分类：' . $excel_category_name . ' => ID:' . $source_category_id);
                        }
                    } elseif (!empty($default_category_id)) {
                        // 使用前端传来的默认分类
                        $source_category_id = intval($default_category_id);
                    }
                    $record['source_category_id'] = $source_category_id;
                    
                    $record['account_name'] = $account_name;

                    $record['description'] = $description;
                    $record['vod_content'] = $vod_content;
                    $record['update_time'] = time();
                    $record['create_time'] = time();
                    $record['status'] = 1;
                    $record['is_delete'] = 0;
                    $record['is_time'] = 0;
                    $record['is_user'] = 0;
                    $record['page_views'] = 0;
                    $record['vod_pic'] = '';
                    $record['sort'] = 0;
                    $record['is_top'] = 0;
                    $record['content'] = '';
                    $record['code'] = '';
                    $record['fid'] = '';

                    $data[] = $record;
                    $existing_data[$key] = true;
                    $i++;
                }
            }
            
            \think\facade\Log::info('准备插入数据数：' . count($data));
            
            if (empty($data)) {
                \think\facade\Log::error('没有可导入的数据');
                return jok('无可导入的资源，请检查表格格式');
            }

            $this->model->autoWriteTimestamp = false;
            
            try {
                $result = $this->model->insertAll($data);
                \think\facade\Log::info('插入结果：' . $result);
            } catch (\Exception $e) {
                \think\facade\Log::error('插入失败：' . $e->getMessage());
                \think\facade\Log::error('错误堆栈：' . $e->getTraceAsString());
                return jerr('数据库插入失败：' . $e->getMessage());
            }
            
            $this->model->autoWriteTimestamp = true;
            if ($i == 0) {
                return jok('无可导入的资源，请检查表格格式');
            }
            return jok('导入成功' . $i . '个资源');
        } catch (ValidateException $e) {
            \think\facade\Log::error('验证异常：' . $e->getMessage());
            \think\facade\Log::error('验证异常堆栈：' . $e->getTraceAsString());
            return jerr($e->getMessage());
        } catch (\Exception $error) {
            \think\facade\Log::error('上传文件异常：' . $error->getMessage());
            \think\facade\Log::error('异常堆栈：' . $error->getTraceAsString());
            return jerr('上传文件失败，请检查你的文件！');
        }
    }



    /**
     * 导出
     *
     * @return void
     */
    public function excel()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        //查询数据
        $map = [];
        $filter = input('');
        foreach ($filter as $k => $v) {
            if ($k == 'filter') {
                $k = input('filter');
                $v = input('keyword');
            }
            if ($v === '' || $v === null) {
                continue;
            }
            if (array_key_exists($k, $this->searchFilter)) {
                switch ($this->searchFilter[$k]) {
                    case "like":
                        array_push($map, [$k, 'like', "%" . $v . "%"]);
                        break;
                    case "=":
                        array_push($map, [$k, '=', $v]);
                        break;
                    default:
                }
            }
        }

        // 获取所有分类，建立ID到名称的映射
        $categoryModel = new \app\model\SourceCategory();
        $categories = $categoryModel->field('source_category_id, name')->select()->toArray();
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat['source_category_id']] = $cat['name'];
        }

        $field = 'title,url,source_category_id,account_name,description,vod_content';
        $dataList = $this->model->field($field)->where($map)->select();
        // 处理数据，将分类ID转换为分类名称
        $data = [];
        foreach ($dataList as $item) {
            $item['source_category_name'] = $categoryMap[$item['source_category_id']] ?? '';
            $data[] = $item;
        }
        $excelField = [
            "title" => "资源名称",
            "url" => "资源地址",
            "source_category_name" => "资源分类",
            "account_name" => "添加人",
            "description" => "关键字搜索",
            "vod_content" => "资源介绍",
        ];

        $this->excelField = $excelField;
        // 转换为集合对象，以便调用toArray()方法
        $dataCollection = collect($data);
        $this->exportExcelData($dataCollection);
    }

    /**
     * 一键转存并分享夸克资源
     *
     * @return void
     */
    public function transfer()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (empty(input("type")) || empty(input("urls"))) {
            return jerr('参数不能为空');
        }

        $source_category_id = input('source_category_id') ?? 0;

        $allData = parsePanLinks(input("urls"));

        $quarkPlugin = new QuarkPlugin();
        if (input("type") == 2) {
            //转存分享导入
            $res = $quarkPlugin->transfer($allData, $source_category_id);
        } else {
            // 直接导入
            $res = $quarkPlugin->import($allData, $source_category_id);
        }

        return jok('已提交任务，稍后查看结果2', $allData);
    }

    /**
     * 全部转存 
     * @return void
     */
    public function transferAll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (empty(input('source_category_id'))) {
            return jerr('参数异常');
        }
        $quarkPlugin = new QuarkPlugin();
        $quarkPlugin->transferAll(input('source_category_id'));
        return jok('已提交任务，稍后查看结果');
    }

    /**
     * 获取夸克网盘文件夹
     *
     * @return void
     */
    public function getFiles()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $quarkPlugin = new QuarkPlugin();
        $result = $quarkPlugin->getFiles(input('type') ?? 0, input('pdir_fid') ?? 0);

        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
    }

    /**
     * 批量修改分类
     *
     * @return void
     */
    public function batchUpdateCategory()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        
        $source_ids = input('source_ids');
        $source_category_id = input('source_category_id');
        
        if (empty($source_ids) || empty($source_category_id)) {
            return jerr('参数不能为空');
        }
        
        $ids = explode(',', $source_ids);
        
        try {
            $this->model->where('source_id', 'in', $ids)->update([
                'source_category_id' => $source_category_id,
                'update_time' => time()
            ]);
            return jok('批量修改分类成功');
        } catch (\Exception $e) {
            return jerr('批量修改分类失败');
        }
    }

    public function guangyaQrcode()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $urlData = [
                'scope' => 'user',
                'client_id' => 'aMe-8VSlkrbQXpUR',
            ];

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ];

            $res = curlHelper(
                'https://account.guangyapan.com/v1/auth/device/code',
                'POST',
                json_encode($urlData),
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('请求光鸭服务器失败: ' . $res['error']);
            }

            $data = json_decode($res['body'] ?? '', true);
            if (empty($data) || empty($data['device_code'])) {
                return jerr('获取光鸭二维码失败: ' . ($res['body'] ?? 'empty response'));
            }

            $verifyUrl = $data['verification_uri_complete'] ?? ($data['verification_url'] ?? '');

            require_once root_path('extend') . 'phpqrcode' . DIRECTORY_SEPARATOR . 'phpqrcode.php';
            ob_start();
            \QRcode::png($verifyUrl, false, QR_ECLEVEL_M, 6, 2);
            $imageData = ob_get_clean();
            $base64 = 'data:image/png;base64,' . base64_encode($imageData);

            cache('guangya_device_code', $data['device_code'], $data['expires_in'] ?? 600);

            return jok('获取成功', [
                'code_url' => $base64,
                'raw_url' => $verifyUrl,
                'expires_in' => $data['expires_in'] ?? 600,
                'interval' => $data['interval'] ?? 5,
            ]);
        } catch (\Exception $e) {
            return jerr('获取二维码异常: ' . $e->getMessage());
        }
    }

    public function guangyaQrcodePoll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $deviceCode = cache('guangya_device_code');
            if (empty($deviceCode)) {
                return jerr('二维码已过期，请重新获取');
            }

            $urlData = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $deviceCode,
                'client_id' => 'aMe-8VSlkrbQXpUR',
            ];

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ];

            $res = curlHelper(
                'https://account.guangyapan.com/v1/auth/token',
                'POST',
                json_encode($urlData),
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('请求光鸭服务器失败: ' . $res['error']);
            }

            $httpCode = 0;
            if (!empty($res['detail']) && !empty($res['detail']['http_code'])) {
                $httpCode = $res['detail']['http_code'];
            }

            $data = json_decode($res['body'] ?? '', true);

            if ($httpCode == 200 && !empty($data['access_token'])) {
                $authFile = app()->getConfigPath() . 'guangya_auth.json';
                $existingData = [];
                if (file_exists($authFile)) {
                    $existingData = json_decode(file_get_contents($authFile), true) ?: [];
                }

                $cookieJson = json_encode(array_merge($existingData, [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? '',
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'scope' => $data['scope'] ?? 'user',
                ]));

                file_put_contents($authFile, $cookieJson);

                cache('guangya_device_code', null);

                return jok('登录成功', [
                    'status' => 'success',
                    'cookie' => $cookieJson,
                ]);
            }

            if (!empty($data['error'])) {
                $errorType = $data['error'];
                if ($errorType === 'authorization_pending') {
                    return jok('等待扫码', ['status' => 'waiting']);
                }
                if ($errorType === 'slow_down') {
                    return jok('请稍后重试', ['status' => 'waiting']);
                }
                if ($errorType === 'expired_token') {
                    cache('guangya_device_code', null);
                    return jerr('二维码已过期，请重新获取');
                }
                if ($errorType === 'access_denied') {
                    cache('guangya_device_code', null);
                    return jerr('用户拒绝授权');
                }
            }

            return jok('等待扫码', ['status' => 'waiting']);
        } catch (\Exception $e) {
            return jerr('轮询异常: ' . $e->getMessage());
        }
    }

    public function guangyaGetAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $authFile = app()->getConfigPath() . 'guangya_auth.json';
        $cookieJson = '';
        if (file_exists($authFile)) {
            $cookieJson = file_get_contents($authFile);
        }

        return jok('获取成功', [
            'cookie' => $cookieJson,
        ]);
    }

    public function guangyaSaveAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $cookieJson = input('cookie', '');
        if (empty($cookieJson)) {
            return jerr('凭证不能为空');
        }

        $decoded = json_decode($cookieJson, true);
        if (empty($decoded) || empty($decoded['access_token'])) {
            return jerr('凭证格式无效，需要包含 access_token');
        }

        $authFile = app()->getConfigPath() . 'guangya_auth.json';
        file_put_contents($authFile, $cookieJson);

        return jok('保存成功');
    }

}
