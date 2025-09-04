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

    $tmp = $_FILES['file']['tmp_name'];
    $name = $_FILES['file']['name'] ?? 'upload';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv','txt'])) {
        // We only use native CSV parser (no Composer deps). Excel can save as CSV UTF-8.
        throw new RuntimeException('Only CSV/TXT supported (save your sheet as CSV UTF-8).');
    }

    $fh = fopen($tmp, 'r');
    if (!$fh) throw new RuntimeException('Unable to open file');

    // Read first line for delimiter sniff + BOM strip
    $peek = fgets($fh, 8192);
    if ($peek === false) throw new RuntimeException('File appears empty');

    // Strip UTF-8 BOM
    if (strncmp($peek, "\xEF\xBB\xBF", 3) === 0) {
        $peek = substr($peek, 3);
    }

    // Detect delimiter
    $counts = [
        ","  => substr_count($peek, ","),
        ";"  => substr_count($peek, ";"),
        "\t" => substr_count($peek, "\t"),
        "|"  => substr_count($peek, "|"),
    ];
    arsort($counts);
    $delimiter = array_key_first($counts);
    if (($counts[$delimiter] ?? 0) === 0) {
        // If we couldn't detect, default to comma
        $delimiter = ",";
    }

    // Rewind to start for real parsing
    rewind($fh);

    // Cell cleaner (handles NBSP and zero-width spaces)
    $clean = function($v) {
        if ($v === null) return '';
        $v = (string)$v;
        // Remove BOM if present at start
        if (strncmp($v, "\xEF\xBB\xBF", 3) === 0) $v = substr($v, 3);
        // Replace NBSP and thin spaces with normal space
        $v = str_replace(["\xC2\xA0", "\xA0", "\xE2\x80\x89", "\xE2\x80\x8A", "\xE2\x80\xAF", "\xE2\x80\x8B"], ' ', $v);
        // Collapse spaces
        $v = preg_replace('/[ \t]+/u', ' ', $v);
        return trim($v);
    };

    // Read one row with fgetcsv for header, honoring detected delimiter
    $header = fgetcsv($fh, 0, $delimiter);
    if (!$header) throw new RuntimeException('Missing header row');

    // Normalize header keys (case-insensitive, spaces collapsed, punctuation relaxed)
    $normKey = function($s) use($clean) {
        $s = strtolower($clean($s));
        // Turn multiple spaces into single
        $s = preg_replace('/\s+/u', ' ', $s);
        // Unify common punctuation variants
        $s = str_replace(['–','—'], '-', $s);
        return $s;
    };

    $map = [];
    foreach ($header as $i => $h) {
        $map[$normKey($h)] = $i;
    }

    // Accept common variants
    $colDate = $map['date'] ?? null;
    $colTime = $map['time ko'] ?? ($map['time'] ?? ($map['ko'] ?? ($map['kickoff'] ?? null)));
    $colHome = $map['home team'] ?? ($map['home'] ?? null);
    $colAway = $map['away team'] ?? ($map['away'] ?? null);

    if ($colDate === null || $colHome === null || $colAway === null) {
        $seen = implode(', ', array_keys($map));
        throw new RuntimeException("Headers must include: Date, Time KO, Home Team, Away Team. Seen: {$seen}");
    }

    // Prepare queries
    $qTeamHome = $pdo->prepare("
        SELECT 
            t.uuid,
            t.division,
            d.name AS district_name,
            l.uuid AS loc_uuid,
            CASE 
              WHEN COALESCE(l.name,'')<>'' AND COALESCE(l.address_text,'')<>'' 
                THEN CONCAT(l.name, ', ', l.address_text)
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
    $qDup = $pdo->prepare("SELECT 1 FROM matches WHERE match_date = ? AND home_team_id = ? LIMIT 1");
    $ins = $pdo->prepare("
        INSERT INTO matches (
            uuid, match_date, kickoff_time, 
            home_team_id, away_team_id,
            division, district, poule, 
            location_address
        ) VALUES (
            UUID(), ?, ?, ?, ?, ?, ?, NULL, ?
        )
    ");

    $inserted = 0;
    $skipped = 0;
    $errors = [];

    // Helpers
    $normDate = function($v) use($clean) {
        $v = $clean($v);
        if ($v === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v; // ISO
        // d/m/Y or d-m-Y
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $v, $m)) {
            $d = (int)$m[1]; $M = (int)$m[2]; $Y = (int)$m[3];
            if ($Y < 100) $Y += 2000;
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

    // Iterate data rows
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

            // Look up teams (case-insensitive exact by team_name)
            $qTeamHome->execute([$home]);
            $homeTeam = $qTeamHome->fetch(PDO::FETCH_ASSOC);

            $qTeamAway->execute([$away]);
            $awayTeam = $qTeamAway->fetch(PDO::FETCH_ASSOC);

            if (!$homeTeam || !$awayTeam) {
                $errors[] = "Unknown team: {$home} vs {$away}";
                continue;
            }

            // Duplicate (date + home team)
            $qDup->execute([$date, $homeTeam['uuid']]);
            if ($qDup->fetch()) { $skipped++; continue; }

            // Insert; keep location storage consistent with your table (location_address as label)
            $ins->execute([
                $date,
                $time,
                $homeTeam['uuid'],
                $awayTeam['uuid'],
                $homeTeam['division'] ?? null,
                $homeTeam['district_name'] ?? null,
                $homeTeam['loc_label'] ?? null,
            ]);
            $inserted++;
        } catch (\Throwable $t) {
            $errors[] = $t->getMessage();
        }
    }
    fclose($fh);

    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'skipped_duplicates' => $skipped,
        'errors' => $errors,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
