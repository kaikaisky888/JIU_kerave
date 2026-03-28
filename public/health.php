<?php
// Health check and database setup - no framework dependencies
header('Content-Type: application/json');

$host = getenv('DATABASE_HOSTNAME') ?: '127.0.0.1';
$port = getenv('DATABASE_HOSTPORT') ?: '3306';
$user = getenv('DATABASE_USERNAME') ?: 'root';
$pass = getenv('DATABASE_PASSWORD') ?: '';
$db1  = getenv('DATABASE_DATABASE') ?: 'curve_1';
$db2  = getenv('KLINE_DB_NAME') ?: 'curve_2';

$checks = [
    'php' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'env_file' => file_exists(dirname(__DIR__) . '/.env'),
    'runtime_writable' => is_writable(dirname(__DIR__) . '/runtime'),
    'db_host' => $host,
    'db_port' => $port,
    'db_user' => $user,
];

// If ?setup is passed, create databases and import SQL
$action = isset($_GET['setup']) ? 'setup' : 'check';

try {
    // Connect without specifying a database
    $dsn = "mysql:host={$host};port={$port};charset=utf8";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_TIMEOUT => 10,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $checks['mysql_connection'] = 'connected';

    // List existing databases
    $stmt = $pdo->query("SHOW DATABASES");
    $checks['databases'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($action === 'setup') {
        $checks['setup'] = [];

        // Create curve_1
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db1}` CHARACTER SET utf8 COLLATE utf8_general_ci");
        $checks['setup']['create_curve_1'] = 'OK';

        // Import curve_1.sql if empty
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?");
        $stmt->execute([$db1]);
        $tableCount = (int)$stmt->fetchColumn();
        if ($tableCount === 0) {
            $sqlFile = dirname(__DIR__) . '/curve_1.sql';
            if (file_exists($sqlFile)) {
                $checks['setup']['import_curve_1'] = 'importing...';
                $pdo->exec("USE `{$db1}`");
                $sql = file_get_contents($sqlFile);
                // Split by semicolons (basic), execute one by one
                $pdo->exec($sql);
                $checks['setup']['import_curve_1'] = 'OK';
            } else {
                $checks['setup']['import_curve_1'] = 'file not found: ' . $sqlFile;
            }
        } else {
            $checks['setup']['import_curve_1'] = "skipped ({$tableCount} tables exist)";
        }

        // Create curve_2
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db2}` CHARACTER SET utf8 COLLATE utf8_general_ci");
        $checks['setup']['create_curve_2'] = 'OK';

        // Import curve_2.sql if empty
        $stmt->execute([$db2]);
        $tableCount = (int)$stmt->fetchColumn();
        if ($tableCount === 0) {
            $sqlFile = dirname(__DIR__) . '/curve_2.sql';
            if (file_exists($sqlFile)) {
                set_time_limit(600);
                $checks['setup']['import_curve_2'] = 'importing (large file)...';
                $pdo->exec("USE `{$db2}`");
                $sql = file_get_contents($sqlFile);
                $pdo->exec($sql);
                $checks['setup']['import_curve_2'] = 'OK';
            } else {
                $checks['setup']['import_curve_2'] = 'file not found: ' . $sqlFile;
            }
        } else {
            $checks['setup']['import_curve_2'] = "skipped ({$tableCount} tables exist)";
        }
    } else {
        // Just check if databases exist
        $checks['curve_1_exists'] = in_array($db1, $checks['databases']);
        $checks['curve_2_exists'] = in_array($db2, $checks['databases']);
    }
} catch (Exception $e) {
    $checks['mysql_error'] = $e->getMessage();
}

// Test Redis
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

$checks['hint'] = 'Visit /health.php?setup to create databases and import SQL';
echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
