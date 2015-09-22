#!/usr/local/php/bin/php
<?php
 
define("CONF_PATH", '/usr/local/nginx/conf/hosts');
 
$google_ips = __DIR__ . "/google_ips.txt"; // 保存IP地址文件
$ngx_cnf = CONF_PATH . "/51open.conf";     // NGINX配置文件
 
exec("nslookup google.com", $out, $ret); 
 
$start = false;
$ips = [];
 
foreach($out as $line) {
    // answer: 之后的是服务器对应IP
    if (false !== strpos($line, "answer:")) {
        $start = true;
    } else if (!$start) {
        continue;
    }
 
    preg_match("/Address: ((?:(?:25[0-5]|2[0-4]\d|((1\d{2})|([1-9]?\d)))\.){3}(?:25[0-5]|2[0-4]\d|((1\d{2})|([1-9]?\d))))/i", $line, $matches);
    if (!empty($matches) && isset($matches[1])) {
        $ips[] = $matches[1];
    }
}
 
// 保存的IP地址
if (file_exists($google_ips)) {
    $data = file_get_contents($google_ips);
    if ($data) {
        $arr = unserialize($data);
        if (isset($arr['ips']) && date('Ymd', $arr['__logtime'])==date('Ymd')) {
            $ips = array_merge($ips, $arr['ips']);
            $ips = array_unique($ips);
        }
    }
}
 
$data = ['__logtime' => time(), 'ips' => $ips];
file_put_contents($google_ips, serialize($data));
 
$str = '';
foreach ($ips as $ip) {
    $str .= sprintf("\n    server %s:80 max_fails=3;", $ip);
}
 
if (!$str) {
    exit;
}
 
$ngx_tpl = <<<EOT
proxy_cache_path  /data/cache/nginx/one  levels=1:2   keys_zone=one:10m max_size=10g;
proxy_cache_key  "$host$request_uri";
 
upstream google {%s
}
 
server {
    listen       80;
    server_name  g.51open.net google.51open.net;
 
    rewrite ^(.*)$  https://$host$1 permanent;     
}
 
server {
    listen       443;
    server_name  g.51open.net google.51open.net;
 
    ssl on;
    ssl_certificate      /usr/local/nginx/conf/hosts/ssl/g.51open.net.crt;
    ssl_certificate_key  /usr/local/nginx/conf/hosts/ssl/g.51open.net.key;         
 
    location / {
        proxy_cache one;
        proxy_cache_valid  200 302  1h;
        proxy_cache_valid  404      1m;
        proxy_redirect https://www.google.com/ /;
        proxy_cookie_domain google.com 51open.net;
        proxy_pass              http://google;
        proxy_set_header Host "www.google.com";
        proxy_set_header Accept-Encoding "";
        proxy_set_header User-Agent $http_user_agent;
        proxy_set_header Accept-Language "zh-CN";
        proxy_set_header Cookie "PREF=ID=047808f19f6de346:U=0f62f33dd8549d11:FF=2:LD=zh-CN:NW=1:TM=1325338577:LM=1332142444:GM=1:SG=2:S=rE0SyJh2w1IQ-Maw";             
        #sub_filter www.google.com g.51open.net;
        #sub_filter_once off;
    }
}
EOT;
 
$content = sprintf($ngx_tpl, $str);
 
file_put_contents($ngx_cnf, $content);