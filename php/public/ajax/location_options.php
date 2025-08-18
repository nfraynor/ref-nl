<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();

// DISTINCT list of actual filterable values from matches.location_address
$sql = "
    SELECT DISTINCT TRIM(m.location_address) AS addr
    FROM matches m
    WHERE m.location_address IS NOT NULL
      AND TRIM(m.location_address) <> ''
    ORDER BY addr ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

// Return [{value: "...", label: "..."}] — easy for front-end to render
$options = array_map(fn($a) => ['value' => $a, 'label' => $a], $rows);
echo json_encode($options);
