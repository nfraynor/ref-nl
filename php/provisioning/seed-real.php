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


// Build a name => id map for districts
function getDistrictIdMap(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, name FROM districts")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[mb_strtolower(trim($r['name']))] = (int)$r['id']; }
    return $map;
}

// Round-robin iterator (deterministic fallback)
function roundRobinPicker(array $ids): callable {
    $i = 0; $n = count($ids);
    return function() use (&$i, $n, $ids) { $v = $ids[$i % $n]; $i++; return $v; };
}



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
        $stmt_check_district = $pdo->prepare("SELECT id FROM districts WHERE name = ?");
        $stmt_check_district->execute([$district_name]);
        $district_row = $stmt_check_district->fetch(PDO::FETCH_ASSOC);

        if (!$district_row) {
            echo "Error: District {$district_name} not found in database. Please run seed_districts.php first.\n";
            exit(1);
        }
        $district_id = $district_row['id'];
        $district_ids[$district_name] = $district_id;

        // Check association
        $stmt_check_link = $pdo->prepare("SELECT 1 FROM division_districts WHERE division_id = ? AND district_id = ?");
        $stmt_check_link->execute([$division_ids[$division_name], $district_id]);
        if (!$stmt_check_link->fetch()) {
            echo "Error: No association between division {$division_name} and district {$district_name}. Please check seed_districts.php.\n";
            exit(1);
        }
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
                    'club_name' => $club_name,
                    'location_candidate_name' => $club_name . ' Ground' // we'll create/link a location with this name
                ];
            }
        }

        foreach ([$home_team => $home_club, $away_team => $away_club] as $team_name => $club_name) {
            if (!isset($team_map[$team_name]) && $team_name !== '') {
                if (isset($club_map[$club_name])) {
                    $team_map[$team_name] = [
                        'uuid'       => generate_uuid_v4(),
                        'team_name'  => $team_name,
                        'club_name'  => $club_name,                // keep name for later resolution
                        'club_id'    => $club_map[$club_name]['uuid'], // provisional (may be wrong if club existed)
                        'division'   => $division,
                        'district'   => $district,                 // for district_id insert later
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

        foreach ($club_map as $club) {
            $locName = $club['location_candidate_name'];
            if (!isset($location_map[$locName])) {
                $location_map[$locName] = [
                    'uuid' => generate_uuid_v4(),
                    'name' => $locName,
                    'address_text' => $locName . ", Netherlands",
                    'latitude' => $base_lat + (mt_rand(-100, 100) / 10000),
                    'longitude' => $base_lon + (mt_rand(-100, 100) / 10000),
                    'notes' => "Home ground for {$club['club_name']}"
                ];
            }
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


// ----- Seed Clubs -----
echo "Seeding Clubs...\n";
$seeded_clubs_count = 0;
$existing_clubs_count = 0;
$stmt_insert_club = $pdo->prepare("
    INSERT IGNORE INTO clubs (
        uuid, club_name, location_uuid,
        primary_contact_name, primary_contact_email, primary_contact_phone,
        website_url, notes, active
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($club_map as $club) {
    $stmt_check_club = $pdo->prepare("SELECT uuid FROM clubs WHERE club_name = ?");
    $stmt_check_club->execute([$club['club_name']]);
    $row = $stmt_check_club->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Club already exists — IMPORTANT: keep the real UUID so teams point to it
        $existing_clubs_count++;
        $club_map[$club['club_name']]['uuid'] = $row['uuid'];
    } else {
        // Resolve location_uuid from location_map we just prepared
        $locName = $club['location_candidate_name'];
        $locUuid = $location_map[$locName]['uuid'] ?? null;

        $stmt_insert_club->execute([
            $club['uuid'],
            $club['club_name'],
            $locUuid,
            null,
            null,
            null,
            null,
            null,
            1
        ]);

        // Assign CB-### code (uses AUTO_INCREMENT club_number)
        $pdo->prepare("UPDATE clubs SET club_id = CONCAT('CB-', LPAD(club_number, 3, '0')) WHERE uuid = ?")
            ->execute([$club['uuid']]);

        $seeded_clubs_count++;
    }
}

echo "Clubs: {$seeded_clubs_count} seeded, {$existing_clubs_count} already existed.\n";

$districtIdMap = getDistrictIdMap($pdo);           // ['national' => 1, 'noord west' => 2, ...]
$districtIds    = array_values($districtIdMap);
if (empty($districtIds)) {
    echo "Error: No districts found. Run seed_districts.php first.\n";
    exit(1);
}
$pickNextDistrict = roundRobinPicker($districtIds); // fallback if name lookup fails
// ---- Resolve real club UUIDs for teams (avoid FK errors) ----
echo "Resolving club UUIDs for teams...\n";
$clubUuidByName = [];
$stmt = $pdo->query("SELECT club_name, uuid FROM clubs");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clubUuidByName[$r['club_name']] = $r['uuid'];
}

// Patch team_map club_id to the actual DB UUID (by club_name)
foreach ($team_map as &$t) {
    if (!empty($t['club_name']) && isset($clubUuidByName[$t['club_name']])) {
        $t['club_id'] = $clubUuidByName[$t['club_name']];
    } else {
        // If we can't resolve, force null so we can skip later
        $t['club_id'] = null;
        echo "Warning: Unable to resolve club UUID for team {$t['team_name']} (club: " . ($t['club_name'] ?? '?') . "). Skipping.\n";
    }
}
unset($t);

// ----- Seed Teams -----
echo "Seeding Teams...\n";
$seeded_teams_count = 0;
$existing_teams_count = 0;
$stmt_insert_team = $pdo->prepare("
    INSERT INTO teams (uuid, team_name, club_id, division, district_id)
    VALUES (?, ?, ?, ?, ?)
");

foreach ($team_map as $team) {
    $stmt_check_team = $pdo->prepare("SELECT uuid FROM teams WHERE team_name = ? AND club_id = ?");
    $stmt_check_team->execute([$team['team_name'], $team['club_id']]);

    if ($stmt_check_team->fetch()) {
        $existing_teams_count++;
        continue;
    }

    // Resolve district_id from district name (case-insensitive), fallback to round-robin
    $districtName = $team['district'] ?? null;
    $districtId   = null;
    if ($districtName) {
        $key = mb_strtolower(trim($districtName));
        $districtId = $districtIdMap[$key] ?? null;
    }
    if ($districtId === null) {
        $districtId = $pickNextDistrict(); // deterministic fallback
        echo "Info: Falling back district for team {$team['team_name']} -> ID {$districtId}\n";
    }

    $stmt_insert_team->execute([
        $team['uuid'],
        $team['team_name'],
        $team['club_id'],
        $team['division'],
        $districtId
    ]);
    $seeded_teams_count++;
}

echo "Teams: {$seeded_teams_count} seeded, {$existing_teams_count} already existed.\n";

// ----- Seed Matches -----
echo "Seeding Matches...\n";
// Build a quick lookup by location UUID (from $location_map)
$locationByUuid = [];
foreach ($location_map as $loc) {
    $locationByUuid[$loc['uuid']] = $loc;
}

echo "Seeding Matches...\n";
$seeded_matches_count = 0;
$existing_matches_count = 0;

$stmt_insert_match = $pdo->prepare("
    INSERT IGNORE INTO matches
      (uuid, home_team_id, away_team_id,
       location_address, location_lat, location_lon,
       division, expected_grade, match_date, kickoff_time, district, poule)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

/* Uniqueness check: date+time+teams+address (NULL-safe) */
$stmt_check_match = $pdo->prepare("
    SELECT uuid
    FROM matches
    WHERE match_date = ?
      AND kickoff_time = ?
      AND home_team_id = ?
      AND away_team_id = ?
      AND (location_address <=> ?)
");

foreach ($matches_data as $match) {
    // Resolve address/coords from the prepared location_map via UUID
    $loc = $locationByUuid[$match['location_uuid']] ?? null;
    $addr = $loc['address_text'] ?? null;
    $lat  = $loc['latitude']     ?? null;
    $lon  = $loc['longitude']    ?? null;

    $stmt_check_match->execute([
        $match['match_date'],
        $match['kickoff_time'],
        $match['home_team_id'],
        $match['away_team_id'],
        $addr
    ]);

    if ($stmt_check_match->fetch()) {
        $existing_matches_count++;
        continue;
    }

    $stmt_insert_match->execute([
        $match['uuid'],
        $match['home_team_id'],
        $match['away_team_id'],
        $addr,
        $lat,
        $lon,
        $match['division'],
        $match['expected_grade'],
        $match['match_date'],
        $match['kickoff_time'],
        $match['district'],
        $match['poule']
    ]);
    $seeded_matches_count++;
}

echo "Matches: {$seeded_matches_count} seeded, {$existing_matches_count} already existed.\n";

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
echo "Matches: {$seeded_matches_count} seeded, {$existing_matches_count} already existed.\n";

?>