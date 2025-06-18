<?php
// php/public/logout.php

// Step 1: Start the session.
// This is necessary to access and then manipulate session data.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Step 2: Unset all session variables.
// This clears all data stored in the session.
$_SESSION = array();

// Step 3: Destroy the session.
// This removes the session data from the server and invalidates the session ID cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Step 4: Redirect the user to login.php.
// After logout, the user is typically sent back to the login page.
header("Location: login.php");

// Step 5: Call exit to prevent further script execution.
exit;
?>
