<?php

require_once __DIR__ . '/../config/database.php';

// Step 1: connect without dbname to create database if needed
$config = include(__DIR__ . '/../config/database.php');

$dsnNoDb = "mysql:host={$config['host']};charset={$config['charset']}";

try {
    $pdo = new PDO($dsnNoDb, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connected to MySQL server (no database selected).\n";

    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['dbname']}`");
    echo "Database `{$config['dbname']}` checked/created.\n";

} catch (\PDOException $e) {
    die("Database server connection failed: " . $e->getMessage() . "\n");
}

// Step 2: Connect to the new/created database
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

try {
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connected to `{$config['dbname']}` database.\n";
} catch (\PDOException $e) {
    die("Database selection failed: " . $e->getMessage() . "\n");
}

// Load SQL schema file
$schemaSql = file_get_contents(__DIR__ . '/../../sql/provisioning.sql');

if ($schemaSql === false) {
    die("Could not read provisioning.sql\n");
}

try {
    $pdo->exec($schemaSql);
    echo "Database provisioned successfully.\n";
} catch (\PDOException $e) {
    echo "Error provisioning database: " . $e->getMessage() . "\n";
}

?>
