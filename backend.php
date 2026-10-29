<?php
/**
 * 全功能高可用音乐后端 (MKOnlinePlayer & SourceHub 2.0 动力核心)
 *
 * 功能：
 *   1. 跨域中继代理 (types=proxy / types=fetchsrc)：解除浏览器 CORS 限制，支持完整 POST/GET 转发
 *   2. 音频防拦截中继流 (types=stream)：解决 HTTPS 站点播放 HTTP 音频流被 Mixed Content 拦截的问题
 *   3. 官方搜索聚合 (types=search)：酷我 / QQ / 网易云官方高可用搜索
 *   4. 直链解析与回退 (types=url)：各平台直接播放流提取与回退机制
 *   5. 内置音源库分发 (types=sources / types=getsource)：自动分发洛雪 20+ 音源脚本
 *   6. 防 CDN 误缓存：强制输出 Cache-Control: no-store，避免边缘节点静态化
 */

// 0. 严密屏蔽任何非致命性警告或废弃通知，防止输出到响应体污染 JSON/JSONP 结果（针对 PHP 8.0 ~ 8.5+）
error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// 1. 强制禁用 CDN 缓存，防止又拍云等 CDN 缓存动态接口响应
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 2. 跨域配置与预检处理
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, User-Agent');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
    http_response_code(204);
    exit();
}

// 3. 通用参数提取
$CALLBACK = isset($_GET['callback']) ? trim($_GET['callback']) : (isset($_POST['callback']) ? trim($_POST['callback']) : '');
if ($CALLBACK !== '' && !preg_match('/^[A-Za-z_$][A-Za-z0-9_$.]*$/', $CALLBACK)) {
    $CALLBACK = 'callback';
}

function out_json($data)
{
    global $CALLBACK;
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE);
    // 全面升级网易云旧 CDN 图床与资源链接为 HTTPS，彻底杜绝 Chrome 产生海量 Mixed Content 警告与连接重置
    $raw = preg_replace('#http://(p[1-4]|m[7-8]c?)\.music\.126\.net#i', 'https://$1.music.126.net', $raw);
    if ($CALLBACK !== '') {
        header('Content-Type: application/javascript; charset=utf-8');
        echo $CALLBACK . '(' . $raw . ');';
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo $raw;
    }
    exit();
}

/* ---------------------------------------------------------
 * SSRF 安全防护：判断是否为公网合法 IP
 * --------------------------------------------------------- */
function is_public_host($host)
{
    $host = trim((string) $host);
    if ($host === '' || strcasecmp($host, 'localhost') === 0) {
        return false;
    }
    // 允许标准纯域名
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (!$ips) {
            $ip = @gethostbyname($host);
            if ($ip && $ip !== $host) {
                $ips = [['ip' => $ip]];
            }
        }
        if (!$ips) {
            return false;
        }
        foreach ($ips as $rec) {
            $ipAddr = isset($rec['ip']) ? $rec['ip'] : (isset($rec['ipv6']) ? $rec['ipv6'] : '');
            if ($ipAddr && filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }
    // 直接是 IP
    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/* ---------------------------------------------------------
 * HTTP 基础请求工具
 * --------------------------------------------------------- */
function http_request($url, $method = 'GET', $headers = [], $body = null, $timeout = 15)
{
    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '',
    ];

    $method = strtoupper(trim((string) $method));
    if ($method === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
        }
    } elseif ($method !== 'GET') {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = is_array($body) ? json_encode($body) : $body;
        }
    }

    if (!empty($headers)) {
        $hList = [];
        foreach ($headers as $k => $v) {
            if (is_numeric($k)) {
                $hList[] = $v;
            } else {
                if (strtolower($k) === 'host') continue;
                $hList[] = "$k: $v";
            }
        }
        $curlOpts[CURLOPT_HTTPHEADER] = $hList;
    }

    $respHeaders = [];
    $curlOpts[CURLOPT_HEADERFUNCTION] = function ($ch, $headerLine) use (&$respHeaders) {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    };

    curl_setopt_array($ch, $curlOpts);
    $respBody = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
        @curl_close($ch);
    }

    return [$httpCode, $respBody, $respHeaders, $err];
}

/* ---------------------------------------------------------
 * 1. 代理转发 (types=proxy 或 types=fetchsrc)
 * --------------------------------------------------------- */
function handle_proxy()
{
    // 兼容 GET 参数、POST 表单或原始 JSON body
    $url = isset($_REQUEST['url']) ? trim($_REQUEST['url']) : '';
    $method = isset($_REQUEST['method']) ? strtoupper(trim($_REQUEST['method'])) : $_SERVER['REQUEST_METHOD'];
    $headers = [];
    $body = null;

    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            if (empty($url) && isset($jsonData['url'])) $url = trim($jsonData['url']);
            if (isset($jsonData['method'])) $method = strtoupper(trim($jsonData['method']));
            if (isset($jsonData['headers']) && is_array($jsonData['headers'])) $headers = $jsonData['headers'];
            if (isset($jsonData['body'])) $body = $jsonData['body'];
        } else {
            if ($method === 'POST' && empty($body)) {
                $body = $rawInput;
            }
        }
    }

    if (empty($body) && isset($_POST['body'])) {
        $body = $_POST['body'];
    }

    if (!preg_match('#^https?://#i', $url)) {
        out_json(['ok' => false, 'error' => '只支持 http / https 链接']);
    }

    $parts = parse_url($url);
    $host = isset($parts['host']) ? $parts['host'] : '';
    if ($host === '' || !is_public_host($host)) {
        out_json(['ok' => false, 'error' => '目标地址不可达或属于局域网私有地址']);
    }

    list($code, $respBody, $respHeaders, $err) = http_request($url, $method, $headers, $body, 20);

    if ($respBody === false) {
        out_json(['ok' => false, 'error' => '代理请求失败：' . $err, 'status' => $code]);
    }

    // 洛雪音源协议约定：返回原始字符串 body，由脚本决定是否 parse
    out_json([
        'ok'      => true,
        'status'  => $code,
        'headers' => $respHeaders,
        'body'    => $respBody
    ]);
}

/* ---------------------------------------------------------
 * 2. 音频流代理 (types=stream)
 * 解决 HTTPS 网页播放 HTTP 音频流被 Mixed Content 拦截
 * --------------------------------------------------------- */
function handle_stream()
{
    $url = isset($_GET['url']) ? trim($_GET['url']) : '';
    if (!preg_match('#^https?://#i', $url)) {
        http_response_code(400);
        exit('Invalid stream URL');
    }

    $parts = parse_url($url);
    $host = isset($parts['host']) ? $parts['host'] : '';
    if ($host === '' || !is_public_host($host)) {
        http_response_code(403);
        exit('Private host forbidden');
    }

    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');

    $headers = [];
    if (isset($_SERVER['HTTP_RANGE'])) {
        $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    // 根据目标 CDN 注入权威 Referer，防止任何防盗链拦截
    $ref = 'https://music.163.com/';
    if (stripos($host, 'kuwo.cn') !== false) {
        $ref = 'https://www.kuwo.cn/';
    } elseif (stripos($host, 'qq.com') !== false) {
        $ref = 'https://y.qq.com/';
    } elseif (stripos($host, 'kugou.com') !== false) {
        $ref = 'https://www.kugou.com/';
    }

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => false, // 直冲 php://output
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_REFERER        => $ref,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) {
            $h = trim($headerLine);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
                $code = (int)$m[1];
                if ($code === 200 || $code === 206) {
                    http_response_code($code);
                }
            }
            if (stripos($h, 'Content-Type:') === 0 ||
                stripos($h, 'Content-Length:') === 0 ||
                stripos($h, 'Content-Range:') === 0 ||
                stripos($h, 'Accept-Ranges:') === 0) {
                header($h);
            }
            return strlen($headerLine);
        }
    ];

    if (!empty($headers)) {
        $curlOpts[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $curlOpts);
    curl_exec($ch);
    if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
        @curl_close($ch);
    }
    exit();
}

/* ---------------------------------------------------------
 * 3. 内置音源列表与脚本分发 (types=sources & types=getsource)
 * --------------------------------------------------------- */
