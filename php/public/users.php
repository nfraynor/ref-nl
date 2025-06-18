<?php
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
    $stmt = $pdo->prepare("SELECT uuid, username, role, created_at FROM users ORDER BY username ASC");
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
?>

<div class="container">
    <h1>User Management</h1>

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
                        <th>Role</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['role']); ?></td>
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
