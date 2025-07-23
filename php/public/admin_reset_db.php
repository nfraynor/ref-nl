<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin DB Reset</title>
    <link rel="stylesheet" href="css/main.css"> <!-- Assuming a main.css exists -->
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .button {
            background-color: #dc3545; /* Red color for destructive action */
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .button:hover {
            background-color: #c82333; /* Darker red on hover */
        }
        .output-log {
            background-color: #222;
            color: #eee;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-family: monospace;
            white-space: pre-wrap; /* Preserve whitespace and wrap lines */
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #444;
        }
        .error { color: red; font-weight: bold; }
        .success { color: green; font-weight: bold; }
        .info { color: blue; }
    </style>
</head>
<body>
<div class="container">
    <h1>Database Reset Control Panel</h1>
            <p>This page allows you to clear, provision, and seed the application database. This is a destructive operation and should be used with caution.</p>

        <form action="admin_reset_db.php" method="POST" onsubmit="return confirm('Are you absolutely sure you want to clear, provision, and seed the entire database? This action cannot be undone.');">
            <button type="submit" name="reset_database" class="button">Clear, Provision, and Seed Database</button>
        </form>

        <?php
        $output_log = '';
        $error_occured = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_database'])) {
            if (true) {
                $output_log .= "Operation started at: " . date("Y-m-d H:i:s") . "\n\n";

                // Define script paths relative to the current script's directory
                $clear_script = __DIR__ . '/../provisioning/clear_schema.php';
                $provision_script = __DIR__ . '/../provisioning/provision.php';
                $seed_script = __DIR__ . '/../provisioning/seed-real.php';

                // Stage 1: Clear Schema
                $output_log .= "--- Stage 1: Clearing Schema ---\n";
                $output_log .= "Executing: php " . basename($clear_script) . "\n";
                $current_output = shell_exec("php " . escapeshellarg($clear_script) . " 2>&1");
                if ($current_output === null) {
                    $output_log .= "ERROR: Failed to execute clear_schema.php. Check PHP error logs.\n";
                    $error_occured = true;
                } else {
                    $output_log .= "Output:\n" . htmlspecialchars(trim($current_output)) . "\n";
                    // More specific error check for clear_schema
                    if (strpos(strtolower($current_output), 'error dropping table') !== false ||
                        (strpos(strtolower($current_output), 'failed') !== false && strpos(strtolower($current_output), 'database operation failed') !== false)) {
                        $error_occured = true;
                        $output_log .= "** Clear Schema: DETECTED ERRORS **\n";
                    } elseif (strpos(strtolower($current_output), 'schema cleared successfully') !== false || strpos(strtolower($current_output), 'no tables found') !== false) {
                        $output_log .= "** Clear Schema: Success **\n";
                    } else {
                        // Catch-all for unexpected output that isn't explicitly success but not a clear PDO/script error
                        $output_log .= "** Clear Schema: Finished with unexpected output (check manually) **\n";
                    }
                }
                $output_log .= "--- End Stage 1 ---\n\n";

                // Stage 2: Provision Database
                if (!$error_occured) {
                    $output_log .= "--- Stage 2: Provisioning Database ---\n";
                    $output_log .= "Executing: php " . basename($provision_script) . "\n";
                    $current_output = shell_exec("php " . escapeshellarg($provision_script) . " 2>&1");
                    if ($current_output === null) {
                        $output_log .= "ERROR: Failed to execute provision.php. Check PHP error logs.\n";
                        $error_occured = true;
                    } else {
                        $output_log .= "Output:\n" . htmlspecialchars(trim($current_output)) . "\n";
                        if (strpos(strtolower($current_output), 'error provisioning database') !== false ||
                            strpos(strtolower($current_output), 'failed') !== false) {
                            $error_occured = true;
                            $output_log .= "** Provisioning: DETECTED ERRORS **\n";
                        } elseif (strpos(strtolower($current_output), 'database provisioned successfully') !== false) {
                            $output_log .= "** Provisioning: Success **\n";
                        } else {
                            $output_log .= "** Provisioning: Finished with unexpected output (check manually) **\n";
                        }
                    }
                    $output_log .= "--- End Stage 2 ---\n\n";
                } else {
                    $output_log .= "--- Stage 2: Provisioning Database (SKIPPED due to previous errors) ---\n\n";
                }

                // Stage 3: Seed Database
                if (!$error_occured) {
                    $output_log .= "--- Stage 3: Seeding Database ---\n";
                    $output_log .= "Executing: php " . basename($seed_script) . "\n";
                    $current_output = shell_exec("php " . escapeshellarg($seed_script) . " 2>&1");
                    if ($current_output === null) {
                        $output_log .= "ERROR: Failed to execute seed.php. Check PHP error logs.\n";
                        $error_occured = true;
                    } else {
                        $output_log .= "Output:\n" . htmlspecialchars(trim($current_output)) . "\n";
                        // Seed script has multiple "echo" statements. Check for "Error" or "failed".
                        // Assuming the last lines would indicate overall success if no errors.
                        if (strpos(strtolower($current_output), 'error') !== false ||
                            strpos(strtolower($current_output), 'failed') !== false) {
                            $error_occured = true;
                            $output_log .= "** Seeding: DETECTED ERRORS (or script did not complete as expected) **\n";
                        } elseif (strpos(strtolower($current_output), 'admin user') !== false && strpos(strtolower($current_output), 'seeded') !== false ) { // A bit generic, but seed script ends with admin user
                            $output_log .= "** Seeding: Success (based on typical output) **\n";
                        } else {
                            $output_log .= "** Seeding: Finished with output (check manually for full success) **\n";
                        }
                    }
                    $output_log .= "--- End Stage 3 ---\n\n";
                } else {
                    $output_log .= "--- Stage 3: Seeding Database (SKIPPED due to previous errors) ---\n\n";
                }

                $output_log .= "------------------------------------\n";
                if ($error_occured) {
                    $output_log .= "Overall Status: Operation completed with ERRORS.\n";
                } else {
                    $output_log .= "Overall Status: Operation completed successfully.\n";
                }
                $output_log .= "Operation finished at: " . date("Y-m-d H:i:s") . "\n";

            } else {
                $output_log = "Error: You do not have permission to perform this action. Super admin rights required.";
                $error_occured = true;
            }
        }
        ?>

        <div id="outputLog" class="output-log" <?php if (empty($output_log)) echo 'style="display: none;"'; ?>>
            <h3>Operation Log:</h3>
            <pre><?php echo $output_log; ?></pre>
        </div>
</div>
<script>
    // If there's content in the log, ensure it's visible
    const outputLogDiv = document.getElementById('outputLog');
    if (outputLogDiv && outputLogDiv.querySelector('pre').textContent.trim() !== '') {
        outputLogDiv.style.display = 'block';
    }
</script>
</body>
</html>
