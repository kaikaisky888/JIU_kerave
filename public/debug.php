<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

try {
    require __DIR__ . '/../vendor/autoload.php';
    define('DS', DIRECTORY_SEPARATOR);
    define('ROOT_PATH', __DIR__ . DS . '..' . DS);
    
    $app = new \think\App();
    $app->debug(true)->initialize();
    
    echo "App initialized OK\n\n";
    
    // Test DB
    echo "--- Testing curve_1 DB ---\n";
    $db = $app->db->connect('mysql');
    $tables = $db->getTables();
    echo "Tables in curve_1: " . count($tables) . "\n";
    echo implode(', ', $tables) . "\n\n";
    
    // Test curve_2
    echo "--- Testing curve_2 DB ---\n";
    try {
        $kline = $app->db->connect('kline');
        $tables2 = $kline->getTables();
        echo "Tables in curve_2: " . count($tables2) . "\n";
    } catch (\Throwable $e) {
        echo "curve_2 error: " . $e->getMessage() . "\n";
    }
    
    // Test route
    echo "\n--- Testing HTTP dispatch ---\n";
    $http = $app->http;
    $response = $http->run();
    echo "Response status: " . $response->getCode() . "\n";
    
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
