<?php

namespace app\api\controller;

use think\App;
use think\facade\Cache;
use think\facade\Request;
use think\facade\Log;
use app\api\QfShop;
use app\model\Source as SourceModel;
use app\model\Days as DaysModel;
use app\model\ApiList as ApiListModel;
use Lizhichao\Word\VicWord;

class Other extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new SourceModel();
        $this->ApiListModel = new ApiListModel();
    }

    /**
     * 全网搜索 该接口用户网页端使用
     * 
     * @return void
     */
    public function web_search()
    {
        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 防止 Nginx 缓冲

        $title = input('title', '');

        // 被屏蔽的关键词，用逗号分隔
        $banKeywords = explode(',', Config('qfshop.ban_keywords'));
        // 检查$name是否包含屏蔽关键词
        $blocked = false;
        foreach ($banKeywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && mb_strpos($title, $keyword) !== false) {
                $blocked = true;
                break;
            }
        }

        if (empty($title) || $blocked) {
            echo "data: [DONE] 无搜索词\n\n";
            ob_flush();
            flush();
            exit;
        }
        $is_type = input('is_type', 0); //0夸克 1阿里云盘 2百度 3Uc 4迅雷
        $is_show = input('is_show', 0); //0加密网址  1显示网址

        // 记录搜索统计到 qf_feedback 表
        if (!empty($title)) {
            $panTypeMap = config('pan_types');
            $panTypeName = $panTypeMap[$is_type]['name'] ?? '未知';
            
            $FeedbackModel = new \app\model\Feedback();
            $FeedbackModel->save([
                'content' => '[' . $panTypeName . '] ' . $title,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        $logMsg = "[web_search] is_type=$is_type, title=$title";
        Log::info($logMsg);
        // 确保日志目录存在
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $logMsg . "\n", FILE_APPEND);

        // 查找一条可用线路
        $lines = $this->ApiListModel->where('status', 1)->where('pantype', $is_type)->order('weight desc')->select()->toArray();

        Log::info("[web_search] Found " . count($lines) . " lines for pantype=$is_type");

        // 获取自定义线路并合并到线路列表前面
        $lines = array_merge($this->getCustomLines(), $lines);

        if (!$lines || count($lines) == 0) {
            Log::error("[web_search] No available lines for pantype=$is_type");
            echo "data: [DONE] 暂无可用线路\n\n";
            ob_flush();
            flush();
            exit;
        }

        // 定义输出单条数据的回调函数
        // 仅校验前 N 条结果（提升搜索速度），其余直接输出
        $verifiedCount = 0;
        $maxVerify = 6;
        $outputCallback = function($item, $lineName) use ($is_show, $logFile, &$verifiedCount, $maxVerify) {
            $item['is_type'] = determineIsType($item['url']);
            $logMsg = date('Y-m-d H:i:s') . " [web_search] Processing item: title=" . $item['title'] . ", url=" . substr($item['url'], 0, 50) . "..., is_type=" . $item['is_type'] . "\n";
            file_put_contents($logFile, $logMsg, FILE_APPEND);
            
            if (Config('qfshop.is_quan_zc') == 1 && $verifiedCount < $maxVerify) {
                $verifiedCount++;
                //检测是否有效 - 支持夸克和阿里网盘
                $infoData = $this->verificationUrl($item['url'], $item['is_type']);
                if (!empty($infoData['stoken'])) {
                    $item['stoken'] = $infoData['stoken'];
                }
                if ($infoData === 0) {
                    $logMsg = date('Y-m-d H:i:s') . " [web_search] Item verification failed but still outputting: " . $item['url'] . "\n";
                    file_put_contents($logFile, $logMsg, FILE_APPEND);
                    // 测试模式下，即使验证失败也输出数据
                    $item['verification_status'] = 'failed';
                } else {
                    $item['verification_status'] = 'success';
                }
            }
            // 超过校验上限的条目直接输出（点击获取资源时仍会实时校验）
            if ($verifiedCount >= $maxVerify && empty($item['verification_status'])) {
                $item['verification_status'] = 'fast';
            }
            if (config('qfshop.is_quan_type') != 1 && $is_show != 1 && $this->hasPanAccount($item['is_type'])) {
                $item['url'] = encryptObject($item['url']);
            }
            echo "data: " . str_replace(["\n", "\r"], '', json_encode($item, JSON_UNESCAPED_UNICODE)) . "\n\n";
            ob_flush();
            flush();
            return 1;
        };

        foreach ($lines as $line) {
            echo "线路：" . $line['name'] . "\n\n";
            $type = $line['type'] ?? 'api';
            $logMsg = date('Y-m-d H:i:s') . " [web_search] Processing line: " . $line['name'] . ", type=$type\n";
            file_put_contents($logFile, $logMsg, FILE_APPEND);
            
            $outputCount = 0;
            if ($type === 'tg') {
                $outputCount = $this->handleTg($line, $title, $outputCallback);
            } else if ($type === 'api') {
                $outputCount = $this->handleApi($line, $title, $outputCallback);
            } else if ($type === 'html') {
                $outputCount = $this->handleWeb($line, $title, $outputCallback);
            } else if ($type === 'kk') {
                $outputCount = $this->handleKk($line, $title, $line['num'], $outputCallback);
            }
            // 兼容空结果返回数组的情况，避免数组转字符串报错中断全网搜
            if (is_array($outputCount)) {
                $outputCount = count($outputCount);
            }
            $outputCount = (int) $outputCount;
            
            $logMsg = date('Y-m-d H:i:s') . " [web_search] Output $outputCount items for line: " . $line['name'] . "\n";
            file_put_contents($logFile, $logMsg, FILE_APPEND);
        }
        echo "data: [DONE]\n\n";
        ob_flush();
        flush();
        exit;
    }

    /**
     * 检查本地资源是否存在
     * 用于首页搜索时优先展示本地资源
     * 
     * @return \think\Response
     */
    public function local_search_check()
    {
        try {
            $title = input('title', '');
            $isType = input('is_type', '');
            
            if (empty($title)) {
                return jok('检查完成', ['hasData' => false, 'count' => 0, 'items' => []]);
            }

            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0]; // 只查询永久资源，不查询临时资源
            
            // 如果指定了网盘类型，添加类型过滤
            if ($isType !== '' && is_numeric($isType)) {
                $map[] = ['is_type', '=', intval($isType)];
            }
            
            // 简单模糊查询
            $map[] = ['title|vod_content', 'like', '%' . trim($title) . '%'];
            $items = $this->model->where($map)
                ->field('source_id, title, vod_content, url, is_type, code, update_time, status')
                ->order('source_id', 'desc')
                ->limit(20)
                ->select()
                ->toArray();

            // 格式化返回数据，与全网搜格式保持一致
            $formattedItems = [];
            $panTypeMap = config('pan_types');
            
            foreach ($items as $item) {
                $updateTime = isset($item['update_time']) && is_numeric($item['update_time']) ? intval($item['update_time']) : time();
                $formattedItems[] = [
                    'title' => $item['title'],
                    'desc' => $item['vod_content'] ?? '',
                    'url' => $item['url'],
                    'is_type' => intval($item['is_type'] ?? 0),
                    'code' => $item['code'] ?? '',
                    'datetime' => date('Y-m-d H:i:s', $updateTime),
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源',
                    'source' => '本地资源'
                ];
            }

            return jok('检查完成', [
                'hasData' => count($formattedItems) > 0,
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('查询失败: ' . $e->getMessage() . ' | 文件: ' . $e->getFile() . ' | 行: ' . $e->getLine());
        }
    }

    /**
     * 获取最近更新的本地资源
     * 
     * @return void
     */
    public function get_latest_sources()
    {
        try {
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];

            $list = $this->model->where($map)
                ->field('source_id as id, title, url, create_time as time, is_type')
                ->order('source_id', 'desc')
                ->limit(9)
                ->select()
                ->toArray();

            $formattedItems = [];
            foreach ($list as $item) {
                // 处理时间字段，可能是时间戳或字符串
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源'
                ];
            }

            return jok('获取成功', [
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取热门搜索资源（按浏览量排序，浏览量为0时按时间排序）
     * 
     * @return void
     */
    public function get_hot_sources()
    {
        try {
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];
            
            // 先尝试获取有浏览量的资源（按浏览量降序）
            $hotList = $this->model->where($map)
                ->where('page_views', '>', 0)
                ->field('source_id as id, title, url, create_time as time, is_type, page_views')
                ->order('page_views', 'desc')
                ->limit(9)
                ->select()
                ->toArray();
            
            $formattedItems = [];
            
            // 如果有浏览量的资源不足9条，补充最新的资源
            if (count($hotList) < 9) {
                // 获取已有资源的ID列表
                $existingIds = array_column($hotList, 'id');
                
                // 补充最新资源
                $additionalMap = $map;
                if (!empty($existingIds)) {
                    $additionalMap[] = ['source_id', 'not in', $existingIds];
                }
                
                $additionalList = $this->model->where($additionalMap)
                    ->field('source_id as id, title, url, create_time as time, is_type, page_views')
                    ->order('source_id', 'desc')
                    ->limit(9 - count($hotList))
                    ->select()
                    ->toArray();
                
                $hotList = array_merge($hotList, $additionalList);
            }
            
            foreach ($hotList as $item) {
                // 处理时间字段
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源',
                    'page_views' => $item['page_views'] ?? 0
                ];
            }
            
            return jok('获取成功', [
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取最近更新的本地资源（分页）
     * 
     * @return void
     */
    public function get_latest_sources_page()
    {
        try {
            $page = input('page', 1);
            $pageSize = input('page_size', 20);
            $panType = input('pan_type', '');
            $categoryId = input('category_id', '');
            
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];
            
            // 如果指定了网盘类型，添加筛选条件
            if ($panType !== '' && is_numeric($panType)) {
                $map[] = ['is_type', '=', intval($panType)];
            }
            
            // 如果指定了分类，添加筛选条件
            if ($categoryId !== '' && is_numeric($categoryId)) {
                $map[] = ['source_category_id', '=', intval($categoryId)];
            }

            // 获取总数
            $total = $this->model->where($map)->count();

            // 获取分页数据
            $list = $this->model->where($map)
                ->field('source_id as id, title, url, create_time as time, is_type, source_category_id')
                ->order('source_id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $formattedItems = [];
            foreach ($list as $item) {
                // 处理时间字段，可能是时间戳或字符串
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源'
                ];
            }

            return jok('获取成功', [
                'total' => $total,
                'page' => intval($page),
                'page_size' => intval($pageSize),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 全网搜索 该接口仅用于机器人和微信对话时使用
     * 
     * @return void
     */
    public function all_search($param = '')
    {
        $title = $param ?: input('title', '');
        if (empty($title)) {
            return jerr("请输入要看的内容");
        }
        $is_type = 0; //0夸克  2百度

        $map[] = ['status', '=', 1];
        $map[] = ['is_delete', '=', 0];
        $map[] = ['is_time', '=', 1];
        $map[] = ['title|description', 'like', '%' . trim($title) . '%'];

        $urls = $this->model->where($map)->field('source_id as id, title, url,is_time')->order('update_time', 'desc')->limit(5)->select()->toArray();
        if (!empty($urls)) {
            $ids = array_column($urls, 'id');
            $this->model->whereIn('source_id', $ids)->update(['update_time' => time()]);
            return !empty($param) ? $urls : jok('临时资源获取成功', $urls);
        }

        //同一个搜索内容锁机
        if (Cache::has($title)) {
            // 检查缓存中是否已有结果
            return !empty($param) ? Cache::get($title) : jok('临时资源获取成功', Cache::get($title));
        }

        // 检查是否有正在处理的请求
        if (Cache::has($title . '_processing')) {
            // 如果当前正在处理相同关键词的请求，等待结果
            $startTime = time(); // 记录开始时间
            while (Cache::has($title . '_processing')) {
                usleep(1000000); // 暂停1秒

                // 检查是否超过60秒
                if (time() - $startTime > 60) {
                    return !empty($param) ? [] : jok('临时资源获取成功', []);
                }
            }
            return !empty($param) ? Cache::get($title) : jok('临时资源获取成功', Cache::get($title));
        }

        // 设置处理状态为正在处理
        Cache::set($title . '_processing', true, 60); // 锁定60秒


        $typeV = input('type', 0);

        $searchList = []; //查询的结果集
        $datas = []; //最终转存后的数据
        $num_total = 2; //最多想要几条转存后的结果
        $num_success = 0;

        $datas_zc = []; //最终未转存的数据
        $num_total_zc = $typeV == 1 ? 3 : 0; //最多想要几条未转存的结果
        $num_success_zc = 0;

        // 查找一条可用线路
        $lines = $this->ApiListModel->where('status', 1)->where('pantype', $is_type)->order('weight desc')->select()->toArray();;

        // 获取自定义线路并合并到线路列表前面
        $lines = array_merge($this->getCustomLines(), $lines);

        if (!$lines || count($lines) == 0) {
            Cache::set($title, $datas, 60); // 缓存结果60秒
            Cache::delete($title . '_processing'); // 解锁
            return !empty($param) ? $datas : jok('临时资源获取成功', $datas);
        }

        foreach ($lines as $line) {
            if ($num_success >= $num_total && $num_success_zc >= $num_total_zc) {
                break;
            }
            $result = [];
            $type = $line['type'] ?? 'api';
            if ($type === 'tg') {
                $result = $this->handleTg($line, $title);
            } else if ($type === 'api') {
                $result = $this->handleApi($line, $title);
            } else if ($type === 'html') {
                $result = $this->handleWeb($line, $title);
            } else if ($type === 'kk') {
                $result = $this->handleKk($line, $title, $line['num']);
            }

            foreach ($result as $item) {
                // 确定网盘类型
                $item['is_type'] = determineIsType($item['url']);
                
                if ($num_success < $num_total) {
                    //检测是否有效 - 支持夸克和阿里网盘
                    $infoData = $this->verificationUrl($item['url'], $item['is_type']);
                    if (!empty($infoData['stoken'])) {
                        $item['stoken'] = $infoData['stoken'];
                    }
                    if ($infoData !== 0) {
                        if (!$this->urlExists($searchList, $item['url'])) {
                            $searchList[] = $item;
                            $this->processUrl($item, $num_success, $datas);
                        }
                    }
                } else if ($num_success_zc < $num_total_zc) {
                    //检测是否有效 - 支持夸克和阿里网盘
                    $infoData = $this->verificationUrl($item['url'], $item['is_type']);
                    if (!empty($infoData['stoken'])) {
                        $item['stoken'] = $infoData['stoken'];
                    }
                    if ($infoData !== 0) {
                        if (!$this->urlExists($searchList, $item['url'])) {
                            $titles = array_column($searchList, 'title');
                            if (!in_array($item['title'], $titles)) {
                                $searchList[] = $item;
                                $datas_zc[] = $item;
                                $num_success_zc++;
                            }
                        }
                    }
                }
            }
        }
        Cache::set($title, $datas, 60); // 缓存结果60秒
        Cache::delete($title . '_processing'); // 解锁

        if ($typeV == 1) {
            $datas = array_merge($datas, $datas_zc);
        }

        return !empty($param) ? $datas : jok('临时资源获取成功', $datas);
    }

    /**
     * 获取自定义线路配置
     * @return array 自定义线路数组
     */
    private function getCustomLines()
    {
        // 自定义线路 - 线路一
        // $customLines = array_map(function ($i) {
        //     return [
        //         'name' => '自定义线路一',
        //         'pantype' => 0,
        //         'type' => 'kk',
        //         'count' => 5,
        //         'num' => $i,
        //     ];
        // }, range(1, 6));

        // 可以在这里添加更多自定义线路
        // 例如：
        /*
        $customLines[] = [
            'name' => '自定义线路二',
            'pantype' => 0,
            'type' => 'GG',
            'count' => 5,
        ];
        */
        return $customLines ?? [];
    }

    /**
     * 接口类型处理
     */
    private function handleApi($line, $title, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        // 从配置文件读取网盘类型
        $panTypes = config('pan_types');
        $panType = [];
        foreach ($panTypes as $config) {
            $panType[$config['id']] = $config['pan_type'] ?? '';
        }

        if (!isset($panType[$type]) || $maxCount <= 0) {
            return [];
        }

        $url     = $line['url'];
        $method  = strtoupper($line['method']);
        $headers = json_decode($line['headers'], true) ?? [];
        $params  = json_decode($line['fixed_params'], true) ?? [];

        // 替换 {keyword}
        foreach ($params as &$val) {
            $val = str_replace('{keyword}', $title, $val);
        }

        // headers 转为 curl 格式
        $headerArr = [];
        foreach ($headers as $k => $v) {
            $headerArr[] = "$k: $v";
        }

        // 确保POST请求有正确的Content-Type
        if ($method === 'POST' && !isset($headers['Content-Type'])) {
            $headerArr[] = "Content-Type: application/x-www-form-urlencoded";
        }

        // 简化参数处理
        $queryParams = $method === 'GET' ? $params : [];

        // 处理POST数据
        if ($method === 'POST' && !empty($params)) {
            $postData = http_build_query($params);
            $result = curlHelper($url, $method, $postData, $headerArr, $queryParams, "", 15);
        } else {
            $result = curlHelper($url, $method, $method === 'GET' ? null : $params, $headerArr, $queryParams, "", 15);
        }

        $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        
        if (empty($result['body'])) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] Empty response body from: " . $url . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
            return [];
        }

        $fieldMap = json_decode($line['field_map'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] field_map JSON decode error: " . json_last_error_msg() . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }

        $response = json_decode($result['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] Response JSON decode error: " . json_last_error_msg() . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
            return [];
        }

        // 调试日志：记录响应结构
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] type=$type, Response keys: " . json_encode(array_keys($response)) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] field_map: " . json_encode($fieldMap) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Response body preview: " . substr($result['body'], 0, 500) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);

        $results = $this->extractList($response, $fieldMap, $type, $logFile);
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Extracted " . count($results) . " results, maxCount=$maxCount\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $returnResults = array_slice($results, 0, $maxCount);
        
        // 如果提供了回调函数，逐条输出
        if ($outputCallback && is_callable($outputCallback)) {
            $outputCount = 0;
            foreach ($returnResults as $item) {
                $outputCount += $outputCallback($item, $line['name'] ?? 'API线路');
            }
            return $outputCount;
        }
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Returning " . count($returnResults) . " results\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);

        return $returnResults;
    }

    /**
     * 提取字段
     */
    protected function extractList($response, $fieldMap, $type, $logFile = '')
    {
        if (empty($logFile)) {
            $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        }
        
        // 调试日志
        if (empty($fieldMap['list_path'])) {
            $errMsg = date('Y-m-d H:i:s') . " [extractList] field_map.list_path is empty\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }
        if (empty($fieldMap['fields'])) {
            $errMsg = date('Y-m-d H:i:s') . " [extractList] field_map.fields is empty\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }
        
        $listPath = explode('.', $fieldMap['list_path'] ?? '');
        $listData = $response;
        foreach ($listPath as $key) {
            if (empty($key)) continue; // 跳过空键
            if (isset($listData[$key])) {
                $listData = $listData[$key];
            } else {
                $errMsg = date('Y-m-d H:i:s') . " [extractList] list_path key not found: " . $key . ", available keys: " . json_encode(array_keys($listData)) . "\n";
                file_put_contents($logFile, $errMsg, FILE_APPEND);
                return [];
            }
        }

        $fields = $fieldMap['fields'] ?? [];
        $result = [];
        foreach ($listData as $item) {
            $row = [];
            foreach ($fields as $targetKey => $sourcePath) {
                $value = $item;
                foreach (explode('.', $sourcePath) as $p) {
                    $value = $value[$p] ?? null;
                }
                $row[$targetKey] = $value;

                if ($targetKey == 'url') {
                    // 将任何类型的值转换为字符串
                    $stringValue = '';

                    if (is_array($value)) {
                        // 原始数组转字符串
                        $stringValue = json_encode($value, JSON_UNESCAPED_UNICODE);
                        // JSON 中链接中的 / 会变成 \/，替换回来
                        $stringValue = str_replace('\/', '/', $stringValue);
                    } else {
                        $stringValue = (string)$value;
                    }

                    $urlPattern = getPanUrlPattern($type);
                    $logMsg = date('Y-m-d H:i:s') . " [extractList] type=$type, urlPattern=$urlPattern, stringValue=" . substr($stringValue, 0, 200) . "\n";
                    file_put_contents($logFile, $logMsg, FILE_APPEND);

                    if (!empty($urlPattern) && preg_match($urlPattern, $stringValue, $urlMatch)) {
                        $row['url'] = trim($urlMatch[0]);
                        $logMsg = date('Y-m-d H:i:s') . " [extractList] URL matched: " . $row['url'] . "\n";
                        file_put_contents($logFile, $logMsg, FILE_APPEND);

                        if (in_array($type, [2, 4])) {
                            if (!strpos($row['url'], '?pwd=') && preg_match('/["\'](pwd|code)["\']\s*:\s*["\']([^"\']+)["\']/', $stringValue, $pwdMatches)) {
                                $row['url'] .= '?pwd=' . $pwdMatches[2];
                            }
                        } else if ($type == 5) {
                            if (!strpos($row['url'], '?提取码:') && preg_match('/["\']?提取码["\']?\s*[:：]\s*["\']?([a-zA-Z0-9]+)["\']?/', $stringValue, $pwdMatches)) {
                                $row['url'] .= '?提取码:' . $pwdMatches[1];
                            }
                        }
                    } else {
                        $logMsg = date('Y-m-d H:i:s') . " [extractList] URL not matched for type=$type\n";
                        file_put_contents($logFile, $logMsg, FILE_APPEND);
                        $row['url'] = '';
                    }
                }
            }
            if (!empty($row['url'])) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * TG频道类型处理
     */
    private function handleTg($line, $title, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        // 根据类型选择搜索参数
        $panType = [
            0 => 'quark',   // 夸克
            1 => 'alipan',  // 阿里云盘
            2 => 'baidu',    // 百度
            3 => 'uc',       // UC
            4 => 'xunlei',   // 迅雷
            5 => 'pan123',   // 123网盘
            6 => 'pan115',   // 115网盘
        ];

        if (!isset($panType[$type]) || $maxCount <= 0) {
            return $outputCallback ? 0 : [];
        }

        $results = [];
        $outputCount = 0;
        $url = 'https://t.me/s/' . $line['url'] . '?q=' . urlencode($title);
        $dom = getDom($url);
        $finder = new \DomXPath($dom);

        $nodes = $finder->query('//div[contains(@class, "tgme_widget_message_text")]');

        foreach ($nodes as $node) {
            // 获取 HTML 内容
            $htmlContent = $dom->saveHTML($node);

            if (preg_match('/名称：(.+?)<br/i', $htmlContent, $titleMatch)) {
                $parsedItem['title'] = trim(html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES, 'UTF-8'));
            } else {
                $parsedItem['title'] = $title;
            }

            // 提取夸克链接（可支持百度扩展）
            $parsedItem['url'] = '';
            $urlPattern = getPanUrlPattern($type);
            if (!empty($urlPattern) && preg_match($urlPattern, $htmlContent, $urlMatch)) {
                $parsedItem['url'] = trim($urlMatch[0]);
            }

            // 过滤不合法或无效链接
            if ($parsedItem['title'] && $parsedItem['url']) {
                if ($outputCallback && is_callable($outputCallback)) {
                    $outputCount += $outputCallback($parsedItem, $line['name'] ?? 'TG线路');
                } else {
                    $results[] = $parsedItem;
                }
            }

            if ($outputCallback) {
                if ($outputCount >= $maxCount) {
                    return $outputCount;
                }
            } else {
                if (count($results) >= $maxCount) {
                    return $results;
                }
            }
        }
        return $outputCallback ? $outputCount : $results;
    }


    /**
     * 网页类型处理
     */
    private function handleWeb($line, $title, $outputCallback = null)
    {
        $results = [];
        $outputCount = 0;

        // 替换搜索关键词并获取配置参数
        $url = str_replace('{keyword}', urlencode($title), $line['url']);

        $parts = explode('+', $line['html_item'], 2);
        $tag = $parts[0] ?? '';
        $classString = $parts[1] ?? '';

        $partsTitle = explode('+', $line['html_title'], 2);
        $tagTitle = $partsTitle[0] ?? '';
        $classStringTitle = $partsTitle[1] ?? '';

        $partsUrl = explode('+', $line['html_url2'], 2);
        $tagUrl = $partsUrl[0] ?? '';
        $classStringUrl = $partsUrl[1] ?? '';

        $maxCount = $line['count'] ?? 10;
        $type = $line['pantype'];

        // 定义网盘链接匹配规则
        $panPatterns = [
            0 => '/https:\/\/pan\.quark\.cn\/s\/[a-zA-Z0-9]+/', // 夸克
            1 => '/https:\/\/(www\.alipan\.com|www\.aliyundrive\.com)\/s\/[a-zA-Z0-9]+/', // 阿里云盘
            2 => '/https:\/\/pan\.baidu\.com\/s\/[a-zA-Z0-9_-]+/', // 百度
            3 => '/https:\/\/drive\.uc\.cn\/s\/[a-zA-Z0-9]+/', // UC
            4 => '/https:\/\/pan\.xunlei\.com\/s\/[a-zA-Z0-9_-]+/', // 迅雷
            5 => '/https:\/\/123\d{3}\.com\/s\/[a-zA-Z0-9-]+/', // 123网盘
        ];

        // 获取DOM并设置XPath查询
        $dom = getDom($url);
        if (!$dom) {
            return $results;
        }

        $finder = new \DomXPath($dom);
        $xpath = $this->buildXPathQuery($tag, $classString);
        $nodes = $finder->query($xpath);

        foreach ($nodes as $node) {
            if ($outputCallback) {
                if ($outputCount >= $maxCount) {
                    break;
                }
            } else {
                if (count($results) >= $maxCount) {
                    break;
                }
            }

            $html = $dom->saveHTML($node);
            $item = [
                'title' => '',
                'url'   => '',
            ];

            // 提取资源标题
            $item['title'] = $this->extractTitle($html, $tagTitle, $classStringTitle);

            // 尝试直接从当前HTML中提取网盘链接
            if (preg_match($panPatterns[$type], $html, $match)) {
                $item['url'] = trim($match[0]);
            } else {
                // 根据配置决定是否需要进入详情页
                if ($line['html_type'] == 1) {
                    $item['url'] = $this->extractUrlFromDetailPage($html, $line, $url, $tagUrl, $classStringUrl, $panPatterns[$type]);
                } else {
                    $item['url'] = $this->extractUrlFromListPage($html, $tagUrl, $classStringUrl, $panPatterns[$type]);
                }
            }

            // 只添加同时有标题和URL的结果
            if ($item['title'] && $item['url']) {
                if ($outputCallback && is_callable($outputCallback)) {
                    $outputCount += $outputCallback($item, $line['name'] ?? '网页线路');
                } else {
                    $results[] = $item;
                }
            }
        }

        return $outputCallback ? $outputCount : $results;
    }

    /**
     * 构建XPath查询语句
     * 
     * @param string $tag 标签名
     * @param string $classString 类名字符串
     * @return string XPath查询语句
     */
    private function buildXPathQuery($tag, $classString)
    {
        $classArray = explode(' ', trim($classString));
        $xpathConditions = [];
        foreach ($classArray as $cls) {
            if (!empty($cls)) {
                $xpathConditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
            }
        }

        return "//{$tag}" . (empty($xpathConditions) ? "" : "[" . implode(' and ', $xpathConditions) . "]");
    }

    /**
     * 从HTML中提取标题
     * 
     * @param string $html HTML内容
     * @param string $tagTitle 标题标签
     * @param string $classStringTitle 标题类名
     * @return string 提取的标题
     */
    private function extractTitle($html, $tagTitle, $classStringTitle)
    {
        // 尝试匹配"名称：xxx 描述："格式
        if (preg_match('/名称：(.*?)\n\n描述：/s', $html, $match)) {
            return trim(strip_tags($match[1]));
        }

        // 尝试根据标签和类名匹配
        $escapedClass = preg_quote($classStringTitle, '#');
        $escapedTag = preg_quote($tagTitle, '#');
        $pattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

        if (preg_match($pattern, $html, $titleMatch)) {
            return trim(strip_tags($titleMatch[1]));
        }

        return '';
    }

    /**
     * 从详情页提取URL
     * 
     * @param string $html 列表页HTML
     * @param array $line 配置信息
     * @param string $baseUrl 基础URL
     * @param string $tagUrl URL标签
     * @param string $classStringUrl URL类名
     * @param string $panPattern 网盘链接匹配模式
     * @return string 提取的URL
     */
    private function extractUrlFromDetailPage($html, $line, $baseUrl, $tagUrl, $classStringUrl, $panPattern)
    {
        list($tagD, $classStringD) = explode('+', $line['html_url'], 2);

        // 构建匹配详情页链接的正则表达式
        $detailUrlPattern = $this->buildHrefPattern($tagD, $classStringD);

        if (!preg_match($detailUrlPattern, $html, $match)) {
            return '';
        }

        // 处理相对URL
        $detailUrl = trim($match[1]);
        $fullDetailUrl = $this->buildFullUrl($detailUrl, $baseUrl);

        // 获取详情页内容
        $dom2 = getDom($fullDetailUrl);
        if (!$dom2) {
            return '';
        }

        $finder2 = new \DomXPath($dom2);
        $xpath2 = $this->buildXPathQuery($tagUrl, $classStringUrl);
        $nodes2 = $finder2->query($xpath2);

        // 遍历详情页节点查找网盘链接
        foreach ($nodes2 as $node2) {
            $html2 = $dom2->saveHTML($node2);

            // 尝试从内容中提取
            $escapedClass = preg_quote($classStringUrl, '#');
            $escapedTag = preg_quote($tagUrl, '#');
            $contentPattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

            if (preg_match($contentPattern, $html2, $titleMatch)) {
                $extractedUrl = trim(strip_tags($titleMatch[1]));
                if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                    return trim($urlMatch[0]);
                }
            }

            // 尝试从href属性中提取
            $hrefPattern = $this->buildHrefPattern($tagUrl, $classStringUrl);
            if (preg_match($hrefPattern, $html2, $match)) {
                $extractedUrl = trim($match[1]);
                if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                    return trim($urlMatch[0]);
                }
            }
        }

        return '';
    }

    /**
     * 从列表页直接提取URL
     * 
     * @param string $html HTML内容
     * @param string $tagUrl URL标签
     * @param string $classStringUrl URL类名
     * @param string $panPattern 网盘链接匹配模式
     * @return string 提取的URL
     */
    private function extractUrlFromListPage($html, $tagUrl, $classStringUrl, $panPattern)
    {
        // 尝试从内容中提取
        $escapedClass = preg_quote($classStringUrl, '#');
        $escapedTag = preg_quote($tagUrl, '#');
        $contentPattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

        if (preg_match($contentPattern, $html, $titleMatch)) {
            $extractedUrl = trim(strip_tags($titleMatch[1]));
            if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                return trim($urlMatch[0]);
            }
        }

        // 尝试从href属性中提取
        $hrefPattern = $this->buildHrefPattern($tagUrl, $classStringUrl);
        if (preg_match($hrefPattern, $html, $match)) {
            $extractedUrl = trim($match[1]);
            if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                return trim($urlMatch[0]);
            }
        }

        return '';
    }

    /**
     * 构建 href 属性匹配模式，支持 class 和 href 顺序不固定
     *
     * @param string $tag 标签名
     * @param string $classString 类名（可为空）
     * @return string 正则表达式
     */
    private function buildHrefPattern($tag, $classString)
    {
        $escapedClass = preg_quote($classString, '#');
        $escapedTag = preg_quote($tag, '#');

        if (empty($escapedClass)) {
            // 没有类名要求，只匹配标签中包含 href 的内容
            return '#<' . $escapedTag . '\b[^>]*href=["\']([^"\']+)["\'][^>]*>#i';
        } else {
            // 匹配包含指定 class 的标签，不要求 href 和 class 的顺序
            return '#<' . $escapedTag . '\b(?=[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'])(?=[^>]*href=["\']([^"\']+)["\'])[^>]*>#i';
        }
    }


    /**
     * 构建完整URL
     * 
     * @param string $url 可能是相对URL
     * @param string $baseUrl 基础URL
     * @return string 完整URL
     */
    private function buildFullUrl($url, $baseUrl)
    {
        if (strpos($url, 'http') !== 0) {
            $parsed = parse_url($baseUrl);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            return $base . $url;
        }
        return $url;
    }

    /**
     * 自定义接口
     */
    private function handleKk($line, $title, $apiType = 0, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        $url2 = [];
        $outputCount = 0;
        $urlDefault = "https://m.kkkba.com";

        // 网盘链接匹配正则
        $pattern = [
            0 => '/https:\/\/pan\.quark\.cn\/[^\s]+/',   // 夸克
            2 => '/https:\/\/pan\.baidu\.com\/[^\s]+/',  // 百度
        ];

        if (!isset($pattern[$type])) {
            return $outputCallback ? 0 : [];
        }

        try {
            $res = curlHelper($urlDefault . "/v/api/getToken", "GET", null, [], "", "", 5)['body'] ?? null;
            if (!$res) return $url2;
        } catch (Exception $err) {
            return $url2;
        }

        $res = json_decode($res, true);
        $token = $res['token'] ?? '';
        if (empty($token)) {
            return $url2;
        }

        // 所有接口列表
        $allApiList = [
            1 => "/v/api/getJuzi",
            2 => "/v/api/search",
            // 3 => "/v/api/getXiaoyu",
            // 4 => "/v/api/getDJ",
            // 5 => "/v/api/getKK"
        ];

        // 根据 apiType 确定要调用的接口列表
        if ($apiType == 0) {
            // 全部接口
            $apiList = array_values($allApiList);
        } elseif (isset($allApiList[$apiType])) {
            // 指定某个接口
            $apiList = [$allApiList[$apiType]];
        } else {
            // 错误类型，直接返回空
            return $outputCallback ? 0 : [];
        }

        // 请求头
        $urlData = array(
            'name' => $title,
            'token' => $token
        );
        $headers = ['Content-Type: application/json'];

        foreach ($apiList as $apiUrl) {
            try {
                $response = curlHelper($urlDefault . $apiUrl, "POST", json_encode($urlData), $headers, "", "", 5);
                $res = isset($response['body']) ? json_decode($response['body'], true) : null;
            } catch (Exception $err) {
                continue;
            }

            if (empty($res['list']) || !is_array($res['list'])) {
                continue;
            }
            foreach ($res['list'] as $value) {
                if (preg_match($pattern[$type], $value['answer'], $matches)) {
                    $link = $matches[0];
                    if (preg_match('/提取码[:：]?\s*([a-zA-Z0-9]{4})/', $value['answer'], $codeMatch)) {
                        $link .= '?pwd=' . $codeMatch[1];
                    }
                    $titleText = preg_replace('/\s*[\(（]?(夸克|百度)?[\)）]?\s*/u', '', $value['answer'] ?? '');
                    $item = [
                        'title' => $titleText,
                        'url' => $link
                    ];
                    if ($outputCallback && is_callable($outputCallback)) {
                        $outputCount += $outputCallback($item, $line['name'] ?? 'KK线路');
                        if ($outputCount >= $maxCount) {
                            return $outputCount;
                        }
                    } else {
                        $url2[] = $item;
                        if (count($url2) >= $maxCount) {
                            return $url2;
                        }
                    }
                }
            }
        }

        return $outputCallback ? $outputCount : $url2;
    }

    /**
     * 验证网盘地址是否有效
     * @param string $url 网盘分享链接
     * @param int $isType 网盘类型 0夸克 1阿里 2百度 3UC 4迅雷
     * @return array|int
     */
    private function verificationUrl($url, $isType = 0)
    {
        // 磁力链接直接返回，不需要验证
        if (preg_match('/^magnet:\?xt=urn:btih:/i', $url)) {
            return ['url' => $url];
        }

        $code = '';
        if (preg_match('/\?pwd=([^,\s&]+)/', $url, $pwdMatch)) {
            $code = trim($pwdMatch[1]);
        }
        $urlData = [
            'url' => $url,
            'code' => $code,
            'isType' => 1  // 1表示只获取资源信息，不转存
        ];

        $transfer = new \netdisk\Transfer();
        $res = $transfer->transfer($urlData);

        if ($res['code'] !== 200) {
            return 0;
        }

        return $res['data'];
    }

    /**
     * 解密url并转存
     * @return void
     */
    public function save_url()
    {
        $rawUrl = input('url', '');
        $decodedUrl = rawurldecode($rawUrl);
        
        // 调试日志
        error_log('save_url - rawUrl: ' . $rawUrl);
        error_log('save_url - decodedUrl: ' . $decodedUrl);
        
        $value = [
            'title'  => input('title', ''),
            'url'    => $decodedUrl,
            'stoken' => input('stoken', ''),
        ];
        $decrypted = decryptObject($value['url']);
        
        // 调试日志
        error_log('save_url - decrypted: ' . json_encode($decrypted));
        
        // 如果解密结果是数组，取其中的 url 字段
        if (is_array($decrypted) && isset($decrypted['url'])) {
            $value['url'] = $decrypted['url'];
        } else {
            $value['url'] = $decrypted;
        }

        if (empty($value['url'])) {
            error_log('save_url - 参数不对: url为空');
            return jerr("参数不对");
        }
        
        // 如果只是解密链接（title为空），直接返回解密后的链接
        if (empty($value['title'])) {
            error_log('save_url - 直接返回解密链接: ' . $value['url']);
            return jok('链接解密成功', ['url' => $value['url']]);
        }

        $map[] = ['status', '=', 1];
        $map[] = ['is_delete', '=', 0];
        $map[] = ['is_time', '=', 1];
        $map[] = ['content', '=', $value['url']];

        $url = $this->model->where($map)->field('source_id as id, title, url')->find();
        if (!empty($url)) {
            $this->model->where('source_id', $url['id'])->update(['update_time' => time()]);
            unset($url['id']);
            return jok('临时资源获取成功', $url);
        }

        //同一个搜索内容锁机
        $keys = $value['url'] . 'ACAA';
        if (Cache::has($keys)) {
            // 检查缓存中是否已有结果
            return jok('临时资源获取成功', Cache::get($keys));
        }

        // 检查是否有正在处理的请求
        if (Cache::has($keys . '_processing')) {
            // 如果当前正在处理相同关键词的请求，等待结果
            $startTime = time(); // 记录开始时间
            while (Cache::has($keys . '_processing')) {
                usleep(1000000); // 暂停1秒

            // 检查是否超过20秒
            if (time() - $startTime > 20) {
                return jok('临时资源获取成功', []);
            }
            }
            return jok('临时资源获取成功', Cache::get($keys));
        }

        // 设置处理状态为正在处理
        Cache::set($keys . '_processing', true, 20); // 锁定20秒

        $datas = [];
        $num_total = 1;
        $num_success = 0;
        $res = $this->processUrl($value, $num_success, $datas, true);

        Cache::delete($keys . '_processing'); // 解锁

        if ($res['code'] !== 200) {
            return jerr($res['message']);
        } else {
            $result['title'] = $res['data']['title'];
            $result['url'] = $res['data']['url'];
            Cache::set($keys, $result, 1800); // 缓存结果30分钟
            return jok('临时资源获取成功', $result);
        }
    }

    /**
     * 获取资源文件列表（夸克/UC/迅雷/百度/阿里全支持，30分钟缓存）
     *
     * @return void
     */
    public function file_list()
    {
        $rawUrl = input('url', '');
        $decoded = decryptObject(rawurldecode($rawUrl));
        $url = is_array($decoded) ? ($decoded['url'] ?? '') : $decoded;
        if (empty($url)) {
            return jerr('参数错误');
        }

        $stoken = trim((string) input('stoken', ''));
        $code = trim((string) input('code', ''));
        if (preg_match('/\?pwd=([^&\s]+)/', $url, $m)) {
            $code = $m[1];
            $url = preg_replace('/\?pwd=.*$/', '', $url);
        }

        $isType = determineIsType($url);
        $pwd_id = '';
        if (preg_match('#/s/([a-zA-Z0-9]+)#', $url, $m)) {
            $pwd_id = $m[1];
        }
        if (empty($pwd_id)) {
            return jerr('链接格式有误');
        }

        $cacheKey = 'file_list_v3_' . md5($url . '|' . $stoken . '|' . $code . '|' . $isType);
        if (Cache::has($cacheKey)) {
            return jok('获取成功', Cache::get($cacheKey));
        }

        $files = [];
        $totalSize = 0;
        $title = '';

        try {
            if ($isType == 0) {
                // 夸克
                if (empty($stoken)) {
                    $transfer = new \netdisk\Transfer();
                    $res = $transfer->transfer(['url' => $url, 'code' => $code, 'isType' => 1]);
                    if (($res['code'] ?? 0) !== 200) {
                        return jerr($res['message'] ?? '获取资源信息失败');
                    }
                    $stoken = str_replace(' ', '+', (string) ($res['data']['stoken'] ?? ''));
                }
                if (empty($stoken)) {
                    return jerr('获取资源信息失败');
                }
                $pan = new \netdisk\pan\QuarkPan(['url' => $url, 'code' => $code, 'stoken' => $stoken, 'isType' => 1]);
                $share = $pan->getShare($pwd_id, $stoken);
                if (($share['status'] ?? 0) !== 200) {
                    return jerr($share['message'] ?? '获取文件列表失败');
                }
                $title = $share['data']['share']['title'] ?? '';
                $count = 0;
                $files = $this->walkShareTree($pan, $pwd_id, $stoken, 0, 0, $count, $totalSize);
                $totalItems = $count;
            } elseif ($isType == 3) {
                // UC
                if (empty($stoken)) {
                    $transfer = new \netdisk\Transfer();
                    $res = $transfer->transfer(['url' => $url, 'code' => $code, 'isType' => 1]);
                    if (($res['code'] ?? 0) !== 200) {
                        return jerr($res['message'] ?? '获取资源信息失败');
                    }
                    $stoken = str_replace(' ', '+', (string) ($res['data']['stoken'] ?? ''));
                }
                if (empty($stoken)) {
                    return jerr('获取资源信息失败');
                }
                $pan = new \netdisk\pan\UcPan(['url' => $url, 'code' => $code, 'stoken' => $stoken, 'isType' => 1]);
                $count = 0;
                $files = $this->walkShareTree($pan, $pwd_id, $stoken, 0, 0, $count, $totalSize);
                $totalItems = $count;
            } elseif ($isType == 4) {
                // 迅雷
                $pan = new \netdisk\pan\XunleiPan(['url' => $url, 'code' => $code, 'isType' => 1]);
                $share = $pan->getShare($pwd_id, $code);
                if (($share['code'] ?? 0) !== 200) {
                    return jerr($share['message'] ?? '获取文件列表失败');
                }
                $title = $share['data']['title'] ?? '';
                $list = $share['data']['file_list'] ?? $share['data']['list'] ?? [];
                foreach ($list as $f) {
                    $size = intval($f['file_size'] ?? $f['size'] ?? 0);
                    $totalSize += $size;
                    $files[] = [
                        'name' => $f['file_name'] ?? $f['name'] ?? '',
                        'size_text' => $size > 0 ? formatBytes($size) : '',
                        'is_dir' => !empty($f['is_dir']) || !empty($f['dir']),
                        'children' => [],
                    ];
                }
            } elseif ($isType == 2) {
                // 百度（返回文件名与目录标识，大小需登录后获取）
                $cookie = Config('qfshop.baidu_cookie');
                $network = new \netdisk\pan\BaiduWork($cookie);
                $bdstoken = $network->getBdstoken();
                if (!is_numeric($bdstoken)) {
                    $network->setBdstoken($bdstoken);
                    if (!empty($code)) {
                        $randsk = $network->verifyPassCode($url, $code);
                        if (!is_numeric($randsk)) {
                            $network->updateBdclnd($randsk);
                        }
                    }
                    $transferParams = $network->getTransferParams($url);
                    if (!is_numeric($transferParams) && !isset($transferParams['error'])) {
                        list($shareId, $userId, $fsIds, $fileNames, $isDirs) = $transferParams;
                        $title = $fileNames[0] ?? '';
                        foreach ($fileNames as $i => $name) {
                            $files[] = [
                                'name' => $name,
                                'size_text' => '',
                                'is_dir' => !empty($isDirs[$i]),
                                'children' => [],
                            ];
                        }
                    }
                }
            } elseif ($isType == 1) {
                // 阿里云盘
                $pan = new \netdisk\pan\AlipanPan(['url' => $url, 'code' => $code, 'isType' => 1]);
                $infos = $pan->getAlipan1($pwd_id);
                $title = $infos['share_name'] ?? '';
                foreach (($infos['file_infos'] ?? []) as $f) {
                    $size = intval($f['file_size'] ?? 0);
                    $totalSize += $size;
                    $files[] = [
                        'name' => $f['file_name'] ?? '',
                        'size_text' => $size > 0 ? formatBytes($size) : '',
                        'is_dir' => false,
                        'children' => [],
                    ];
                }
            } else {
                return jerr('暂不支持该网盘类型的文件列表');
            }
        } catch (\Throwable $e) {
            return jerr('获取文件列表失败：' . $e->getMessage());
        }

        $result = [
            'title' => $title,
            'files' => $files,
            'total' => $totalItems ?? count($files),
            'total_size_text' => $totalSize > 0 ? formatBytes($totalSize) : '',
        ];
        Cache::set($cacheKey, $result, 1800);
        return jok('获取成功', $result);
    }

    /**
     * 递归展开分享内的所有目录（夸克/UC）
     *
     * @param object $pan       网盘实例
     * @param string $pwd_id    分享ID
     * @param string $token     stoken
     * @param string $dirFid    当前目录fid
     * @param string $prefix    相对路径前缀
     * @param int    $depth     当前深度
     * @param array  $files     文件结果（引用）
     * @param int    $totalSize 总大小（引用）
     * @return void
     */
    protected function walkShareTree($pan, $pwd_id, $token, $dirFid, $depth, &$count, &$totalSize)
    {
        $result = [];
        if ($depth > 5 || $count > 300) {
            return $result;
        }
        $share = $pan->getShare($pwd_id, $token, $dirFid);
        $list = $share['data']['list'] ?? $share['list'] ?? [];
        foreach ($list as $f) {
            $name = $f['file_name'] ?? '';
            if ($name === '') {
                continue;
            }
            $count++;
            if (!empty($f['dir'])) {
                $children = $this->walkShareTree($pan, $pwd_id, $token, $f['fid'] ?? 0, $depth + 1, $count, $totalSize);
                $result[] = [
                    'name' => $name,
                    'size_text' => '',
                    'is_dir' => true,
                    'children' => $children,
                ];
            } else {
                $size = intval($f['size'] ?? 0);
                $totalSize += $size;
                $result[] = [
                    'name' => $name,
                    'size_text' => $size > 0 ? formatBytes($size) : '',
                    'is_dir' => false,
                    'children' => [],
                ];
            }
        }
        return $result;
    }

    // 检查 URL 是否已存在（忽略查询参数）
    public function urlExists($searchList, $urlToCheck)
    {
        // 解析待检查的 URL
        $parsedUrlToCheck = parse_url($urlToCheck);

        foreach ($searchList as $item) {
            $parsedUrl = parse_url($item['url']);

            // 比较 scheme, host 和 path
            if (
                $parsedUrlToCheck['scheme'] === $parsedUrl['scheme'] &&
                $parsedUrlToCheck['host'] === $parsedUrl['host'] &&
                $parsedUrlToCheck['path'] === $parsedUrl['path']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 临时资源转存
     * 
     * @return void
     */
    public function processUrl($value, &$num_success, &$datas, $type = false)
    {
        // 检查是否为磁力链接
        if (preg_match('/^magnet:\?xt=urn:btih:/i', $value['url'])) {
            // 磁力链接直接处理，不需要转存
            $patterns = '/^\d+\./';
            $title = preg_replace($patterns, '', $value['title']);
            
            // 添加资源到系统中
            $data["title"] = $title;
            $data["url"] = $value['url'];
            $data["is_type"] = 9; // 磁力链接类型
            $data["content"] = $value['url'];
            $data["fid"] = '';
            $data["is_time"] = 1;
            $data["update_time"] = time();
            $data["create_time"] = time();
            $data["id"] = $this->model->insertGetId($data);
            $datas[] = $data;
            $num_success++;

            if ($type) {
                return jok2('转存成功', ['title' => $title, 'url' => $value['url'], 'fid' => '']);
            } else {
                return;
            }
        }

        // 网盘链接处理
        $substring = strstr($value['url'], 's/');
        if ($substring === false) {
            if ($type) {
                return jerr2("资源地址格式有误");
            } else {
                return; // 模拟 continue 行为
            }
        }

        $code = '';
        if (preg_match('/\?pwd=([^,\s&]+)/', $value['url'], $pwdMatch)) {
            $code = trim($pwdMatch[1]);
        }

        $urlData = array(
            'url' => $value['url'],
            'code' => $code,
            'expired_type' => 2,
            'ad_fid' => '', //分享时带上这个文件
        );

        $transfer = new \netdisk\Transfer();
        $res = $transfer->transfer($urlData);

        if ($res['code'] !== 200) {
            if ($type) {
                return jerr2($res['message']);
            } else {
                return; // 模拟 continue 行为
            }
        }

        $patterns = '/^\d+\./';
        $title = preg_replace($patterns, '', $value['title']);
        // 添加资源到系统中
        $data["title"] = $title;
        $data["url"] = $res['data']['share_url'];
        $data["is_type"] = determineIsType($data["url"]);
        $data["content"] = $value['url'];
        $dataFid = $res['data']['fid'] ?? '';
        $data["fid"] = is_array($dataFid) ? json_encode($dataFid) : $dataFid;
        $data["is_time"] = 1;
        $data["update_time"] = time();
        $data["create_time"] = time();
        $data["id"] = $this->model->insertGetId($data);
        $datas[] = $data;
        $num_success++;

        if ($type) {
            return jok2('转存成功', $data);
        }
    }


    /**
     * 30分钟后清除临时资源
     * 
     * @return void
     */
    public function delete_search()
    {
        // 搜索条件
        $map[] = ['is_time', '=', 1];
        $map[] = ['update_time', '<=', time() - (30 * 60)];
        $abc = $this->model->where($map)->select();


        $this->model->where($map)->chunk(100, function ($order) {
            foreach ($order as $value) {
                $deles = $value->toArray();

                $fid = $deles['fid'];

                // 尝试解码，如果是有效的 JSON 数组则使用，否则转为单元素数组
                $filelist = (is_string($fid) && ($decodedFid = json_decode($fid, true)) && is_array($decodedFid)) ? $decodedFid : (array)$fid;

                $this->model->where('fid', $deles['fid'])->delete();
                $transfer = new \netdisk\Transfer();
                $transfer->deletepdirFid($deles['is_type'], $filelist);
            }
        });

        return jok('临时资源删除成功', $abc);
    }

    /**
     * 检查对应网盘账号是否已配置
     * @param int $isType 网盘类型 0夸克 1阿里 2百度 3UC 4迅雷
     * @return bool
     */
    private function hasPanAccount($isType)
    {
        $configKeys = [
            0 => 'quark_cookie',
            1 => 'Authorization',
            2 => 'baidu_cookie',
            3 => 'uc_cookie',
            4 => 'xunlei_cookie',
        ];

        if ($isType == 10) {
            $authFile = app()->getConfigPath() . 'guangya_auth.json';
            if (file_exists($authFile)) {
                $content = file_get_contents($authFile);
                $data = json_decode($content, true);
                return !empty($data['access_token']);
            }
            return false;
        }

        $configKey = $configKeys[$isType] ?? null;
        if ($configKey === null) {
            return false;
        }

        $cookie = Config('qfshop.' . $configKey);
        return !empty($cookie);
    }

    /**
     * 获取搜索统计数据
     * 用于前端实时更新今日搜索和总搜索量
     * 
     * @return \think\response\Json
     */
    public function get_search_stats()
    {
        try {
            $stats = getSearchStats();
            
            return jok('获取成功', [
                'today' => $stats['today'],
                'total' => $stats['total'],
                'today_formatted' => formatNumberToK($stats['today']),
                'total_formatted' => formatNumberToK($stats['total'])
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }
}
