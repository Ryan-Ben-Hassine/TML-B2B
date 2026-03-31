<?php
// Router for PHP built-in server: serves static files with CORS headers.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Accept requests that accidentally include a leading /backend prefix
if ($path === '/backend') $path = '/';
if (strpos($path, '/backend/') === 0) {
    $path = substr($path, strlen('/backend'));
}
$file = __DIR__ . $path;

if (!function_exists('cors_allow_origin')) {
    function cors_allow_origin() {
        $allowed = getenv('CORS_ALLOW_ORIGINS');
        if (!$allowed) {
            return '*';
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $list = array_filter(array_map('trim', explode(',', $allowed)));
        if (in_array('*', $list, true)) return '*';
        if ($origin && in_array($origin, $list, true)) {
            return $origin;
        }
        return 'null';
    }
}

if ($path === '/api.php' || $path === '/setup.php') {
    require __DIR__ . $path;
    return;
}

if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    header("Access-Control-Allow-Origin: " . cors_allow_origin());
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: no-referrer");
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (isset($types[$ext])) {
        header("Content-Type: " . $types[$ext]);
    }
    readfile($file);
    return;
}

require __DIR__ . '/api.php';
