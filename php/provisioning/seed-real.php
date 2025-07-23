<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();

// ----- Function to generate a version 4 UUID -----
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ----- Seed Divisions and Districts -----
echo "Seeding Divisions and Districts...\n";
$divisions_districts_data = [
    'Ereklasse' => ['National'],
    '3e Klasse' => ['Noordwest', 'Zuidwest']
];

$seeded_divisions_count = 0;
$existing_divisions_count = 0;
$seeded_districts_count = 0;
$existing_districts_count = 0;

foreach ($divisions_districts_data as $division_name => $districts_array) {
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

// ----- Extract Data from Sheets -----
$clubs_data = [];
$locations_data = [];
$teams_data = [];
$matches_data = [];

$sheets = [
    'Ereklasse' => [
        ['date' => '2025-09-06', 'time' => 0.625, 'location' => 'RC DIOK', 'home' => 'RC DIOK 1', 'away' => 'RC The Dukes 1'],
        ['date' => '2025-09-06', 'time' => 0.625, 'location' => 'Sportpark Wouterland', 'home' => 'Cas RC 1', 'away' => 'RRC 1'],
        ['date' => '2025-09-06', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 1', 'away' => 'BRC 1'],
        ['date' => '2025-09-06', 'time' => 0.6666666666666666, 'location' => 'Sportpark de Bokkeduinen', 'home' => 'RC Eemland 1', 'away' => 'RC Hoek van Holland 1'],
        ['date' => '2025-09-06', 'time' => 0.6666666666666666, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 1', 'away' => 'RFC Oisterwijk Oysters 1'],
        ['date' => '2025-09-06', 'time' => 0.75, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 1', 'away' => 'RFC Haarlem 1'],
        ['date' => '2025-09-13', 'time' => 0.625, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 1', 'away' => 'RC \'t Gooi 1'],
        ['date' => '2025-09-13', 'time' => 0.625, 'location' => 'Sportpark de Wolfsputten', 'home' => 'RFC Oisterwijk Oysters 1', 'away' => 'Haagsche RC 1'],
        ['date' => '2025-09-13', 'time' => 0.6666666666666666, 'location' => 'RC Hoek van Holland', 'home' => 'RC Hoek van Holland 1', 'away' => 'Cas RC 1'],
        ['date' => '2025-09-13', 'time' => 0.6666666666666666, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 1', 'away' => 'RC DIOK 1'],
        ['date' => '2025-09-13', 'time' => 0.7083333333333334, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 1', 'away' => 'RC Eemland 1'],
        ['date' => '2025-09-13', 'time' => 0.7083333333333334, 'location' => 'RC The Dukes', 'home' => 'RC The Dukes 1', 'away' => 'AAC 1'],
        ['date' => '2025-09-20', 'time' => 0.625, 'location' => 'Sportpark Wouterland', 'home' => 'Cas RC 1', 'away' => 'RFC Haarlem 1'],
        ['date' => '2025-09-20', 'time' => 0.6666666666666666, 'location' => 'RC DIOK', 'home' => 'RC DIOK 1', 'away' => 'RC Hoek van Holland 1'],
        ['date' => '2025-09-20', 'time' => 0.6666666666666666, 'location' => 'Sportpark de Bokkeduinen', 'home' => 'RC Eemland 1', 'away' => 'RFC Oisterwijk Oysters 1'],
        ['date' => '2025-09-20', 'time' => 0.6666666666666666, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 1', 'away' => 'AAC 1'],
        ['date' => '2025-09-20', 'time' => 0.6666666666666666, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 1', 'away' => 'RC The Dukes 1'],
        ['date' => '2025-09-20', 'time' => 0.6875, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 1', 'away' => 'BRC 1'],
        ['date' => '2025-09-27', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 1', 'away' => 'Haagsche RC 1'],
        ['date' => '2025-09-27', 'time' => 0.625, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 1', 'away' => 'RC Eemland 1'],
        ['date' => '2025-09-27', 'time' => 0.625, 'location' => 'Sportpark de Wolfsputten', 'home' => 'RFC Oisterwijk Oysters 1', 'away' => 'Cas RC 1'],
        ['date' => '2025-09-27', 'time' => 0.6666666666666666, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 1', 'away' => 'RC DIOK 1'],
        ['date' => '2025-09-27', 'time' => 0.6666666666666666, 'location' => 'RC Hoek van Holland', 'home' => 'RC Hoek van Holland 1', 'away' => 'RRC 1'],
        ['date' => '2025-09-27', 'time' => 0.7083333333333334, 'location' => 'RC The Dukes', 'home' => 'RC The Dukes 1', 'away' => 'RC \'t Gooi 1'],
        ['date' => '2025-10-11', 'time' => 0.625, 'location' => 'RC DIOK', 'home' => 'RC DIOK 1', 'away' => 'RFC Oisterwijk Oysters 1'],
        ['date' => '2025-10-11', 'time' => 0.625, 'location' => 'Sportpark Wouterland', 'home' => 'Cas RC 1', 'away' => 'BRC 1'],
        ['date' => '2025-10-11', 'time' => 0.6666666666666666, 'location' => 'Sportpark de Bokkeduinen', 'home' => 'RC Eemland 1', 'away' => 'AAC 1'],
        ['date' => '2025-10-11', 'time' => 0.6666666666666666, 'location' => 'RC Hoek van Holland', 'home' => 'RC Hoek van Holland 1', 'away' => 'RC The Dukes 1'],
        ['date' => '2025-10-11', 'time' => 0.6666666666666666, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 1', 'away' => 'RFC Haarlem 1'],
        ['date' => '2025-10-11', 'time' => 0.6875, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 1', 'away' => 'RC \'t Gooi 1'],
        ['date' => '2025-10-18', 'time' => 0.625, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 1', 'away' => 'RC DIOK 1'],
        ['date' => '2025-10-18', 'time' => 0.625, 'location' => 'Sportpark de Wolfsputten', 'home' => 'RFC Oisterwijk Oysters 1', 'away' => 'RRC 1'],
        ['date' => '2025-10-18', 'time' => 0.625, 'location' => 'RC The Dukes', 'home' => 'RC The Dukes 1', 'away' => 'Haagsche RC 1'],
        ['date' => '2025-10-18', 'time' => 0.6458333333333334, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 1', 'away' => 'Cas RC 1'],
        ['date' => '2025-10-18', 'time' => 0.6666666666666666, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 1', 'away' => 'RC Hoek van Holland 1'],
        ['date' => '2025-10-25', 'time' => 0.625, 'location' => 'RC DIOK', 'home' => 'RC DIOK 1', 'away' => 'AAC 1'],
        ['date' => '2025-10-25', 'time' => 0.625, 'location' => 'Sportpark Wouterland', 'home' => 'Cas RC 1', 'away' => 'RC \'t Gooi 1'],
        ['date' => '2025-10-25', 'time' => 0.6666666666666666, 'location' => 'Sportpark de Bokkeduinen', 'home' => 'RC Eemland 1', 'away' => 'Haagsche RC 1'],
        ['date' => '2025-10-25', 'time' => 0.6666666666666666, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 1', 'away' => 'RC The Dukes 1'],
        ['date' => '2025-10-25', 'time' => 0.6666666666666666, 'location' => 'RC Hoek van Holland', 'home' => 'RC Hoek van Holland 1', 'away' => 'RFC Oisterwijk Oysters 1'],
        ['date' => '2025-10-25', 'time' => 0.6666666666666666, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 1', 'away' => 'BRC 1'],
        ['date' => '2025-11-01', 'time' => 0.625, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 1', 'away' => 'RC DIOK 1'],
        ['date' => '2025-11-01', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 1', 'away' => 'RRC 1'],
        ['date' => '2025-11-01', 'time' => 0.625, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 1', 'away' => 'RC Hoek van Holland 1'],
        ['date' => '2025-11-01', 'time' => 0.625, 'location' => 'Sportpark de Wolfsputten', 'home' => 'RFC Oisterwijk Oysters 1', 'away' => 'RFC Haarlem 1'],
        ['date' => '2025-11-01', 'time' => 0.625, 'location' => 'RC The Dukes', 'home' => 'RC The Dukes 1', 'away' => 'RC Eemland 1'],
        ['date' => '2025-11-01', 'time' => 0.6875, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 1', 'away' => 'Cas RC 1'],
        ['date' => '2025-11-08', 'time' => 0.625, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 1', 'away' => 'RC Eemland 1'],
        ['date' => '2025-11-22', 'time' => 0.625, 'location' => 'RC DIOK', 'home' => 'RC DIOK 1', 'away' => 'Haagsche RC 1'],
        ['date' => '2025-11-22', 'time' => 0.625, 'location' => 'RC The Dukes', 'home' => 'RC The Dukes 1', 'away' => 'RFC Oisterwijk Oysters 1'],
        ['date' => '2025-11-22', 'time' => 0.6666666666666666, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 1', 'away' => 'BRC 1'],
        ['date' => '2025-11-22', 'time' => 0.6666666666666666, 'location' => 'RC Hoek van Holland', 'home' => 'RC Hoek van Holland 1', 'away' => 'AAC 1'],
    ],
    '3e klasse NW' => [
        ['date' => '2025-09-21', 'time' => 0.6041666666666666, 'location' => 'Sportpark Groenoord Schagen', 'home' => 'SRC Rush 1', 'away' => 'RC Den Helder 1'],
        ['date' => '2025-09-21', 'time' => 0.6041666666666666, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 3', 'away' => 'Ascrum AA'],
        ['date' => '2025-09-21', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 2', 'away' => 'Haagsche RC Espoirs'],
        ['date' => '2025-09-21', 'time' => 0.625, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 3', 'away' => 'Amstelveense RC 2'],
        ['date' => '2025-09-21', 'time' => 0.625, 'location' => 'Sportpark de Blauwe Berg', 'home' => 'RC West-Friesland 1', 'away' => 'CL Mokum Rugby 1'],
        ['date' => '2025-09-28', 'time' => 0.5, 'location' => 'Sportpark de Eendracht', 'home' => 'Ascrum AA', 'away' => 'SRC Rush 1'],
        ['date' => '2025-09-28', 'time' => 0.5416666666666666, 'location' => 'Sportpark Sportlaan West', 'home' => 'Amstelveense RC 2', 'away' => 'AAC 2'],
        ['date' => '2025-09-28', 'time' => 0.625, 'location' => 'Sportpark de Linie', 'home' => 'RC Den Helder 1', 'away' => 'RC West-Friesland 1'],
        ['date' => '2025-09-28', 'time' => 0.625, 'location' => 'RC Amsterdam', 'home' => 'CL Mokum Rugby 1', 'away' => 'RFC Haarlem 3'],
        ['date' => '2025-09-28', 'time' => 0.625, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC Espoirs', 'away' => 'RC \'t Gooi 3'],
        ['date' => '2025-10-05', 'time' => 0.5208333333333334, 'location' => 'Sportpark Sportlaan West', 'home' => 'Amstelveense RC 2', 'away' => 'Haagsche RC Espoirs'],
        ['date' => '2025-10-05', 'time' => 0.6041666666666666, 'location' => 'Sportpark Groenoord Schagen', 'home' => 'SRC Rush 1', 'away' => 'RC \'t Gooi 3'],
        ['date' => '2025-10-05', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 2', 'away' => 'CL Mokum Rugby 1'],
        ['date' => '2025-10-05', 'time' => 0.625, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 3', 'away' => 'RC Den Helder 1'],
        ['date' => '2025-10-12', 'time' => 0.6041666666666666, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 3', 'away' => 'RC West-Friesland 1'],
        ['date' => '2025-10-12', 'time' => 0.625, 'location' => 'Sportpark de Linie', 'home' => 'RC Den Helder 1', 'away' => 'AAC 2'],
        ['date' => '2025-10-19', 'time' => 0.625, 'location' => 'Sportpark de Blauwe Berg', 'home' => 'RC West-Friesland 1', 'away' => 'Ascrum AA'],
        ['date' => '2025-10-26', 'time' => 0.5416666666666666, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC Espoirs', 'away' => 'SRC Rush 1'],
        ['date' => '2025-10-26', 'time' => 0.625, 'location' => 'RC Amsterdam', 'home' => 'CL Mokum Rugby 1', 'away' => 'Amstelveense RC 2'],
        ['date' => '2025-11-09', 'time' => 0.5416666666666666, 'location' => 'Sportpark Sportlaan West', 'home' => 'Amstelveense RC 2', 'away' => 'RC Den Helder 1'],
        ['date' => '2025-11-09', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 2', 'away' => 'Ascrum AA'],
        ['date' => '2025-11-09', 'time' => 0.625, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 3', 'away' => 'RC \'t Gooi 3'],
        ['date' => '2025-11-09', 'time' => 0.625, 'location' => 'Sportpark de Blauwe Berg', 'home' => 'RC West-Friesland 1', 'away' => 'SRC Rush 1'],
        ['date' => '2025-11-09', 'time' => 0.625, 'location' => 'RC Amsterdam', 'home' => 'CL Mokum Rugby 1', 'away' => 'Haagsche RC Espoirs'],
        ['date' => '2025-11-16', 'time' => 0.5416666666666666, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC Espoirs', 'away' => 'RC West-Friesland 1'],
        ['date' => '2025-11-16', 'time' => 0.6041666666666666, 'location' => 'Sportpark Groenoord Schagen', 'home' => 'SRC Rush 1', 'away' => 'RFC Haarlem 3'],
        ['date' => '2025-11-16', 'time' => 0.6041666666666666, 'location' => 'RC \'t Gooi', 'home' => 'RC \'t Gooi 3', 'away' => 'AAC 2'],
        ['date' => '2025-11-16', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'Ascrum AA', 'away' => 'Amstelveense RC 2'],
        ['date' => '2025-11-16', 'time' => 0.625, 'location' => 'Sportpark de Linie', 'home' => 'RC Den Helder 1', 'away' => 'CL Mokum Rugby 1'], // time was empty, set to 0.625 (15:00)
        ['date' => '2025-11-23', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'AAC 2', 'away' => 'SRC Rush 1'],
        ['date' => '2025-11-23', 'time' => 0.625, 'location' => 'Van der Aart Sportpark', 'home' => 'RFC Haarlem 3', 'away' => 'RC West-Friesland 1'],
        ['date' => '2025-11-23', 'time' => 0.625, 'location' => 'Sportpark de Linie', 'home' => 'RC Den Helder 1', 'away' => 'Haagsche RC Espoirs'],
        ['date' => '2025-11-23', 'time' => 0.625, 'location' => 'Sportpark de Eendracht', 'home' => 'Ascrum AA', 'away' => 'CL Mokum Rugby 1'],
    ],
    '3e klasse ZW' => [
        ['date' => '2024-09-23', 'time' => 0.5416666666666666, 'location' => 'Sportpark Rijnvliet', 'home' => 'URC 3', 'away' => 'A.S.R.V. Ascrum 2'],
        ['date' => '2024-09-23', 'time' => 0.5833333333333334, 'location' => 'Sportpark het Schenge', 'home' => 'GRC Tovaal 1', 'away' => 'BRC 2'],
        ['date' => '2024-09-23', 'time' => 0.6041666666666666, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'WRC Te Werve 1', 'away' => 'Haagsche RC 3'],
        ['date' => '2024-09-23', 'time' => 0.625, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 3', 'away' => 'SVRC 1'],
        ['date' => '2024-09-28', 'time' => 0.5416666666666666, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 2', 'away' => 'WRC Te Werve 1'],
        ['date' => '2024-09-28', 'time' => 0.5416666666666666, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 3', 'away' => 'RC Sparta 1'],
        ['date' => '2024-09-28', 'time' => 0.5416666666666666, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'The Hague Hornets 1', 'away' => 'URC 3'],
        ['date' => '2024-09-28', 'time' => 0.6041666666666666, 'location' => 'Sportpark de Eendracht', 'home' => 'A.S.R.V. Ascrum 2', 'away' => 'RRC 3'],
        ['date' => '2024-09-28', 'time' => 0.625, 'location' => 'Sportcentrum TU Delft', 'home' => 'SVRC 1', 'away' => 'GRC Tovaal 1'],
        ['date' => '2024-10-05', 'time' => 0.5416666666666666, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 3', 'away' => 'The Hague Hornets 1'],
        ['date' => '2024-10-05', 'time' => 0.5833333333333334, 'location' => 'Sportpark het Schenge', 'home' => 'GRC Tovaal 1', 'away' => 'A.S.R.V. Ascrum 2'],
        ['date' => '2024-10-05', 'time' => 0.6041666666666666, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'WRC Te Werve 1', 'away' => 'SVRC 1'],
        ['date' => '2024-10-05', 'time' => 0.625, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 3', 'away' => 'URC 3'],
        ['date' => '2024-10-12', 'time' => 0.4375, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 2', 'away' => 'Haagsche RC 3'],
        ['date' => '2024-10-12', 'time' => 0.5416666666666666, 'location' => 'Sportpark Rijnvliet', 'home' => 'URC 3', 'away' => 'GRC Tovaal 1'],
        ['date' => '2024-10-12', 'time' => 0.5416666666666666, 'location' => 'Sportpark de Eendracht', 'home' => 'A.S.R.V. Ascrum 2', 'away' => 'WRC Te Werve 1'],
        ['date' => '2024-10-12', 'time' => 0.5416666666666666, 'location' => 'Sportcentrum TU Delft', 'home' => 'SVRC 1', 'away' => 'RC Sparta 1'],
        ['date' => '2024-10-12', 'time' => 0.625, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'The Hague Hornets 1', 'away' => 'RRC 3'],
        ['date' => '2024-11-02', 'time' => 0.625, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'The Hague Hornets 1', 'away' => 'RC Sparta 1'],
        ['date' => '2024-11-09', 'time' => 0.5416666666666666, 'location' => 'Sparta Rugby', 'home' => 'RC Sparta 1', 'away' => 'A.S.R.V. Ascrum 2'],
        ['date' => '2024-11-09', 'time' => 0.5833333333333334, 'location' => 'Sportpark het Schenge', 'home' => 'GRC Tovaal 1', 'away' => 'RRC 3'],
        ['date' => '2024-11-09', 'time' => 0.6041666666666666, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'WRC Te Werve 1', 'away' => 'URC 3'],
        ['date' => '2024-11-09', 'time' => 0.625, 'location' => 'Sportboulevard Wisselaar', 'home' => 'BRC 2', 'away' => 'The Hague Hornets 1'],
        ['date' => '2024-11-16', 'time' => 0.5416666666666666, 'location' => 'Sportcomplex Duivensteijn', 'home' => 'RRC 3', 'away' => 'WRC Te Werve 1'],
        ['date' => '2024-11-16', 'time' => 0.5416666666666666, 'location' => 'Sportpark de Eendracht', 'home' => 'A.S.R.V. Ascrum 2', 'away' => 'Haagsche RC 3'],
        ['date' => '2024-11-16', 'time' => 0.5416666666666666, 'location' => 'Sportcentrum TU Delft', 'home' => 'SVRC 1', 'away' => 'BRC 2'],
        ['date' => '2024-11-16', 'time' => 0.625, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'The Hague Hornets 1', 'away' => 'GRC Tovaal 1'],
        ['date' => '2024-11-16', 'time' => 0.625, 'location' => 'Sportpark Rijnvliet', 'home' => 'URC 3', 'away' => 'RC Sparta 1'], // time was empty, set to 0.625
        ['date' => '2024-11-23', 'time' => 0.5416666666666666, 'location' => 'Sportpark de Eendracht', 'home' => 'A.S.R.V. Ascrum 2', 'away' => 'BRC 2'],
        ['date' => '2024-11-23', 'time' => 0.5416666666666666, 'location' => 'Haagsche RC', 'home' => 'Haagsche RC 3', 'away' => 'URC 3'],
        ['date' => '2024-11-23', 'time' => 0.6041666666666666, 'location' => 'Sportcomplex Beresteinlaan', 'home' => 'WRC Te Werve 1', 'away' => 'GRC Tovaal 1'],
        ['date' => '2024-11-23', 'time' => 0.625, 'location' => 'Sportcentrum TU Delft', 'home' => 'SVRC 1', 'away' => 'The Hague Hornets 1'],
    ],
];

// Extract unique clubs, locations, and teams
$club_map = []; // Maps club name to UUID and details
$location_map = []; // Maps location name to UUID and details
$team_map = []; // Maps team name to UUID, club UUID, and division
$base_lat = 52.370216; // Approximate latitude for Amsterdam
$base_lon = 4.895168; // Approximate longitude for Amsterdam

foreach ($sheets as $sheet_key => $sheet_matches) {
    // Determine division and district based on sheet
    if ($sheet_key === 'Ereklasse') {
        $division = 'Ereklasse';
        $district = 'National';
    } elseif ($sheet_key === '3e klasse NW') {
        $division = '3e Klasse';
        $district = 'Noordwest';
    } elseif ($sheet_key === '3e klasse ZW') {
        $division = '3e Klasse';
        $district = 'Zuidwest';
    } else {
        continue; // Skip unknown sheets
    }

    foreach ($sheet_matches as $match) {
        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $match['date'])) {
            echo "Warning: Invalid date format {$match['date']} for match in {$sheet_key} at {$match['location']} between {$match['home']} and {$match['away']}.\n";
            continue;
        }

        // Validate time
        $decimal_time = $match['time'];
        if ($decimal_time < 0 || $decimal_time >= 1) {
            echo "Warning: Invalid decimal time {$decimal_time} for match in {$sheet_key} on {$match['date']} at {$match['location']}.\n";
            continue;
        }

        $home_team = $match['home'];
        $away_team = $match['away'];
        $location = $match['location'];

        // Infer club from team name (remove numeric or special suffixes)
        $home_club = preg_replace('/\s*\d+|Espoirs|AA$/', '', $home_team);
        $away_club = preg_replace('/\s*\d+|Espoirs|AA$/', '', $away_team);
        $home_club = trim($home_club);
        $away_club = trim($away_club);

        // Add clubs to club_map if not already present
        foreach ([$home_club, $away_club] as $club_name) {
            if (!isset($club_map[$club_name]) && $club_name !== '') {
                $club_map[$club_name] = [
                    'uuid' => generate_uuid_v4(),
                    'club_id' => strtoupper(str_replace(' ', '', $club_name)),
                    'club_name' => $club_name,
                    'precise_location_lat' => $base_lat + (mt_rand(-100, 100) / 10000),
                    'precise_location_lon' => $base_lon + (mt_rand(-100, 100) / 10000),
                    'address_text' => "$club_name Ground"
                ];
            }
        }

        // Add teams to team_map if not already present
        foreach ([$home_team => $home_club, $away_team => $away_club] as $team_name => $club_name) {
            if (!isset($team_map[$team_name]) && $team_name !== '') {
                if (isset($club_map[$club_name])) {
                    $team_map[$team_name] = [
                        'uuid' => generate_uuid_v4(),
                        'team_name' => $team_name,
                        'club_id' => $club_map[$club_name]['uuid'],
                        'division' => $division
                    ];
                } else {
                    echo "Warning: Skipping team {$team_name} in {$sheet_key} because club {$club_name} could not be inferred.\n";
                }
            }
        }

        // Add location to location_map if not already present
        if (!isset($location_map[$location]) && $location !== '') {
            $location_map[$location] = [
                'uuid' => generate_uuid_v4(),
                'name' => $location,
                'address_text' => "$location, Netherlands",
                'latitude' => $base_lat + (mt_rand(-100, 100) / 10000),
                'longitude' => $base_lon + (mt_rand(-100, 100) / 10000),
                'notes' => "Standard rugby pitch at $location"
            ];
        } elseif ($location === '') {
            echo "Warning: Skipping match in {$sheet_key} on {$match['date']} because location is empty.\n";
            continue;
        }

        // Convert time (decimal day) to HH:MM:SS
        $hours = floor($decimal_time * 24);
        $minutes = floor(($decimal_time * 24 - $hours) * 60);
        $kickoff_time = sprintf("%02d:%02d:00", $hours, $minutes);

        // Add match to matches_data
        if (isset($team_map[$home_team]) && isset($team_map[$away_team]) && isset($location_map[$location])) {
            $matches_data[] = [
                'uuid' => generate_uuid_v4(),
                'home_team_id' => $team_map[$home_team]['uuid'],
                'away_team_id' => $team_map[$away_team]['uuid'],
                'location_uuid' => $location_map[$location]['uuid'],
                'division' => $division,
                'district' => $district,
                'poule' => 'Cup',
                'match_date' => $match['date'],
                'kickoff_time' => $kickoff_time,
                'expected_grade' => ['A', 'B', 'C', 'D', 'E'][array_rand(['A', 'B', 'C', 'D', 'E'])]
            ];
        } else {
            echo "Warning: Skipping match in {$sheet_key} on {$match['date']} at {$match['location']} due to missing team or location.\n";
        }
    }
}

// ----- Seed Clubs -----
echo "Seeding Clubs...\n";
$seeded_clubs_count = 0;
$existing_clubs_count = 0;
$stmt_insert_club = $pdo->prepare("INSERT IGNORE INTO clubs (uuid, club_id, club_name, precise_location_lat, precise_location_lon, address_text) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($club_map as $club) {
    $stmt_check_club = $pdo->prepare("SELECT uuid FROM clubs WHERE club_name = ?");
    $stmt_check_club->execute([$club['club_name']]);
    if ($stmt_check_club->fetch()) {
        $existing_clubs_count++;
    } else {
        $stmt_insert_club->execute([
            $club['uuid'],
            $club['club_id'],
            $club['club_name'],
            $club['precise_location_lat'],
            $club['precise_location_lon'],
            $club['address_text']
        ]);
        $seeded_clubs_count++;
    }
}
echo "Clubs: {$seeded_clubs_count} seeded, {$existing_clubs_count} already existed.\n";

// ----- Seed Locations -----
echo "Seeding Locations...\n";
$seeded_locations_count = 0;
$existing_locations_count = 0;
$stmt_insert_location = $pdo->prepare("INSERT IGNORE INTO locations (uuid, name, address_text, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($location_map as $location) {
    $stmt_check_location = $pdo->prepare("SELECT uuid FROM locations WHERE name = ?");
    $stmt_check_location->execute([$location['name']]);
    if ($stmt_check_location->fetch()) {
        $existing_locations_count++;
    } else {
        $stmt_insert_location->execute([
            $location['uuid'],
            $location['name'],
            $location['address_text'],
            $location['latitude'],
            $location['longitude'],
            $location['notes']
        ]);
        $seeded_locations_count++;
    }
}
echo "Locations: {$seeded_locations_count} seeded, {$existing_locations_count} already existed.\n";

// ----- Seed Teams -----
echo "Seeding Teams...\n";
$seeded_teams_count = 0;
$existing_teams_count = 0;
$stmt_insert_team = $pdo->prepare("INSERT IGNORE INTO teams (uuid, team_name, club_id, division) VALUES (?, ?, ?, ?)");
foreach ($team_map as $team) {
    $stmt_check_team = $pdo->prepare("SELECT uuid FROM teams WHERE team_name = ? AND club_id = ?");
    $stmt_check_team->execute([$team['team_name'], $team['club_id']]);
    if ($stmt_check_team->fetch()) {
        $existing_teams_count++;
    } else {
        $stmt_insert_team->execute([
            $team['uuid'],
            $team['team_name'],
            $team['club_id'],
            $team['division']
        ]);
        $seeded_teams_count++;
    }
}
echo "Teams: {$seeded_teams_count} seeded, {$existing_teams_count} already existed.\n";

// ----- Seed Matches -----
echo "Seeding Matches...\n";
$seeded_matches_count = 0;
$existing_matches_count = 0;
$stmt_insert_match = $pdo->prepare("INSERT IGNORE INTO matches (uuid, home_team_id, away_team_id, location_uuid, division, expected_grade, match_date, kickoff_time, district, poule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($matches_data as $match) {
    $stmt_check_match = $pdo->prepare("SELECT uuid FROM matches WHERE match_date = ? AND kickoff_time = ? AND home_team_id = ? AND away_team_id = ? AND location_uuid = ?");
    $stmt_check_match->execute([
        $match['match_date'],
        $match['kickoff_time'],
        $match['home_team_id'],
        $match['away_team_id'],
        $match['location_uuid']
    ]);
    if ($stmt_check_match->fetch()) {
        $existing_matches_count++;
    } else {
        $stmt_insert_match->execute([
            $match['uuid'],
            $match['home_team_id'],
            $match['away_team_id'],
            $match['location_uuid'],
            $match['division'],
            $match['expected_grade'],
            $match['match_date'],
            $match['kickoff_time'],
            $match['district'],
            $match['poule']
        ]);
        $seeded_matches_count++;
    }
}
echo "Matches: {$seeded_matches_count} seeded, {$existing_matches_count} already existed.\n";

// ----- Seed Referees -----
$grades = ['A', 'B', 'C', 'D', 'E'];

$referees_data = []; // Store referee data for later use if needed
$referee_names = [
    'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona', 'George', 'Hannah', 'Isaac', 'Julia',
    'Kevin', 'Laura', 'Michael', 'Nina', 'Oscar', 'Paula', 'Quentin', 'Rachel', 'Samuel', 'Tina',
    'Umar', 'Vanessa', 'William', 'Xenia', 'Yusuf', 'Zara', 'Aaron', 'Bianca', 'Caleb', 'Delilah'
];


foreach ($referee_names as $index => $name) {
    $club = array_values($club_map)[array_rand(array_keys($club_map))];
    $ref_uuid = generate_uuid_v4();
    $ref = [
        'uuid' => $ref_uuid,
        'referee_id' => 'REF' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
        'first_name' => $name,
        'last_name' => 'Referee',
        'email' => strtolower($name) . '@example.com',
        'phone' => '000-000-000' . $index,
        'home_club_id' => $club['uuid'],
        'home_location_city' => $club['club_name'], // Assuming city is same as club name for dummy data
        'grade' => $grades[array_rand($grades)],
        'ar_grade' => $grades[array_rand($grades)],
        'home_lat' => $club['precise_location_lat'] + (mt_rand(-100, 100) / 10000), // Slight random variation for demo
        'home_lon' => $club['precise_location_lon'] + (mt_rand(-100, 100) / 10000)
    ];
    $referees_data[] = $ref;

    $stmt = $pdo->prepare("INSERT IGNORE INTO referees (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade, ar_grade, home_lat, home_lon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // Assuming ar_grade is the same as grade for initial seeding
    $stmt->execute([$ref['uuid'], $ref['referee_id'], $ref['first_name'], $ref['last_name'], $ref['email'], $ref['phone'], $ref['home_club_id'], $ref['home_location_city'], $ref['grade'], $ref['ar_grade'], $ref['home_lat'], $ref['home_lon']]);
}

echo "Referees seeded.\n";

// ----- Seed Referee Weekly Availability -----
echo "Seeding Referee Weekly Availability...\n";
$stmt_insert_availability = $pdo->prepare("
    INSERT INTO referee_weekly_availability
        (uuid, referee_id, weekday, morning_available, afternoon_available, evening_available)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        morning_available = VALUES(morning_available),
        afternoon_available = VALUES(afternoon_available),
        evening_available = VALUES(evening_available)
");

$availability_seeded_count = 0;
foreach ($referees_data as $referee) {
    for ($weekday = 0; $weekday <= 6; $weekday++) { // 0 = Sunday, 6 = Saturday
        $availability_uuid = generate_uuid_v4();
        $stmt_insert_availability->execute([
            $availability_uuid,
            $referee['uuid'],
            $weekday,
            true, // morning_available
            true, // afternoon_available
            true  // evening_available
        ]);
        $availability_seeded_count++;
    }
}
echo "{$availability_seeded_count} referee availability records seeded/updated.\n";

// ----- Seed Admin User -----
$adminUsername = 'admin';
$adminPassword = 'password'; // Securely hash this password

// Hash the password
$passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

// Generate UUID for the admin user
$adminUuid = '123e4567-e89b-12d3-a456-426614174000'; // Fixed UUID for default admin
$adminRole = 'super_admin'; // Set role to super_admin

try {
    // Check if admin user already exists
    $stmt = $pdo->prepare("SELECT uuid FROM users WHERE username = ?");
    $stmt->execute([$adminUsername]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        echo "Admin user '{$adminUsername}' already exists.\n";
    } else {
        // Insert the admin user
        $stmt = $pdo->prepare("INSERT INTO users (uuid, username, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$adminUuid, $adminUsername, $passwordHash, $adminRole]);
        echo "Admin user '{$adminUsername}' created successfully.\n";
    }
} catch (PDOException $e) {
    // Check if the error is about duplicate entry for username (though IGNORE should handle it, this is more explicit for username)
    // MySQL error code for duplicate entry is 1062
    if ($e->getCode() == '23000' || $e->errorInfo[1] == 1062) {
        echo "Admin user '{$adminUsername}' already exists (caught exception).\n";
    } else {
        echo "Error creating admin user '{$adminUsername}': " . $e->getMessage() . "\n";
    }
}

// ----- Additional Users -----
$newUsers = [
    ['username' => 'Antoine', 'password' => 'password', 'role' => 'super_admin'],
    ['username' => 'Celine', 'password' => 'password', 'role' => 'super_admin'],
    ['username' => 'Nathan', 'password' => 'password', 'role' => 'super_admin'],
];

foreach ($newUsers as $userData) {
    $username = $userData['username'];
    $password = $userData['password'];
    $role = $userData['role'];

    // Hash the password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Generate UUID for the user
    $userUuid = generate_uuid_v4(); // Using the existing UUID generation function

    try {
        // Check if user already exists
        $stmt = $pdo->prepare("SELECT uuid FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            echo "User '{$username}' already exists.\n";
        } else {
            // Insert the user
            $stmt = $pdo->prepare("INSERT INTO users (uuid, username, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userUuid, $username, $passwordHash, $role]);
            echo "User '{$username}' created successfully.\n";
        }
    } catch (PDOException $e) {
        if ($e->getCode() == '23000' || $e->errorInfo[1] == 1062) {
            echo "User '{$username}' already exists (caught exception).\n";
        } else {
            echo "Error creating user '{$username}': " . $e->getMessage() . "\n";
        }
    }
}

?>