<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();
require_once __DIR__ . '/sheets_data.php';

// ----- Function to generate a version 4 UUID -----
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ----- Validate Divisions and Districts -----
echo "Validating Divisions and Districts...\n";
$divisions_districts_data = [
    'Ereklasse' => 'National',
    'Futureklasse' => 'National',
    'Ereklasse Dames' => 'National',
    'Colts Cup' => 'National',
    '1e Klasse' => 'National',
    '3e Klasse' => ['Noord West', 'Zuid West']
];

$division_ids = [];
$district_ids = [];

foreach ($divisions_districts_data as $division_name => $districts) {
    $stmt_check_division = $pdo->prepare("SELECT id FROM divisions WHERE name = ?");
    $stmt_check_division->execute([$division_name]);
    $division_row = $stmt_check_division->fetch(PDO::FETCH_ASSOC);

    if (!$division_row) {
        echo "Error: Division {$division_name} not found in database. Please run seed_districts.php first.\n";
        exit(1);
    }
    $division_ids[$division_name] = $division_row['id'];

    $district_list = is_array($districts) ? $districts : [$districts];
    foreach ($district_list as $district_name) {
        $stmt_check_district = $pdo->prepare("SELECT id FROM districts WHERE name = ? AND division_id = ?");
        $stmt_check_district->execute([$district_name, $division_ids[$division_name]]);
        $district_row = $stmt_check_district->fetch(PDO::FETCH_ASSOC);

        if (!$district_row) {
            echo "Error: District {$district_name} for division {$division_name} not found in database. Please run seed_districts.php first.\n";
            exit(1);
        }
        $district_ids[$district_name] = $district_row['id'];
    }
}
echo "Divisions and districts validated successfully.\n";

// ----- Extract Data from Excel Sheets -----
$clubs_data = [];
$locations_data = [];
$teams_data = [];
$matches_data = [];


// Extract unique clubs, locations, and teams
$club_map = []; // Maps club name to UUID and details
$location_map = []; // Maps location name to UUID and details
$team_map = []; // Maps team name to UUID, club UUID, and division
$base_lat = 52.370216; // Approximate latitude for Amsterdam
$base_lon = 4.895168; // Approximate longitude for Amsterdam

foreach ($sheets as $sheet_key => $sheet_matches) {
    // Determine division and district based on sheet
    $division = '';
    $district = '';
    switch ($sheet_key) {
        case 'Ereklasse':
            $division = 'Ereklasse';
            $district = 'National';
            break;
        case 'Futureklasse':
            $division = 'Futureklasse';
            $district = 'National';
            break;
        case 'ereklasse dames':
            $division = 'Ereklasse Dames';
            $district = 'National';
            break;
        case 'colts cup':
            $division = 'Colts Cup';
            $district = 'National';
            break;
        case '1e klasse':
            $division = '1e Klasse';
            $district = 'National';
            break;
        case '3e klasse NW':
            $division = '3e Klasse';
            $district = 'Noord West';
            break;
        case '3e klasse ZW':
            $division = '3e Klasse';
            $district = 'Zuid West';
            break;
        default:
            echo "Warning: Skipping unknown sheet {$sheet_key}.\n";
            continue 2;
    }

    foreach ($sheet_matches as $match) {
        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $match['date'])) {
            echo "Warning: Invalid date format {$match['date']} for match in {$sheet_key} at {$match['location']} between {$match['home']} and {$match['away']}.\n";
            continue;
        }

        // Correct likely typo in Ereklasse date
        if ($sheet_key === 'Ereklasse' && $match['date'] === '2025-06-09') {
            echo "Warning: Correcting date '2025-06-09' to '2025-09-06' for match in {$sheet_key} at {$match['location']} between {$match['home']} and {$match['away']}.\n";
            $match['date'] = '2025-09-06';
        }

        // Validate and handle time
        $decimal_time = $match['time'];
        if ($decimal_time <= 0 || $decimal_time >= 1) {
            echo "Warning: Invalid or missing decimal time {$decimal_time} for match in {$sheet_key} on {$match['date']} at {$match['location']}. Defaulting to 15:00:00.\n";
            $decimal_time = 0.625; // Default to 15:00:00
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

?>