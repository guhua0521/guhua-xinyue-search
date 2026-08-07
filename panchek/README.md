# PanCheck PHP 版本

PanCheck 的 PHP 版本实现，支持检测 9 种主流网盘平台的分享链接有效性。

## 支持的网盘平台

| 平台 | 检测方式 | 文件 |
|------|----------|------|
| 百度网盘 | API 检测 | `BaiduChecker.php` |
| 阿里云盘 | API 检测 | `AliyunChecker.php` |
| 夸克网盘 | API 检测 | `QuarkChecker.php` |
| 115网盘 | API 检测 | `Pan115Checker.php` |
| 123网盘 | API 检测 | `Pan123Checker.php` |
| UC网盘 | 页面分析 | `UCChecker.php` |
| 天翼云盘 | API 检测 | `TianyiChecker.php` |
| 迅雷云盘 | API 检测 | `XunleiChecker.php` |
| 中国移动云盘 | API 检测 | `CMCCChecker.php` |

## 目录结构

```
php/
├── src/
│   ├── CheckResult.php              # 检测结果类
│   ├── LinkCheckerInterface.php     # 检测器接口
│   ├── BaseChecker.php              # 基础检测器
│   ├── CheckerFactory.php           # 检测器工厂
│   └── Checkers/
│       ├── BaiduChecker.php         # 百度网盘
│       ├── AliyunChecker.php        # 阿里云盘
│       ├── QuarkChecker.php         # 夸克网盘
│       ├── Pan115Checker.php        # 115网盘
│       ├── Pan123Checker.php        # 123网盘
│       ├── UCChecker.php            # UC网盘
│       ├── TianyiChecker.php        # 天翼云盘
│       ├── XunleiChecker.php        # 迅雷云盘
│       └── CMCCChecker.php          # 中国移动云盘
├── example.php                      # 使用示例
├── api.php                          # HTTP API 接口
└── README.md                        # 本文件
```

## 使用方法

### 1. 命令行运行示例

```bash
php example.php
```

### 2. 作为库使用

```php
require_once 'src/CheckResult.php';
require_once 'src/LinkCheckerInterface.php';
require_once 'src/BaseChecker.php';
require_once 'src/Checkers/BaiduChecker.php';
require_once 'src/CheckerFactory.php';

use PanCheck\CheckerFactory;

// 创建工厂
$factory = new CheckerFactory();

// 自动识别并检测
$result = $factory->autoCheck('https://pan.baidu.com/s/1example');
echo $result['valid'] ? '有效' : '失效';

// 使用指定检测器
$checker = $factory->getChecker('baidu');
$result = $checker->check('https://pan.baidu.com/s/1example');
echo $result->valid ? '有效' : '失效';
```

### 3. HTTP API 接口

启动本地服务器：

```bash
php -S localhost:8000 api.php
```

发送检测请求：

```bash
curl -X POST http://localhost:8000/api.php \
  -H "Content-Type: application/json" \
  -d '{
    "links": [
      "https://pan.baidu.com/s/1example",
      "https://pan.quark.cn/s/test123"
    ]
  }'
```

响应格式：

```json
{
  "success": true,
  "summary": {
    "total": 2,
    "valid": 1,
    "invalid": 1,
    "error": 0,
    "duration": 1500
  },
  "results": [
    {
      "link": "https://pan.baidu.com/s/1example",
      "platform": "baidu",
      "valid": false,
      "failureReason": "分享已被取消",
      "duration": 800
    }
  ]
}
```

## 配置选项

每个检测器都支持以下配置：

```php
// 创建检测器时设置并发限制和超时
$checker = new BaiduChecker(
    concurrencyLimit: 5,  // 并发限制
    timeout: 30           // 超时时间（秒）
);

// 设置请求延迟（毫秒）
$checker->setRequestDelay(1000);  // 每次请求间隔 1 秒
```

## 宝塔面板部署

1. 上传 `php` 目录到网站目录（如 `/www/wwwroot/pancheck-php`）

2. 配置 Nginx 反向代理：

```nginx
location / {
    try_files $uri $uri/ /api.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_index api.php;
    include fastcgi_params;
}
```

3. 配置 PHP 版本要求：PHP 7.4 或更高版本，需要启用 curl 扩展

## 注意事项

1. **中国移动云盘**：需要 AES 加密请求，当前版本未实现完整加密，可能无法正常工作
2. **迅雷云盘**：需要多层 MD5 加密获取 token
3. **请求频率**：建议设置合理的延迟，避免被平台限制
4. **超时处理**：各平台检测器对超时和错误的处理方式可能不同

## 与 Go 版本的差异

| 功能 | Go 版本 | PHP 版本 |
|------|---------|----------|
| 并发控制 | ✅ 完整支持 | ⚠️ 单进程，需配合队列 |
| 数据库存储 | ✅ 支持 | ❌ 不支持 |
| Redis 缓存 | ✅ 支持 | ❌ 不支持 |
| 定时任务 | ✅ 支持 | ❌ 不支持 |
| Web 界面 | ✅ 支持 | ❌ 仅 API |

PHP 版本适合：
- 简单快速的链接检测
- 集成到现有 PHP 项目
- 轻量级部署

建议生产环境使用 Go 版本以获得更好的性能和完整功能。
