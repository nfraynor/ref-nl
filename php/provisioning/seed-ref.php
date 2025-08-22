<?php

require_once __DIR__ . '/../utils/db.php'; // Assumes db.php sets up PDO using config/database.php

$pdo = Database::getConnection();
require_once __DIR__ . '/referee_data.php';

// ----- Function to generate a version 4 UUID -----
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ----- Function to parse referee name -----
function parse_referee_name($name_str) {
    if (preg_match('/^([^,]+),\s*([^()]+)\s*\(([^)]+)\)/', $name_str, $matches)) {
        $surname = trim($matches[1]);
        $initials = trim($matches[2]);
        $first_name = trim($matches[3]);

        // Handle Dutch prefixes in initials
        if (preg_match('/([A-Z.]+)\s+(van|de|der|van der|van de|den)\s*$/i', $initials, $prefix_matches)) {
            $prefix = $prefix_matches[2];
            $last_name = $prefix . ' ' . $surname;
        } else {
            $last_name = $surname;
        }
    } else {
        // Fallback: treat whole string as first_name, empty last_name
        $first_name = trim($name_str);
        $last_name = '';
    }

    return ['first_name' => $first_name, 'last_name' => $last_name];
}


// ----- Parse referee rows and build referees_data -----
$referees_data = [];
foreach ($referee_rows as $row) {
    $name_str = $row['Name'];
    $email_str = $row['Email'];
    $class_str = $row['Class'];
    $district = $row['District'];
    $club = $row['Club'];

    // Extract grade
    if (!preg_match('/^([A-E]):/', $class_str, $grade_match)) {
        continue; // Skip if no grade
    }
    $grade = $grade_match[1];

    if (!in_array($grade, ['A', 'B', 'C', 'D'])) {
        continue; // Skip if not A-D
    }

    // Parse name
    $name_parts = parse_referee_name($name_str);
    $first_name = $name_parts['first_name'];
    $last_name = $name_parts['last_name'];

    if (empty($first_name) || empty($last_name)) {
        echo "Warning: Could not parse name from: $name_str\n";
        continue;
    }

    // Extract district name (remove "District ")
    $district_name = trim(str_replace('District ', '', $district));

    // Fetch district_id
    $stmt_district = $pdo->prepare("SELECT id FROM districts WHERE name = ?");
    $stmt_district->execute([$district_name]);
    $district_row = $stmt_district->fetch();
    if (!$district_row) {
        echo "Warning: District '$district_name' not found for referee $name_str. Skipping.\n";
        continue;
    }
    $district_id = $district_row['id'];

    // Get coords or default
    $base_lat = $district_coords[$district_name]['lat'] ?? 52.1326;
    $base_lon = $district_coords[$district_name]['lon'] ?? 5.2913; // Default to Netherlands center
    $home_lat = $base_lat + (mt_rand(-100, 100) / 10000);
    $home_lon = $base_lon + (mt_rand(-100, 100) / 10000);

    // Random AR grade A-D
    $ar_grades = ['A', 'B', 'C', 'D'];
    $ar_grade = $ar_grades[array_rand($ar_grades)];

    // Select primary email (first one if multiple)
    $emails = explode(';', $email_str);
    $primary_email = trim($emails[0]);

    // Build referee data
    $referees_data[] = [
        'uuid' => generate_uuid_v4(),
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $primary_email,
        'phone' => null, // No phone in data
        'home_club_id' => null, // Ignored
        'home_location_city' => null, // Set to null as per fix
        'grade' => $grade,
        'ar_grade' => $ar_grade,
        'home_lat' => $home_lat,
        'home_lon' => $home_lon,
        'district_id' => $district_id
    ];
}

// ----- Seed Referees -----
echo "Seeding Referees...\n";
$seeded_referees_count = 0;
$existing_referees_count = 0;
$stmt_insert_referee = $pdo->prepare("
    INSERT IGNORE INTO referees 
      (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade, ar_grade, home_lat, home_lon, district_id) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");


foreach ($referees_data as $index => $ref) {
    // Generate referee_id
    $referee_id = 'REF' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);

    // Check if exists (by email, assuming unique)
    $stmt_check_referee = $pdo->prepare("SELECT uuid FROM referees WHERE email = ?");
    $stmt_check_referee->execute([$ref['email']]);
    if ($stmt_check_referee->fetch()) {
        $existing_referees_count++;
    } else {
        $stmt_insert_referee->execute([
            $ref['uuid'],
            $referee_id,
            $ref['first_name'],
            $ref['last_name'],
            $ref['email'],
            $ref['phone'],
            $ref['home_club_id'],
            $ref['home_location_city'],
            $ref['grade'],
            $ref['ar_grade'],
            $ref['home_lat'],
            $ref['home_lon'],
            $ref['district_id']
        ]);
        $seeded_referees_count++;
    }
}
echo "Referees: {$seeded_referees_count} seeded, {$existing_referees_count} already existed.\n";

// ----- Seed Referee Weekly Availability -----
echo "Seeding Referee Weekly Availability...\n";
$availability_seeded_count = 0;
$availability_existing_count = 0;
$stmt_insert_availability = $pdo->prepare("
    INSERT IGNORE INTO referee_weekly_availability 
        (uuid, referee_id, weekday, morning_available, afternoon_available, evening_available) 
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt_check_availability = $pdo->prepare("SELECT COUNT(*) FROM referee_weekly_availability WHERE referee_id = ? AND weekday = ?");

foreach ($referees_data as $ref) {
    for ($weekday = 0; $weekday <= 6; $weekday++) {
        $stmt_check_availability->execute([$ref['uuid'], $weekday]);
        if ($stmt_check_availability->fetchColumn() > 0) {
            $availability_existing_count++;
        } else {
            $availability_uuid = generate_uuid_v4();
            $stmt_insert_availability->execute([
                $availability_uuid,
                $ref['uuid'],
                $weekday,
                true, // morning_available
                true, // afternoon_available
                true  // evening_available
            ]);
            $availability_seeded_count++;
        }
    }
}
echo "Availability: {$availability_seeded_count} seeded, {$availability_existing_count} already existed.\n";

?>