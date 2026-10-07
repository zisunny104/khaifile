<?php
// Local development only. Keep upload and conversion data outside this document root.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_contains($path, '..') || preg_match('~(?:^|/)(?:\.[^/]*|api|tools|tests|partials)(?:/|$)~', $path)) {
    http_response_code(403); exit;
}
$root = dirname(__DIR__);
$file = $root . $path;
if ($path !== '/' && is_file($file)) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'php' && $path !== '/index.php') { http_response_code(403); exit; }
    if ($path !== '/index.php') return false;
}
if (!in_array($path, ['/', '/index.php'], true)) { http_response_code(404); exit; }
require $root . '/index.php';
