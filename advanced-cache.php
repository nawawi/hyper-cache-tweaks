<?php

$GLOBALS['hyper_cache_stop'] = false;

if (!\function_exists('hyper_cache_sanitize_uri')) {
    function hyper_cache_sanitize_uri($uri)
    {
        $uri = strtok($uri, '?');
        $uri = preg_replace('|[^a-zA-Z0-9/\-_]+|', '_', $uri);
        $uri = preg_replace('|/+|', '/', $uri);
        $uri = rtrim($uri, '/');
        if (empty($uri) || '/' != $uri[0]) {
            $uri = '/'.$uri;
        }

        return $uri;
    }
}

if (!\function_exists('hyper_cache_sanitize_host')) {
    function hyper_cache_sanitize_host($host)
    {
        $host = preg_replace('|[^a-zA-Z0-9\.\-]+|', '', $host);

        return strtolower($host);
    }
}

if (!\function_exists('hyper_cache_header')) {
    function hyper_cache_header($value)
    {
        if (!headers_sent()) {
            header('X-Cache: '.$value, false);
        }
    }
}

if (!\function_exists('hyper_cache_ignoreqs')) {
    function hyper_cache_ignoreqs()
    {
        if (!empty($_GET)) {
            $hyper_cache_iqs = array_diff_key(
                $_GET,
                [
                    'utm_source' => 1,
                    'utm_medium' => 1,
                    'utm_campaign' => 1,
                    'utm_expid' => 1,
                    'fb_action_ids' => 1,
                    'fb_action_types' => 1,
                    'fb_source' => 1,
                    'fbclid' => 1,
                    'campaignid' => 1,
                    'adgroupid' => 1,
                    'adid' => 1,
                    'gclid' => 1,
                    'age-verified' => 1,
                    'ao_noptimize' => 1,
                    'usqp' => 1,
                    'cn-reloaded' => 1,
                    '_ga' => 1,
                ]
            );

            if (!empty($hyper_cache_iqs)) {
                return true;
            }
        }

        return false;
    }
}

