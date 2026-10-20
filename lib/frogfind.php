<?php
// Shared helpers that keep FrogFind standing up under bot traffic.
// Every page includes this file first, then calls ff_block_crawlers().
//
//   1. Settings
//   2. Small page helpers
//   3. Crawler block
//   4. Busy valve (caps how many outside fetches run at once)
//   5. Signed links
//   6. Safe fetch (timeouts, size caps, no private addresses)
//   7. File cache

// ---------------------------------------------------------------------------
// 1. Settings. The secret lives in config.php (not in git). Anything below
//    can be overridden there with its own define().
// ---------------------------------------------------------------------------

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    echo 'FrogFind is not set up yet: copy config.example.php to config.php and put a long random secret in it.';
    exit();
}
require_once __DIR__ . '/../config.php';

if (!defined('FF_SECRET') || strlen(FF_SECRET) < 16 || FF_SECRET === 'change-me-to-a-long-random-string') {
    http_response_code(500);
    echo 'FrogFind is not set up yet: put a long random secret in config.php.';
    exit();
}

// Where cached pages and lock files go. Best kept outside the web root.
if (!defined('FF_CACHE_DIR')) define('FF_CACHE_DIR', sys_get_temp_dir() . '/frogfind-cache');
// Give the cache this much disk before the oldest files get thrown out.
if (!defined('FF_CACHE_MAX_BYTES')) define('FF_CACHE_MAX_BYTES', 500 * 1024 * 1024);
// How long each kind of thing stays cached, in seconds.
if (!defined('FF_CACHE_TTLS')) define('FF_CACHE_TTLS', [
    'search' => 3600,       // search results: 1 hour
    'read'   => 86400,      // reader pages: 1 day
    'image'  => 7 * 86400,  // shrunk images: 1 week
]);

// How many outside fetches may run at the same time, and how long a request
// waits for a free slot before getting the "busy" page.
if (!defined('FF_SLOTS')) define('FF_SLOTS', 8);
if (!defined('FF_SLOT_WAIT')) define('FF_SLOT_WAIT', 1.0);

// Connect timeout, and how long a remote server may stall mid-transfer.
if (!defined('FF_FETCH_TIMEOUT')) define('FF_FETCH_TIMEOUT', 8);
// Hard limit for one whole fetch, redirects included.
if (!defined('FF_FETCH_MAX_TIME')) define('FF_FETCH_MAX_TIME', 30);
if (!defined('FF_MAX_REDIRECTS')) define('FF_MAX_REDIRECTS', 3);
if (!defined('FF_MAX_HTML_BYTES')) define('FF_MAX_HTML_BYTES', 3 * 1024 * 1024);
if (!defined('FF_MAX_DOWNLOAD_BYTES')) define('FF_MAX_DOWNLOAD_BYTES', 8 * 1024 * 1024);
if (!defined('FF_IMAGE_MAX_SIDE')) define('FF_IMAGE_MAX_SIDE', 4000);
// Some sites (Wikipedia, for one) refuse requests that don't say who they are.
if (!defined('FF_USER_AGENT')) define('FF_USER_AGENT', 'Mozilla/5.0 (compatible; FrogFind/1.0; +http://frogfind.com/about.php)');

// Signed links last 24 to 25 hours. The address box token lasts an hour.
if (!defined('FF_LINK_LIFETIME')) define('FF_LINK_LIFETIME', 86400);
if (!defined('FF_BOX_TOKEN_LIFETIME')) define('FF_BOX_TOKEN_LIFETIME', 3600);

// ---------------------------------------------------------------------------
// 2. Small page helpers
// ---------------------------------------------------------------------------

