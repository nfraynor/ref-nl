<?php
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

$testingMode = true;

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

// Load existing assignments (unless testing)
if ($testingMode) {
    $existingAssignments = [];
} else {
    $existingAssignments = $pdo->query("
        SELECT uuid AS match_id, referee_id, ar1_id, ar2_id FROM matches
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$assignedReferees = [];

foreach ($existingAssignments as $assignment) {
    foreach (['referee_id', 'ar1_id', 'ar2_id'] as $role) {
        $refId = $assignment[$role];
        if (!$refId) continue;

        if (!isset($assignedReferees[$refId])) {
            $assignedReferees[$refId] = 0;
        }
        $assignedReferees[$refId]++;
    }
}

function canAssign($refId, $matchId, $matchDate, $kickoffTime, $locationLat, $locationLon, $suggestions, $matches, $currentRole) {

    $rolesInMatch = ['referee_id', 'ar1_id', 'ar2_id'];

    foreach ($rolesInMatch as $role) {
        if ($role === $currentRole) continue;
        if (!empty($suggestions[$matchId][$role]) && $suggestions[$matchId][$role] === $refId) {
            return false;
        }
    }

    foreach ($suggestions as $otherMatchId => $assigned) {
        foreach ($rolesInMatch as $role) {
            if (empty($assigned[$role]) || $assigned[$role] !== $refId) continue;

            foreach ($matches as $match) {
                if ($match['uuid'] !== $otherMatchId) continue;
                if ($match['match_date'] !== $matchDate) continue;

                if ($match['location_lat'] == $locationLat && $match['location_lon'] == $locationLon) {
                    continue;
                } else {
                    return false;
                }
            }
        }
    }

    return true;
}

// Group matches by date
$matchesByDate = [];

foreach ($matches as $match) {
    $matchesByDate[$match['match_date']][] = $match;
}

$suggestions = [];

foreach ($matches as $match) {
    $suggestions[$match['uuid']] = [
        'referee_id' => null,
        'ar1_id' => null,
        'ar2_id' => null
    ];
}

// Process day by day
foreach ($matchesByDate as $date => $dayMatches) {

    usort($dayMatches, function($a, $b) {
        return strcmp($a['division'], $b['division']);
    });

    // REFEREE FIRST
    foreach ($dayMatches as $match) {

        foreach ($referees as $ref) {
            $refId = $ref['uuid'];
            $assignedCount = $assignedReferees[$refId] ?? 0;

            if (!$testingMode && $assignedCount > 0) continue;

            if (!canAssign($refId, $match['uuid'], $match['match_date'], $match['kickoff_time'], $match['location_lat'], $match['location_lon'], $suggestions, $matches, 'referee_id')) continue;

            $suggestions[$match['uuid']]['referee_id'] = $refId;
            $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
            break;
        }
    }

    // AR1 + AR2 TOGETHER per match
    foreach ($dayMatches as $match) {

        // AR1
        foreach ($referees as $ref) {
            $refId = $ref['uuid'];
            $assignedCount = $assignedReferees[$refId] ?? 0;

            if (!$testingMode && $assignedCount > 0) continue;

            if (!canAssign($refId, $match['uuid'], $match['match_date'], $match['kickoff_time'], $match['location_lat'], $match['location_lon'], $suggestions, $matches, 'ar1_id')) continue;

            $suggestions[$match['uuid']]['ar1_id'] = $refId;
            $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
            break;
        }

        // AR2
        foreach ($referees as $ref) {
            $refId = $ref['uuid'];
            $assignedCount = $assignedReferees[$refId] ?? 0;

            if (!$testingMode && $assignedCount > 0) continue;

            if (!canAssign($refId, $match['uuid'], $match['match_date'], $match['kickoff_time'], $match['location_lat'], $match['location_lon'], $suggestions, $matches, 'ar2_id')) continue;

            $suggestions[$match['uuid']]['ar2_id'] = $refId;
            $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
            break;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($suggestions, JSON_PRETTY_PRINT);
