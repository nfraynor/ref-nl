<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../utils/db.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (ob_get_level() === 0) ob_start();
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
ob_implicit_flush(true);

const GRADES = ['A'=>4,'B'=>3,'C'=>2,'D'=>1];
const DEFAULT_MIN_GRADE = 'D';

$preferredGradeByDivision = [
    'Ereklasse'        => 'A',
    'Futureklasse'     => 'B',
    'Ereklasse Dames'  => 'B',
    'Colts Cup'        => 'B',
    '1e Klasse'        => 'B',
    '2e Klasse'        => 'C',
    '3e Klasse'        => 'D',
];

function ndjson_line(array $obj): void {
    echo json_encode($obj), "\n";
    @ob_flush(); @flush();
}
function grade_weight(?string $g): int { return GRADES[strtoupper((string)$g)] ?? 0; }

function normalize_range_or_default(?string $start, ?string $end): array {
    if (!$start && !$end) {
        $firstFri = new DateTimeImmutable('next friday');
        $weekends = [];
        for ($i=0; $i<4; $i++) {
            $fri = $firstFri->modify("+{$i} week");
            $sun = new DateTimeImmutable($fri->format('Y-m-d') . ' Sunday this week');
            $weekends[] = [$fri->format('Y-m-d'), $sun->format('Y-m-d')];
        }
        return [$weekends[0][0], $weekends[count($weekends)-1][1], $weekends];
    }
    $s = $start ? new DateTimeImmutable($start) : new DateTimeImmutable('today');
    $e = $end   ? new DateTimeImmutable($end)   : $s;
    if ($e < $s) { [$s,$e] = [$e,$s]; }
    $rangeStart = $s->format('Y-m-d'); $rangeEnd = $e->format('Y-m-d');
    $firstFri = new DateTimeImmutable($rangeStart . ' Friday this week');
    $weekends = [];
    for ($cur=$firstFri; $cur->format('Y-m-d') <= $rangeEnd; $cur=$cur->modify('+7 days')) {
        $fri = $cur->format('Y-m-d');
        $sun = (new DateTimeImmutable($fri . ' Sunday this week'))->format('Y-m-d');
        if (!($sun < $rangeStart || $fri > $rangeEnd)) $weekends[] = [$fri,$sun];
    }
    return [$rangeStart,$rangeEnd,$weekends];
}
function friday_sunday_for(string $ymd): array {
    $fri = new DateTimeImmutable($ymd . ' Friday this week');
    $sun = new DateTimeImmutable($ymd . ' Sunday this week');
    return [$fri->format('Y-m-d'), $sun->format('Y-m-d')];
}
function required_grade_for(array $m, array $map): string {
    if (!empty($m['expected_grade'])) return strtoupper($m['expected_grade']);
    return $map[$m['division']] ?? DEFAULT_MIN_GRADE;
}
function is_available_for(string $rid, string $date, string $time, array $adhoc, array $weekly): bool {
    if (!empty($adhoc[$rid])) {
        foreach ($adhoc[$rid] as $u) if ($date >= $u['start_date'] && $date <= $u['end_date']) return false;
    }
    $w = (int)(new DateTimeImmutable($date))->format('w'); // 0..6
    $hour = (int)explode(':', (string)$time)[0];
    $slot = ($hour < 12) ? 'morning_available' : (($hour < 17) ? 'afternoon_available' : 'evening_available');
    if (isset($weekly[$rid][$w])) return (bool)$weekly[$rid][$w][$slot];
    return true;
}
function choose_best(array $c): ?array {
    if (!$c) return null;
    usort($c, function($a,$b){
        if ($a['days']    !== $b['days'])    return $a['days']    <=> $b['days'];
        if ($a['matches'] !== $b['matches']) return $a['matches'] <=> $b['matches'];
        if ($a['grade_w'] !== $b['grade_w']) return $b['grade_w'] <=> $a['grade_w'];
        return strcmp($a['uuid'], $b['uuid']);
    });
    return $c[0];
}

