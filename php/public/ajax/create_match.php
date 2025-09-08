<?php
require_once __DIR__ . '/../../utils/session_auth.php';
require_once __DIR__ . '/../../utils/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'POST only']);
    exit;
}

function body($k,$d=null){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }

/* -------- Inputs (support both id + label fallbacks) -------- */
$uuid          = bin2hex(random_bytes(16));
$match_date    = body('match_date');           // YYYY-MM-DD  (required)
$kickoff_time  = body('kickoff_time');         // HH:MM       (optional)

$home_team_id  = body('home_team_uuid');       // preferred
$away_team_id  = body('away_team_uuid');       // preferred
$home_team_txt = body('home_team');            // fallback
$away_team_txt = body('away_team');            // fallback

$division      = body('division');
$district      = body('district');
$poule         = body('poule');

$location_uuid  = body('location_uuid');       // preferred
$location_label = body('location_label');      // text label the user picked/typed
$location_addr  = body('location_address');    // legacy fallback if still posted

if (!$match_date || (!$home_team_id && !$home_team_txt) || (!$away_team_id && !$away_team_txt)) {
    echo json_encode(['success'=>false,'message'=>'Required: match_date, home team, away team']);
    exit;
}

try {
    $pdo = Database::getConnection();
    // Make sure PDO throws so we can see what failed
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $hasCol = function(PDO $pdo, string $table, string $col): bool {
        $q = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $q->execute([':c'=>$col]);
        return (bool)$q->fetch(PDO::FETCH_ASSOC);
    };

    // ---- Detect columns present in `matches`
    $has_home_id     = $hasCol($pdo, 'matches', 'home_team_id');
    $has_home_uuid   = $hasCol($pdo, 'matches', 'home_team_uuid');
    $has_away_id     = $hasCol($pdo, 'matches', 'away_team_id');
    $has_away_uuid   = $hasCol($pdo, 'matches', 'away_team_uuid');
    $has_home_txt    = $hasCol($pdo, 'matches', 'home_team');
    $has_away_txt    = $hasCol($pdo, 'matches', 'away_team');

    $has_location_uuid = $hasCol($pdo, 'matches', 'location_uuid');
    $has_location_addr = $hasCol($pdo, 'matches', 'location_address');

    // Optional assignment columns
    $has_assigner   = $hasCol($pdo, 'matches', 'referee_assigner_uuid');
    $has_referee    = $hasCol($pdo, 'matches', 'referee_id');
    $has_ar1        = $hasCol($pdo, 'matches', 'ar1_id');
    $has_ar2        = $hasCol($pdo, 'matches', 'ar2_id');
    $has_comm       = $hasCol($pdo, 'matches', 'commissioner_id');

    // ---- Resolve labels for the response
    $home_label = $home_team_txt;
    $away_label = $away_team_txt;

    if ($home_team_id) {
        $stmt = $pdo->prepare("SELECT team_name FROM teams WHERE uuid=:u");
        $stmt->execute([':u'=>$home_team_id]);
        $home_label = $stmt->fetchColumn() ?: $home_team_txt;
    }
    if ($away_team_id) {
        $stmt = $pdo->prepare("SELECT team_name FROM teams WHERE uuid=:u");
        $stmt->execute([':u'=>$away_team_id]);
        $away_label = $stmt->fetchColumn() ?: $away_team_txt;
    }

    $resolved_location_label = $location_label;
    $resolved_location_addr  = $location_addr;
    if ($location_uuid) {
        $stmt = $pdo->prepare("
            SELECT 
              CASE
                WHEN COALESCE(name,'')<>'' AND COALESCE(address_text,'')<>'' THEN CONCAT(name, ' – ', address_text)
                ELSE COALESCE(name, address_text, '')
              END AS label,
              address_text
            FROM locations WHERE uuid=:u
        ");
        $stmt->execute([':u'=>$location_uuid]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resolved_location_label = $row['label'];
            if (!$resolved_location_addr) $resolved_location_addr = $row['address_text'];
        }
    }
    if (!$resolved_location_label && $resolved_location_addr) {
        $resolved_location_label = $resolved_location_addr;
    }

    // ---- Build INSERT dynamically
    $cols = ['uuid','match_date','kickoff_time','division','district','poule'];
    $vals = [':uuid',':match_date',':kickoff_time',':division',':district',':poule'];
    $bind = [
        ':uuid'         => $uuid,
        ':match_date'   => $match_date,
        ':kickoff_time' => $kickoff_time ?: null,
        ':division'     => $division ?: null,
        ':district'     => $district ?: null,
        ':poule'        => $poule ?: null,
    ];

    // Teams: prefer uuid/id columns if available, else fall back to text columns
    if ($has_home_uuid && $has_away_uuid) {
        $cols[]='home_team_uuid'; $vals[]=':home_team_uuid'; $bind[':home_team_uuid'] = $home_team_id ?: null;
        $cols[]='away_team_uuid'; $vals[]=':away_team_uuid'; $bind[':away_team_uuid'] = $away_team_id ?: null;
    } elseif ($has_home_id && $has_away_id) {
        $cols[]='home_team_id'; $vals[]=':home_team_id'; $bind[':home_team_id'] = $home_team_id ?: null;
        $cols[]='away_team_id'; $vals[]=':away_team_id'; $bind[':away_team_id'] = $away_team_id ?: null;
    } elseif ($has_home_txt && $has_away_txt) {
        $cols[]='home_team'; $vals[]=':home_team'; $bind[':home_team'] = $home_label ?: $home_team_txt;
        $cols[]='away_team'; $vals[]=':away_team'; $bind[':away_team'] = $away_label ?: $away_team_txt;
    } else {
        throw new RuntimeException('Matches table missing team columns (id/uuid/text).');
    }

    // Location: prefer location_uuid; else fall back to location_address if present
    // Location: prefer location_uuid; also store a label into location_address if the column exists (for UI)
    if ($has_location_uuid) {
        $cols[]='location_uuid';    $vals[]=':location_uuid';    $bind[':location_uuid']    = $location_uuid ?: null;

        if ($has_location_addr) {
            $cols[]='location_address';
            $vals[]=':location_address';
            // Pick a nice user-facing label (name – address if possible)
            $bind[':location_address'] = $resolved_location_label ?: $resolved_location_addr ?: null;
        }
    } elseif ($has_location_addr) {
        $cols[]='location_address'; $vals[]=':location_address';
        $bind[':location_address'] = $resolved_location_addr ?: $resolved_location_label ?: null;
    }

    foreach ($cols as $c) {
        if ($c === '' || $c === null) {
            throw new RuntimeException('Empty column name detected in INSERT.');
        }
    }
    // Optional assignment columns: include only if they exist
    if ($has_assigner) { $cols[]='referee_assigner_uuid'; $vals[]='NULL'; }
    if ($has_referee)  { $cols[]='referee_id';            $vals[]='NULL'; }
    if ($has_ar1)      { $cols[]='ar1_id';                $vals[]='NULL'; }
    if ($has_ar2)      { $cols[]='ar2_id';                $vals[]='NULL'; }
    if ($has_comm)     { $cols[]='commissioner_id';       $vals[]='NULL'; }

    $quoteId = function(string $id): string {
        // allow letters, digits, underscore ONLY (adjust if you need more)
        if (!preg_match('/^[A-Za-z0-9_]+$/', $id)) {
            throw new RuntimeException("Illegal identifier: $id");
        }
        return "`$id`";
    };

    $cols_sql = implode(',', array_map($quoteId, $cols));
    $sql = "INSERT INTO `matches` ($cols_sql) VALUES (".implode(',', $vals).")";
    if (count($cols) !== count($vals)) {
        throw new RuntimeException("Columns/values length mismatch: cols=".count($cols).", vals=".count($vals));
    }

// Extract placeholders from VALUES
    $placeholders = [];
    foreach ($vals as $v) {
        if ($v === 'NULL') continue;
        if (!preg_match('/^:[A-Za-z0-9_]+$/', $v)) {
            throw new RuntimeException("Bad placeholder token in VALUES: ".$v);
        }
        $placeholders[] = $v;
    }

// Check that every placeholder is bound
    foreach ($placeholders as $ph) {
        if (!array_key_exists($ph, $bind)) {
            throw new RuntimeException("Missing bind for placeholder ".$ph);
        }
    }

// Check for extra binds that won’t be used (helps catch typos)
    foreach ($bind as $k => $v) {
        if (!in_array($k, $placeholders, true)) {
            error_log("create_match WARN: extra bind key not used in SQL: ".$k);
        }
    }
    $stmt = $pdo->prepare($sql);
    if (true) { // set to false in prod
        error_log("create_match INSERT SQL: $sql");
        // Log bound values in a readable way
        $dbg = [];
        foreach ($bind as $k => $v) {
            $dbg[$k] = (is_null($v) ? 'NULL' : (string)$v);
        }
        error_log("create_match BIND: ".json_encode($dbg, JSON_UNESCAPED_SLASHES));
    }
    $stmt->execute($bind);

    echo json_encode([
        'success'=>true,
        'row'=>[
            'uuid'           => $uuid,
            'match_date'     => $match_date,
            'kickoff_time'   => $kickoff_time ?: null,
            'home_team'      => $home_label,
            'away_team'      => $away_label,
            'division'       => $division ?: null,
            'district'       => $district ?: null,
            'poule'          => $poule ?: null,
            'location_label' => $resolved_location_label ?: null,
            'referee_assigner_uuid' => null,
            'referee_id'     => null,
            'ar1_id'         => null,
            'ar2_id'         => null,
            'commissioner_id'=> null,
        ]
    ]);
} catch (Throwable $e) {
    // Log full details to server logs; return a helpful message in dev
    error_log("create_match.php error: ".$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Insert failed: '.$e->getMessage()]);
}