if (!isset($_SERVER['HTTP_HOST'])) {
    hyper_cache_header('stop - invalid host');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

// Use this only if you can't or don't want to modify the .htaccess
if ('GET' != $_SERVER['REQUEST_METHOD']) {
    hyper_cache_header('stop - non get');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

// Globally used. Here because of the function "theme change"
if (\defined('HYPER_CACHE_IS_MOBILE')) {
    $GLOBALS['hyper_cache_is_mobile'] = (bool) HYPER_CACHE_IS_MOBILE;
} elseif (\defined('IS_PHONE')) {
    $GLOBALS['hyper_cache_is_mobile'] = IS_PHONE;
} else {
    $GLOBALS['hyper_cache_is_mobile'] = isset($_SERVER['HTTP_USER_AGENT']) ? preg_match('#(HC_MOBILE_AGENTS)#i', $_SERVER['HTTP_USER_AGENT']) : false;
}

$hyper_cache_gzip_accepted = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && false !== strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip');

$hyper_cache_is_bot = isset($_SERVER['HTTP_USER_AGENT']) && preg_match('#(googlebot)#i', $_SERVER['HTTP_USER_AGENT']);

if (HC_MOBILE === 2 && $GLOBALS['hyper_cache_is_mobile']) {
    hyper_cache_header('stop - mobile');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

if (!empty($_SERVER['QUERY_STRING'])) {
    if (hyper_cache_ignoreqs()) {
        hyper_cache_header('stop - query string');
        $GLOBALS['hyper_cache_stop'] = true;

        return false;
    }
}

if (\defined('SID') && '' != (string) SID) {
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

/*if (isset($_SERVER['HTTP_CACHE_CONTROL']) && 'no-cache' == $_SERVER['HTTP_CACHE_CONTROL']) {
    hyper_cache_header('stop - no cache header');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

if (isset($_SERVER['HTTP_PRAGMA']) && 'no-cache' == $_SERVER['HTTP_PRAGMA']) {
    hyper_cache_header('stop - no cache header');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}*/

// Used globally
$hyper_cache_is_ssl = false;

// Copied from WP core
if (isset($_SERVER['HTTPS'])) {
    $server_https = strtolower($_SERVER['HTTPS']);
    if ('on' == $server_https || '1' == $server_https) {
        $hyper_cache_is_ssl = true;
    } elseif (isset($_SERVER['SERVER_PORT']) && ('443' == $_SERVER['SERVER_PORT'])) {
        $hyper_cache_is_ssl = true;
    }
}

if (HC_HTTPS === 0 && $hyper_cache_is_ssl) {
    hyper_cache_header('stop - https');
    $GLOBALS['hyper_cache_stop'] = true;

    return false;
}

if (HC_REJECT_AGENTS_ENABLED && isset($_SERVER['HTTP_USER_AGENT'])) {
    if (preg_match('#(HC_REJECT_AGENTS)#i', $_SERVER['HTTP_USER_AGENT'])) {
        hyper_cache_header('stop - rejected user agent');
        $GLOBALS['hyper_cache_stop'] = true;

        return false;
    }
}

if (!empty($_COOKIE)) {
    foreach ($_COOKIE as $n => $v) {
        if ('wordpress_logged_in_' == substr($n, 0, 20)) {
            hyper_cache_header('stop - logged in cookie');
            $GLOBALS['hyper_cache_stop'] = true;

            return false;
        }

        if ('wp-postpass_' == substr($n, 0, 12)) {
            hyper_cache_header('stop - password cookie');
            $GLOBALS['hyper_cache_stop'] = true;

            return false;
        }

        if (HC_REJECT_COMMENT_AUTHORS && 'comment_author' == substr($n, 0, 14)) {
            hyper_cache_header('stop - comment author cookie');
            $GLOBALS['hyper_cache_stop'] = true;

            return false;
        }

        if (HC_REJECT_COOKIES_ENABLED) {
            if (preg_match('#(HC_REJECT_COOKIES)#i', $n)) {
                hyper_cache_header('stop - rejected cookie');
                $GLOBALS['hyper_cache_stop'] = true;

                return false;
            }
        }
    }
}

// Globally used
$GLOBALS['hyper_cache_group'] = '';

if (HC_HTTPS === 1 && $hyper_cache_is_ssl) {
    $GLOBALS['hyper_cache_group'] .= '-https';
}

if (HC_MOBILE === 1 && $GLOBALS['hyper_cache_is_mobile']) {
    $GLOBALS['hyper_cache_group'] .= '-mobile';
}

$hc_uri = hyper_cache_sanitize_uri($_SERVER['REQUEST_URI']);
$GLOBALS['hc_host'] = hyper_cache_sanitize_host($_SERVER['HTTP_HOST']);
$hc_file = 'HC_FOLDER/'.$GLOBALS['hc_host'].$hc_uri.'/index'.$GLOBALS['hyper_cache_group'].'.html';
if (HC_GZIP == 1 && $hyper_cache_gzip_accepted) {
    $hc_gzip = true;
} else {
    $hc_gzip = false;
}

if ($hc_gzip) {
    $hc_file .= '.gz';
}

if (!is_file($hc_file)) {
    hyper_cache_header('continue - no file');

    return false;
}

$hc_file_time = filemtime($hc_file);

if (HC_SERVE_EXPIRED_TO_BOT && $hyper_cache_is_bot) {
    hyper_cache_header('hit - bot');
} else {
    if (HC_MAX_AGE > 0 && $hc_file_time < time() - (HC_MAX_AGE * 3600)) {
        hyper_cache_header('continue - old file');

        return false;
    }
}
if (\array_key_exists('HTTP_IF_MODIFIED_SINCE', $_SERVER)) {
    $hc_if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE']));
    if ($hc_if_modified_since >= $hc_file_time) {
        header('HTTP/1.0 304 Not Modified');
        flush();
        exit();
    }
}

header('Content-Type: text/html;charset=UTF-8');
header('Last-Modified: '.gmdate('D, d M Y H:i:s', $hc_file_time).' GMT');

if (HC_MOBILE === 0) {
    header('Vary: Accept-Encoding');
} else {
    header('Vary: Accept-Encoding,User-Agent');
}

if (HC_BROWSER_CACHE) {
    if (HC_BROWSER_CACHE_HOURS != 0) {
        $hc_cache_max_age = HC_BROWSER_CACHE_HOURS * 3600;
    } else {
        // If there is not a default expire time, use 24 hours.
        if (HC_MAX_AGE > 0) {
            $hc_cache_max_age = time() + (HC_MAX_AGE * 3600) - $hc_file_time;
        } else {
            $hc_cache_max_age = time() + (24 * 3600) - $hc_file_time;
        }
    }
    header('Cache-Control: public, max-age='.$hc_cache_max_age, false);
} else {
    header('Cache-Control: public, max-age=0, no-cache, no-transform', false);
}

if ($hc_gzip) {
    hyper_cache_header('hit - gzip'.$GLOBALS['hyper_cache_group']);
    header('Content-Encoding: gzip');
    header('Content-Length: '.filesize($hc_file));
} else {
    hyper_cache_header('hit - plain'.$GLOBALS['hyper_cache_group']);
    header('Content-Length: '.filesize($hc_file));
}

exit(@readfile($hc_file));
