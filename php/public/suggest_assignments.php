<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

$pdo = Database::getConnection();

// Function copied from php/public/components/referee_dropdown.php
function isRefereeAvailable($refId, $matchDate, $kickoffTime, $pdo) {
    // 1. Check for hard-blocked dates
    $stmt = $pdo->prepare("
        SELECT 1 FROM referee_unavailability
        WHERE referee_id = :refId AND :matchDate BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmt->execute(['refId' => $refId, 'matchDate' => $matchDate]);
    if ($stmt->fetch()) return false;

    // 2. Check weekly availability
    $weekday = date('w', strtotime($matchDate)); // Sunday = 0 ... Saturday = 6
    $time = strtotime($kickoffTime);
    $hour = (int)date('H', $time);

    // Determine slot
    if ($hour < 12) $slot = 'morning_available';
    elseif ($hour < 17) $slot = 'afternoon_available';
    else $slot = 'evening_available';

    $stmt = $pdo->prepare("
        SELECT $slot FROM referee_weekly_availability
        WHERE referee_id = :refId AND weekday = :weekday
        LIMIT 1
    ");
    $stmt->execute(['refId' => $refId, 'weekday' => $weekday]);

    $row = $stmt->fetch();
    return $row && $row[$slot]; // true if set
}

$testingMode = false; // Changed default to false

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

// Load existing assignments
$dbExistingAssignments = [];
if (!$testingMode) {
    $dbExistingAssignments = $pdo->query("
        SELECT uuid AS match_id, match_date, referee_id, ar1_id, ar2_id
        FROM matches
        WHERE referee_id IS NOT NULL OR ar1_id IS NOT NULL OR ar2_id IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Process existing assignments to count games per ref per day
$existingAssignmentsByDateRef = [];
foreach ($dbExistingAssignments as $assignment) {
    $date = $assignment['match_date'];
    foreach (['referee_id', 'ar1_id', 'ar2_id'] as $role) {
        $refId = $assignment[$role];
        if ($refId) {
            if (!isset($existingAssignmentsByDateRef[$date][$refId])) {
                $existingAssignmentsByDateRef[$date][$refId] = 0;
            }
            $existingAssignmentsByDateRef[$date][$refId]++;
        }
    }
}

// Keeps track of how many games a referee has been *suggested* in this run
$suggestedAssignmentsCountThisRun = array_fill_keys(array_map(function($r){ return $r['uuid']; }, $referees), 0);


function canAssign($refId, $matchId, $matchDate, $kickoffTime, $locationLat, $locationLon, &$suggestions, $matches, $currentRole, $existingAssignmentsOnDateForRef) {
    // $existingAssignmentsOnDateForRef is the count of existing assignments for $refId on $matchDate

    $rolesInMatch = ['referee_id', 'ar1_id', 'ar2_id'];
    $maxGamesPerDay = 2;

    foreach ($rolesInMatch as $role) {
        if ($role === $currentRole) continue;
        if (!empty($suggestions[$matchId][$role]) && $suggestions[$matchId][$role] === $refId) {
            return false;
        }
    }

    // Define match duration and buffer in minutes
    $matchDurationMinutes = 90;
    $bufferMinutes = 30;
    $totalMatchTimeMinutes = $matchDurationMinutes + $bufferMinutes;

    // Convert current match kickoff time to a comparable format (minutes from midnight)
    list($currentHours, $currentMinutes) = explode(':', $kickoffTime);
    $currentMatchStartTimeMinutes = $currentHours * 60 + $currentMinutes;
    $currentMatchEndTimeMinutes = $currentMatchStartTimeMinutes + $matchDurationMinutes;

    // Count games for this referee on this specific day from current suggestions
    $suggestedGamesThisDay = 0;
    foreach ($suggestions as $s_matchId => $s_assignedRoles) {
        if ($s_matchId === $matchId) continue; // Don't count the match currently being assigned against itself here

        $s_matchDetails = null;
        foreach($matches as $m_detail) {
            if ($m_detail['uuid'] === $s_matchId) {
                $s_matchDetails = $m_detail;
                break;
            }
        }
        if ($s_matchDetails && $s_matchDetails['match_date'] === $matchDate) {
            foreach ($rolesInMatch as $role) {
                if (!empty($s_assignedRoles[$role]) && $s_assignedRoles[$role] === $refId) {
                    $suggestedGamesThisDay++;
                    break; // count match only once even if ref has multiple roles (should be prevented by first check)
                }
            }
        }
    }

    $totalGamesThisDayByThisRef = $existingAssignmentsOnDateForRef + $suggestedGamesThisDay;

    if ($totalGamesThisDayByThisRef >= $maxGamesPerDay) {
        return false; // Already at or over daily limit
    }

    // This loop checks for conflicts with *other suggested* assignments for the same ref on the same day.
    foreach ($suggestions as $otherMatchId => $assignedRoles) {
        // Check if the referee is assigned to any role in this other suggested match
        // This check is vital for location and time conflict for the *next* potential game.
        $isRefAssignedToOtherMatch = false;
        foreach ($rolesInMatch as $role) {
            if (!empty($assignedRoles[$role]) && $assignedRoles[$role] === $refId) {
                $isRefAssignedToOtherMatch = true;
                break;
            }
        }

        if (!$isRefAssignedToOtherMatch) {
            continue;
        }

        // Find the details of this other match
        $otherMatchDetails = null;
        foreach ($matches as $m) {
            if ($m['uuid'] === $otherMatchId) {
                $otherMatchDetails = $m;
                break;
            }
        }

        if (!$otherMatchDetails) continue; // Should not happen if suggestions are clean

        // Check for same day conflict
        if ($otherMatchDetails['match_date'] === $matchDate) {
            // If locations are different, cannot assign
            if ($otherMatchDetails['location_lat'] != $locationLat || $otherMatchDetails['location_lon'] != $locationLon) {
                return false; // Different location on the same day
            } else {
                // Same location, same day: Check for time overlap
                list($otherHours, $otherMinutes) = explode(':', $otherMatchDetails['kickoff_time']);
                $otherAssignedMatchStartTimeMinutes = $otherHours * 60 + $otherMinutes;
                $otherAssignedMatchEndTimeMinutes = $otherAssignedMatchStartTimeMinutes + $matchDurationMinutes;

                // Check for overlap:
                // New match starts during or too close to other match:
                // current_start is between (other_start - buffer) and (other_end + buffer)
                // Other match starts during or too close to new match:
                // other_start is between (current_start - buffer) and (current_end + buffer)

                $currentStartCheck = $currentMatchStartTimeMinutes;
                $currentEndCheck = $currentMatchEndTimeMinutes;
                $otherStartCheck = $otherAssignedMatchStartTimeMinutes;
                $otherEndCheck = $otherAssignedMatchEndTimeMinutes;

                // Condition for overlap:
                // (current_start < other_end + buffer) AND (other_start < current_end + buffer)
                if ($currentStartCheck < ($otherEndCheck + $bufferMinutes) && $otherStartCheck < ($currentEndCheck + $bufferMinutes)) {
                    return false; // Time overlap
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

// Initialize suggestions structure
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

            if (!$testingMode && $assignedCount > 0) continue; // This logic will be revised later for Rule 3

            if (!isRefereeAvailable($refId, $match['match_date'], $match['kickoff_time'], $pdo)) continue;
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

            if (!$testingMode && $assignedCount > 0) continue; // This logic will be revised later for Rule 3

            if (!isRefereeAvailable($refId, $match['match_date'], $match['kickoff_time'], $pdo)) continue;
            if (!canAssign($refId, $match['uuid'], $match['match_date'], $match['kickoff_time'], $match['location_lat'], $match['location_lon'], $suggestions, $matches, 'ar1_id')) continue;

            $suggestions[$match['uuid']]['ar1_id'] = $refId;
            $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
            break;
        }

        // AR2
        foreach ($referees as $ref) {
            $refId = $ref['uuid'];
            $assignedCount = $assignedReferees[$refId] ?? 0;

            if (!$testingMode && $assignedCount > 0) continue; // This logic will be revised later for Rule 3

            if (!isRefereeAvailable($refId, $match['match_date'], $match['kickoff_time'], $pdo)) continue;
            if (!canAssign($refId, $match['uuid'], $match['match_date'], $match['kickoff_time'], $match['location_lat'], $match['location_lon'], $suggestions, $matches, 'ar2_id')) continue;

            $suggestions[$match['uuid']]['ar2_id'] = $refId;
            $assignedReferees[$refId] = ($assignedReferees[$refId] ?? 0) + 1;
            break;
        }
    }
}

header('Content-Type: application/json');
echo json_encode($suggestions, JSON_PRETTY_PRINT);