function handle_sources()
{
    $manifestPath = __DIR__ . '/sources/manifest.json';
    if (file_exists($manifestPath)) {
        header('Content-Type: application/json; charset=utf-8');
        echo file_get_contents($manifestPath);
        exit();
    }

    $sources = [];
    $sourcesDir = __DIR__ . '/sources';
    if (is_dir($sourcesDir)) {
        foreach (scandir($sourcesDir) as $f) {
            if (substr($f, -3) === '.js') {
                $sources[] = [
                    'id'   => substr($f, 0, -3),
                    'file' => $f,
                    'name' => substr($f, 0, -3)
                ];
            }
        }
    }
    out_json(['version' => '2.0.0', 'sources' => $sources]);
}

function handle_getsource()
{
    $file = isset($_GET['file']) ? trim($_GET['file']) : '';
    $file = basename($file);
    if ($file === '' || substr($file, -3) !== '.js') {
        http_response_code(400);
        exit('Invalid script file');
    }
    $path = __DIR__ . '/sources/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        exit('Source script not found');
    }
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    exit();
}

/* ---------------------------------------------------------
 * 4. 酷我伪 JSON 解码状态机
 * --------------------------------------------------------- */
function fakejson_decode($text)
{
    $text = trim((string) $text);
    if ($text === '') return null;
    $j = json_decode($text, true);
    if ($j !== null) return $j;

    $out = '';
    $n = strlen($text);
    $ins = false;
    $q = '';
    $i = 0;
    while ($i < $n) {
        $c = $text[$i];
        if (!$ins) {
            if ($c === "'" || $c === '"') {
                $out .= '"';
                $ins = true;
                $q = $c;
                $i++;
                continue;
            }
            if (ctype_alpha($c)) {
                $j2 = $i;
                while ($j2 < $n && ctype_alpha($text[$j2])) $j2++;
                $w = substr($text, $i, $j2 - $i);
                switch ($w) {
                    case 'None':
                    case 'null':  $out .= 'null'; break;
                    case 'True':  $out .= 'true'; break;
                    case 'False': $out .= 'false'; break;
                    default:      $out .= $w;
                }
                $i = $j2;
                continue;
            }
            $out .= $c;
            $i++;
            continue;
        }
        if ($c === chr(92)) {
            $out .= $c . ($i + 1 < $n ? $text[$i + 1] : '');
            $i += 2;
            continue;
        }
        if ($c === $q) {
            $out .= '"';
            $ins = false;
            $q = '';
            $i++;
            continue;
        }
        if ($c === '"' && $q === "'") {
            $out .= chr(92) . '"';
            $i++;
            continue;
        }
        if ($c === "\n" || $c === "\r" || $c === "\t") {
            $out .= ($c === "\n") ? '\n' : (($c === "\r") ? '\r' : '\t');
            $i++;
            continue;
        }
        $out .= $c;
        $i++;
    }
    return json_decode($out, true);
}

function clean_str($s)
{
    $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

function norm_source($s)
{
    $s = strtolower(trim((string) $s));
    $map = [
        'all' => 'all', 'aggregate' => 'all',
        'kuwo' => 'kuwo', 'kw' => 'kuwo',
        'kugou' => 'kugou', 'kg' => 'kugou',
        'qq' => 'qq', 'tx' => 'qq', 'tencent' => 'qq',
        'netease' => 'netease', 'wy' => 'netease', '163' => 'netease',
    ];
    return isset($map[$s]) ? $map[$s] : 'all';
}

/* ---------------------------------------------------------
 * 5. 官方歌曲检索实现
 * --------------------------------------------------------- */
function search_kuwo($kw, $page = 1, $count = 20)
{
    $url = 'https://search.kuwo.cn/r.s?all=' . rawurlencode($kw)
         . '&ft=music&pn=' . max(0, $page - 1) . '&rn=' . $count
         . '&rformat=json&encoding=utf8&moession=1';
    list($c, $b) = http_request($url, 'GET', [], null, 8);
    if ($c !== 200 || empty($b)) return [];

    $d = fakejson_decode($b);
    $list = (is_array($d) && isset($d['abslist']) && is_array($d['abslist'])) ? $d['abslist'] : [];
    $out = [];
    foreach ($list as $row) {
        $rawRid = isset($row['MUSICRID']) ? (string)$row['MUSICRID'] : '';
        if ($rawRid === '') continue;
        $rid = preg_replace('/^MUSIC_/i', '', $rawRid);
        $singer = clean_str(isset($row['ARTIST']) ? $row['ARTIST'] : '');
        $name = clean_str(isset($row['SONGNAME']) ? $row['SONGNAME'] : '');
        $album = clean_str(isset($row['ALBUM']) ? $row['ALBUM'] : '');

        $out[] = [
            'id'       => $rid,
            'name'     => $name,
            'artist'   => array_values(array_filter([$singer])),
            'album'    => $album,
            'source'   => 'kuwo',
            'url_id'   => $rid,
            'pic_id'   => 'https://img4.kuwo.cn/star/albumcover/' . $rid,
            'lyric_id' => $rid,
        ];
    }
    return $out;
}

function search_qq($kw, $page = 1, $count = 20)
{
    $url = 'https://c.y.qq.com/soso/fcgi-bin/client_search_cp?w=' . rawurlencode($kw)
         . '&format=json&p=' . $page . '&n=' . $count;
    list($c, $b) = http_request($url, 'GET', [], null, 8);
    if ($c !== 200 || empty($b)) return [];

    $j = json_decode($b, true);
    $list = isset($j['data']['song']['list']) && is_array($j['data']['song']['list']) ? $j['data']['song']['list'] : [];
    $out = [];
    foreach ($list as $row) {
        $mid = isset($row['songmid']) ? (string)$row['songmid'] : '';
        if ($mid === '') continue;
        $sg = [];
        foreach ((array) (isset($row['singer']) ? $row['singer'] : []) as $s) {
            if (isset($s['name'])) $sg[] = clean_str($s['name']);
        }
        $albummid = isset($row['albummid']) ? (string)$row['albummid'] : '';
        $out[] = [
            'id'       => $mid,
            'name'     => clean_str(isset($row['songname']) ? $row['songname'] : ''),
            'artist'   => $sg,
            'album'    => clean_str(isset($row['albumname']) ? $row['albumname'] : ''),
            'source'   => 'qq',
            'url_id'   => $mid,
            'pic_id'   => $albummid !== ''
                ? 'https://y.qq.com/music/photo_new/T002R300x300M000' . $albummid . '.jpg'
                : null,
            'lyric_id' => $mid,
        ];
    }
    return $out;
}

function search_netease($kw, $page = 1, $count = 20)
{
    $url = 'https://music.163.com/api/cloudsearch/pc?s=' . rawurlencode($kw)
         . '&type=1&limit=' . $count . '&offset=' . (($page - 1) * $count);
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://music.163.com/'], null, 8);
    if ($c !== 200 || empty($b)) return [];

    $j = json_decode($b, true);
    $list = isset($j['result']['songs']) && is_array($j['result']['songs']) ? $j['result']['songs'] : [];
    $out = [];
    foreach ($list as $row) {
        $id = isset($row['id']) ? (string)$row['id'] : '';
        if ($id === '') continue;
        $sg = [];
        foreach ((array) (isset($row['ar']) ? $row['ar'] : []) as $s) {
            if (isset($s['name'])) $sg[] = clean_str($s['name']);
        }
        $picUrl = isset($row['al']['picUrl']) ? $row['al']['picUrl'] : null;
        $out[] = [
            'id'       => $id,
            'name'     => clean_str(isset($row['name']) ? $row['name'] : ''),
            'artist'   => $sg,
            'album'    => clean_str(isset($row['al']['name']) ? $row['al']['name'] : ''),
            'source'   => 'netease',
            'url_id'   => $id,
            'pic_id'   => $picUrl,
            'lyric_id' => $id,
        ];
    }
    return $out;
}

function search_kugou($kw, $page = 1, $count = 20)
{
    $url = 'http://mobilecdn.kugou.com/api/v3/search/song?format=json&keyword='
         . rawurlencode($kw) . '&page=' . max(1, (int)$page) . '&pagesize=' . max(1, (int)$count);
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://www.kugou.com/'], null, 8);
    if ($c !== 200 || empty($b)) return [];

    $j = json_decode($b, true);
    $list = isset($j['data']['info']) && is_array($j['data']['info']) ? $j['data']['info'] : [];
    $out = [];
    foreach ($list as $row) {
        $hash = isset($row['hash']) ? (string)$row['hash'] : '';
        if ($hash === '') continue;
        $singer = isset($row['singername']) ? clean_str($row['singername']) : '未知歌手';
        $out[] = [
            'id'       => $hash,
            'name'     => clean_str(isset($row['songname']) ? $row['songname'] : ''),
            'artist'   => [$singer],
            'album'    => clean_str(isset($row['album_name']) ? $row['album_name'] : ''),
            'source'   => 'kugou',
            'url_id'   => $hash,
            'pic_id'   => $hash,
            'lyric_id' => $hash,
        ];
    }
    return $out;
}

function search_all($kw, $page = 1, $count = 20)
{
    $subCount = max(4, (int)ceil($count / 3));
    $resWy = search_netease($kw, $page, $subCount);
    $resQq = search_qq($kw, $page, $subCount);
    $resKg = search_kugou($kw, $page, $subCount);

    $combined = [];
    $maxCount = max(count($resWy), count($resQq), count($resKg));
    for ($i = 0; $i < $maxCount; $i++) {
        if (isset($resWy[$i])) $combined[] = $resWy[$i];
        if (isset($resQq[$i])) $combined[] = $resQq[$i];
        if (isset($resKg[$i])) $combined[] = $resKg[$i];
        if (count($combined) >= $count) break;
    }
    return array_slice($combined, 0, $count);
}

/* ---------------------------------------------------------
 * 6. 直链解析 (内置官方兜底通道)
 * --------------------------------------------------------- */
function url_kuwo($id)
{
    $id = preg_replace('/^MUSIC_/i', '', trim((string)$id));
    if ($id === '') return '';
    // 酷我官方公开试听流
    $url = 'https://antiserver.kuwo.cn/anti.s?type=convert_url&rid=' . $id . '&format=mp3&response=url';
    list($c, $b) = http_request($url, 'GET', [], null, 6);
    if ($c === 200 && preg_match('#^https?://#i', trim($b))) {
        return trim($b);
    }
    return '';
}

function url_netease($id)
{
    $id = trim((string)$id);
    if ($id === '') return '';

    // 1. 读取本地 VIP 配置（若配置了会员 Cookie，享有高品质/无损与 VIP 完整播放权）
    $vipCookie = '';
    $configFile = __DIR__ . '/data/vip_config.json';
    if (file_exists($configFile)) {
        $cfg = @json_decode(file_get_contents($configFile), true);
        if (!empty($cfg['netease_cookie'])) {
            $vipCookie = trim((string)$cfg['netease_cookie']);
        }
    }

    $cookieHeader = 'os=pc; osver=Microsoft-Windows-10-Professional-build-19042-64bit; appver=2.9.7.199837; channel=netease;';
    if (!empty($vipCookie)) {
        if (strpos($vipCookie, 'MUSIC_U=') === false) {
            $cookieHeader .= ' MUSIC_U=' . $vipCookie . ';';
        } else {
            $cookieHeader .= ' ' . $vipCookie . ';';
        }
    }

    // 2. 调用网易云官方高可用解析接口（优先下发 320k 高品质 MP3 直链）
    $url = 'https://music.163.com/api/song/enhance/player/url/v1?ids=[' . rawurlencode($id) . ']&level=exhigh&encodeType=mp3';
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Referer'    => 'https://music.163.com/',
        'Cookie'     => $cookieHeader
    ];
    list($code, $body) = http_request($url, 'GET', $headers, null, 8);
    if ($code === 200 && !empty($body)) {
        $json = @json_decode($body, true);
        if (!empty($json['data'][0]['url'])) {
            $playUrl = (string)$json['data'][0]['url'];
            // 自动提升为 HTTPS，杜绝浏览器 Mixed Content 混合内容拦截
            if (strpos($playUrl, 'http://') === 0) {
                $playUrl = 'https://' . substr($playUrl, 7);
            }
            return $playUrl;
        }
    }

    return '';
}

