<?php

if (!function_exists('is_https_request')) {
    function is_https_request() {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }
        return false;
    }
}

if (!function_exists('start_secure_session')) {
    function start_secure_session() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = is_https_request();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

if (!function_exists('send_security_headers')) {
    function send_security_headers() {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; frame-ancestors \'none\'; object-src \'none\'; form-action \'self\'; connect-src \'self\'; img-src \'self\' data: https://www.google.com; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src \'self\' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:;');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        start_secure_session();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('is_valid_csrf_token')) {
    function is_valid_csrf_token($token) {
        $sessionToken = csrf_token();
        if (!is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('sanitize_single_line')) {
    function sanitize_single_line($value, $maxLen = 255) {
        $text = trim((string)$value);
        $text = preg_replace('/[\r\n\t]+/', ' ', $text);
        if ($maxLen > 0) {
            if (function_exists('mb_substr')) {
                $text = mb_substr($text, 0, $maxLen, 'UTF-8');
            } else {
                $text = substr($text, 0, $maxLen);
            }
        }
        return $text;
    }
}

if (!function_exists('sanitize_multiline_text')) {
    function sanitize_multiline_text($value, $maxLen = 4000) {
        $text = (string)$value;
        $text = str_replace("\0", '', $text);
        if ($maxLen > 0) {
            if (function_exists('mb_substr')) {
                $text = mb_substr($text, 0, $maxLen, 'UTF-8');
            } else {
                $text = substr($text, 0, $maxLen);
            }
        }
        return $text;
    }
}

if (!function_exists('normalize_hex_color')) {
    function normalize_hex_color($input, $default = '#5C6CFF') {
        $color = strtoupper(trim((string)$input));
        if (preg_match('/^#(?:[0-9A-F]{6}|[0-9A-F]{8})$/', $color)) {
            return $color;
        }
        return strtoupper($default);
    }
}

if (!function_exists('normalize_iso_date')) {
    function normalize_iso_date($input) {
        $raw = trim((string)$input);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        $parts = explode('-', $raw);
        $year = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('rate_limit_register_hit')) {
    function rate_limit_register_hit($key, $windowSeconds = 300) {
        start_secure_session();
        $now = time();
        if (!isset($_SESSION['rate_limit']) || !is_array($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = [];
        }
        if (!isset($_SESSION['rate_limit'][$key]) || !is_array($_SESSION['rate_limit'][$key])) {
            $_SESSION['rate_limit'][$key] = [];
        }

        $hits = [];
        foreach ($_SESSION['rate_limit'][$key] as $ts) {
            $ts = (int)$ts;
            if (($now - $ts) < $windowSeconds) {
                $hits[] = $ts;
            }
        }

        $hits[] = $now;
        $_SESSION['rate_limit'][$key] = $hits;
        return count($hits);
    }
}

if (!function_exists('rate_limit_is_blocked')) {
    function rate_limit_is_blocked($key, $maxHits = 8, $windowSeconds = 300) {
        start_secure_session();
        $now = time();
        $hits = $_SESSION['rate_limit'][$key] ?? [];
        if (!is_array($hits)) {
            return false;
        }

        $activeHits = [];
        foreach ($hits as $ts) {
            $ts = (int)$ts;
            if (($now - $ts) < $windowSeconds) {
                $activeHits[] = $ts;
            }
        }
        $_SESSION['rate_limit'][$key] = $activeHits;
        return count($activeHits) >= $maxHits;
    }
}

if (!function_exists('rate_limit_retry_after')) {
    function rate_limit_retry_after($key, $windowSeconds = 300) {
        start_secure_session();
        $hits = $_SESSION['rate_limit'][$key] ?? [];
        if (!is_array($hits) || empty($hits)) {
            return 0;
        }

        $oldest = min(array_map('intval', $hits));
        $retryAfter = ($oldest + $windowSeconds) - time();
        return max($retryAfter, 0);
    }
}

if (!function_exists('rate_limit_clear')) {
    function rate_limit_clear($key) {
        start_secure_session();
        if (isset($_SESSION['rate_limit'][$key])) {
            unset($_SESSION['rate_limit'][$key]);
        }
    }
}

start_secure_session();
send_security_headers();

?>
