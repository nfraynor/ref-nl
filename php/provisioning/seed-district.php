<?php

require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

echo "Seeding Divisions and Districts...\n";

// Seed divisions
$divisions = [
    'Ereklasse' => ['National'],
    'Futureklasse' => ['National'],
    'Ereklasse Dames' => ['National'],
    'Colts Cup' => ['National'],
    '1e Klasse' => ['National'],
    '3e Klasse' => ['Noord', 'Zuid', 'Oost', 'West', 'Midden', 'Noord West', 'Zuid West']
];

$seeded_divisions_count = 0;
$existing_divisions_count = 0;
$seeded_districts_count = 0;
$existing_districts_count = 0;

foreach ($divisions as $division_name => $districts_array) {
    $stmt_check_division = $pdo->prepare("SELECT id FROM divisions WHERE name = ?");
    $stmt_check_division->execute([$division_name]);
    $division_row = $stmt_check_division->fetch(PDO::FETCH_ASSOC);

    $division_id = null;
    if ($division_row) {
        $division_id = $division_row['id'];
        $existing_divisions_count++;
    } else {
        $stmt_insert_division = $pdo->prepare("INSERT INTO divisions (name) VALUES (?)");
        $stmt_insert_division->execute([$division_name]);
        $division_id = $pdo->lastInsertId();
        $seeded_divisions_count++;
    }

    if ($division_id) {
        foreach ($districts_array as $district_name) {
            $stmt_check_district = $pdo->prepare("SELECT id FROM districts WHERE name = ? AND division_id = ?");
            $stmt_check_district->execute([$district_name, $division_id]);
            $district_row = $stmt_check_district->fetch(PDO::FETCH_ASSOC);

            if ($district_row) {
                $existing_districts_count++;
            } else {
                $stmt_insert_district = $pdo->prepare("INSERT INTO districts (name, division_id) VALUES (?, ?)");
                $stmt_insert_district->execute([$district_name, $division_id]);
                $seeded_districts_count++;
            }
        }
    }
}
echo "Divisions: {$seeded_divisions_count} seeded, {$existing_divisions_count} already existed.\n";
echo "Districts: {$seeded_districts_count} seeded, {$existing_districts_count} already existed.\n";

?>