function lyric_netease($id)
{
    $url = 'https://music.163.com/api/song/lyric?os=pc&id=' . rawurlencode($id) . '&lv=-1&kv=-1&tv=-1';
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://music.163.com/'], null, 6);
    if ($c !== 200) return '';
    $j = json_decode($b, true);
    return isset($j['lrc']['lyric']) ? (string)$j['lrc']['lyric'] : '';
}

function lyric_kuwo($id)
{
    $id = preg_replace('/^MUSIC_/i', '', trim((string)$id));
    $url = 'https://m.kuwo.cn/newh5/singles/songinfoandlrc?musicId=' . rawurlencode($id);
    list($c, $b) = http_request($url, 'GET', [], null, 6);
    if ($c !== 200) return '';
    $j = json_decode($b, true);
    $list = isset($j['data']['lrclist']) && is_array($j['data']['lrclist']) ? $j['data']['lrclist'] : [];
    $lines = [];
    foreach ($list as $r) {
        $t = isset($r['time']) ? (float)$r['time'] : 0.0;
        $m = (int)($t / 60);
        $s = $t - $m * 60;
        $lines[] = sprintf('[%02d:%05.2f]%s', $m, $s, isset($r['lineLyric']) ? $r['lineLyric'] : '');
    }
    return implode("\n", $lines);
}

function lyric_qq($id)
{
    $mid = trim((string)$id);
    if ($mid === '') return '';
    $url = 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg?songmid=' . rawurlencode($mid) . '&format=json&nobase64=1';
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://y.qq.com/'], null, 6);
    if ($c !== 200 || empty($b)) return '';
    $j = json_decode($b, true);
    return isset($j['lyric']) ? html_entity_decode((string)$j['lyric'], ENT_QUOTES, 'UTF-8') : '';
}

function lyric_kugou($id)
{
    $hash = trim((string)$id);
    if ($hash === '') return '';
    $sUrl = 'http://krcs.kugou.com/search?ver=1&man=yes&client=mobi&keyword=&duration=&hash=' . rawurlencode($hash);
    list($c, $b) = http_request($sUrl, 'GET', [], null, 6);
    if ($c !== 200 || empty($b)) return '';
    $j = json_decode($b, true);
    $cands = isset($j['candidates']) && is_array($j['candidates']) ? $j['candidates'] : [];
    if (empty($cands)) return '';
    $c0 = $cands[0];
    if (empty($c0['id']) || empty($c0['accesskey'])) return '';
    $dUrl = 'http://lyrics.kugou.com/download?ver=1&client=pc&id=' . rawurlencode($c0['id']) . '&accesskey=' . rawurlencode($c0['accesskey']) . '&fmt=lrc&charset=utf8';
    list($dc, $db) = http_request($dUrl, 'GET', [], null, 6);
    if ($dc !== 200 || empty($db)) return '';
    $dj = json_decode($db, true);
    if (!empty($dj['content'])) {
        return (string)base64_decode($dj['content']);
    }
    return '';
}

/* ---------------------------------------------------------
 * 7. 歌单与用户列表解析 (网易云官方高可用接口)
 * --------------------------------------------------------- */