try {
    [$rangeStart, $rangeEnd, $weekends] = normalize_range_or_default($_GET['start_date'] ?? null, $_GET['end_date'] ?? null);
    usort($weekends, fn($a,$b)=>strcmp($a[0],$b[0]));

    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Referees
    $refRows = $pdo->query("
        SELECT uuid, grade, max_matches_per_weekend, max_days_per_weekend
        FROM referees
    ")->fetchAll(PDO::FETCH_ASSOC);
    $refById = [];
    foreach ($refRows as $r) {
        $refById[$r['uuid']] = [
            'uuid' => $r['uuid'],
            'grade_w' => grade_weight($r['grade']),
            'max_matches_per_weekend' => (int)$r['max_matches_per_weekend'],
            'max_days_per_weekend'    => (int)$r['max_days_per_weekend'],
        ];
    }

    // Matches to assign: treat NULL **or empty string** as unassigned ✅
    $stmtSug = $pdo->prepare("
        SELECT uuid, division, expected_grade, match_date, kickoff_time
        FROM matches
        WHERE match_date BETWEEN ? AND ?
          AND (referee_id IS NULL OR referee_id = '')
    ");
    $stmtSug->execute([$rangeStart, $rangeEnd]);
    $allMatchesToAssign = $stmtSug->fetchAll(PDO::FETCH_ASSOC);

    // Existing assignments for caps: treat NOT NULL **and not empty** as assigned ✅
    $stmtExisting = $pdo->prepare("
        SELECT referee_id, match_date
        FROM matches
        WHERE match_date BETWEEN ? AND ?
          AND referee_id IS NOT NULL
          AND referee_id <> ''
    ");
    $stmtExisting->execute([$rangeStart, $rangeEnd]);
    $allExistingAssigned = $stmtExisting->fetchAll(PDO::FETCH_ASSOC);

    // Availability
    $stmtU = $pdo->prepare("
        SELECT referee_id, start_date, end_date
        FROM referee_unavailability
        WHERE NOT (end_date < ? OR start_date > ?)
    ");
    $stmtU->execute([$rangeStart, $rangeEnd]);
    $adhocByRef = [];
    while ($row = $stmtU->fetch(PDO::FETCH_ASSOC)) $adhocByRef[$row['referee_id']][] = $row;

    $stmtW = $pdo->query("
        SELECT referee_id, weekday, morning_available, afternoon_available, evening_available
        FROM referee_weekly_availability
    ");
    $weeklyByRef = [];
    while ($row = $stmtW->fetch(PDO::FETCH_ASSOC)) {
        $weeklyByRef[$row['referee_id']][(int)$row['weekday']] = [
            'morning_available'   => (bool)$row['morning_available'],
            'afternoon_available' => (bool)$row['afternoon_available'],
            'evening_available'   => (bool)$row['evening_available'],
        ];
    }

    // Group by weekend
    $matchesByWeekend = [];
    foreach ($allMatchesToAssign as $m) {
        [$fri,$sun] = friday_sunday_for($m['match_date']);
        $matchesByWeekend["$fri|$sun"][] = $m;
    }
    $existingByWeekend = [];
    foreach ($allExistingAssigned as $row) {
        [$fri,$sun] = friday_sunday_for($row['match_date']);
        $existingByWeekend["$fri|$sun"][] = $row;
    }

    $totalConsidered = 0; $totalAssigned = 0;

    foreach ($weekends as [$wkStart, $wkEnd]) {
        $key = "$wkStart|$wkEnd";
        $matchesToAssign = array_values(array_filter($matchesByWeekend[$key] ?? [], fn($m)=>$m['match_date'] >= $wkStart && $m['match_date'] <= $wkEnd));

        // caps from existing for this weekend
        $weekMatchesCount = []; $weekDaysSet = [];
        foreach ($existingByWeekend[$key] ?? [] as $row) {
            $rid = $row['referee_id']; if (!$rid) continue;
            $weekMatchesCount[$rid] = ($weekMatchesCount[$rid] ?? 0) + 1;
            $weekDaysSet[$rid][$row['match_date']] = true;
        }

        foreach ($matchesToAssign as &$m) {
            $m['req_grade']  = required_grade_for($m, $preferredGradeByDivision);
            $m['req_weight'] = grade_weight($m['req_grade']);
            if (!empty($m['kickoff_time']) && strlen($m['kickoff_time']) === 5) $m['kickoff_time'] .= ':00';
        } unset($m);

        usort($matchesToAssign, function($a,$b){
            if ($a['req_weight'] !== $b['req_weight']) return $b['req_weight'] <=> $a['req_weight'];
            if ($a['match_date'] !== $b['match_date']) return strcmp($a['match_date'], $b['match_date']);
            $ta = $a['kickoff_time'] ?? '99:99:99'; $tb = $b['kickoff_time'] ?? '99:99:99';
            if ($ta !== $tb) return strcmp($ta, $tb);
            return strcmp($a['uuid'], $b['uuid']);
        });

        $batch = [];
        foreach ($matchesToAssign as $m) {
            $mid=$m['uuid']; $date=$m['match_date']; $time=$m['kickoff_time'] ?? '';
            if ($time === '' || $time === null) { $batch[$mid] = null; continue; }

            $reqW = $m['req_weight'];
            $cands = [];
            foreach ($refById as $rid=>$ref) {
                if ($ref['grade_w'] < $reqW) continue;
                if (!is_available_for($rid, $date, $time, $adhocByRef, $weeklyByRef)) continue;

                $curM = $weekMatchesCount[$rid] ?? 0;
                $curD = isset($weekDaysSet[$rid]) ? count($weekDaysSet[$rid]) : 0;
                $nextM = $curM + 1;
                $nextD = $curD + (isset($weekDaysSet[$rid][$date]) ? 0 : 1);
                if ($nextM > $ref['max_matches_per_weekend']) continue;
                if ($nextD > $ref['max_days_per_weekend']) continue;

                $cands[] = ['uuid'=>$rid,'grade_w'=>$ref['grade_w'],'matches'=>$curM,'days'=>$curD];
            }
            if (!$cands) { $batch[$mid] = null; continue; }

            $best = choose_best($cands);
            if (!$best) { $batch[$mid] = null; continue; }

            $rid = $best['uuid'];
            $batch[$mid] = ['referee_id'=>$rid];
            $weekMatchesCount[$rid] = ($weekMatchesCount[$rid] ?? 0) + 1;
            $weekDaysSet[$rid][$date] = true;
        }

        $totalConsidered += count($matchesToAssign);
        $totalAssigned   += count(array_filter($batch, fn($v)=>is_array($v) && !empty($v['referee_id'])));

        // Stream with small debug counters so you can see why a weekend is empty
        ndjson_line([
            'weekend_start' => $wkStart,
            'weekend_end'   => $wkEnd,
            'debug'         => [
                'candidates_in_window' => count($matchesToAssign),
                'existing_assigned_in_window' => count($existingByWeekend[$key] ?? []),
            ],
            'suggestions'   => $batch,
        ]);
    }

    ndjson_line([
        'done'=>true,
        'scope'=>[
            'range_start'=>$rangeStart, 'range_end'=>$rangeEnd,
            'weekends'=>array_map(fn($w)=>['start'=>$w[0],'end'=>$w[1]], $weekends),
        ],
        'stats'=>[
            'matches_considered'=>$totalConsidered,
            'assigned_now'=>$totalAssigned,
        ],
    ]);

    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    if (ob_get_level() > 0) @ob_end_flush();

} catch (Throwable $e) {
    ndjson_line(['error'=>'internal_error','message'=>$e->getMessage()]);
}
