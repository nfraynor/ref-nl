<?php
require_once __DIR__ . '/../utils/session_auth.php';
// users.php
// Page for managing users

// Initialize variables
$users = [];
$error_message = '';

// Database connection and data fetching
try {
    // Include database configuration
    // Correct path from php/public/users.php to php/config/database.php
    $config = require __DIR__ . '/../config/database.php';

    // DSN (Data Source Name)
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

    // PDO options
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Turn on errors in the form of exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Make the default fetch be an associative array
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Turn off emulation mode for real prepared statements
    ];

    // Create a new PDO instance
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);

    // Prepare and execute SQL query
    $stmt = $pdo->prepare("
        SELECT u.uuid, u.username, u.role, u.created_at, GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') AS divisions, GROUP_CONCAT(DISTINCT dist.name SEPARATOR ', ') AS districts
        FROM users u
        LEFT JOIN user_permissions up ON u.uuid = up.user_id
        LEFT JOIN divisions d ON up.division_id = d.id
        LEFT JOIN districts dist ON up.district_id = dist.id
        GROUP BY u.uuid, u.username, u.role, u.created_at
        ORDER BY u.username ASC
    ");
    $stmt->execute();

    // Fetch all users
    $users = $stmt->fetchAll();

} catch (PDOException $e) {
    // Store error message
    $error_message = "Database error: " . $e->getMessage();
    // Ensure $users is an empty array on error
    $users = [];
} catch (Exception $e) {
    // Catch any other exceptions (e.g., if config file is missing)
    $error_message = "An unexpected error occurred: " . $e->getMessage();
    $users = [];
}

// Include the header
require_once 'includes/header.php';

// Include the navigation menu
require_once 'includes/nav.php';
?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1>User Management</h1>
        <?php if (isset($_SESSION['user_role']) && ($_SESSION['user_role'] === 'super_admin' || $_SESSION['user_role'] === 'user_admin')): ?>
            <a href="add_user.php" class="btn btn-success">Add New User</a>
        <?php endif; ?>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php else: ?>
        <?php if (empty($users)): ?>
            <div class="alert alert-info" role="alert">
                No users found in the system.
            </div>
        <?php else: ?>
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Username</th>
                        <th>Global Role</th>
                        <th>Divisions</th>
                        <th>Districts</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['role'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($user['divisions'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($user['districts'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($user['created_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Further user management UI will go here -->
</div>

<?php
// Include the footer
require_once 'includes/footer.php';
?>