function playlist_netease($id)
{
    $id = trim((string)$id);
    if ($id === '') {
        return ['code' => 400, 'playlist' => null];
    }

    // 优先尝试网易云 v6 歌单详情接口（包含完整 ar, al 字段，直接匹配播放器前端格式，支持 n=1000 首）
    $url = 'https://music.163.com/api/v6/playlist/detail?id=' . rawurlencode($id) . '&n=1000&s=0';
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://music.163.com/'], null, 10);
    if ($c === 200 && !empty($b)) {
        $j = json_decode($b, true);
        if (isset($j['playlist']['tracks']) && is_array($j['playlist']['tracks'])) {
            return $j;
        }
    }

    // 回退尝试网易云 v1 歌单详情接口并自动格式化字段（artists -> ar, album -> al）
    $urlFallback = 'https://music.163.com/api/playlist/detail?id=' . rawurlencode($id);
    list($c, $b) = http_request($urlFallback, 'GET', ['Referer' => 'https://music.163.com/'], null, 10);
    if ($c === 200 && !empty($b)) {
        $j = json_decode($b, true);
        if (isset($j['result'])) {
            $pl = $j['result'];
            $tracks = isset($pl['tracks']) && is_array($pl['tracks']) ? $pl['tracks'] : [];
            $normTracks = [];
            foreach ($tracks as $t) {
                $ar = [];
                foreach ((array)(isset($t['artists']) ? $t['artists'] : []) as $a) {
                    $ar[] = [
                        'id'   => isset($a['id']) ? $a['id'] : 0,
                        'name' => isset($a['name']) ? clean_str($a['name']) : '',
                    ];
                }
                if (empty($ar)) {
                    $ar[] = ['id' => 0, 'name' => '未知歌手'];
                }
                $al = [
                    'id'     => isset($t['album']['id']) ? $t['album']['id'] : 0,
                    'name'   => isset($t['album']['name']) ? clean_str($t['album']['name']) : '',
                    'picUrl' => isset($t['album']['picUrl']) ? $t['album']['picUrl'] : (isset($t['album']['blurPicUrl']) ? $t['album']['blurPicUrl'] : ''),
                ];
                $normTracks[] = [
                    'id'   => isset($t['id']) ? $t['id'] : 0,
                    'name' => clean_str(isset($t['name']) ? $t['name'] : ''),
                    'ar'   => $ar,
                    'al'   => $al,
                ];
            }
            return [
                'code' => 200,
                'playlist' => [
                    'id'          => isset($pl['id']) ? $pl['id'] : $id,
                    'name'        => clean_str(isset($pl['name']) ? $pl['name'] : ''),
                    'coverImgUrl' => isset($pl['coverImgUrl']) ? $pl['coverImgUrl'] : '',
                    'creator'     => [
                        'nickname'  => isset($pl['creator']['nickname']) ? clean_str($pl['creator']['nickname']) : '',
                        'avatarUrl' => isset($pl['creator']['avatarUrl']) ? $pl['creator']['avatarUrl'] : '',
                    ],
                    'tracks'      => $normTracks,
                ]
            ];
        }
    }

    return ['code' => 404, 'playlist' => null];
}

function userlist_netease($uid)
{
    $uid = trim((string)$uid);
    if ($uid === '') {
        return ['code' => 400, 'playlist' => []];
    }
    $url = 'https://music.163.com/api/user/playlist/?offset=0&limit=1001&uid=' . rawurlencode($uid);
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://music.163.com/'], null, 10);
    if ($c === 200 && !empty($b)) {
        $j = json_decode($b, true);
        if (is_array($j) && isset($j['playlist'])) {
            return $j;
        }
    }
    return ['code' => -1, 'playlist' => []];
}

function playlist_qq($id)
{
    $id = trim((string)$id);
    if (preg_match('/(?:playlist\/|id=|disstid=)(\d+)/i', $id, $m)) {
        $id = $m[1];
    } elseif (preg_match('/^qq_(\d+)$/i', $id, $m)) {
        $id = $m[1];
    }

    if (!preg_match('/^\d+$/', $id)) {
        return ['code' => 400, 'playlist' => null];
    }

    $url = 'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&disstid='
         . rawurlencode($id) . '&format=json&inCharset=utf8&outCharset=utf8';
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://y.qq.com/'], null, 10);
    if ($c !== 200 || empty($b)) {
        return ['code' => 500, 'playlist' => null];
    }

    $j = json_decode($b, true);
    if (!isset($j['cdlist'][0])) {
        return ['code' => 404, 'playlist' => null];
    }
    $cd = $j['cdlist'][0];
    $songlist = isset($cd['songlist']) && is_array($cd['songlist']) ? $cd['songlist'] : [];

    $normTracks = [];
    foreach ($songlist as $row) {
        $mid = isset($row['songmid']) ? (string)$row['songmid'] : '';
        if ($mid === '') continue;
        $ar = [];
        foreach ((array)(isset($row['singer']) ? $row['singer'] : []) as $s) {
            $ar[] = [
                'id'   => isset($s['mid']) ? $s['mid'] : 0,
                'name' => isset($s['name']) ? clean_str($s['name']) : '未知歌手',
            ];
        }
        if (empty($ar)) {
            $ar[] = ['id' => 0, 'name' => '未知歌手'];
        }
        $albummid = isset($row['albummid']) ? (string)$row['albummid'] : '';
        $picUrl = $albummid !== '' ? 'https://y.qq.com/music/photo_new/T002R300x300M000' . $albummid . '.jpg' : '';

        $normTracks[] = [
            'id'       => $mid,
            'name'     => clean_str(isset($row['songname']) ? $row['songname'] : ''),
            'ar'       => $ar,
            'al'       => [
                'id'     => $albummid,
                'name'   => clean_str(isset($row['albumname']) ? $row['albumname'] : ''),
                'picUrl' => $picUrl,
            ],
            'source'   => 'qq',
            'url_id'   => $mid,
            'lyric_id' => $mid,
        ];
    }

    return [
        'code' => 200,
        'playlist' => [
            'id'          => $id,
            'name'        => clean_str(isset($cd['dissname']) ? $cd['dissname'] : 'QQ音乐歌单'),
            'coverImgUrl' => isset($cd['logo']) ? $cd['logo'] : '',
            'creator'     => [
                'nickname'  => isset($cd['nickname']) ? clean_str($cd['nickname']) : 'QQ音乐用户',
                'avatarUrl' => isset($cd['headurl']) ? $cd['headurl'] : '',
            ],
            'tracks'      => $normTracks,
        ]
    ];
}

function playlist_kugou($id)
{
    $id = trim((string)$id);
    if (preg_match('/(?:songlist\/|specialid=|index\/)(\d+)/i', $id, $m)) {
        $id = $m[1];
    } elseif (preg_match('/^kg_(\d+)$/i', $id, $m)) {
        $id = $m[1];
    }

    if (!preg_match('/^\d+$/', $id)) {
        return ['code' => 400, 'playlist' => null];
    }

    $url = 'http://mobilecdn.kugou.com/api/v3/special/song?specialid=' . rawurlencode($id) . '&page=1&pagesize=100&format=json';
    list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://www.kugou.com/'], null, 10);
    if ($c !== 200 || empty($b)) {
        return ['code' => 500, 'playlist' => null];
    }

    $j = json_decode($b, true);
    $list = isset($j['data']['info']) && is_array($j['data']['info']) ? $j['data']['info'] : [];
    if (empty($list)) {
        return ['code' => 404, 'playlist' => null];
    }

    $normTracks = [];
    foreach ($list as $row) {
        $hash = isset($row['hash']) ? (string)$row['hash'] : '';
        if ($hash === '') continue;
        $singer = isset($row['singername']) ? clean_str($row['singername']) : '未知歌手';
        $normTracks[] = [
            'id'       => $hash,
            'name'     => clean_str(isset($row['songname']) ? $row['songname'] : ''),
            'ar'       => [['id' => 0, 'name' => $singer]],
            'al'       => [
                'id'     => isset($row['album_id']) ? $row['album_id'] : 0,
                'name'   => clean_str(isset($row['album_name']) ? $row['album_name'] : ''),
                'picUrl' => '',
            ],
            'source'   => 'kugou',
            'url_id'   => $hash,
            'lyric_id' => $hash,
        ];
    }

    return [
        'code' => 200,
        'playlist' => [
            'id'          => $id,
            'name'        => clean_str(isset($j['data']['specialname']) ? $j['data']['specialname'] : '酷狗音乐歌单'),
            'coverImgUrl' => '',
            'creator'     => [
                'nickname'  => clean_str(isset($j['data']['nickname']) ? $j['data']['nickname'] : '酷狗音乐用户'),
                'avatarUrl' => '',
            ],
            'tracks'      => $normTracks,
        ]
    ];
}

/* ---------------------------------------------------------
 * 7.5 云端数据持久化引擎 (SQLite / JSON 双模式自愈存储)
 * --------------------------------------------------------- */
class MusicStorage
{
    private static $pdo = null;
    private static $useSqlite = false;
    private static $jsonFile = __DIR__ . '/data/cloud_data.json';

