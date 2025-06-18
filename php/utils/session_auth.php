<?php
// php/utils/session_auth.php

// Ensure session is started.
// Check session_status to avoid errors if already started.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if the user_id session variable is set and not empty.
// This variable is expected to be set upon successful login.
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // User is not logged in or session is invalid.
    // Redirect to login.php.
    // We assume '/login.php' correctly maps to 'php/public/login.php'
    // due to web server configuration (e.g., DocumentRoot pointing to php/public).
    header('Location: /login.php');
    // Ensure no further script execution after redirect.
    exit;
}

// If $_SESSION['user_id'] is set, the script does nothing,
// and execution continues in the script that included this file.
?>
