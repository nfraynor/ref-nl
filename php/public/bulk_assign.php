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
            // Fetch match location (prefer locations table via location_uuid, fallback to legacy match fields)
            $stmtMatch = $pdo->prepare("
                SELECT COALESCE(l.latitude, m.location_lat) AS location_lat, 
                       COALESCE(l.longitude, m.location_lon) AS location_lon 
                FROM matches m 
                LEFT JOIN locations l ON m.location_uuid = l.uuid 
                WHERE m.uuid = ?
            ");
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

            // Debug: Log why we're skipping or proceeding
            if (!$matchLoc || !$refLoc) {
                error_log("Skipping travel log for ref $refId match $matchId: No location data fetched (matchLoc=" . var_export($matchLoc, true) . ", refLoc=" . var_export($refLoc, true) . ")");
                continue;
            }

            if (!isset($matchLoc['location_lat']) || !isset($matchLoc['location_lon']) || !isset($refLoc['lat']) || !isset($refLoc['lon']) ||
                $matchLoc['location_lat'] === null || $matchLoc['location_lon'] === null || $refLoc['lat'] === null || $refLoc['lon'] === null) {
                error_log("Skipping travel log for ref $refId match $matchId: Missing coordinates (match_lat={$matchLoc['location_lat']}, match_lon={$matchLoc['location_lon']}, ref_lat={$refLoc['lat']}, ref_lon={$refLoc['lon']})");
                continue;
            }

            $distance = haversine($refLoc['lat'], $refLoc['lon'], $matchLoc['location_lat'], $matchLoc['location_lon']);

            // Debug: Log insertion attempt
            error_log("Inserting travel log for ref $refId match $matchId distance $distance");

            // Store in log
            $stmtLog = $pdo->prepare("
                INSERT INTO referee_travel_log (uuid, referee_id, match_id, distance_km) 
                VALUES (UUID(), ?, ?, ?) 
                ON DUPLICATE KEY UPDATE distance_km = ?
            ");
            $stmtLog->execute([$refId, $matchId, $distance, $distance]);

            // Debug: Check if insertion succeeded
            if ($stmtLog->rowCount() > 0) {
                error_log("Successfully inserted/updated travel log for ref $refId match $matchId");
            } else {
                error_log("No rows affected for travel log insert/update for ref $refId match $matchId (possible duplicate with no change)");
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