<?php
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

// Load referees
$referees = $pdo->query("SELECT uuid, grade FROM referees ORDER BY grade DESC")->fetchAll(PDO::FETCH_ASSOC);

// Load matches
$matches = $pdo->query("
    SELECT 
        m.uuid,
        m.match_date,
        m.kickoff_time,
        m.location_lat,
        m.location_lon,
        m.division
    FROM matches m
    ORDER BY m.match_date ASC, m.kickoff_time ASC
")->fetchAll(PDO::FETCH_ASSOC);

$existingAssignments = $pdo->query("
    SELECT uuid AS match_id, referee_id, ar1_id, ar2_id FROM matches 
    WHERE referee_id IS NOT NULL OR ar1_id IS NOT NULL OR ar2_id IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
$existingAssignments =[];
$assignedReferees = [];

foreach ($existingAssignments as $assignment) {
    foreach (['referee_id', 'ar1_id', 'ar2_id'] as $role) {
        if (!empty($assignment[$role])) {
            if (!isset($assignedReferees[$assignment[$role]])) {
                $assignedReferees[$assignment[$role]] = 0;
            }
            $assignedReferees[$assignment[$role]]++;
        }
    }
}


$suggestions = [];

// Helper to check if referee already assigned at this location/date
function isAvailable($suggestions, $refereeId, $matchDate, $locationLat, $locationLon, $role, $matches) {

    foreach ($suggestions as $matchId => $assigned) {

        if ($assigned[$role] !== $refereeId) continue;

        // Find match details
        foreach ($matches as $match) {
            if ($match['uuid'] !== $matchId) continue;

            // Same day?
            if ($match['match_date'] === $matchDate) {
                // Same location → allowed
                if ($match['location_lat'] == $locationLat && $match['location_lon'] == $locationLon) {
                    return true;
                } else {
                    return false; // Same day but different location → not allowed
                }
            }
        }
    }

    return true; // No conflict
}

// FIRST PASS → assign REFEREE
foreach ($matches as $match) {
    $suggestions[$match['uuid']] = [
        'referee_id' => null,
        'ar1_id' => null,
        'ar2_id' => null
    ];

    foreach ($referees as $ref) {
        $refId = $ref['uuid'];
        $assignedCount = $assignedReferees[$refId] ?? 0;

        if ($assignedCount > 0) continue; // Priority to referees with no games yet

        if (!isAvailable($suggestions, $refId, $match['match_date'], $match['location_lat'], $match['location_lon'], 'referee_id', $matches)) continue;

        $suggestions[$match['uuid']]['referee_id'] = $refId;
        $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
        break;
    }
}

// SECOND PASS → assign AR1 / AR2
foreach ($matches as $match) {
    foreach (['ar1_id', 'ar2_id'] as $role) {

        foreach ($referees as $ref) {
            $refId = $ref['uuid'];

            // ✅ Check if already assigned to REFEREE or AR1 in this match
            $alreadyAssigned = [];

            if ($suggestions[$match['uuid']]['referee_id']) {
                $alreadyAssigned[] = $suggestions[$match['uuid']]['referee_id'];
            }
            if ($suggestions[$match['uuid']]['ar1_id'] && $role === 'ar2_id') {
                $alreadyAssigned[] = $suggestions[$match['uuid']]['ar1_id'];
            }

            if (in_array($refId, $alreadyAssigned)) continue;

            $suggestions[$match['uuid']][$role] = $refId;
            break;
        }
    }
}


header('Content-Type: application/json');
echo json_encode($suggestions);
