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
    if (!in_array($ext, ['csv','txt'], true)) {
        throw new RuntimeException('Only CSV/TXT supported (save your sheet as CSV UTF-8).');
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) throw new RuntimeException('Unable to open file');

    /* ---------- Sniff delimiter (first line), strip BOM if present ---------- */
    $peek = fgets($fh, 8192);
    if ($peek === false) throw new RuntimeException('File appears empty');
    if (strncmp($peek, "\xEF\xBB\xBF", 3) === 0) $peek = substr($peek, 3);

    $counts = [
        ","  => substr_count($peek, ","),
        ";"  => substr_count($peek, ";"),
        "\t" => substr_count($peek, "\t"),
        "|"  => substr_count($peek, "|"),
    ];
    arsort($counts);
    $delimiter = array_key_first($counts);
    if (($counts[$delimiter] ?? 0) === 0) $delimiter = ",";

    rewind($fh);

    /* ------------------------------- Helpers ------------------------------- */
    $clean = function($v) {
        if ($v === null) return '';
        $v = (string)$v;
        if (strncmp($v, "\xEF\xBB\xBF", 3) === 0) $v = substr($v, 3); // strip UTF-8 BOM
        // replace various non-breaking / thin spaces with normal space
        $v = str_replace(
            ["\xC2\xA0", "\xA0", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\xAF", "\xE2\x80\x8B"],
            ' ',
            $v
        );
        $v = preg_replace('/[ \t]+/u', ' ', $v);
        return trim($v);
    };
    $normKey = function($s) use($clean) {
        $s = strtolower($clean($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(['–','—'], '-', $s); // normalize dashes
        return $s;
    };
    $normDate = function($v) use($clean) {
        $v = $clean($v);
        if ($v === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v; // already YYYY-MM-DD
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

    /* ------------------------------ Parse header ------------------------------ */
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
        throw new RuntimeException("Headers must include: Date, Home Team, Away Team (Time optional). Seen: {$seen}");
    }

    /* ----------------- Schema detection (matches table columns) ---------------- */
    $hasCol = function(PDO $pdo, string $table, string $col): bool {
        $q = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND COLUMN_NAME  = ?
        ");
        $q->execute([$table, $col]);
        return (bool)$q->fetchColumn();
    };
    $isChar36 = function(PDO $pdo, string $table, string $col): bool {
        $q = $pdo->prepare("
            SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND COLUMN_NAME  = ?
            LIMIT 1
        ");
        $q->execute([$table, $col]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        $type = strtolower((string)$row['DATA_TYPE']);
        $len  = (int)($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
        return ($len === 36) && ($type === 'char' || $type === 'varchar');
    };

    $has_home_uuid_col = $hasCol($pdo, 'matches', 'home_team_uuid');
    $has_away_uuid_col = $hasCol($pdo, 'matches', 'away_team_uuid');
    $has_home_id_col   = $hasCol($pdo, 'matches', 'home_team_id');
    $has_away_id_col   = $hasCol($pdo, 'matches', 'away_team_id');

    if (!(($has_home_uuid_col && $has_away_uuid_col) || ($has_home_id_col && $has_away_id_col))) {
        throw new RuntimeException('matches table missing team uuid/id column pair');
    }

    // Choose which columns to use in matches; treat *_team_id as UUID if it's CHAR/VARCHAR(36)
    if ($has_home_uuid_col && $has_away_uuid_col) {
        $home_col = 'home_team_uuid';
        $away_col = 'away_team_uuid';
    } else {
        // Only *_id present
        if (!$isChar36($pdo, 'matches', 'home_team_id') || !$isChar36($pdo, 'matches', 'away_team_id')) {
            throw new RuntimeException(
                "Schema mismatch: matches has *_team_id but they are not CHAR(36)/VARCHAR(36). " .
                "Either add *_team_uuid or change *_team_id columns to CHAR(36)/VARCHAR(36)."
            );
        }
        $home_col = 'home_team_id';   // UUID semantics despite the name
        $away_col = 'away_team_id';
    }

    $has_division = $hasCol($pdo, 'matches', 'division');
    $has_district = $hasCol($pdo, 'matches', 'district');
    $has_poule    = $hasCol($pdo, 'matches', 'poule');
    $has_ko       = $hasCol($pdo, 'matches', 'kickoff_time');

    $has_loc_uuid = $hasCol($pdo, 'matches', 'location_uuid');
    $has_loc_addr = $hasCol($pdo, 'matches', 'location_address');

    /* ------------------------------ Team lookups ------------------------------ */
    $qTeamHome = $pdo->prepare("
        SELECT 
            t.uuid AS team_uuid,
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
        LEFT JOIN clubs c     ON c.uuid = t.club_id
        LEFT JOIN locations l ON l.uuid = c.location_uuid
        WHERE t.team_name COLLATE utf8mb4_general_ci = ?
        LIMIT 1
    ");
    $qTeamAway = $pdo->prepare("
        SELECT uuid AS team_uuid
        FROM teams
        WHERE team_name COLLATE utf8mb4_general_ci = ?
        LIMIT 1
    ");

    /* ------------------------- Duplicate checker (home) ------------------------ */
    $qDup = $pdo->prepare("SELECT 1 FROM matches WHERE match_date = ? AND {$home_col} = ? LIMIT 1");

    /* ---------------- Build INSERT SQL once, based on schema ------------------ */
    $cols = ['uuid', 'match_date', $home_col, $away_col];
    $vals = ['UUID()', ':match_date', ':home_key', ':away_key'];

    if ($has_ko)        { $cols[] = 'kickoff_time';      $vals[] = ':kickoff_time'; }
    if ($has_division)  { $cols[] = 'division';          $vals[] = ':division';     }
    if ($has_district)  { $cols[] = 'district';          $vals[] = ':district';     }
    if ($has_poule)     { $cols[] = 'poule';             $vals[] = ':poule';        }
    if ($has_loc_uuid)  { $cols[] = 'location_uuid';     $vals[] = ':loc_uuid';     }
    if ($has_loc_addr)  { $cols[] = 'location_address';  $vals[] = ':loc_label';    }

    $quoteId = function(string $id): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            throw new RuntimeException("Illegal identifier: $id");
        }
        return "`$id`";
    };

    $sqlInsert = "INSERT INTO `matches` (".implode(',', array_map($quoteId, $cols)).") VALUES (".implode(',', $vals).")";
    $ins = $pdo->prepare($sqlInsert);

    /* ------------------------------- Iterate rows ------------------------------ */
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

            // Duplicate (date + home team key)
            $qDup->execute([$date, $homeTeam['team_uuid']]);
            if ($qDup->fetch()) { $skipped++; continue; }

            // Bind for insert
            $bind = [
                ':match_date' => $date,
                ':home_key'   => $homeTeam['team_uuid'], // UUID value for either *_uuid or *_id (uuid-like)
                ':away_key'   => $awayTeam['team_uuid'],
            ];
            if ($has_ko) $bind[':kickoff_time'] = $time;

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