    public static function init()
    {
        if (!is_dir(__DIR__ . '/data')) {
            @mkdir(__DIR__ . '/data', 0755, true);
        }
        if (class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers())) {
            try {
                $dbFile = __DIR__ . '/data/music_vault.db';
                self::$pdo = new PDO('sqlite:' . $dbFile);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->exec("
                    CREATE TABLE IF NOT EXISTS cloud_users (
                        uid TEXT PRIMARY KEY,
                        nickname TEXT,
                        avatar TEXT,
                        updated_at INTEGER
                    );
                    CREATE TABLE IF NOT EXISTS cloud_accounts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        username TEXT UNIQUE NOT NULL,
                        password_hash TEXT NOT NULL,
                        token TEXT,
                        netease_uid TEXT DEFAULT '',
                        netease_name TEXT DEFAULT '',
                        netease_avatar TEXT DEFAULT '',
                        created_at INTEGER,
                        last_login INTEGER
                    );
                    CREATE INDEX IF NOT EXISTS idx_acc_username ON cloud_accounts(username);
                    CREATE INDEX IF NOT EXISTS idx_acc_token ON cloud_accounts(token);
                    CREATE TABLE IF NOT EXISTS cloud_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        uid TEXT,
                        song_id TEXT,
                        song_name TEXT,
                        artist TEXT,
                        album TEXT,
                        source TEXT,
                        pic TEXT,
                        played_at INTEGER
                    );
                    CREATE INDEX IF NOT EXISTS idx_hist_uid_time ON cloud_history(uid, played_at);
                    CREATE TABLE IF NOT EXISTS cloud_favorites (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        uid TEXT,
                        song_id TEXT,
                        song_name TEXT,
                        artist TEXT,
                        album TEXT,
                        source TEXT,
                        pic TEXT,
                        created_at INTEGER,
                        UNIQUE(uid, song_id, source)
                    );
                    CREATE INDEX IF NOT EXISTS idx_fav_uid ON cloud_favorites(uid);
                    CREATE TABLE IF NOT EXISTS cloud_searches (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        uid TEXT NOT NULL,
                        keyword TEXT NOT NULL,
                        source TEXT DEFAULT 'all',
                        searched_at INTEGER,
                        UNIQUE(uid, keyword)
                    );
                    CREATE INDEX IF NOT EXISTS idx_search_uid_time ON cloud_searches(uid, searched_at DESC);
                ");
                self::$useSqlite = true;
                return;
            } catch (Exception $e) {
                self::$useSqlite = false;
            }
        }
        self::$useSqlite = false;
    }

    private static function getJsonData()
    {
        if (!file_exists(self::$jsonFile)) {
            return ['users' => [], 'history' => [], 'favorites' => []];
        }
        $data = @json_decode(file_get_contents(self::$jsonFile), true);
        return is_array($data) ? $data : ['users' => [], 'history' => [], 'favorites' => []];
    }

    private static function saveJsonData($data)
    {
        @file_put_contents(self::$jsonFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function syncUser($uid, $nickname = '', $avatar = '')
    {
        $uid = trim((string)$uid);
        if ($uid === '') return null;
        $now = time();
        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("INSERT INTO cloud_users (uid, nickname, avatar, updated_at) 
                VALUES (:uid, :nickname, :avatar, :updated_at)
                ON CONFLICT(uid) DO UPDATE SET 
                nickname = CASE WHEN :nickname != '' THEN :nickname ELSE nickname END,
                avatar = CASE WHEN :avatar != '' THEN :avatar ELSE avatar END,
                updated_at = :updated_at");
            $stmt->execute([':uid' => $uid, ':nickname' => $nickname, ':avatar' => $avatar, ':updated_at' => $now]);
            $sel = self::$pdo->prepare("SELECT * FROM cloud_users WHERE uid = :uid LIMIT 1");
            $sel->execute([':uid' => $uid]);
            return $sel->fetch(PDO::FETCH_ASSOC);
        } else {
            $d = self::getJsonData();
            $curr = isset($d['users'][$uid]) ? $d['users'][$uid] : ['uid' => $uid, 'nickname' => '', 'avatar' => '', 'updated_at' => $now];
            if ($nickname !== '') $curr['nickname'] = $nickname;
            if ($avatar !== '') $curr['avatar'] = $avatar;
            $curr['updated_at'] = $now;
            $d['users'][$uid] = $curr;
            self::saveJsonData($d);
            return $curr;
        }
    }

    /* ---------------------------------------------------------
     * 账户认证系统 (用户名 + 密码 + 绑定网易云 UID)
     * --------------------------------------------------------- */
    public static function resolveKey($token = '', $uid = '')
    {
        $token = trim((string)$token);
        if ($token !== '') {
            $acc = self::getAccountByToken($token);
            if ($acc && !empty($acc['username'])) {
                return 'usr_' . $acc['username'];
            }
        }
        $uid = trim((string)$uid);
        return $uid !== '' ? $uid : '';
    }

    public static function fetchNeteaseProfile($uid)
    {
        $res = ['nickname' => '网易云用户 ' . $uid, 'avatar' => 'images/player_cover.png'];
        $uid = trim((string)$uid);
        if ($uid === '') return $res;
        $url = 'https://music.163.com/api/user/playlist/?offset=0&limit=1&uid=' . rawurlencode($uid);
        list($c, $b) = http_request($url, 'GET', ['Referer' => 'https://music.163.com/'], null, 8);
        if ($c === 200 && !empty($b)) {
            $j = json_decode($b, true);
            if (!empty($j['playlist'][0]['creator'])) {
                $creator = $j['playlist'][0]['creator'];
                if (!empty($creator['nickname'])) $res['nickname'] = $creator['nickname'];
                if (!empty($creator['avatarUrl'])) $res['avatar'] = $creator['avatarUrl'];
            }
        }
        return $res;
    }

    public static function getAccountByToken($token)
    {
        $token = trim((string)$token);
        if ($token === '') return null;
        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT id, username, netease_uid, netease_name, netease_avatar, created_at, last_login FROM cloud_accounts WHERE token = :t LIMIT 1");
            $stmt->execute([':t' => $token]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $d = self::getJsonData();
            if (isset($d['accounts'])) {
                foreach ($d['accounts'] as $acc) {
                    if (isset($acc['token']) && $acc['token'] === $token) {
                        return [
                            'id' => $acc['id'],
                            'username' => $acc['username'],
                            'netease_uid' => isset($acc['netease_uid']) ? $acc['netease_uid'] : '',
                            'netease_name' => isset($acc['netease_name']) ? $acc['netease_name'] : '',
                            'netease_avatar' => isset($acc['netease_avatar']) ? $acc['netease_avatar'] : '',
                            'created_at' => $acc['created_at'],
                            'last_login' => isset($acc['last_login']) ? $acc['last_login'] : 0
                        ];
                    }
                }
            }
            return null;
        }
    }

    public static function register($username, $password, $neteaseUid = '')
    {
        $username = trim((string)$username);
        $password = (string)$password;
        $neteaseUid = trim((string)$neteaseUid);

        if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 30) {
            return ['code' => 400, 'msg' => '用户名长度应为 2~30 位字符'];
        }
        if (strlen($password) < 4) {
            return ['code' => 400, 'msg' => '密码长度至少需要 4 位'];
        }

        $now = time();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(16));

        $nName = '';
        $nAvatar = '';
        if ($neteaseUid !== '') {
            $prof = self::fetchNeteaseProfile($neteaseUid);
            $nName = $prof['nickname'];
            $nAvatar = $prof['avatar'];
        }

        if (self::$useSqlite && self::$pdo) {
            $chk = self::$pdo->prepare("SELECT id FROM cloud_accounts WHERE username = :u LIMIT 1");
            $chk->execute([':u' => $username]);
            if ($chk->fetch()) {
                return ['code' => 409, 'msg' => '用户名已被注册，请直接登录或换一个用户名'];
            }

            $stmt = self::$pdo->prepare("INSERT INTO cloud_accounts (username, password_hash, token, netease_uid, netease_name, netease_avatar, created_at, last_login) VALUES (:u, :p, :t, :nuid, :nname, :navatar, :c, :l)");
            $stmt->execute([
                ':u' => $username,
                ':p' => $hash,
                ':t' => $token,
                ':nuid' => $neteaseUid,
                ':nname' => $nName,
                ':navatar' => $nAvatar,
                ':c' => $now,
                ':l' => $now
            ]);
            $accId = self::$pdo->lastInsertId();
            return [
                'code' => 200,
                'msg' => '注册成功',
                'token' => $token,
                'user' => [
                    'id' => $accId,
                    'username' => $username,
                    'netease_uid' => $neteaseUid,
                    'netease_name' => $nName,
                    'netease_avatar' => $nAvatar
                ]
            ];
        } else {
            $d = self::getJsonData();
            if (!isset($d['accounts'])) $d['accounts'] = [];
            foreach ($d['accounts'] as $acc) {
                if ($acc['username'] === $username) {
                    return ['code' => 409, 'msg' => '用户名已被注册，请直接登录'];
                }
            }
            $accId = count($d['accounts']) + 1;
            $newAcc = [
                'id' => $accId,
                'username' => $username,
                'password_hash' => $hash,
                'token' => $token,
                'netease_uid' => $neteaseUid,
                'netease_name' => $nName,
                'netease_avatar' => $nAvatar,
                'created_at' => $now,
                'last_login' => $now
            ];
            $d['accounts'][] = $newAcc;
            self::saveJsonData($d);
            return [
                'code' => 200,
                'msg' => '注册成功',
                'token' => $token,
                'user' => [
                    'id' => $accId,
                    'username' => $username,
                    'netease_uid' => $neteaseUid,
                    'netease_name' => $nName,
                    'netease_avatar' => $nAvatar
                ]
            ];
        }
    }

    public static function login($username, $password)
    {
        $username = trim((string)$username);
        $password = (string)$password;

        if ($username === '' || $password === '') {
            return ['code' => 400, 'msg' => '请输入用户名和密码'];
        }
        $now = time();
        $token = bin2hex(random_bytes(16));

        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT * FROM cloud_accounts WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $username]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc || !password_verify($password, $acc['password_hash'])) {
                return ['code' => 401, 'msg' => '用户名或密码错误'];
            }
            $upd = self::$pdo->prepare("UPDATE cloud_accounts SET token = :t, last_login = :l WHERE id = :id");
            $upd->execute([':t' => $token, ':l' => $now, ':id' => $acc['id']]);

            return [
                'code' => 200,
                'msg' => '登录成功',
                'token' => $token,
                'user' => [
                    'id' => $acc['id'],
                    'username' => $acc['username'],
                    'netease_uid' => $acc['netease_uid'],
                    'netease_name' => $acc['netease_name'],
                    'netease_avatar' => $acc['netease_avatar']
                ]
            ];
        } else {
            $d = self::getJsonData();
            if (isset($d['accounts'])) {
                foreach ($d['accounts'] as &$acc) {
                    if ($acc['username'] === $username) {
                        if (!password_verify($password, $acc['password_hash'])) {
                            return ['code' => 401, 'msg' => '用户名或密码错误'];
                        }
                        $acc['token'] = $token;
                        $acc['last_login'] = $now;
                        self::saveJsonData($d);
                        return [
                            'code' => 200,
                            'msg' => '登录成功',
                            'token' => $token,
                            'user' => [
                                'id' => $acc['id'],
                                'username' => $acc['username'],
                                'netease_uid' => isset($acc['netease_uid']) ? $acc['netease_uid'] : '',
                                'netease_name' => isset($acc['netease_name']) ? $acc['netease_name'] : '',
                                'netease_avatar' => isset($acc['netease_avatar']) ? $acc['netease_avatar'] : ''
                            ]
                        ];
                    }
                }
            }
            return ['code' => 401, 'msg' => '用户名或密码错误'];
        }
    }

    public static function bindNetease($token, $neteaseUid)
    {
        $neteaseUid = trim((string)$neteaseUid);
        if ($neteaseUid === '') return ['code' => 400, 'msg' => 'UID 不能为空'];

        $prof = self::fetchNeteaseProfile($neteaseUid);
        $nName = $prof['nickname'];
        $nAvatar = $prof['avatar'];

        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("UPDATE cloud_accounts SET netease_uid = :nuid, netease_name = :nname, netease_avatar = :navatar WHERE token = :t");
            $stmt->execute([':nuid' => $neteaseUid, ':nname' => $nName, ':navatar' => $nAvatar, ':t' => $token]);
            if ($stmt->rowCount() > 0) {
                return [
                    'code' => 200,
                    'msg' => '网易云绑定成功',
                    'user' => [
                        'netease_uid' => $neteaseUid,
                        'netease_name' => $nName,
                        'netease_avatar' => $nAvatar
                    ]
                ];
            }
            return ['code' => 401, 'msg' => '登录已过期，请重新登录'];
        } else {
            $d = self::getJsonData();
            if (isset($d['accounts'])) {
                foreach ($d['accounts'] as &$acc) {
                    if (isset($acc['token']) && $acc['token'] === $token) {
                        $acc['netease_uid'] = $neteaseUid;
                        $acc['netease_name'] = $nName;
                        $acc['netease_avatar'] = $nAvatar;
                        self::saveJsonData($d);
                        return [
                            'code' => 200,
                            'msg' => '网易云绑定成功',
                            'user' => [
                                'netease_uid' => $neteaseUid,
                                'netease_name' => $nName,
                                'netease_avatar' => $nAvatar
                            ]
                        ];
                    }
                }
            }
            return ['code' => 401, 'msg' => '登录已过期，请重新登录'];
        }
    }

    public static function unbindNetease($token)
    {
        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("UPDATE cloud_accounts SET netease_uid = '', netease_name = '', netease_avatar = '' WHERE token = :t");
            $stmt->execute([':t' => $token]);
            return ['code' => 200, 'msg' => '已解除网易云账号绑定'];
        } else {
            $d = self::getJsonData();
            if (isset($d['accounts'])) {
                foreach ($d['accounts'] as &$acc) {
                    if (isset($acc['token']) && $acc['token'] === $token) {
                        $acc['netease_uid'] = '';
                        $acc['netease_name'] = '';
                        $acc['netease_avatar'] = '';
                        self::saveJsonData($d);
                        return ['code' => 200, 'msg' => '已解除网易云账号绑定'];
                    }
                }
            }
            return ['code' => 200, 'msg' => '已解除网易云账号绑定'];
        }
    }


    public static function recordHistory($uid, $song)
    {
        $uid = trim((string)$uid);
        if ($uid === '' || empty($song['id'])) return false;
        $now = time();
        $songId = trim((string)$song['id']);
        $songName = trim(isset($song['name']) ? (string)$song['name'] : '未知歌曲');
        $artist = trim(isset($song['artist']) ? (string)$song['artist'] : '群星');
        $album = trim(isset($song['album']) ? (string)$song['album'] : '');
        $source = trim(isset($song['source']) ? (string)$song['source'] : 'netease');
        $pic = trim(isset($song['pic']) ? (string)$song['pic'] : '');

        if (self::$useSqlite && self::$pdo) {
            $del = self::$pdo->prepare("DELETE FROM cloud_history WHERE uid = :uid AND song_id = :sid AND source = :src");
            $del->execute([':uid' => $uid, ':sid' => $songId, ':src' => $source]);

            $ins = self::$pdo->prepare("INSERT INTO cloud_history (uid, song_id, song_name, artist, album, source, pic, played_at) VALUES (:uid, :sid, :name, :artist, :album, :src, :pic, :played_at)");
            $ins->execute([
                ':uid' => $uid,
                ':sid' => $songId,
                ':name' => $songName,
                ':artist' => $artist,
                ':album' => $album,
                ':src' => $source,
                ':pic' => $pic,
                ':played_at' => $now
            ]);

            self::$pdo->exec("DELETE FROM cloud_history WHERE id NOT IN (SELECT id FROM cloud_history WHERE uid = " . self::$pdo->quote($uid) . " ORDER BY played_at DESC LIMIT 300) AND uid = " . self::$pdo->quote($uid));
            return true;
        } else {
            $d = self::getJsonData();
            if (!isset($d['history'][$uid])) $d['history'][$uid] = [];
            $newList = [];
            foreach ($d['history'][$uid] as $item) {
                if ($item['song_id'] == $songId && $item['source'] == $source) continue;
                $newList[] = $item;
            }
            array_unshift($newList, [
                'id' => time() . mt_rand(100, 999),
                'uid' => $uid,
                'song_id' => $songId,
                'song_name' => $songName,
                'artist' => $artist,
                'album' => $album,
                'source' => $source,
                'pic' => $pic,
                'played_at' => $now
            ]);
            $d['history'][$uid] = array_slice($newList, 0, 300);
            self::saveJsonData($d);
            return true;
        }
    }

    public static function getHistory($uid, $limit = 100)
    {
        $uid = trim((string)$uid);
        if ($uid === '') return [];
        $limit = max(1, min(300, (int)$limit));
        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT song_id as id, song_name as name, artist, album, source, pic, played_at FROM cloud_history WHERE uid = :uid ORDER BY played_at DESC LIMIT :limit");
            $stmt->bindValue(':uid', $uid, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $d = self::getJsonData();
            $items = isset($d['history'][$uid]) ? $d['history'][$uid] : [];
            $res = [];
            foreach (array_slice($items, 0, $limit) as $it) {
                $res[] = [
                    'id' => $it['song_id'],
                    'name' => $it['song_name'],
                    'artist' => $it['artist'],
                    'album' => $it['album'],
                    'source' => $it['source'],
                    'pic' => $it['pic'],
                    'played_at' => $it['played_at']
                ];
            }
            return $res;
        }
    }

    public static function toggleFavorite($uid, $song)
    {
        $uid = trim((string)$uid);
        if ($uid === '' || empty($song['id'])) return ['liked' => false];
        $now = time();
        $songId = trim((string)$song['id']);
        $songName = trim(isset($song['name']) ? (string)$song['name'] : '未知歌曲');
        $artist = trim(isset($song['artist']) ? (string)$song['artist'] : '群星');
        $album = trim(isset($song['album']) ? (string)$song['album'] : '');
        $source = trim(isset($song['source']) ? (string)$song['source'] : 'netease');
        $pic = trim(isset($song['pic']) ? (string)$song['pic'] : '');

        if (self::$useSqlite && self::$pdo) {
            $chk = self::$pdo->prepare("SELECT id FROM cloud_favorites WHERE uid = :uid AND song_id = :sid AND source = :src LIMIT 1");
            $chk->execute([':uid' => $uid, ':sid' => $songId, ':src' => $source]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $del = self::$pdo->prepare("DELETE FROM cloud_favorites WHERE id = :id");
                $del->execute([':id' => $row['id']]);
                return ['liked' => false];
            } else {
                $ins = self::$pdo->prepare("INSERT INTO cloud_favorites (uid, song_id, song_name, artist, album, source, pic, created_at) VALUES (:uid, :sid, :name, :artist, :album, :src, :pic, :created_at)");
                $ins->execute([
                    ':uid' => $uid,
                    ':sid' => $songId,
                    ':name' => $songName,
                    ':artist' => $artist,
                    ':album' => $album,
                    ':src' => $source,
                    ':pic' => $pic,
                    ':created_at' => $now
                ]);
                return ['liked' => true];
            }
        } else {
            $d = self::getJsonData();
            if (!isset($d['favorites'][$uid])) $d['favorites'][$uid] = [];
            $foundIdx = -1;
            foreach ($d['favorites'][$uid] as $idx => $it) {
                if ($it['song_id'] == $songId && $it['source'] == $source) {
                    $foundIdx = $idx;
                    break;
                }
            }
            if ($foundIdx >= 0) {
                array_splice($d['favorites'][$uid], $foundIdx, 1);
                self::saveJsonData($d);
                return ['liked' => false];
            } else {
                array_unshift($d['favorites'][$uid], [
                    'song_id' => $songId,
                    'song_name' => $songName,
                    'artist' => $artist,
                    'album' => $album,
                    'source' => $source,
                    'pic' => $pic,
                    'created_at' => $now
                ]);
                self::saveJsonData($d);
                return ['liked' => true];
            }
        }
    }

    public static function getFavorites($uid)
    {
        $uid = trim((string)$uid);
        if ($uid === '') return [];
        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT song_id as id, song_name as name, artist, album, source, pic, created_at FROM cloud_favorites WHERE uid = :uid ORDER BY created_at DESC");
            $stmt->execute([':uid' => $uid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $d = self::getJsonData();
            $items = isset($d['favorites'][$uid]) ? $d['favorites'][$uid] : [];
            $res = [];
            foreach ($items as $it) {
                $res[] = [
                    'id' => $it['song_id'],
                    'name' => $it['song_name'],
                    'artist' => $it['artist'],
                    'album' => $it['album'],
                    'source' => $it['source'],
                    'pic' => $it['pic'],
                    'created_at' => $it['created_at']
                ];
            }
            return $res;
        }
    }

    /* ---------------------------------------------------------
     * 搜索历史云端持久化 (歌手/歌曲检索记录漫游)
     * --------------------------------------------------------- */
    public static function recordSearch($uid, $keyword, $source = 'all')
    {
        $uid = trim((string)$uid);
        $keyword = trim((string)$keyword);
        $source = trim((string)$source);
        if ($source === '') $source = 'all';
        if ($uid === '' || $keyword === '') return false;
        $now = time();

        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("INSERT INTO cloud_searches (uid, keyword, source, searched_at)
                VALUES (:uid, :kw, :src, :t)
                ON CONFLICT(uid, keyword) DO UPDATE SET searched_at = :t, source = :src");
            $stmt->execute([':uid' => $uid, ':kw' => $keyword, ':src' => $source, ':t' => $now]);
            self::$pdo->exec("DELETE FROM cloud_searches WHERE id NOT IN (SELECT id FROM cloud_searches WHERE uid = " . self::$pdo->quote($uid) . " ORDER BY searched_at DESC LIMIT 50) AND uid = " . self::$pdo->quote($uid));
            return true;
        } else {
            $d = self::getJsonData();
            if (!isset($d['searches'][$uid])) $d['searches'][$uid] = [];
            $newList = [];
            foreach ($d['searches'][$uid] as $item) {
                if ($item['keyword'] === $keyword) continue;
                $newList[] = $item;
            }
            array_unshift($newList, [
                'keyword' => $keyword,
                'source' => $source,
                'searched_at' => $now
            ]);
            $d['searches'][$uid] = array_slice($newList, 0, 50);
            self::saveJsonData($d);
            return true;
        }
    }

    public static function getSearches($uid, $limit = 20)
    {
        $uid = trim((string)$uid);
        if ($uid === '') return [];
        $limit = max(1, min(50, (int)$limit));

        if (self::$useSqlite && self::$pdo) {
            $stmt = self::$pdo->prepare("SELECT keyword, source, searched_at FROM cloud_searches WHERE uid = :uid ORDER BY searched_at DESC LIMIT " . $limit);
            $stmt->execute([':uid' => $uid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $d = self::getJsonData();
            if (!isset($d['searches'][$uid])) return [];
            return array_slice($d['searches'][$uid], 0, $limit);
        }
    }

    public static function deleteSearch($uid, $keyword = '')
    {
        $uid = trim((string)$uid);
        if ($uid === '') return false;
        $keyword = trim((string)$keyword);

        if (self::$useSqlite && self::$pdo) {
            if ($keyword !== '') {
                $stmt = self::$pdo->prepare("DELETE FROM cloud_searches WHERE uid = :uid AND keyword = :kw");
                $stmt->execute([':uid' => $uid, ':kw' => $keyword]);
            } else {
                $stmt = self::$pdo->prepare("DELETE FROM cloud_searches WHERE uid = :uid");
                $stmt->execute([':uid' => $uid]);
            }
            return true;
        } else {
            $d = self::getJsonData();
            if (!isset($d['searches'][$uid])) return true;
            if ($keyword !== '') {
                $newList = [];
                foreach ($d['searches'][$uid] as $item) {
                    if ($item['keyword'] === $keyword) continue;
                    $newList[] = $item;
                }
                $d['searches'][$uid] = $newList;
            } else {
                $d['searches'][$uid] = [];
            }
            self::saveJsonData($d);
            return true;
        }
    }
}
MusicStorage::init();

/* ---------------------------------------------------------
 * 8. 路由总分发
 * --------------------------------------------------------- */
$type   = isset($_REQUEST['types']) ? trim($_REQUEST['types']) : '';
$source = norm_source(isset($_REQUEST['source']) ? $_REQUEST['source'] : 'kuwo');
$id     = isset($_REQUEST['id']) ? trim($_REQUEST['id']) : '';

// 兼容 CDN / 反向代理过滤 Query 参数而将数据以 JSON POST 传递的场景
if ($type === '' && !empty($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            if (!empty($jsonData['types'])) $type = trim($jsonData['types']);
            if (!empty($jsonData['source'])) $source = norm_source($jsonData['source']);
            if (!empty($jsonData['id'])) $id = trim($jsonData['id']);
        }
    }
}

// 专用动作
if ($type === 'proxy' || $type === 'fetchsrc') {
    handle_proxy();
}
if ($type === 'stream') {
    handle_stream();
}
if ($type === 'sources') {
    handle_sources();
}
if ($type === 'getsource') {
    handle_getsource();
}

// 播放器标准操作
switch ($type) {
    case 'search':
        $kw    = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : '';
        $page  = max(1, (int) (isset($_REQUEST['pages']) ? $_REQUEST['pages'] : 1));
        $count = min(50, max(5, (int) (isset($_REQUEST['count']) ? $_REQUEST['count'] : 20)));
        if ($kw === '') {
            out_json([]);
        }

        $res = [];
        switch ($source) {
            case 'qq':
                $res = search_qq($kw, $page, $count);
                if (empty($res)) $res = search_kuwo($kw, $page, $count);
                break;
            case 'netease':
                $res = search_netease($kw, $page, $count);
                if (empty($res)) $res = search_kuwo($kw, $page, $count);
                break;
            case 'kugou':
                $res = search_kugou($kw, $page, $count);
                if (empty($res)) $res = search_kuwo($kw, $page, $count);
                break;
            case 'kuwo':
                $res = search_kuwo($kw, $page, $count);
                if (empty($res)) $res = search_netease($kw, $page, $count);
                break;
            case 'all':
            default:
                $res = search_all($kw, $page, $count);
                break;
        }
        out_json($res);

    case 'playlist':
        $reqSrc = isset($_REQUEST['source']) ? trim($_REQUEST['source']) : '';
        if ($reqSrc === 'qq' || stripos($id, 'qq') !== false || stripos($id, 'y.qq.com') !== false) {
            $res = playlist_qq($id);
        } elseif ($reqSrc === 'kugou' || stripos($id, 'kugou') !== false) {
            $res = playlist_kugou($id);
        } else {
            $res = playlist_netease($id);
            if (($res['code'] !== 200 || empty($res['playlist']['tracks'])) && preg_match('/^\d+$/', $id)) {
                $qqRes = playlist_qq($id);
                if ($qqRes['code'] === 200 && !empty($qqRes['playlist']['tracks'])) {
                    $res = $qqRes;
                }
            }
        }
        out_json($res);

    case 'userlist':
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $res = userlist_netease($uid);
        out_json($res);

    case 'url':
        $u = '';
        switch ($source) {
            case 'netease':
                $u = url_netease($id);
                break;
            default:
                $u = url_kuwo($id);
                if (empty($u)) $u = url_netease($id);
                break;
        }
        out_json(['url' => $u]);

    case 'pic':
        out_json(['url' => $id]);

    case 'lyric':
        $l = '';
        switch ($source) {
            case 'netease':
                $l = lyric_netease($id);
                break;
            case 'qq':
                $l = lyric_qq($id);
                break;
            case 'kugou':
                $l = lyric_kugou($id);
                break;
            default:
                $l = lyric_kuwo($id);
                if (empty($l)) $l = lyric_netease($id);
                break;
        }
        out_json(['lyric' => $l]);

    // -------------------------------------------------------------
    // 用户名 + 密码 账号与网易云 UID 绑定体系 (SQLite 驱动)
    // -------------------------------------------------------------
    case 'user_register':
        $u = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
        $p = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '';
        $nuid = isset($_REQUEST['netease_uid']) ? trim($_REQUEST['netease_uid']) : '';
        $res = MusicStorage::register($u, $p, $nuid);
        out_json($res);

    case 'user_login':
        $u = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
        $p = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '';
        $res = MusicStorage::login($u, $p);
        out_json($res);

    case 'user_profile':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $acc = MusicStorage::getAccountByToken($tok);
        if (!$acc) {
            out_json(['code' => 401, 'msg' => '未登录或登录已过期']);
        }
        $key = 'usr_' . $acc['username'];
        $favCount = count(MusicStorage::getFavorites($key));
        $histCount = count(MusicStorage::getHistory($key, 300));
        $searchCount = count(MusicStorage::getSearches($key, 50));
        out_json([
            'code' => 200,
            'user' => $acc,
            'stat' => [
                'favorites' => $favCount,
                'history' => $histCount,
                'searches' => $searchCount
            ]
        ]);

    case 'user_bind_netease':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $nuid = isset($_REQUEST['netease_uid']) ? trim($_REQUEST['netease_uid']) : '';
        $res = MusicStorage::bindNetease($tok, $nuid);
        out_json($res);

    case 'user_unbind_netease':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $res = MusicStorage::unbindNetease($tok);
        out_json($res);

    // 云端数据持久化支持 (支持 Token 或 UID 双模识别)
    case 'sync_account':
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $nick = isset($_REQUEST['nickname']) ? trim($_REQUEST['nickname']) : '';
        $avatar = isset($_REQUEST['avatar']) ? trim($_REQUEST['avatar']) : '';
        if ($uid === '') out_json(['code' => 400, 'msg' => 'UID required']);
        $user = MusicStorage::syncUser($uid, $nick, $avatar);
        $favCount = count(MusicStorage::getFavorites($uid));
        $histCount = count(MusicStorage::getHistory($uid, 300));
        $searchCount = count(MusicStorage::getSearches($uid, 50));
        out_json([
            'code' => 200,
            'user' => $user,
            'stat' => [
                'favorites' => $favCount,
                'history' => $histCount,
                'searches' => $searchCount
            ]
        ]);

    case 'record_history':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $songRaw = isset($_REQUEST['song']) ? $_REQUEST['song'] : '';
        $song = is_array($songRaw) ? $songRaw : json_decode($songRaw, true);
        if ($key === '' || empty($song)) out_json(['code' => 400]);
        $ok = MusicStorage::recordHistory($key, $song);
        out_json(['code' => $ok ? 200 : 500]);

    case 'get_history':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $list = MusicStorage::getHistory($key, 200);
        out_json(['code' => 200, 'list' => $list]);

    case 'toggle_favorite':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $songRaw = isset($_REQUEST['song']) ? $_REQUEST['song'] : '';
        $song = is_array($songRaw) ? $songRaw : json_decode($songRaw, true);
        if ($key === '' || empty($song)) out_json(['code' => 400]);
        $res = MusicStorage::toggleFavorite($key, $song);
        out_json(['code' => 200, 'liked' => $res['liked']]);

    case 'get_favorites':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $list = MusicStorage::getFavorites($key);
        out_json(['code' => 200, 'list' => $list]);

    case 'record_search':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $keyword = isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '';
        $source = isset($_REQUEST['source']) ? trim($_REQUEST['source']) : 'all';
        if ($key === '' || $keyword === '') out_json(['code' => 400, 'msg' => 'Key or keyword missing']);
        $ok = MusicStorage::recordSearch($key, $keyword, $source);
        out_json(['code' => $ok ? 200 : 500]);

    case 'get_searches':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 20;
        if ($key === '') out_json(['code' => 200, 'list' => []]);
        $list = MusicStorage::getSearches($key, $limit);
        out_json(['code' => 200, 'list' => $list]);

    case 'delete_search':
        $tok = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        $uid = isset($_REQUEST['uid']) ? trim($_REQUEST['uid']) : '';
        $key = MusicStorage::resolveKey($tok, $uid);
        $keyword = isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '';
        if ($key === '') out_json(['code' => 400, 'msg' => 'Key missing']);
        $ok = MusicStorage::deleteSearch($key, $keyword);
        out_json(['code' => $ok ? 200 : 500]);

    default:
        // 未匹配时返回就绪状态
        out_json([
            'status' => 'ready',
            'version' => '2.3.0',
            'endpoints' => ['search', 'playlist', 'userlist', 'url', 'pic', 'lyric', 'proxy', 'stream', 'sources', 'getsource', 'sync_account', 'record_history', 'get_history', 'toggle_favorite', 'get_favorites', 'record_search', 'get_searches', 'delete_search']
        ]);
}

