<?php
// public/suggest_assignments.php
declare(strict_types=1);
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../utils/db.php';
require_once __DIR__ . '/../utils/grade_policy.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (ob_get_level() === 0) ob_start();
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
ob_implicit_flush(true);

function emit(array $o): void {
    echo json_encode($o, JSON_UNESCAPED_UNICODE), "\n";
    @ob_flush(); @flush();
}
// ----- Availability helper (must be defined before use) -----
function is_available_for(string $rid, string $date, ?string $ko, array $adhocByRef, array $weeklyByRef): bool {
    // Ad-hoc date windows (full-day blocks)
    if (!empty($adhocByRef[$rid])) {
        foreach ($adhocByRef[$rid] as $u) {
            if ($date >= $u['start_date'] && $date <= $u['end_date']) {
                return false;
            }
        }
    }

    // Weekly time-of-day availability (fallback to true if no record)
    $w = (int)(new DateTimeImmutable($date))->format('w'); // 0..6 (Sun..Sat)
    if (!isset($weeklyByRef[$rid][$w])) return true;

    // Derive hour from KO; default to 13:00 if empty (HH:MM or HH:MM:SS supported)
    $hhmm = $ko && strlen($ko) >= 4 ? $ko : '13:00';
    $hour = (int)substr($hhmm, 0, 2);

    $slot = ($hour < 12) ? 'morning_available'
        : (($hour < 17) ? 'afternoon_available' : 'evening_available');

    return (bool)$weeklyByRef[$rid][$w][$slot];
}



