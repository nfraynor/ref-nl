<?php
// php/public/admin_update_db.php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

function readSqlFile(string $path): string {
    if (!file_exists($path)) {
        throw new RuntimeException("update.sql not found at: $path");
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("update.sql is empty or unreadable.");
    }
    return $sql;
}

/**
 * Very simple SQL splitter:
 * - Strips -- and /* * / comments
 * - Splits on semicolons that end a line (not perfect, but fine for standard DDL/DML)
 * - Skips DELIMITER blocks (not supported)
 * NOTE: Do NOT use for procedures/triggers that need DELIMITER.
 */
function splitSqlStatements(string $sql): array {
    // Remove /* ... */ comments
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    // Remove -- comments
    $lines = preg_split("/\R/", $sql);
    $clean = [];
    foreach ($lines as $line) {
        // keep inline -- inside strings? We keep it simple: if -- appears, strip from there unless it's inside quotes
        $inSingle = false; $inDouble = false; $buf = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            $prev = $i > 0 ? $line[$i-1] : '';
            if ($ch === "'" && $prev !== '\\' && !$inDouble) { $inSingle = !$inSingle; }
            if ($ch === '"' && $prev !== '\\' && !$inSingle) { $inDouble = !$inDouble; }
            if (!$inSingle && !$inDouble && $ch === '-' && $i+1 < $len && $line[$i+1] === '-') {
                // comment starts
                break;
            }
            $buf .= $ch;
        }
        $clean[] = $buf;
    }
    $sql = trim(implode("\n", $clean));

    // Block DELIMITER usage explicitly
    if (preg_match('/\bDELIMITER\b/i', $sql)) {
        throw new RuntimeException("update.sql contains DELIMITER statements (procedures/triggers). This runner does not support DELIMITER.");
    }

    // Split on semicolons that terminate statements
    $parts = preg_split('/;(?=\s*(?:--|\/\*|$)|\s*\R)/', $sql);
    $stmts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $stmts[] = $p;
        }
    }
    return $stmts;
}

$output_log = '';
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_update'])) {
    $output_log .= "Operation started at: " . date("Y-m-d H:i:s") . "\n\n";

    // TODO: Replace this with a real permission check (super admin)
    $hasPermission = true; // session_auth should give you the user; enforce role here.

    if (!$hasPermission) {
        $output_log .= "ERROR: You do not have permission to perform this action.\n";
        $error = true;
    } else {
        $sqlPath = __DIR__ . '/../../sql/update.sql';
        $output_log .= "Using SQL file: $sqlPath\n";

        try {
            $pdo = Database::getConnection();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

            $sqlRaw = readSqlFile($sqlPath);
            $statements = splitSqlStatements($sqlRaw);

            if (count($statements) === 0) {
                throw new RuntimeException("No executable statements found in update.sql.");
            }

            $output_log .= "Parsed " . count($statements) . " statement(s).\n\n";
            $output_log .= "--- Begin Transaction ---\n";

            $pdo->beginTransaction();

            $i = 1;
            foreach ($statements as $stmt) {
                // Show a compact preview per statement
                $preview = preg_replace('/\s+/', ' ', substr($stmt, 0, 180));
                $output_log .= "[$i] Executing: {$preview}" . (strlen($stmt) > 180 ? "..." : "") . "\n";
                $affected = $pdo->exec($stmt);
                if ($affected === false) { // exec returns false on failure
                    throw new RuntimeException("Statement $i failed.");
                }
                $output_log .= "[$i] OK (affected: " . ($affected ?? 0) . ")\n\n";
                $i++;
            }

            $pdo->commit();
            $output_log .= "--- Commit ---\n\n";
            $output_log .= "Overall Status: Update applied successfully.\n";
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
                $output_log .= "--- ROLLBACK ---\n";
            }
            $output_log .= "ERROR: " . $e->getMessage() . "\n";
            $error = true;
        }
    }

    $output_log .= "Operation finished at: " . date("Y-m-d H:i:s") . "\n";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Apply Database Update</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .button {
            background-color: #0ea5e9; color: white; padding: 10px 20px; border: none;
            border-radius: 5px; cursor: pointer; font-size: 16px; transition: background-color .3s;
        }
        .button:hover { background-color: #0284c7; }
        .danger { background-color: #dc2626; }
        .danger:hover { background-color: #b91c1c; }
        .output-log {
            background:#111; color:#e5e7eb; padding:14px; border-radius:8px; margin-top:18px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            white-space: pre-wrap; max-height: 480px; overflow-y:auto; border:1px solid #374151;
        }
        .notice { background:#fff7ed; border:1px solid #fed7aa; color:#7c2d12; padding:10px; border-radius:8px; }
        .meta { color:#6b7280; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Apply Database Update</h1>
    <p>This will execute the SQL in <code>provisioning/update.sql</code> inside a single transaction. It will stop on the first error and roll back.</p>

    <div class="notice" style="margin:12px 0;">
        <strong>Heads up:</strong> This runner does <em>not</em> support <code>DELIMITER</code> blocks (procedures/triggers). Keep changes to standard DDL/DML statements.
    </div>

    <form method="POST" onsubmit="return confirm('Run update.sql now? This will apply schema/data changes inside a transaction.');">
        <button type="submit" name="apply_update" class="button">Run update.sql</button>
        <a href="admin_reset_db.php" class="button danger" style="margin-left:8px; text-decoration:none; display:inline-block;">Reset DB</a>
    </form>

    <?php if (file_exists(__DIR__ . '/../provisioning/update.sql')): ?>
        <p class="meta">Found <code>update.sql</code>, last modified:
            <?php echo date('Y-m-d H:i:s', filemtime(__DIR__ . '/../../sql/update.sql')); ?>
        </p>
    <?php else: ?>
        <p class="meta">No <code>update.sql</code> found at <code>provisioning/update.sql</code>.</p>
    <?php endif; ?>

    <?php if (!empty($output_log)): ?>
        <div class="output-log">
            <h3 style="margin-top:0;">Operation Log</h3>
            <pre><?php echo htmlspecialchars($output_log); ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
