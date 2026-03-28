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
    echo "Tables in curve_1: " . count($tables) . "\n\n";

    // Check critical tables
    echo "--- Checking critical data ---\n";
    try {
        $configCount = $db->table('fox_system_config')->count();
        echo "fox_system_config rows: {$configCount}\n";
    } catch (\Throwable $e) {
        echo "fox_system_config error: " . $e->getMessage() . "\n";
    }
    
    try {
        $productCount = $db->table('fox_product_lists')->where('status', 1)->count();
        echo "fox_product_lists (active): {$productCount}\n";
    } catch (\Throwable $e) {
        echo "fox_product_lists error: " . $e->getMessage() . "\n";
    }

    // Simulate homepage request
    echo "\n--- Simulating homepage ---\n";
    try {
        // Test sysconfig function
        $val = sysconfig('base', 'down_ipa_url');
        echo "sysconfig(base, down_ipa_url) = " . var_export($val, true) . "\n";
    } catch (\Throwable $e) {
        echo "sysconfig error: " . $e->getMessage() . "\n";
        echo "  at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }

    // Test actual HTTP dispatch to /
    echo "\n--- Direct controller test ---\n";
    try {
        // Set up request context
        $_SERVER['REQUEST_URI'] = '/';
        $app->request->setUrl('/');
        
        // Directly instantiate and call the index controller
        $controller = new \app\index\controller\Index($app);
        $result = $controller->index();
        echo "Result type: " . gettype($result) . "\n";
        if (is_string($result)) {
            echo "Result length: " . strlen($result) . "\n";
            echo "First 500 chars:\n" . substr($result, 0, 500) . "\n";
        } elseif ($result instanceof \think\Response) {
            echo "Response code: " . $result->getCode() . "\n";
        }
    } catch (\Throwable $e) {
        echo "Exception: " . get_class($e) . "\n";
        echo "Message: " . $e->getMessage() . "\n";
        echo "Code: " . $e->getCode() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . substr($e->getTraceAsString(), 0, 2000) . "\n";
    }
    
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
