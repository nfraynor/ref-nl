<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST required');
    }
    if (empty($_FILES['file']['tmp_name'])) {
        throw new RuntimeException('No file uploaded');
    }

    $pdo = Database::getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tmp  = $_FILES['file']['tmp_name'];
    $name = $_FILES['file']['name'] ?? 'upload';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv','txt'])) {
        throw new RuntimeException('Only CSV/TXT supported (save your sheet as CSV UTF-8).');
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) throw new RuntimeException('Unable to open file');

    // ---- Sniff delimiter (first line), strip BOM if present
    $peek = fgets($fh, 8192);
    if ($peek === false) throw new RuntimeException('File appears empty');
    if (strncmp($peek, "\xEF\xBB\xBF", 3) === 0) $peek = substr($peek, 3);

    $counts = [","=>substr_count($peek, ","), ";"=>substr_count($peek, ";"), "\t"=>substr_count($peek, "\t"), "|"=>substr_count($peek, "|")];
    arsort($counts);
    $delimiter = array_key_first($counts);
    if (($counts[$delimiter] ?? 0) === 0) $delimiter = ",";

    rewind($fh);

    // ---- Helpers
    $clean = function($v) {
        if ($v === null) return '';
        $v = (string)$v;
        if (strncmp($v, "\xEF\xBB\xBF", 3) === 0) $v = substr($v, 3);
        $v = str_replace(["\xC2\xA0", "\xA0", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\xAF", "\xE2\x80\x8B"], ' ', $v);
        $v = preg_replace('/[ \t]+/u', ' ', $v);
        return trim($v);
    };
    $normKey = function($s) use($clean) {
        $s = strtolower($clean($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(['–','—'], '-', $s);
        return $s;
    };
    $normDate = function($v) use($clean) {
        $v = $clean($v);
        if ($v === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $v, $m)) {
            $d = (int)$m[1]; $M = (int)$m[2]; $Y = (int)$m[3]; if ($Y < 100) $Y += 2000;
            return sprintf('%04d-%02d-%02d', $Y, $M, $d);
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    };
    $normTime = function($v) use($clean) {
        $v = $clean($v);
        if ($v === '') return null;
        $v = str_replace('.', ':', $v);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) return strlen($v) === 5 ? $v.':00' : $v;
        if (preg_match('/^\d{1,2}$/', $v)) return str_pad($v,2,'0',STR_PAD_LEFT).':00:00';
        return null;
    };

    // ---- Parse header
    $header = fgetcsv($fh, 0, $delimiter);
    if (!$header) throw new RuntimeException('Missing header row');

    $map = [];
    foreach ($header as $i => $h) $map[$normKey($h)] = $i;

    // Accept common variants
    $colDate = $map['date'] ?? null;
    $colTime = $map['time ko'] ?? ($map['time'] ?? ($map['ko'] ?? ($map['kickoff'] ?? null)));
    $colHome = $map['home team'] ?? ($map['home'] ?? null);
    $colAway = $map['away team'] ?? ($map['away'] ?? null);

    if ($colDate === null || $colHome === null || $colAway === null) {
        $seen = implode(', ', array_keys($map));
        throw new RuntimeException("Headers must include: Date, Time KO, Home Team, Away Team. Seen: {$seen}");
    }

    // ---- Schema detection (matches table)
    $hasCol = function(PDO $pdo, string $table, string $col): bool {
        $q = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $q->execute([':c'=>$col]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    };

    $has_home_uuid_col = $hasCol($pdo, 'matches', 'home_team_uuid');
    $has_away_uuid_col = $hasCol($pdo, 'matches', 'away_team_uuid');
    $has_home_id_col   = $hasCol($pdo, 'matches', 'home_team_id');
    $has_away_id_col   = $hasCol($pdo, 'matches', 'away_team_id');

    if (!(($has_home_uuid_col && $has_away_uuid_col) || ($has_home_id_col && $has_away_id_col))) {
        throw new RuntimeException('matches table missing team uuid/id columns');
    }

    $dupHomeCol = $has_home_uuid_col ? 'home_team_uuid' : 'home_team_id';

    $has_division  = $hasCol($pdo, 'matches', 'division');
    $has_district  = $hasCol($pdo, 'matches', 'district');
    $has_poule     = $hasCol($pdo, 'matches', 'poule');
    $has_ko        = $hasCol($pdo, 'matches', 'kickoff_time');

    $has_loc_uuid  = $hasCol($pdo, 'matches', 'location_uuid');
    $has_loc_addr  = $hasCol($pdo, 'matches', 'location_address');

    // ---- Lookups
    $qTeamHome = $pdo->prepare("
        SELECT 
            t.uuid,
            t.division,
            d.name AS district_name,
            l.uuid AS loc_uuid,
            CASE 
              WHEN COALESCE(l.name,'')<>'' AND COALESCE(l.address_text,'')<>'' 
                THEN CONCAT(l.name, ' – ', l.address_text)
              ELSE COALESCE(l.name, l.address_text, '')
            END AS loc_label
        FROM teams t
        LEFT JOIN districts d ON d.id = t.district_id
        LEFT JOIN clubs c ON c.uuid = t.club_id
        LEFT JOIN locations l ON l.uuid = c.location_uuid
        WHERE t.team_name COLLATE utf8mb4_general_ci = ?
        LIMIT 1
    ");
    $qTeamAway = $pdo->prepare("SELECT uuid FROM teams WHERE team_name COLLATE utf8mb4_general_ci = ? LIMIT 1");

    // Duplicate check uses schema-correct home column
    $qDup = $pdo->prepare("SELECT 1 FROM matches WHERE match_date = ? AND {$dupHomeCol} = ? LIMIT 1");

    // ---- Build INSERT SQL once, based on schema
    $cols = ['uuid', 'match_date'];
    $vals = ['UUID()', ':match_date'];

    if ($has_ko) { $cols[] = 'kickoff_time'; $vals[] = ':kickoff_time'; }

    if ($has_home_uuid_col && $has_away_uuid_col) {
        $cols[] = 'home_team_uuid'; $vals[]=':home_uuid';
        $cols[] = 'away_team_uuid'; $vals[]=':away_uuid';
    } else {
        $cols[] = 'home_team_id';   $vals[]=':home_id';
        $cols[] = 'away_team_id';   $vals[]=':away_id';
    }

    if ($has_division) { $cols[] = 'division'; $vals[]=':division'; }
    if ($has_district) { $cols[] = 'district'; $vals[]=':district'; }
    if ($has_poule)    { $cols[] = 'poule';    $vals[]=':poule';    }

    if ($has_loc_uuid) { $cols[] = 'location_uuid';    $vals[]=':loc_uuid';  }
    if ($has_loc_addr) { $cols[] = 'location_address'; $vals[]=':loc_label'; }

    $quoteId = function(string $id): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            throw new RuntimeException("Illegal identifier: $id");
        }
        return "`$id`";
    };

    $sqlInsert = "INSERT INTO `matches` (".implode(',', array_map($quoteId, $cols)).") VALUES (".implode(',', $vals).")";
    $ins = $pdo->prepare($sqlInsert);

    // ---- Iterate rows
    $inserted = 0;
    $skipped  = 0;
    $errors   = [];

    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        try {
            $date = $normDate($row[$colDate] ?? '');
            $time = $normTime($colTime !== null ? ($row[$colTime] ?? '') : '');
            $home = $clean($row[$colHome] ?? '');
            $away = $clean($row[$colAway] ?? '');

            if (!$date || !$home || !$away) {
                $errors[] = 'Missing Date/Home/Away';
                continue;
            }

            // Look up teams
            $qTeamHome->execute([$home]);
            $homeTeam = $qTeamHome->fetch(PDO::FETCH_ASSOC);

            $qTeamAway->execute([$away]);
            $awayTeam = $qTeamAway->fetch(PDO::FETCH_ASSOC);

            if (!$homeTeam || !$awayTeam) {
                $errors[] = "Unknown team: {$home} vs {$away}";
                continue;
            }

            // Duplicate (date + home team)
            $dupBindHome = $homeTeam['uuid'];
            $qDup->execute([$date, $dupBindHome]);
            if ($qDup->fetch()) { $skipped++; continue; }

            // Bind for insert
            $bind = [
                ':match_date'   => $date,
            ];
            if ($has_ko) $bind[':kickoff_time'] = $time;

            if ($has_home_uuid_col && $has_away_uuid_col) {
                $bind[':home_uuid'] = $homeTeam['uuid'];
                $bind[':away_uuid'] = $awayTeam['uuid'];
            } else {
                $bind[':home_id']   = $homeTeam['uuid'];
                $bind[':away_id']   = $awayTeam['uuid'];
            }

            if ($has_division) $bind[':division'] = $homeTeam['division'] ?? null;
            if ($has_district) $bind[':district'] = $homeTeam['district_name'] ?? null;
            if ($has_poule)    $bind[':poule']    = null; // set if your CSV supplies it

            // Location: write UUID and a nice label if columns exist
            if ($has_loc_uuid) $bind[':loc_uuid']  = $homeTeam['loc_uuid']  ?? null;
            if ($has_loc_addr) $bind[':loc_label'] = $homeTeam['loc_label'] ?? null;

            $ins->execute($bind);
            $inserted++;
        } catch (\Throwable $t) {
            $errors[] = $t->getMessage();
        }
    }
    fclose($fh);

    echo json_encode([
        'success'             => true,
        'inserted'            => $inserted,
        'skipped_duplicates'  => $skipped,
        'errors'              => $errors,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