// Escape anything that came from a visitor or another site before echoing it.
function ff_h($str) {
    return htmlspecialchars((string) $str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Make a URL safe inside href="..." without turning & into &amp;, which the
// oldest browsers may not decode in links.
function ff_href($url) {
    return strtr((string) $url, ['"' => '%22', "'" => '%27', '<' => '%3C', '>' => '%3E', ' ' => '%20']);
}

// A query string value, or '' if it's missing or not a plain string.
function ff_param($name) {
    return (isset($_GET[$name]) && is_string($_GET[$name])) ? $_GET[$name] : '';
}

// Let browsers and a CDN keep a copy for $seconds.
function ff_cache_headers($seconds) {
    header_remove('Pragma');
    header('Cache-Control: public, max-age=' . $seconds);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
}

// Print a tiny page and stop. Used for errors, so it is never cached.
function ff_small_page($status, $title, $body_html) {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 2.0//EN\">\n<html>\n<head>\n<title>" . ff_h($title) . "</title>\n"
        . "<meta name=\"robots\" content=\"noindex,nofollow\">\n</head>\n<body>\n" . $body_html . "\n</body>\n</html>\n";
    exit();
}

// ---------------------------------------------------------------------------
// 3. Crawler block. Plenty of AI and SEO crawlers ignore robots.txt, so
//    the ones that say who they are get refused before any work happens.
// ---------------------------------------------------------------------------

function ff_block_crawlers() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (preg_match('/GPTBot|ClaudeBot|CCBot|Bytespider|Amazonbot|PetalBot|meta-externalagent|AhrefsBot|SemrushBot|DotBot|MJ12bot|DataForSeoBot|ImagesiftBot/i', $ua)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        echo "Crawlers are not allowed on FrogFind. See /robots.txt\n";
        exit();
    }
}

// ---------------------------------------------------------------------------
// 4. Busy valve. Only FF_SLOTS requests may fetch and process outside pages
//    at once. Everyone else waits up to FF_SLOT_WAIT seconds, then gets a
//    tiny "busy" page instead of piling more work onto the server.
//    The slot is a file lock, so PHP lets go of it when the request ends.
// ---------------------------------------------------------------------------

function ff_busy_valve() {
    static $slot = null;
    if ($slot) return;

    $dir = FF_CACHE_DIR . '/slots';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $deadline = microtime(true) + FF_SLOT_WAIT;
    $first = mt_rand(0, FF_SLOTS - 1);
    do {
        for ($i = 0; $i < FF_SLOTS; $i++) {
            $handle = @fopen($dir . '/slot' . (($first + $i) % FF_SLOTS) . '.lock', 'c');
            if (!$handle) {
                // Can't write to the cache folder. Better to run without the
                // valve than to show "busy" to everyone forever.
                error_log('FrogFind: busy valve disabled, cannot write to ' . $dir);
                return;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $slot = $handle;
                return;
            }
            fclose($handle);
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    header('Retry-After: 60');
    ff_small_page(503, 'FrogFind is busy',
        '<h1>FrogFind is busy</h1><p>FrogFind is busy, leap back in a minute.</p><p><a href="/">Back to FrogFind</a></p>');
}

// ---------------------------------------------------------------------------
// 5. Signed links. read.php, image.php and image_compressed.php only fetch
//    addresses that FrogFind itself linked to in the last day, so the site
//    can't be used as an open proxy or crawled forever.
//    Links look like /read.php?a=<url>&e=<expiry>&s=<signature>. The & is
//    left bare on purpose: the oldest browsers may not decode &amp; in links.
// ---------------------------------------------------------------------------

function ff_sign($kind, $value, $expiry) {
    return substr(hash_hmac('sha256', $kind . '|' . $value . '|' . $expiry, FF_SECRET), 0, 32);
}

function ff_signed_query($param, $kind, $url) {
    $url = trim($url); // the pages trim what they receive, so sign the same thing
    // Round to the hour so the same page always gets the same links for a
    // while, which keeps them cacheable.
    $expiry = (intdiv(time(), 3600) + 1) * 3600 + FF_LINK_LIFETIME;
    return $param . '=' . rawurlencode($url) . '&e=' . $expiry . '&s=' . ff_sign($kind, $url, $expiry);
}

function ff_read_link($url) {
    return '/read.php?' . ff_signed_query('a', 'read', $url);
}

function ff_image_page_link($url) {
    return '/image.php?' . ff_signed_query('i', 'image', $url);
}

function ff_image_src($url) {
    return '/image_compressed.php?' . ff_signed_query('i', 'image', $url);
}

// Does this request carry a valid, unexpired signature for $url?
function ff_link_is_signed($kind, $url) {
    $expiry = ff_param('e');
    $signature = ff_param('s');
    if (!ctype_digit($expiry) || $signature === '') return false;
    $expiry = (int) $expiry;
    if ($expiry < time() || $expiry > time() + FF_LINK_LIFETIME + 7200) return false;
    return hash_equals(ff_sign($kind, $url, $expiry), $signature);
}

// The "Browsing URL" box takes any address, so it carries a token that says
// "this form came from FrogFind recently" instead of a per-URL signature.
function ff_box_token() {
    $time = intdiv(time(), 300) * 300;
    return $time . '-' . ff_sign('box', '', $time);
}

function ff_box_token_is_valid($token) {
    if (!preg_match('/^(\d+)-([0-9a-f]{32})$/', $token, $m)) return false;
    $time = (int) $m[1];
    if ($time > time() || $time < time() - FF_BOX_TOKEN_LIFETIME) return false;
    return hash_equals(ff_sign('box', '', $time), $m[2]);
}

// Point every web link in a chunk of reader HTML back through FrogFind with a
// fresh signature. Every other attribute goes, on links and on the other tags
// the reader keeps, so nothing like onclick= or onfocus= survives.
function ff_proxy_links($html) {
    $html = preg_replace('/<(\/?)(ol|ul|li|br|p|small|font|b|i|strong|em|blockquote|h[1-6])\b[^>]*>/i', '<$1$2>', $html);
    return preg_replace_callback('/<a\s[^>]*>/i', function ($m) {
        if (preg_match('/\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[0], $href)) {
            $url = html_entity_decode($href[1] !== '' ? $href[1] : (isset($href[2]) ? $href[2] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^https?://#i', $url)) {
                return '<a href="' . ff_read_link($url) . '" rel="nofollow">';
            }
            if (preg_match('/^(mailto:|#)/i', $url)) {
                return '<a href="' . ff_href($url) . '">';
            }
        }
        return '<a>';
    }, $html);
}

// ---------------------------------------------------------------------------
// 6. Safe fetch. Every outside request goes through ff_fetch(), which:
//    - only speaks http and https
//    - looks up the address itself and refuses private, loopback, link-local
//      and cloud metadata addresses (169.254.169.254), then pins curl to the
//      address it checked so a second DNS answer can't sneak past
//    - follows at most FF_MAX_REDIRECTS redirects, checking each one again
//    - gives up on slow servers, and stops reading once the body passes the
//      size cap (it doesn't trust Content-Length)
// ---------------------------------------------------------------------------

function ff_ip_is_public($ip) {
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    // An IPv4 address written as IPv6 (::ffff:127.0.0.1) gets checked as IPv4.
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $bin = substr($bin, 12);
    }
    $blocked = strlen($bin) === 4
        ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
           '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
           '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
        : ['::/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16',
           'fc00::/7', 'fe80::/10', 'ff00::/8'];
    foreach ($blocked as $cidr) {
        list($net, $bits) = explode('/', $cidr);
        $net = inet_pton($net);
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) continue;
        if ($rest === 0) return false;
        $mask = (0xff << (8 - $rest)) & 0xff;
        if ((ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) return false;
    }
    return true;
}

// Look up $host and return one public IP for it, or '' (with $error set).
// Refuses the host if any of its addresses is private.
function ff_resolve_public($host, &$error) {
    $host = strtolower(trim($host, '[]'));
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            $error = "couldn't find that website";
            return '';
        }
    }
    foreach ($ips as $ip) {
        if (!ff_ip_is_public($ip)) {
            $error = "that address isn't on the public internet";
            return '';
        }
    }
    return $ips[0];
}

// Turn a relative address (from a redirect or an image) into a full one.
function ff_absolute_url($base, $relative) {
    $relative = trim($relative);
    if ($relative === '') return $base;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) return $relative;
    $b = parse_url($base);
    if (!$b || !isset($b['scheme'], $b['host'])) return $relative;
    if (substr($relative, 0, 2) === '//') return $b['scheme'] . ':' . $relative;
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    $path = isset($b['path']) ? $b['path'] : '/';
    if ($relative[0] === '/') return $origin . $relative;
    if ($relative[0] === '?') return $origin . $path . $relative;
    if ($relative[0] === '#') return $origin . $path . (isset($b['query']) ? '?' . $b['query'] : '') . $relative;
    return $origin . preg_replace('#/[^/]*$#', '/', $path) . $relative;
}

// "text/html; charset=UTF-8" -> "text/html"
function ff_media_type($content_type) {
    return strtolower(trim(explode(';', (string) $content_type)[0]));
}

function ff_is_html_type($media_type) {
    return $media_type === '' || $media_type === 'text/html' || $media_type === 'text/plain' || $media_type === 'application/xhtml+xml';
}

// Fetch $url. HTML/text bodies may be up to $max_html_bytes, anything else up
// to $max_other_bytes. Returns an array:
//   ok          true if we got a 2xx response
//   status      final HTTP status (0 if we never got one)
//   url         final address after redirects
//   headers     final response headers, names lowercased, last value wins
//   media_type  e.g. "text/html"
//   body        the response body
//   error       a short, human reason when ok is false
//   too_big     true if the body passed its size cap
function ff_fetch($url, $max_html_bytes, $max_other_bytes, $user_agent = FF_USER_AGENT) {
    $result = ['ok' => false, 'status' => 0, 'url' => $url, 'headers' => [], 'media_type' => '',
               'body' => '', 'error' => '', 'too_big' => false];
    $deadline = time() + FF_FETCH_MAX_TIME;

    for ($hop = 0; $hop <= FF_MAX_REDIRECTS; $hop++) {
        $url = str_replace(' ', '%20', trim($url));
        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (!$parts || !isset($parts['host']) || ($scheme !== 'http' && $scheme !== 'https')) {
            $result['error'] = "that's not a web page address";
            return $result;
        }
        // Backslashes, control characters and user:password@ are places where
        // PHP and curl can disagree about which host a URL points at.
        if (preg_match('/[\\\\\x00-\x20\x7f]/', $url) || isset($parts['user']) || isset($parts['pass'])) {
            $result['error'] = "couldn't understand that address";
            return $result;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (preg_match('/[^\x21-\x7e]/', $host)) {
            // International domain name: curl and our DNS check must agree on
            // the name, so switch to the ASCII form before going further.
            $host = function_exists('idn_to_ascii') ? strtolower((string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)) : '';
            if ($host === '') {
                $result['error'] = "couldn't understand that address";
                return $result;
            }
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9._-]+$/', $host)) {
            $result['error'] = "couldn't understand that address";
            return $result;
        }
        // Rebuild the URL from the parts we checked, so curl sees exactly the
        // host we looked up.
        $url = $scheme . '://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host)
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . (isset($parts['path']) ? $parts['path'] : '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $error = '';
        $ip = ff_resolve_public($host, $error);
        if ($ip === '') {
            $result['error'] = $error;
            return $result;
        }

        $headers = [];
        $body = '';
        $too_big = false;
        $ch = curl_init($url);
        if (!$ch) {
            $result['error'] = "couldn't understand that address";
            return $result;
        }
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => FF_FETCH_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => FF_FETCH_TIMEOUT,
            CURLOPT_TIMEOUT => max(1, $deadline - time()),
            CURLOPT_ENCODING => '',
            CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => $user_agent,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (strncasecmp($line, 'HTTP/', 5) === 0) {
                    $headers = []; // a new response (e.g. after "100 Continue")
                } elseif (strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$headers, &$body, &$too_big, $max_html_bytes, $max_other_bytes) {
                $type = ff_media_type(isset($headers['content-type']) ? $headers['content-type'] : '');
                $limit = ff_is_html_type($type) ? $max_html_bytes : $max_other_bytes;
                $declared = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
                if ($declared > $limit || strlen($body) + strlen($chunk) > $limit) {
                    $too_big = true;
                    return 0; // tells curl to stop
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            // Make curl connect to the address we just checked, not whatever
            // DNS says a moment later.
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ip];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $connected_ip = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $curl_error = curl_errno($ch);
        unset($ch);

        $result['status'] = $status;
        $result['url'] = $url;
        $result['headers'] = $headers;
        $result['media_type'] = ff_media_type(isset($headers['content-type']) ? $headers['content-type'] : '');
        $result['body'] = $body;
        $result['too_big'] = $too_big;

        if ($connected_ip !== '' && !ff_ip_is_public($connected_ip)) {
            $result['body'] = '';
            $result['error'] = "that address isn't on the public internet";
            return $result;
        }
        if ($too_big) {
            $result['error'] = 'the page is too big';
            return $result;
        }
        if ($ok === false) {
            $result['error'] = $curl_error === CURLE_OPERATION_TIMEDOUT ? 'the website took too long to answer' : "couldn't reach the website";
            return $result;
        }
        if (in_array($status, [301, 302, 303, 307, 308]) && isset($headers['location'])) {
            $url = ff_absolute_url($url, $headers['location']);
            continue;
        }
        $result['ok'] = $status >= 200 && $status < 300;
        if (!$result['ok']) {
            $result['error'] = 'the website answered with error ' . $status;
        }
        return $result;
    }

    $result['ok'] = false;
    $result['body'] = '';
    $result['error'] = 'too many redirects';
    return $result;
}

// ---------------------------------------------------------------------------
// 7. File cache. One file per item, sharded into folders by hash. Old files
//    are swept out every ten minutes or so, and the oldest go first if the
//    cache grows past FF_CACHE_MAX_BYTES.
// ---------------------------------------------------------------------------

function ff_cache_file($kind, $key) {
    $hash = sha1($key);
    return FF_CACHE_DIR . '/' . $kind . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
}

// The cached string, or null if there isn't a fresh one.
function ff_cache_get($kind, $key) {
    $file = ff_cache_file($kind, $key);
    $mtime = @filemtime($file);
    if ($mtime === false || $mtime < time() - FF_CACHE_TTLS[$kind]) return null;
    $data = @file_get_contents($file);
    return $data === false ? null : $data;
}

function ff_cache_put($kind, $key, $data) {
    $file = ff_cache_file($kind, $key);
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    // Write to a temp file and rename, so nobody reads half a file.
    $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temp, $data) !== false && @rename($temp, $file)) {
        // Tidy up after the visitor has their page, not before.
        static $scheduled = false;
        if (!$scheduled) {
            $scheduled = true;
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                ff_cache_cleanup();
            });
        }
    } else {
        @unlink($temp);
    }
}

function ff_cache_cleanup() {
    $marker = FF_CACHE_DIR . '/last-cleanup';
    $last = @filemtime($marker);
    if ($last !== false && $last > time() - 600) return;
    $lock = @fopen(FF_CACHE_DIR . '/cleanup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    @touch($marker);

    $now = time();
    $keep = [];
    $total = 0;
    foreach (FF_CACHE_TTLS as $kind => $ttl) {
        foreach (glob(FF_CACHE_DIR . '/' . $kind . '/*/*') ?: [] as $file) {
            $stat = @stat($file);
            if (!$stat) continue;
            $max_age = substr($file, -4) === '.tmp' ? 3600 : $ttl;
            if ($stat['mtime'] < $now - $max_age) {
                @unlink($file);
                continue;
            }
            $keep[$file] = [$stat['mtime'], $stat['size']];
            $total += $stat['size'];
        }
    }
    if ($total > FF_CACHE_MAX_BYTES) {
        uasort($keep, function ($a, $b) { return $a[0] - $b[0]; }); // oldest first
        foreach ($keep as $file => $info) {
            if ($total <= FF_CACHE_MAX_BYTES * 0.8) break;
            @unlink($file);
            $total -= $info[1];
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
