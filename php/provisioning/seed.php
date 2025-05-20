<?php

require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

function uuid() {
    return uniqid();
}

// ----- Clubs -----
$clubs = [
    ['uuid' => uuid(), 'club_id' => 'OLDTOWN', 'club_name' => 'Old Town RFC', 'precise_location_lat' => 52.123456, 'precise_location_lon' => 4.654321, 'address_text' => 'Old Town Stadium'],
    ['uuid' => uuid(), 'club_id' => 'NEWTOWN', 'club_name' => 'New Town RFC', 'precise_location_lat' => 52.987654, 'precise_location_lon' => 4.123456, 'address_text' => 'New Town Park'],
    ['uuid' => uuid(), 'club_id' => 'RIVERCITY', 'club_name' => 'River City RFC', 'precise_location_lat' => 53.555555, 'precise_location_lon' => 5.555555, 'address_text' => 'River City Ground'],
    ['uuid' => uuid(), 'club_id' => 'HILLTOP', 'club_name' => 'Hilltop RFC', 'precise_location_lat' => 51.222222, 'precise_location_lon' => 3.222222, 'address_text' => 'Hilltop Field'],
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
        $team = [
            'uuid' => uuid(),
            'team_name' => "{$i}st XV",
            'club_id' => $club['uuid'],
            'division' => $divisions[array_rand($divisions)],
        ];
        $teams[] = $team;

        $stmt = $pdo->prepare("INSERT IGNORE INTO teams (uuid, team_name, club_id, division) VALUES (?, ?, ?, ?)");
        $stmt->execute([$team['uuid'], $team['team_name'], $team['club_id'], $team['division']]);
    }
}

echo "Teams seeded.\n";

// ----- Referees -----
$grades = ['A', 'B', 'C', 'D', 'E'];

$referees = [];
$referee_names = ['Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona', 'George', 'Hannah', 'Isaac', 'Julia'];

foreach ($referee_names as $index => $name) {
    $club = $clubs[array_rand($clubs)];
    $ref = [
        'uuid' => uuid(),
        'referee_id' => 'REF' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
        'first_name' => $name,
        'last_name' => 'Referee',
        'email' => strtolower($name) . '@example.com',
        'phone' => '000-000-000' . $index,
        'home_club_id' => $club['uuid'],
        'home_location_city' => $club['club_name'],
        'grade' => $grades[array_rand($grades)],
    ];
    $referees[] = $ref;

    $stmt = $pdo->prepare("INSERT IGNORE INTO referees (uuid, referee_id, first_name, last_name, email, phone, home_club_id, home_location_city, grade) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$ref['uuid'], $ref['referee_id'], $ref['first_name'], $ref['last_name'], $ref['email'], $ref['phone'], $ref['home_club_id'], $ref['home_location_city'], $ref['grade']]);
}

echo "Referees seeded.\n";

// ----- Matches -----
$matches = [];
$districts = ['Noord', 'Zuid', 'Oost', 'West', 'Midden'];
$poules = ['Cup', 'Plate', 'Bowl', 'Shield'];

for ($i = 1; $i <= 250; $i++) {
    $homeTeam = $teams[array_rand($teams)];
    $awayTeam = $teams[array_rand($teams)];

    // Prevent same team vs same team
    while ($homeTeam['uuid'] === $awayTeam['uuid']) {
        $awayTeam = $teams[array_rand($teams)];
    }

    // Generate random Saturday or Sunday within the next 6 months
    $startDate = strtotime("next Saturday");
    $endDate = strtotime("+6 months", $startDate);
    $randomDate = rand($startDate, $endDate);

    // Ensure it's Saturday or Sunday
    $dayOfWeek = date('N', $randomDate); // 6 = Saturday, 7 = Sunday
    if ($dayOfWeek != 6 && $dayOfWeek != 7) {
        $randomDate = strtotime("last Saturday", $randomDate);
    }

    $match_date = date('Y-m-d', $randomDate);

    // Select random kickoff time
    $kickoff_times = ['11:30:00', '13:00:00', '14:30:00', '16:00:00', '17:30:00'];
    $kickoff_time = $kickoff_times[array_rand($kickoff_times)];

    // Select random district and poule
    $district = $districts[array_rand($districts)];
    $poule = $poules[array_rand($poules)];

    $match = [
        'uuid' => uuid(),
        'home_team_id' => $homeTeam['uuid'],
        'away_team_id' => $awayTeam['uuid'],
        'location_lat' => rand(50, 55) + (rand(0, 999999) / 1000000),
        'location_lon' => rand(3, 7) + (rand(0, 999999) / 1000000),
        'location_address' => 'Random Pitch Location ' . $i,
        'division' => $homeTeam['division'],
        'expected_grade' => $grades[array_rand($grades)],
        'match_date' => $match_date,
        'kickoff_time' => $kickoff_time,
        'district' => $district,
        'poule' => $poule
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO matches (uuid, home_team_id, away_team_id, location_lat, location_lon, location_address, division, expected_grade, match_date, kickoff_time, district, poule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $match['uuid'],
        $match['home_team_id'],
        $match['away_team_id'],
        $match['location_lat'],
        $match['location_lon'],
        $match['location_address'],
        $match['division'],
        $match['expected_grade'],
        $match['match_date'],
        $match['kickoff_time'],
        $match['district'],
        $match['poule']
    ]);
}

echo "250 matches seeded with districts and poules.\n";


?>
