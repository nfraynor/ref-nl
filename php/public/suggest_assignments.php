<?php
/**
 * suggest_assignments.php
 *
 * Suggests referee assignments for filtered matches, avoiding red conflicts (time overlaps, different locations)
 * and minimizing orange conflicts (same-day non-overlapping). Enforces daily/weekly limits, prioritizes higher-grade
 * referees with fewer assignments, and respects availability and max travel distance.
 *
 * If no perfect fit, relaxes to allow orange and selects the "best" referee based on score (grade, distance, load).
 * Never allows red conflicts.
 *
 * Input: GET filters (e.g., dates, divisions) for matches.
 * Output: JSON of suggestions {match_uuid: {role: ref_uuid}}.
 *
 * Limitations:
 * - Suggestions only for filtered matches; conflicts consider all existing assignments.
 * - Distance is straight-line (Haversine); no driving routes.
 * - Weekly limit is hard cap; no soft preferences.
 * - "Best" is scored; may not always be optimal without full optimization.
 *
 * Usage: Call via AJAX from matches.js with current filters.
 */

require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

// Suppress notices and warnings
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Prepare for streaming
if (ob_get_level() == 0) ob_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function send_progress($progress, $message) {
    $response = ['progress' => $progress, 'message' => $message];
    echo json_encode($response) . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
        flush();
    }
}

$pdo = Database::getConnection();

// Constants for configuration
const MAX_GAMES_PER_DAY = 2;
const MAX_GAMES_PER_WEEK = 9999; // Weekly cap
const MATCH_DURATION_MINUTES = 90;
const BUFFER_MINUTES = 30;
const ROLES = ['referee_id', 'ar1_id', 'ar2_id']; // Removed commissioner_id

// Grade order for non-numeric grades
const GRADE_ORDER = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, '' => 0];

// Preferred grade by division
$preferredGradeByDivision = [
    'Ereklasse' => 'A',
    'Futureklasse' => 'B',
    'Ereklasse Dames' => 'B',
    'Colts Cup' => 'B',
    '1e Klasse' => 'B',
    '2e Klasse' => 'C',
    '3e Klasse' => 'D'
];

$testingMode = isset($_GET['testing']) && $_GET['testing'] === 'true';

// Build WHERE clause for filtered matches (role-based + GET filters)
$whereClauses = [];
$params = [];

$userRole = $_SESSION['user_role'] ?? null;
$userDivisionIds = $_SESSION['division_ids'] ?? [];
$userDistrictIds = $_SESSION['district_ids'] ?? [];

$allowedDivisionNames = [];
$allowedDistrictNames = [];
$loadMatches = true;

if ($userRole !== 'super_admin') {
    if (!empty($userDivisionIds) && !(count($userDivisionIds) === 1 && $userDivisionIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDivisionIds), '?'));
        $stmtDiv = $pdo->prepare("SELECT name FROM divisions WHERE id IN ($placeholders)");
        $stmtDiv->execute($userDivisionIds);
        $allowedDivisionNames = $stmtDiv->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!empty($userDistrictIds) && !(count($userDistrictIds) === 1 && $userDistrictIds[0] === '')) {
        $placeholders = implode(',', array_fill(0, count($userDistrictIds), '?'));
        $stmtDist = $pdo->prepare("SELECT name FROM districts WHERE id IN ($placeholders)");
        $stmtDist->execute($userDistrictIds);
        $allowedDistrictNames = $stmtDist->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($allowedDivisionNames) || empty($allowedDistrictNames)) {
        $loadMatches = false;
    } else {
        $divisionPlaceholders = implode(',', array_fill(0, count($allowedDivisionNames), '?'));
        $whereClauses[] = "m.division IN ($divisionPlaceholders)";
        $params = array_merge($params, $allowedDivisionNames);

        $districtPlaceholders = implode(',', array_fill(0, count($allowedDistrictNames), '?'));
        $whereClauses[] = "m.district IN ($districtPlaceholders)";
        $params = array_merge($params, $allowedDistrictNames);
    }
}

