<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();

// ----- Seed Divisions and Districts -----
echo "Seeding Divisions and Districts...\n";
$divisions_districts_data = [
    'Division 1' => ['National'],
    'Division 2' => ['Noord', 'Zuid'],
    'Division 3' => ['Noord', 'Zuid', 'Oost', 'West', 'Midden']
];

$seeded_divisions_count = 0;
$existing_divisions_count = 0;
$seeded_districts_count = 0;
$existing_districts_count = 0;

foreach ($divisions_districts_data as $division_name => $districts_array) {
    // Check if division exists, insert if not
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
            // Check if district exists for this division, insert if not
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
// ----- End Seed Divisions and Districts -----\n

// Function to generate a version 4 UUID
function generate_uuid_v4() {
    // Generate 16 bytes (128 bits) of random data
    $data = random_bytes(16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID string
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}


// ----- Clubs -----
$clubs = [
    ['uuid' => generate_uuid_v4(), 'club_id' => 'OLDTOWN', 'club_name' => 'Old Town RFC', 'precise_location_lat' => 52.123456, 'precise_location_lon' => 4.654321, 'address_text' => 'Old Town Stadium'],
    ['uuid' => generate_uuid_v4(), 'club_id' => 'NEWTOWN', 'club_name' => 'New Town RFC', 'precise_location_lat' => 52.987654, 'precise_location_lon' => 4.123456, 'address_text' => 'New Town Park'],
    ['uuid' => generate_uuid_v4(), 'club_id' => 'RIVERCITY', 'club_name' => 'River City RFC', 'precise_location_lat' => 53.555555, 'precise_location_lon' => 5.555555, 'address_text' => 'River City Ground'],
    ['uuid' => generate_uuid_v4(), 'club_id' => 'HILLTOP', 'club_name' => 'Hilltop RFC', 'precise_location_lat' => 51.222222, 'precise_location_lon' => 3.222222, 'address_text' => 'Hilltop Field'],
];

foreach ($clubs as $club) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO clubs (uuid, club_id, club_name, precise_location_lat, precise_location_lon, address_text) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$club['uuid'], $club['club_id'], $club['club_name'], $club['precise_location_lat'], $club['precise_location_lon'], $club['address_text']]);
}

echo "Clubs seeded.\n";

// ----- Teams -----
$teams = [];
$divisions = ['Division 1', 'Division 2', 'Division 3'];

foreach ($clubs as $club) {
    for ($i = 1; $i <= 3; $i++) {
        $team_uuid = generate_uuid_v4();
        $team = [
            'uuid' => $team_uuid,
            'team_name' => "{$i}st XV",
            'club_id' => $club['uuid'],
            'division' => $divisions[array_rand($divisions)],
        ];
        $teams[] = $team; // Add to $teams array for later use if needed

        $stmt = $pdo->prepare("INSERT IGNORE INTO teams (uuid, team_name, club_id, division) VALUES (?, ?, ?, ?)");
        $stmt->execute([$team['uuid'], $team['team_name'], $team['club_id'], $team['division']]);
    }
}

echo "Teams seeded.\n";

// ----- Referees -----
$grades = ['A', 'B', 'C', 'D', 'E'];

// Fetch districts for assignment
$districts_stmt = $pdo->query("SELECT id FROM districts");
$district_ids = $districts_stmt->fetchAll(PDO::FETCH_COLUMN);

$referees_data = []; // Store referee data for later use if needed
$referee_names = [
    'Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona', 'George', 'Hannah', 'Isaac', 'Julia',
    'Kevin', 'Laura', 'Michael', 'Nina', 'Oscar', 'Paula', 'Quentin', 'Rachel', 'Samuel', 'Tina',
    'Umar', 'Vanessa', 'William', 'Xenia', 'Yusuf', 'Zara', 'Aaron', 'Bianca', 'Caleb', 'Delilah'
];


