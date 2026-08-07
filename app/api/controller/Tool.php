<?php

namespace app\api\controller;

use think\App;
use think\facade\Request;
use think\facade\Cache;
use app\api\QfShop;
use app\model\User as Usermodel;
use app\model\Ads as Adsmodel;
use app\model\Feedback as FeedbackModel;
use app\model\SourceCategory as SourceCategoryModel;

class Tool extends QfShop
{
    /**
     * 系统配置参数
     *
     * @return void
     */
    public function getConfig()
    {
        $data = [
            'app_name'        => Config('qfshop.app_name'),
            'qcode'   => getimgurl(Config('qfshop.qcode')),
            'logo'   => getimgurl(Config('qfshop.logo')),
            'app_description'   => Config('qfshop.app_description'),
        ];
        return jok('获取成功',$data);
    }
    /**
     * 上传图片
     *
     * @return void
     */
    public function Upload()
    {
        // 获取当前登录的用户信息
        $userInfo = $this->getLoginUser();
        
        try {
            $file = request()->file('file');
        } catch (\Exception $error) {
            return jerr('上传文件失败，请检查你的文件！');
        }
        $Usermodel = new Usermodel();
        $data = $Usermodel->Upload($file, $userInfo);
        return jok('上传成功',$data);
    }

    /**
     * 根据广告位关键词获取广告图片列表
     * 
     * @return void
     */
    public function getAdsCode()
    {
        $Adsmodel = new Adsmodel();
        $data = $Adsmodel->getAdsCode(input(''));
        return jok('获取成功',$data);
    }

    /**
     * 用户反馈
     * 
     * @return void
     */
    public function feedback()
    {
        $data = input('');
        if (empty($data['content'])) {
            return jerr("请输入要看的内容");
        }
        $FeedbackModel = new FeedbackModel();
        $FeedbackModel->save(['content' => $data['content']]);
        return jok('已反馈');
    }
    
    

    /**
     * 获取首页排行榜数据
     *
     * @return void
     */
    public function ranking()
    {
        $channel = input('channel', '电影');
        $area = input('area', '全部');
        $cate = input('cate', '全部');
        $year = input('year', '全部');
        $page = input('page', 1);
        $page_size = input('page_size', 24);
        $is_m = input('is_m', 0);
        $rank_type_input = input('rank_type', '热搜榜');
        
        // 映射前端传来的榜单名称到 API 参数
        $rank_type_map = [
            '热搜榜' => '最热',
            '新片榜' => '最新',
            '好评榜' => '好评榜'
        ];
        $rank_type = $rank_type_map[$rank_type_input] ?? '最热';
        
        // 使用 ThinkPHP 提供的 runtime_path() 函数获取 runtime 目录路径
        $cacheDir = runtime_path('cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    
        // 根据所有筛选参数生成缓存文件名
        $cacheKey = "{$channel}_{$rank_type}_{$area}_{$cate}_{$year}";
        $cacheFile = $cacheDir . "ranking_data_{$cacheKey}.cache";
        $cacheTime = 24 * 3600; // 缓存时间为 24 小时
    
        // 检查缓存文件是否存在且在缓存时间内
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            // 从缓存中读取数据
            $allData = json_decode(file_get_contents($cacheFile), true);
        } else {
            $allData = [];
            
            $queryParams = array(
                "area" => $area,
                "year" => $year,
                "channel" => $channel,
                "rank_type" => $rank_type,
                "cate" => $cate,
                "from" => "hot_page",
                "start" => 0,
                "hit" => 120, // 获取120条数据用于分页
            );
            
            $res = curlHelper("https://biz.quark.cn/api/trending/ranking/getYingshiRanking", "GET", null, [], $queryParams)['body'];
            $res = json_decode($res, true);
            
            try {
                if (!empty($res['data']['hits']['hit']['item'])) {
                    foreach ($res['data']['hits']['hit']['item'] as $key => $value) {
                        // 尝试多个可能的年份字段名
                        $yearVal = '';
                        if (!empty($value['year'])) {
                            $yearVal = $value['year'];
                        } elseif (!empty($value['pubyear'])) {
                            $yearVal = $value['pubyear'];
                        } elseif (!empty($value['release_year'])) {
                            $yearVal = $value['release_year'];
                        } elseif (!empty($value['pub_date'])) {
                            $yearVal = substr($value['pub_date'], 0, 4);
                        }
                        
                        // 将图片地址中的 http 替换为 https
                    $src = $value['src'] ?? '';
                    if ($src && strpos($src, 'http://') === 0) {
                        $src = 'https://' . substr($src, 7);
                    }
                    
                    $allData[] = array(
                        "title" => $value['title'] ?? '',
                        "src" => $src,
                        "ranking" => $value['ranking'] ?? ($key + 1),
                        "hot_score" => $value['hot_score'] ?? '',
                        "desc" => $value['desc'] ?? '',
                        "year" => $yearVal,
                        "area" => $value['area'] ?? '',
                        "score_avg" => $value['score_avg'] ?? '0.0',
                        "category" => $value['category'] ?? '',
                    );
                    }
                }
            } catch (Exception $error) {
                $allData = [];
            }
    
            // 将数据缓存到文件中
            file_put_contents($cacheFile, json_encode($allData));
        }
        
        // 判断是否是新格式的请求（有分页参数）
        $hasPageParam = input('?page') || input('?page_size');
        
        // 分页处理
        $total = count($allData);
        
        if ($is_m == 1) {
            $ranking_m_num = Config('qfshop.ranking_m_num') ?? 6;
            $data = array_slice($allData, 0, $ranking_m_num);
        } else {
            $start = ($page - 1) * $page_size;
            $data = array_slice($allData, $start, $page_size);
        }
        
        // 首页旧代码兼容：没有分页参数时直接返回数组
        if (!$hasPageParam) {
            return jok('获取成功', $data);
        }
       
        // 新格式：带分页信息
        return jok('获取成功', [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $page_size,
            'total_pages' => ceil($total / $page_size)
        ]);
    }


    /**
     * 网页端全网搜接口
     *
     * @return void
     */
    public function Qsearch()
    {
        $title = input('title');
        $list = [];


        $userAgent = Request::header('user-agent');
        // 定义常见爬虫的 User-Agent 关键字
        $bots = ['Googlebot', 'Bingbot', 'Baiduspider'];
        foreach ($bots as $bot) {
            if (strpos($userAgent, $bot) !== false) {
                return jerr('该接口禁止爬虫访问');
            }
        }

        if (empty($title)) {
            return jok('临时资源获取成功', $list);
        }
        
        $keys = Request::ip()."_".$title;
        if(Cache::get($keys) == 1){
            return jerr('调用太过频繁啦');
        }
        Cache::set($keys, 1, 10);

        $bController = app(\app\api\controller\Other::class);
        $list = $bController->all_search($title);

        Cache::delete($keys); 
        return jok('临时资源获取成功', $list);
    }

}
