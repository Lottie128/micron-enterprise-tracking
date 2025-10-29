<?php
// Simple router for Apache without custom config

// Serve static files from build
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__ . '/../build' . $uri)) {
    return false;
}

// Fallback to index.html for React Router
readfile(__DIR__ . '/../build/index.html');