foreach ($referee_names as $index => $name) {
    $club = $clubs[array_rand($clubs)];
    $ref_uuid = generate_uuid_v4();
    $district_id = $district_ids[array_rand($district_ids)];
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
        'home_lon' => $club['precise_location_lon'] + (mt_rand(-100, 100) / 10000),
        'district_id' => $district_id
    ];
    $referees_data[] = $ref;

    $stmt = $pdo->prepare("INSERT IGNORE INTO referees (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade, ar_grade, home_lat, home_lon, district_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // Assuming ar_grade is the same as grade for initial seeding
    $stmt->execute([$ref['uuid'], $ref['referee_id'], $ref['first_name'], $ref['last_name'], $ref['email'], $ref['phone'], $ref['home_club_id'], $ref['home_location_city'], $ref['grade'], $ref['ar_grade'], $ref['home_lat'], $ref['home_lon'], $ref['district_id']]);
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
// ----- End Seed Referee Weekly Availability -----\n


// ----- Sample Locations -----
$sample_locations_data = [
    ['name' => 'Central Sports Park Pitch 1', 'address_text' => '123 Main St, Sportsville, SP 12345', 'latitude' => 52.370216, 'longitude' => 4.895168, 'notes' => 'Main pitch, well maintained. Good parking.'],
    ['name' => 'North End Community Field', 'address_text' => '456 North Rd, Townsville, TV 67890', 'latitude' => 52.400000, 'longitude' => 4.900000, 'notes' => 'Artificial turf. Limited street parking.'],
    ['name' => 'Riverside Rugby Ground', 'address_text' => '789 River Ln, Riverton, RT 24680', 'latitude' => 52.351111, 'longitude' => 4.888888, 'notes' => 'Often muddy after rain. Clubhouse nearby.'],
    ['name' => 'Hilltop Arena', 'address_text' => '101 Summit Dr, Hillcrest, HC 13579', 'latitude' => 52.391234, 'longitude' => 4.876543, 'notes' => 'Exposed to wind. Excellent views.'],
    ['name' => 'Westside Training Pitch', 'address_text' => '222 West Ave, Westfield, WF 97531', 'latitude' => 52.365432, 'longitude' => 4.865432, 'notes' => 'Primarily for training, basic facilities.'],
    ['name' => 'Old Town RFC Stadium', 'address_text' => '1 Old Stadium Rd, Old Town, OT 54321', 'latitude' => 52.123456, 'longitude' => 4.654321, 'notes' => 'Historic ground, home of Old Town RFC.'],
    ['name' => 'New Town RFC Pitch B', 'address_text' => '2 New Park Ln, New Town, NT 87654', 'latitude' => 52.987654, 'longitude' => 4.123456, 'notes' => 'Secondary pitch for New Town RFC.'],
];

$seeded_location_uuids = [];
$stmt_insert_location = $pdo->prepare("INSERT IGNORE INTO locations (uuid, name, address_text, latitude, longitude, notes) VALUES (?, ?, ?, ?, ?, ?)");

foreach ($sample_locations_data as $loc_data) {
    $location_uuid = generate_uuid_v4();
    $stmt_insert_location->execute([
        $location_uuid,
        $loc_data['name'],
        $loc_data['address_text'],
        $loc_data['latitude'],
        $loc_data['longitude'],
        $loc_data['notes']
    ]);
    $seeded_location_uuids[] = $location_uuid;
}
echo count($seeded_location_uuids) . " sample locations seeded.\n";


// ----- Matches -----
// Ensure $teams is populated correctly for match seeding
// If $teams was not populated globally before, query them or ensure it is
if (empty($teams)) {
    $stmt = $pdo->query("SELECT uuid, division FROM teams");
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($teams)) {
        echo "No teams found to seed matches. Ensure teams are seeded first.\n";
        exit;
    }
}


$matches = [];
$districts = ['Noord', 'Zuid', 'Oost', 'West', 'Midden'];
$poules = ['Cup', 'Plate', 'Bowl', 'Shield'];

for ($i = 1; $i <= 400; $i++) {
    $homeTeam = $teams[array_rand($teams)];
    $awayTeam = $teams[array_rand($teams)];

    // Prevent same team vs same team
    while ($homeTeam['uuid'] === $awayTeam['uuid']) {
        $awayTeam = $teams[array_rand($teams)];
    }

    // Generate random Saturday or Sunday within the next 6 months
    $startDate = strtotime("next Saturday");
    $endDate = strtotime("+8 months", $startDate);
    $randomTimestamp = rand($startDate, $endDate); // Use a more descriptive variable name

    // Ensure it's Saturday or Sunday
    $dayOfWeek = date('N', $randomTimestamp); // 6 = Saturday, 7 = Sunday
    if ($dayOfWeek != 6 && $dayOfWeek != 7) {
        // Go to the previous Saturday
        $randomTimestamp = strtotime("last Saturday", $randomTimestamp);
    }

    $match_date = date('Y-m-d', $randomTimestamp);

    // Select random kickoff time
    $kickoff_times = ['11:30:00', '13:00:00', '14:30:00', '16:00:00', '17:30:00'];
    $kickoff_time = $kickoff_times[array_rand($kickoff_times)];

    // Select random district and poule
    $district = $districts[array_rand($districts)];
    $poule = $poules[array_rand($poules)];
    $match_uuid = generate_uuid_v4();

    $selected_location_uuid = $seeded_location_uuids[array_rand($seeded_location_uuids)];

    $match = [
        'uuid' => $match_uuid,
        'home_team_id' => $homeTeam['uuid'],
        'away_team_id' => $awayTeam['uuid'],
        'location_uuid' => $selected_location_uuid, // Use seeded location UUID
        'division' => $homeTeam['division'], // Assuming division is taken from home team
        'expected_grade' => $grades[array_rand($grades)],
        'match_date' => $match_date,
        'kickoff_time' => $kickoff_time,
        'district' => $district,
        'poule' => $poule
    ];
    // $matches[] = $match; // Add to $matches array if needed for other operations

    $stmt = $pdo->prepare("INSERT IGNORE INTO matches (uuid, home_team_id, away_team_id, location_uuid, division, expected_grade, match_date, kickoff_time, district, poule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
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
}

echo "2500 matches seeded with districts and poules.\n";

// ----- Admin User -----
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