if (!empty($_GET['start_date'])) {
    $whereClauses[] = "m.match_date >= ?";
    $params[] = $_GET['start_date'];
}

if (!empty($_GET['end_date'])) {
    $whereClauses[] = "m.match_date <= ?";
    $params[] = $_GET['end_date'];
}

$filterColumnMap = [
    'division'          => 'division',
    'district'          => 'district',
    'poule'             => 'poule',
    'location'          => 'location_address',      // << fix
    'referee_assigner'  => 'referee_assigner_uuid', // << fix
];

foreach (['division', 'district', 'poule', 'location', 'referee_assigner'] as $filter) {
    if (!empty($_GET[$filter]) && is_array($_GET[$filter])) {
        $column = $filterColumnMap[$filter] ?? $filter;
        $placeholders = implode(',', array_fill(0, count($_GET[$filter]), '?'));
        $whereClauses[] = "m.$column IN ($placeholders)";
        $params = array_merge($params, $_GET[$filter]);
    }
}

$whereSQL = count($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

send_progress(5, 'Filtering matches based on criteria...');

// Load filtered matches
$matches = [];
if ($loadMatches) {
    $stmt = $pdo->prepare("
    SELECT
      m.uuid, m.match_date, m.kickoff_time, m.division,
      m.location_address, m.location_lat, m.location_lon
    FROM matches m
    $whereSQL
    ORDER BY m.match_date ASC, m.kickoff_time ASC");
    $stmt->execute($params);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

send_progress(10, 'Loaded ' . count($matches) . ' matches to be assigned.');
error_log('Number of matches loaded: ' . count($matches));

if (empty($matches)) {
    send_progress(100, 'No matches found in filtered view.');
    // Construct the final data payload
    $response = [
        'progress' => 100,
        'message' => 'No matches found to suggest.',
        'suggestions' => []
    ];
    echo json_encode($response) . "\n";
    ob_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}

// Pre-compute kickoff_minutes and week_key
foreach ($matches as &$match) {
    list($h, $m) = explode(':', $match['kickoff_time']);
    $match['kickoff_minutes'] = (int)$h * 60 + (int)$m;
    $match['week_key'] = date('oW', strtotime($match['match_date'])); // ISO week
}
unset($match);

// Load ALL existing assignments for conflicts
$existingAssignments = [];
if (!$testingMode) {
    $existingAssignments = $pdo->query("
    SELECT
      m.uuid AS match_id, m.match_date, m.kickoff_time,
      m.location_address, m.location_lat, m.location_lon,
      m.referee_id, m.ar1_id, m.ar2_id, m.commissioner_id
    FROM matches m
    WHERE referee_id IS NOT NULL OR ar1_id IS NOT NULL OR ar2_id IS NOT NULL OR commissioner_id IS NOT NULL

    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($existingAssignments as &$assign) {
        list($h, $m) = explode(':', $assign['kickoff_time']);
        $assign['kickoff_minutes'] = (int)$h * 60 + (int)$m;
        $assign['week_key'] = date('oW', strtotime($assign['match_date']));
    }
    unset($assign);
}

send_progress(15, 'Loaded all existing assignments for conflict checking.');
error_log('Number of existing assignments: ' . count($existingAssignments));

// Process existing for per-ref per-date/week
$existingAssignmentsByDateRef = [];
$existingAssignmentsByWeekRef = [];
$existingMatchDetailsByDateRef = [];
foreach ($existingAssignments as $assignment) {
    $date = $assignment['match_date'];
    $week = $assignment['week_key'];
    foreach (ROLES as $role) {
        $refId = $assignment[$role] ?? null;
        if ($refId) {
            $existingAssignmentsByDateRef[$date][$refId] = ($existingAssignmentsByDateRef[$date][$refId] ?? 0) + 1;
            $existingAssignmentsByWeekRef[$week][$refId] = ($existingAssignmentsByWeekRef[$week][$refId] ?? 0) + 1;

            $existingMatchDetailsByDateRef[$date][$refId][] = [
                'kickoff_minutes'  => $assignment['kickoff_minutes'],
                'location_address' => $assignment['location_address'] ?? null,
                'location_lat'     => $assignment['location_lat'],
                'location_lon'     => $assignment['location_lon'],
            ];

        }
    }
}

// Load referees with travel data
$referees = $pdo->query("
    SELECT 
        r.uuid,
        r.grade,
        COALESCE(r.home_lat, l.latitude) AS home_lat,
        COALESCE(r.home_lon, l.longitude) AS home_lon,
        r.max_travel_distance
    FROM referees r
    LEFT JOIN clubs c      ON r.home_club_id = c.uuid
    LEFT JOIN locations l  ON c.location_uuid = l.uuid
    ORDER BY r.grade DESC
")->fetchAll(PDO::FETCH_ASSOC);


send_progress(20, 'Loaded all referees and their data.');
error_log('Number of referees loaded: ' . count($referees));

// Pre-fetch unavailability/weekly
$unavailability = [];
$stmt = $pdo->query("SELECT referee_id, start_date, end_date FROM referee_unavailability");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $unavailability[$row['referee_id']][] = $row;
}

$weekly = [];
$stmt = $pdo->query("SELECT referee_id, weekday, morning_available, afternoon_available, evening_available FROM referee_weekly_availability");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $weekly[$row['referee_id']][$row['weekday']] = $row;
}

// Cache availability
$availabilityCache = [];
foreach ($referees as $ref) {
    $refId = $ref['uuid'];
    $availabilityCache[$refId] = [];
    foreach ($matches as $match) {
        $availabilityCache[$refId][$match['uuid']] = isRefereeAvailable($refId, $match['match_date'], $match['kickoff_time'], $unavailability, $weekly);
    }
}

send_progress(30, 'Pre-cached referee availability for all matches.');

// Group filtered matches by week
$matchesByWeek = [];
foreach ($matches as $match) {
    $matchesByWeek[$match['week_key']][] = $match;
}

// Initialize suggestions
$suggestions = [];
foreach ($matches as $match) {
    $suggestions[$match['uuid']] = array_fill_keys(ROLES, null);
}

// Suggested counts this run (global, for overall load balancing)
$suggestedAssignmentsCountThisRun = array_fill_keys(array_column($referees, 'uuid'), 0);

// Suggested counts per week (for weekly limits)
$suggestedAssignmentsByWeekRef = [];

// Suggested details by date by ref
$suggestedMatchDetailsByDateRef = [];

// Availability function
function isRefereeAvailable($refId, $matchDate, $kickoffTime, $unavailability, $weekly) {
    if (isset($unavailability[$refId])) {
        foreach ($unavailability[$refId] as $u) {
            if ($matchDate >= $u['start_date'] && $matchDate <= $u['end_date']) {
                return false;
            }
        }
    }

    $weekday = date('w', strtotime($matchDate));
    $hour = (int)date('H', strtotime($kickoffTime));
    $slot = $hour < 12 ? 'morning_available' : ($hour < 17 ? 'afternoon_available' : 'evening_available');

    return isset($weekly[$refId][$weekday]) && (bool)$weekly[$refId][$weekday][$slot];
}

// Haversine for distance
function haversine($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // km
    $dlat = deg2rad($lat2 - $lat1);
    $dlon = deg2rad($lon2 - $lon1);
    $a = sin($dlat / 2) * sin($dlat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dlon / 2) * sin($dlon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// canAssign function (returns false or score; higher score = better)
function canAssign($refId, $matchId, $matchDate, $matchWeek, $kickoffMinutes, $locationLat, $locationLon, $currentRole, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $suggestedAssignmentsCountThisRun, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, $allowOrange, $referees, $division) {
    global $preferredGradeByDivision;

    // Find ref data
    $refData = null;
    foreach ($referees as $r) {
        if ($r['uuid'] === $refId) {
            $refData = $r;
            break;
        }
    }
    if (!$refData) return false;

    // Check travel distance
    $distance = INF;
    if ($locationLat && $refData['home_lat']) {
        $distance = haversine($refData['home_lat'], $refData['home_lon'], $locationLat, $locationLon);
        if ($distance > ($refData['max_travel_distance'] ?? INF)) {
            error_log("Ref $refId skipped for match $matchId: Distance $distance > max " . $refData['max_travel_distance']);
            return false;
        }
    }

    // Weekly limit (hard)
    $existingGamesThisWeek = $existingAssignmentsByWeekRef[$matchWeek][$refId] ?? 0;
    $suggestedGamesThisWeek = $suggestedAssignmentsByWeekRef[$matchWeek][$refId] ?? 0;
    $totalGamesThisWeek = $existingGamesThisWeek + $suggestedGamesThisWeek + 1;
    if ($totalGamesThisWeek > MAX_GAMES_PER_WEEK) {
        error_log("Ref $refId skipped for match $matchId: Weekly limit exceeded (total $totalGamesThisWeek)");
        return false;
    }

    // Daily limit (hard)
    $existingGamesThisDay = $existingAssignmentsByDateRef[$matchDate][$refId] ?? 0;
    $suggestedGamesThisDay = count($suggestedMatchDetailsByDateRef[$matchDate][$refId] ?? []);
    $totalGamesThisDay = $existingGamesThisDay + $suggestedGamesThisDay + 1;
    if ($totalGamesThisDay > MAX_GAMES_PER_DAY) {
        error_log("Ref $refId skipped for match $matchId: Daily limit exceeded (total $totalGamesThisDay)");
        return false;
    }

    $currentEndMinutes = $kickoffMinutes + MATCH_DURATION_MINUTES;

    $isOrange = false;

    // Check suggested conflicts
    $suggestedDetails = $suggestedMatchDetailsByDateRef[$matchDate][$refId] ?? [];
    foreach ($suggestedDetails as $sDetail) {
        // time overlap → red (unchanged)
        $otherEndMinutes = $sDetail['kickoff_minutes'] + MATCH_DURATION_MINUTES;
        if ($kickoffMinutes < ($otherEndMinutes + BUFFER_MINUTES) &&
            $sDetail['kickoff_minutes'] < ($currentEndMinutes + BUFFER_MINUTES)) {
            return false; // red
        }

        $curAddrN = normalize_address($locationAddress ?? null);
        $sAddrN   = normalize_address($sDetail['location_address'] ?? null);
        if ($curAddrN !== null && $sAddrN !== null) {
            if ($curAddrN !== $sAddrN) return false;
            else $isOrange = true;
        } else {
            $isOrange = true;
        }
    }

    // Check existing conflicts
    $existingDetails = $existingMatchDetailsByDateRef[$matchDate][$refId] ?? [];
    foreach ($existingDetails as $eDetail) {
        $otherEndMinutes = $eDetail['kickoff_minutes'] + MATCH_DURATION_MINUTES;
        if ($kickoffMinutes < ($otherEndMinutes + BUFFER_MINUTES) &&
            $eDetail['kickoff_minutes'] < ($currentEndMinutes + BUFFER_MINUTES)) {
            return false; // red
        }

        $curAddrN = normalize_address($locationAddress ?? null);
        $eAddrN   = normalize_address($eDetail['location_address'] ?? null);
        if ($curAddrN !== null && $eAddrN !== null) {
            if ($curAddrN !== $eAddrN) return false;  // red
            else $isOrange = true;
        } else {
            $isOrange = true;
        }
    }

    if ($isOrange && !$allowOrange) {
        error_log("Ref $refId skipped for match $matchId: Orange not allowed");
        return false;
    }

    // Calculate score for "best" (higher = better)
    $gradeScore = is_numeric($refData['grade']) ? (int)$refData['grade'] : (GRADE_ORDER[$refData['grade']] ?? 0);
    $preferredGrade = $preferredGradeByDivision[$division] ?? null;
    $preferredBonus = ($preferredGrade && $refData['grade'] === $preferredGrade) ? 100 : 0;
    $loadScore = 10 - min(10, $suggestedAssignmentsCountThisRun[$refId]); // Prefer less loaded, normalized to 0-10
    $distanceScore = $distance === INF ? 0 : (1 / (1 + $distance)); // Prefer closer (0-1 normalized)

    $score = $preferredBonus + $gradeScore * 10 + $loadScore * 5 + $distanceScore; // Weighted score
    error_log("Ref $refId eligible for match $matchId: Score $score (preferred $preferredBonus, grade $gradeScore, load $loadScore, distance $distance)");

    return $score;
}

// attemptAssignment function (updated for best match)
function attemptAssignment(
    $role,
    &$match,
    $referees,
    &$suggestions,
    &$suggestedAssignmentsCountThisRun,
    $existingAssignmentsByDateRef,
    $existingMatchDetailsByDateRef,
    $existingAssignmentsByWeekRef,
    $availabilityCache,
    $pass,
    &$suggestedMatchDetailsByDateRef,
    &$suggestedAssignmentsByWeekRef,
    $allowOrange = false,
    $suggestedInThisWeek
) {
    $matchId = $match['uuid'];
    if ($suggestions[$matchId][$role] !== null) return;

    $matchDate = $match['match_date'];
    $matchWeek = $match['week_key'];

    error_log("Attempting assignment for match $matchId, role $role, pass $pass, allowOrange " . ($allowOrange ? 'true' : 'false'));

    $preferredGrade = $preferredGradeByDivision[$match['division']] ?? null;

    // Collect eligible refs with scores
    $eligibleRefs = [];
    $triedPreferred = false;

    if ($preferredGrade) {
        // First try only preferred grade
        $triedPreferred = true;
        foreach ($referees as $ref) {
            $refId = $ref['uuid'];

            if ($ref['grade'] !== $preferredGrade) continue;

            if ($pass === 1 && $suggestedInThisWeek[$refId] > 0) continue;

            if (!$availabilityCache[$refId][$matchId]) {
                error_log("Ref $refId skipped for match $matchId: Not available");
                continue;
            }

            $score = canAssign($refId, $matchId, $matchDate, $matchWeek, $match['kickoff_minutes'], $match['location_lat'], $match['location_lon'], $role, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $suggestedAssignmentsCountThisRun, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, $allowOrange, $referees, $match['division']);
            if ($score !== false) {
                $eligibleRefs[] = ['refId' => $refId, 'score' => $score];
            }
        }
    }

    if (empty($eligibleRefs)) {
        // If no preferred or no eligible preferred, try all
        foreach ($referees as $ref) {
            $refId = $ref['uuid'];

            if ($pass === 1 && $suggestedInThisWeek[$refId] > 0) continue;

            if (!$availabilityCache[$refId][$matchId]) {
                error_log("Ref $refId skipped for match $matchId:Not available");
                continue;
            }

            $score = canAssign($refId, $matchId, $matchDate, $matchWeek, $match['kickoff_minutes'], $match['location_lat'], $match['location_lon'], $role, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $suggestedAssignmentsCountThisRun, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, $allowOrange, $referees, $match['division']);
            if ($score !== false) {
                $eligibleRefs[] = ['refId' => $refId, 'score' => $score];
            }
        }
    }

    error_log("Eligible refs for match $matchId, role $role: " . count($eligibleRefs));

    if (!empty($eligibleRefs)) {
        // Sort by score descending
        usort($eligibleRefs, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        $bestRefId = $eligibleRefs[0]['refId'];
        $bestScore = $eligibleRefs[0]['score'];
        error_log("Best ref for match $matchId, role $role: $bestRefId with score $bestScore");

        $suggestions[$matchId][$role] = $bestRefId;
        $suggestedInThisWeek[$bestRefId]++;

        if (!isset($suggestedAssignmentsByWeekRef[$matchWeek][$bestRefId])) $suggestedAssignmentsByWeekRef[$matchWeek][$bestRefId] = 0;
        $suggestedAssignmentsByWeekRef[$matchWeek][$bestRefId]++;

        if (!isset($suggestedMatchDetailsByDateRef[$matchDate][$bestRefId])) $suggestedMatchDetailsByDateRef[$matchDate][$bestRefId] = [];
        $suggestedMatchDetailsByDateRef[$matchDate][$bestRefId][] = [
            'kickoff_minutes' => $match['kickoff_minutes'],
            'location_lat' => $match['location_lat'],
            'location_lon' => $match['location_lon']
        ];
    } else {
        error_log("No eligible refs for match $matchId, role $role");
    }
}

// Process week by week
$totalAssignments = count($matches) * count(ROLES);
$processedAssignments = 0;

ksort($matchesByWeek); // Process weeks in order

foreach ($matchesByWeek as $week => $weekMatches) {
    $suggestedInThisWeek = array_fill_keys(array_column($referees, 'uuid'), 0);

    // Sort matches by division priority (higher preferred grade first, then lower class number)
    usort($weekMatches, function($a, $b) {
        $prefA = GRADE_ORDER[$preferredGradeByDivision[$a['division']] ?? ''] ?? 0;
        $prefB = GRADE_ORDER[$preferredGradeByDivision[$b['division']] ?? ''] ?? 0;
        if ($prefA !== $prefB) {
            return $prefB - $prefA; // higher pref grade first
        }
        $aNum = preg_match('/\d+/', $a['division'], $m) ? (int)$m[0] : 999;
        $bNum = preg_match('/\d+/', $b['division'], $m) ? (int)$m[0] : 999;
        return $aNum - $bNum; // lower class number first
    });

    foreach (ROLES as $role) { // Assign main refs first, then AR1, AR2
        foreach ($weekMatches as &$match) {
            if (($role === 'ar1_id' || $role === 'ar2_id') && $match['division'] !== 'Ereklasse') {
                $processedAssignments++;
                continue;
            }

            // Attempt assignment in multiple passes
            attemptAssignment($role, $match, $referees, $suggestions, $suggestedAssignmentsCountThisRun, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $availabilityCache, 1, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, false, $suggestedInThisWeek);
            if ($suggestions[$match['uuid']][$role] === null) {
                attemptAssignment($role, $match, $referees, $suggestions, $suggestedAssignmentsCountThisRun, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $availabilityCache, 2, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, false, $suggestedInThisWeek);
            }
            if ($suggestions[$match['uuid']][$role] === null) {
                attemptAssignment($role, $match, $referees, $suggestions, $suggestedAssignmentsCountThisRun, $existingAssignmentsByDateRef, $existingMatchDetailsByDateRef, $existingAssignmentsByWeekRef, $availabilityCache, 3, $suggestedMatchDetailsByDateRef, $suggestedAssignmentsByWeekRef, true, $suggestedInThisWeek);
            }

            $processedAssignments++;
            $progress = (int)(($processedAssignments / $totalAssignments) * 100);
            send_progress($progress, "Processed {$processedAssignments} of {$totalAssignments} assignments...");
        }
        unset($match);
    }
}

send_progress(95, 'Finalizing suggestions...');

// Construct the final data payload
$response = [
    'progress' => 100,
    'message' => 'Suggestions complete!',
    'suggestions' => $suggestions
];
echo json_encode($response) . "\n";
ob_flush();
flush();

// Close the connection
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>