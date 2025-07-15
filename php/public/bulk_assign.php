<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

if (!isset($_POST['assignments']) || !is_array($_POST['assignments'])) {
    die("No assignments received.");
}

$assignments = $_POST['assignments'];

foreach ($assignments as $matchId => $roles) {
    // Validate matchId
    if (empty($matchId)) {
        continue;
    }

    // Build assignment update dynamically
    $fields = [];
    $values = [];

    foreach ($roles as $role => $refereeId) {
        if (!in_array($role, ['referee_id', 'ar1_id', 'ar2_id', 'commissioner_id'])) {
            continue;
        }

        if (empty($refereeId)) {
            $fields[] = "$role = NULL";
        } else {
            $fields[] = "$role = ?";
            $values[] = $refereeId;
        }
    }

    if (!empty($fields)) {
        $sql = "UPDATE matches SET " . implode(", ", $fields) . " WHERE uuid = ?";
        $values[] = $matchId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    }

    // Calculate travel distance for each assigned referee
    foreach ($roles as $role => $refId) {
        if (!empty($refId)) {
            // Fetch match location
            $stmtMatch = $pdo->prepare("SELECT location_lat, location_lon FROM matches WHERE uuid = ?");
            $stmtMatch->execute([$matchId]);
            $matchLoc = $stmtMatch->fetch(PDO::FETCH_ASSOC);

            // Fetch referee home lat/lon (fall back to home club if not set)
            $stmtRef = $pdo->prepare("
                SELECT 
                    IFNULL(r.home_lat, c.precise_location_lat) AS lat, 
                    IFNULL(r.home_lon, c.precise_location_lon) AS lon
                FROM referees r
                LEFT JOIN clubs c ON r.home_club_id = c.uuid
                WHERE r.uuid = ?
            ");
            $stmtRef->execute([$refId]);
            $refLoc = $stmtRef->fetch(PDO::FETCH_ASSOC);

            if ($matchLoc['location_lat'] && $refLoc['lat']) {
                $distance = haversine($refLoc['lat'], $refLoc['lon'], $matchLoc['location_lat'], $matchLoc['location_lon']);

                // Store in log
                $stmtLog = $pdo->prepare("
                    INSERT INTO referee_travel_log (uuid, referee_id, match_id, distance_km) 
                    VALUES (UUID(), ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE distance_km = ?
                ");
                $stmtLog->execute([$refId, $matchId, $distance, $distance]);
            }
        }
    }
}

// Haversine function
function haversine($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // Earth's radius in km
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat / 2) * sin($dlat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) * sin($dlon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($R * $c, 2); // Round to 2 decimal places
}

header("Location: matches.php?saved=1");
exit;
?>