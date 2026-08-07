<?php
header('Content-Type: text/plain; charset=utf-8');

$url = 'https://pan.quark.cn/s/d17ea5cb11b7';

$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_REFERER => 'https://pan.quark.cn/',
    CURLOPT_TIMEOUT => 30,
));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Error: " . ($error ? $error : 'None') . "\n";
echo "Response Length: " . strlen($response) . "\n";
echo "\n";
echo "=== First 2000 chars ===\n";
echo substr($response, 0, 2000);
echo "\n\n";
echo "=== Check for keywords ===\n";
$keywords = array('删除', '失效', '不存在', '过期', '取消', '违规', 'file_list', 'file-name');
foreach ($keywords as $kw) {
    $found = strpos($response, $kw) !== false ? 'YES' : 'NO';
    echo "[$found] $kw\n";
}
