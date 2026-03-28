<?php
// Simple health check - no framework dependencies
header('Content-Type: application/json');

$checks = [
    'php' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'extensions' => [],
    'env_file' => file_exists(dirname(__DIR__) . '/.env'),
    'runtime_writable' => is_writable(dirname(__DIR__) . '/runtime'),
];

$required_ext = ['pdo_mysql', 'mysqli', 'redis', 'bcmath', 'gd', 'zip', 'pcntl', 'posix', 'sockets'];
foreach ($required_ext as $ext) {
    $checks['extensions'][$ext] = extension_loaded($ext);
}

// Test database connection
try {
    $host = getenv('DATABASE_HOSTNAME') ?: '127.0.0.1';
    $port = getenv('DATABASE_HOSTPORT') ?: '3306';
    $user = getenv('DATABASE_USERNAME') ?: 'root';
    $pass = getenv('DATABASE_PASSWORD') ?: '';
    $db   = getenv('DATABASE_DATABASE') ?: 'curve_1';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8";
    $pdo  = new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 5]);
    $checks['mysql'] = 'connected';
} catch (Exception $e) {
    $checks['mysql'] = 'error: ' . $e->getMessage();
}

// Test Redis connection
try {
    $r = new Redis();
    $rHost = getenv('REDIS_HOST') ?: '127.0.0.1';
    $rPort = (int)(getenv('REDIS_PORT') ?: 6379);
    $rPass = getenv('REDIS_PASSWORD') ?: '';
    $r->connect($rHost, $rPort, 3);
    if ($rPass) $r->auth($rPass);
    $r->ping();
    $checks['redis'] = 'connected';
} catch (Exception $e) {
    $checks['redis'] = 'error: ' . $e->getMessage();
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
