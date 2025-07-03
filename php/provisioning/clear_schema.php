<?php

require_once __DIR__ . '/../config/database.php';

$config = include(__DIR__ . '/../config/database.php');

$dbHost = $config['host'];
$dbName = $config['dbname'];
$dbUser = $config['username'];
$dbPass = $config['password'];
$dbCharset = $config['charset'];

try {
    // Connect to the specific database
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connected to database `{$dbName}` successfully.\n";

    // Fetch all table names
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No tables found in database `{$dbName}`. Nothing to clear.\n";
        exit;
    }

    echo "Disabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

    echo "Dropping tables...\n";
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            echo "Dropped table `{$table}`.\n";
        } catch (PDOException $e) {
            echo "Error dropping table `{$table}`: " . $e->getMessage() . "\n";
            // Optionally, decide if you want to continue or exit on error
        }
    }

    echo "Enabling foreign key checks...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");

    echo "Schema cleared successfully.\n";

} catch (PDOException $e) {
    die("Database operation failed: " . $e->getMessage() . "\n");
}

?>