/* -------- Helpers -------- */
function parse_dt(string $date, ?string $ko): ?array {
    $hhmm = substr($ko && strlen($ko) >= 4 ? $ko : '13:00', 0, 5); // default KO 13:00
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $hhmm");
    if (!$start) return null;
    $end = $start->modify('+90 minutes');
    return [$start, $end];
}
function overlaps(array $A, array $B): bool {
    return ($A[0] < $B[1]) && ($B[0] < $A[1]);
}
// Turn PHP warnings/notices into exceptions so we can see where it broke
set_error_handler(function($severity, $message, $file, $line){
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function normalize_range_or_default(?string $start, ?string $end): array {
    if (!$start && !$end) {
        $firstFri = new DateTimeImmutable('next friday');
        $weekends = [];
        for ($i=0; $i<4; $i++) {
            $fri = $firstFri->modify("+{$i} week");
            $sun = new DateTimeImmutable($fri->format('Y-m-d') . ' Sunday this week');
            $weekends[] = [$fri->format('Y-m-d'), $sun->format('Y-m-d')];
        }
        return [$weekends[0][0], end($weekends)[1], $weekends];
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
    if (!$weekends) $weekends[] = [$rangeStart, $rangeEnd];
    return [$rangeStart,$rangeEnd,$weekends];
}
function fri_sun_for(string $ymd): array {
    $fri = new DateTimeImmutable($ymd . ' Friday this week');
    $sun = new DateTimeImmutable($ymd . ' Sunday this week');
    return [$fri->format('Y-m-d'), $sun->format('Y-m-d')];
}

/* -------- Main -------- */
try {
    [$rangeStart, $rangeEnd, $weekends] = normalize_range_or_default($_GET['start_date'] ?? null, $_GET['end_date'] ?? null);
    usort($weekends, fn($a,$b)=>strcmp($a[0], $b[0]));

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
            'uuid'        => $r['uuid'],
            'grade_letter'=> strtoupper((string)($r['grade'] ?? '')),
            'rank'        => grade_to_rank($r['grade'] ?? ''),
            'max_matches' => max(1, (int)($r['max_matches_per_weekend'] ?? 1)),
            'max_days'    => max(1, (int)($r['max_days_per_weekend'] ?? 1)),
        ];
    }

    // Matches in range
    $matches = $pdo->prepare("
        SELECT uuid, match_date, kickoff_time, division, expected_grade, referee_id
        FROM matches
        WHERE deleted_at IS NULL
          AND match_date BETWEEN ? AND ?
        ORDER BY match_date, COALESCE(kickoff_time,'99:99:99'), uuid
    ");
    $matches->execute([$rangeStart, $rangeEnd]);
    $allRows = $matches->fetchAll(PDO::FETCH_ASSOC);

    // Availability
    $adhocStmt = $pdo->prepare("
        SELECT referee_id, start_date, end_date
        FROM referee_unavailability
        WHERE NOT (end_date < ? OR start_date > ?)
    ");
    $adhocStmt->execute([$rangeStart, $rangeEnd]);
    $adhocByRef = [];
    while ($row = $adhocStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!$row['referee_id']) continue;
        $adhocByRef[$row['referee_id']][] = $row;
    }
    $weeklyQ = $pdo->query("
        SELECT referee_id, weekday, morning_available, afternoon_available, evening_available
        FROM referee_weekly_availability
    ");
    $weeklyByRef = [];
    while ($row = $weeklyQ->fetch(PDO::FETCH_ASSOC)) {
        $rid = $row['referee_id']; if (!$rid) continue;
        $weeklyByRef[$rid][(int)$row['weekday']] = [
            'morning_available'   => (bool)$row['morning_available'],
            'afternoon_available' => (bool)$row['afternoon_available'],
            'evening_available'   => (bool)$row['evening_available'],
        ];
    }

    // Group by weekend
    $byWeekend = [];
    foreach ($allRows as $m) {
        [$fri,$sun] = fri_sun_for($m['match_date']);
        $byWeekend["$fri|$sun"][] = $m;
    }

    // Stats
    $totalToConsider = 0;
    foreach ($weekends as [$ws,$we]) {
        $k = "$ws|$we";
        foreach ($byWeekend[$k] ?? [] as $m) {
            if (empty($m['referee_id'])) $totalToConsider++;
        }
    }
    $assignedNow = 0;
    $progressSoFar = 0;

    // Per-weekend loop (A→D inside)
    foreach ($weekends as [$wkStart, $wkEnd]) {
        try {
            $wkey = "$wkStart|$wkEnd";
            $rows = array_values(array_filter($byWeekend[$wkey] ?? [], fn($r) => $r['match_date'] >= $wkStart && $r['match_date'] <= $wkEnd));

            // Seed counters & ledger from already-assigned  // FIX: build ledger here, not at file top
            $weekMatches = [];     // rid -> count
            $weekDaysSet = [];     // rid -> set(date=>true)
            $weekLedger = [];     // rid -> [date => [[start,end], ...]]

            foreach ($rows as $r) {
                $rid = $r['referee_id'] ?? '';
                if ($rid === '' || $rid === null) continue;
                $date = $r['match_date'];
                $ko = $r['kickoff_time'] ?? null;
                $weekMatches[$rid] = ($weekMatches[$rid] ?? 0) + 1;
                $weekDaysSet[$rid][$date] = true;
                $iv = parse_dt($date, $ko);
                if ($iv) $weekLedger[$rid][$date][] = $iv;
            }

            // Expected grade + KO normalization
            foreach ($rows as &$r) {
                $r['expected_letter'] = expected_grade_for_match_letter($r);
                $r['expected_rank'] = expected_grade_rank($r);
                $ko = (string)($r['kickoff_time'] ?? '');
                if ($ko === '' || $ko === null) $r['kickoff_time'] = '13:00:00';
                elseif (strlen($ko) === 5) $r['kickoff_time'] = $ko . ':00';
            }
            unset($r);

            // Sort A→D, then by date/time
            usort($rows, function ($a, $b) {
                if ($a['expected_rank'] !== $b['expected_rank']) return $b['expected_rank'] <=> $a['expected_rank'];
                if ($a['match_date'] !== $b['match_date']) return strcmp($a['match_date'], $b['match_date']);
                $ta = $a['kickoff_time'] ?? '99:99:99';
                $tb = $b['kickoff_time'] ?? '99:99:99';
                if ($ta !== $tb) return strcmp($ta, $tb);
                return strcmp($a['uuid'], $b['uuid']);
            });

            $batch = []; // FIX: initialize, and emit this at end of weekend once

            foreach ($rows as $m) {
                if (!empty($m['referee_id'])) {
                    $batch[$m['uuid']] = null;
                    continue;
                }

                $progressSoFar++;
                if ($totalToConsider > 0) {
                    $pct = min(100, (int)round(100 * $progressSoFar / $totalToConsider));
                    emit(['progress' => $pct, 'message' => "Processing {$wkStart} – {$wkEnd}"]);
                }

                $mid = $m['uuid'];
                $date = $m['match_date'];
                $ko = $m['kickoff_time'] ?? null;
                $slot = parse_dt($date, $ko);
                if (!$slot) {
                    $batch[$mid] = null;
                    continue;
                }

                $best = null; // ['rid'=>..., 'score'=>..., 'curDs'=>..., 'curM'=>...]
                foreach ($refById as $rid => $meta) {
                    // Current load (penalties only)
                    $curM = (int)($weekMatches[$rid] ?? 0);
                    $curDs = isset($weekDaysSet[$rid]) ? count($weekDaysSet[$rid]) : 0;

                    $score = 100;
                    $refRank = (int)$meta['rank'];
                    $reqRank = (int)$m['expected_rank'];

                    if ($refRank < $reqRank) {
                        $score -= 40 * ($reqRank - $refRank);
                    }

                    $avail = is_available_for($rid, $date, $ko, $adhocByRef, $weeklyByRef);
                    if (!$avail) $score -= 100;

                    $hasConflict = false;
                    if (!empty($weekLedger[$rid][$date])) {
                        foreach ($weekLedger[$rid][$date] as $iv) {
                            if (overlaps($slot, $iv)) {
                                $hasConflict = true;
                                break;
                            }
                        }
                    }
                    if ($hasConflict) $score -= 100;

                    $dayMatches = !empty($weekLedger[$rid][$date]) ? count($weekLedger[$rid][$date]) : 0;
                    $score -= 10 * $curM;
                    $score -= 20 * $dayMatches;

                    if ($best === null
                        || $score > $best['score']
                        || ($score === $best['score'] && ($curDs < $best['curDs']
                                || ($curDs === $best['curDs'] && ($curM < $best['curM']
                                        || ($curM === $best['curM'] && strcmp($rid, $best['rid']) < 0)))))) {
                        $best = ['rid' => $rid, 'score' => $score, 'curDs' => $curDs, 'curM' => $curM];
                    }
                    $zeros = 0;
                    if ($score === 0) $zeros++;

                    if ($zeros > 0 && $best === null) {
                        emit(['note' => 'all_zero', 'match_uuid' => $mid, 'zeros' => $zeros]);
                    }

                }

                if ($best === null || $best['score'] === 0) {
                    $batch[$mid] = null; // per your rule: do not assign exactly 0
                } else {
                    $chosen = $best['rid'];
                    $batch[$mid] = ['referee_id' => $chosen];
                    $assignedNow++;

                    // Update counters/ledger so subsequent matches see it
                    $weekMatches[$chosen] = ($weekMatches[$chosen] ?? 0) + 1;
                    $weekDaysSet[$chosen][$date] = true;
                    $weekLedger[$chosen][$date][] = $slot;
                }
            }

            // FIX: emit once per weekend using the populated $batch
            emit([
                'weekend_start' => $wkStart,
                'weekend_end' => $wkEnd,
                'suggestions' => $batch,
            ]);
        } catch (Throwable $e) {
            error_log("[suggest_assignments][weekend $wkStart-$wkEnd] ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
            emit([
                'note' => 'weekend_error',
                'weekend_start' => $wkStart,
                'weekend_end'   => $wkEnd,
                'message'       => 'failed this weekend',
                'detail'        => (isset($_GET['debug']) && $_GET['debug']==='1') ? $e->getMessage() : null,
            ]);
            continue;
        }
    }

    emit([
        'done'  => true,
        'scope' => [
            'range_start' => $rangeStart,
            'range_end'   => $rangeEnd,
            'weekends'    => array_map(fn($w)=>['start'=>$w[0],'end'=>$w[1]], $weekends),
        ],
        'stats' => [
            'matches_considered' => $totalToConsider,
            'assigned_now'       => $assignedNow,
        ],
    ]);

    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    if (ob_get_level() > 0) @ob_end_flush();

} catch (Throwable $e) {
    http_response_code(500);

    // Always log full detail to server logs
    error_log("[suggest_assignments] ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()."\n".$e->getTraceAsString());

    // Only expose details when explicitly requested
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    $payload = ['error' => 'internal_error', 'message' => 'Suggest failed'];

    if ($debug) {
        $payload['detail'] = [
            'type'    => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ];
    }

    emit($payload);
}

