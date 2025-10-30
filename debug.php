<?php
// Simple debug file to test if PHP is working
header('Content-Type: application/json');
echo json_encode([
    'status' => 'PHP is working',
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'current_file' => __FILE__,
    'current_dir' => __DIR__
]);
?>