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
    if ($CALLBACK !== '') {
        header('Content-Type: application/javascript; charset=utf-8');
        echo $CALLBACK . '(' . json_encode($data, JSON_UNESCAPED_UNICODE) . ');';
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) {
            $h = trim($headerLine);
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
        'kuwo' => 'kuwo', 'kw' => 'kuwo',
        'kugou' => 'kugou', 'kg' => 'kugou',
        'qq' => 'qq', 'tx' => 'qq', 'tencent' => 'qq',
        'netease' => 'netease', 'wy' => 'netease', '163' => 'netease',
    ];
    return isset($map[$s]) ? $map[$s] : 'kuwo';
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
    return 'https://music.163.com/song/media/outer/url?id=' . $id . '.mp3';
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
            default:
                $res = search_kuwo($kw, $page, $count);
                if (empty($res)) $res = search_netease($kw, $page, $count);
                break;
        }
        out_json($res);

    case 'playlist':
        $res = playlist_netease($id);
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
            default:
                $l = lyric_kuwo($id);
                if (empty($l)) $l = lyric_netease($id);
                break;
        }
        out_json(['lyric' => $l]);

    default:
        // 未匹配时返回就绪状态
        out_json([
            'status' => 'ready',
            'version' => '2.1.0',
            'endpoints' => ['search', 'playlist', 'userlist', 'url', 'pic', 'lyric', 'proxy', 'stream', 'sources', 'getsource']
        ]);
}
