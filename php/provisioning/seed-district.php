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
$seeded_links_count = 0;
$existing_links_count = 0;

// Collect unique districts and their associated divisions
$district_to_div_names = [];
foreach ($divisions as $division_name => $districts_array) {
    foreach ($districts_array as $district_name) {
        if (!isset($district_to_div_names[$district_name])) {
            $district_to_div_names[$district_name] = [];
        }
        if (!in_array($division_name, $district_to_div_names[$district_name])) {
            $district_to_div_names[$district_name][] = $division_name;
        }
    }
}

// Insert or get divisions
$division_ids = [];
foreach ($divisions as $division_name => $dummy) {
    $stmt_check_division = $pdo->prepare("SELECT id FROM divisions WHERE name = ?");
    $stmt_check_division->execute([$division_name]);
    $division_row = $stmt_check_division->fetch(PDO::FETCH_ASSOC);

    if ($division_row) {
        $division_ids[$division_name] = $division_row['id'];
        $existing_divisions_count++;
    } else {
        $stmt_insert_division = $pdo->prepare("INSERT INTO divisions (name) VALUES (?)");
        $stmt_insert_division->execute([$division_name]);
        $division_ids[$division_name] = $pdo->lastInsertId();
        $seeded_divisions_count++;
    }
}

// Insert or get districts
$district_ids = [];
foreach (array_keys($district_to_div_names) as $district_name) {
    $stmt_check_district = $pdo->prepare("SELECT id FROM districts WHERE name = ?");
    $stmt_check_district->execute([$district_name]);
    $district_row = $stmt_check_district->fetch(PDO::FETCH_ASSOC);

    if ($district_row) {
        $district_ids[$district_name] = $district_row['id'];
        $existing_districts_count++;
    } else {
        $stmt_insert_district = $pdo->prepare("INSERT INTO districts (name) VALUES (?)");
        $stmt_insert_district->execute([$district_name]);
        $district_ids[$district_name] = $pdo->lastInsertId();
        $seeded_districts_count++;
    }
}

// Insert links in division_districts
foreach ($district_to_div_names as $district_name => $div_names) {
    $district_id = $district_ids[$district_name];
    foreach ($div_names as $div_name) {
        $division_id = $division_ids[$div_name];

        $stmt_check_link = $pdo->prepare("SELECT 1 FROM division_districts WHERE division_id = ? AND district_id = ?");
        $stmt_check_link->execute([$division_id, $district_id]);
        $link_row = $stmt_check_link->fetch(PDO::FETCH_ASSOC);

        if ($link_row) {
            $existing_links_count++;
        } else {
            $stmt_insert_link = $pdo->prepare("INSERT INTO division_districts (division_id, district_id) VALUES (?, ?)");
            $stmt_insert_link->execute([$division_id, $district_id]);
            $seeded_links_count++;
        }
    }
}

echo "Divisions: {$seeded_divisions_count} seeded, {$existing_divisions_count} already existed.\n";
echo "Districts: {$seeded_districts_count} seeded, {$existing_districts_count} already existed.\n";
echo "Division-District Links: {$seeded_links_count} seeded, {$existing_links_count} already existed.\n";

?>