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

    // Prepare and execute SQL query to fetch users and their individual permissions
    $stmt = $pdo->prepare("
        SELECT
            u.uuid,
            u.username,
            u.role AS global_role,
            u.created_at,
            d.name AS division_name,
            dist.name AS district_name
        FROM
            users u
        LEFT JOIN
            user_permissions up ON u.uuid = up.user_id
        LEFT JOIN
            divisions d ON up.division_id = d.id
        LEFT JOIN
            districts dist ON up.district_id = dist.id
        ORDER BY
            u.username ASC, d.name ASC, dist.name ASC
    ");
    $stmt->execute();

    // Fetch all rows. This will have duplicates for users if they have multiple permissions.
    $raw_user_data = $stmt->fetchAll();

    // Process the raw data to group permissions by user
    $users_processed = [];
    foreach ($raw_user_data as $row) {
        $user_uuid = $row['uuid'];
        if (!isset($users_processed[$user_uuid])) {
            $users_processed[$user_uuid] = [
                'uuid' => $user_uuid,
                'username' => $row['username'],
                'global_role' => $row['global_role'],
                'created_at' => $row['created_at'],
                'permissions' => [] // Array to store "Division - District" strings or global role
            ];
        }

        // Add global role if present and not already added (it will be the same for all rows of a user)
        if (!empty($row['global_role']) && !in_array($row['global_role'], $users_processed[$user_uuid]['permissions'])) {
            // Check if the global role is the only permission type we want to show if present
            // For now, let's assume global role is primary. If it exists, specific permissions might be secondary or not shown
            // Based on user request, global role is one of the roles.
            // To avoid adding it multiple times if user has district permissions too:
            if (empty($users_processed[$user_uuid]['permissions']) || $users_processed[$user_uuid]['permissions'][0] !== $row['global_role']) {
                // If permissions list is empty, or global role is not already the first item.
                // This logic might need refinement based on how we want to prioritize display if both exist.
                // For now, let's add it if it's not there. A user should ideally have a global role OR specific permissions.
                // The add_user.php logic enforces this.
                if(!in_array($row['global_role'], $users_processed[$user_uuid]['permissions'])) { // ensure it's not duplicately added
                    $users_processed[$user_uuid]['permissions'][] = $row['global_role'];
                }
            }
        }

        // Add specific "Division - District" permission
        if (!empty($row['district_name'])) { // district_name implies division_name will also be there for a valid permission
            $permission_string = $row['division_name'] . " - " . $row['district_name'];
            if (!in_array($permission_string, $users_processed[$user_uuid]['permissions'])) {
                $users_processed[$user_uuid]['permissions'][] = $permission_string;
            }
        }
    }
    // $users_processed is now the array to loop through in the HTML table
    // If a user has no global role and no specific permissions, their 'permissions' array will be empty.

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
            <a href="assign_user_to_division.php" class="btn btn-info">Assign User to Division</a>
            <a href="add_user.php" class="btn btn-success">Add New User</a>
        <?php endif; ?>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php else: ?>
        <?php if (empty($users_processed)): ?>
            <div class="alert alert-info" role="alert">
                No users found in the system.
            </div>
        <?php else: ?>
            <table class="table table-striped table-hover">
                <thead class="table-dark">
                <tr>
                    <th>Username</th>
                    <th>Roles</th>
                    <th>Date Created</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users_processed as $user_uuid => $user_data): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user_data['username']); ?></td>
                        <td>
                            <?php
                            if (!empty($user_data['permissions'])) {
                                echo implode('<br>', array_map('htmlspecialchars', $user_data['permissions']));
                            } else {
                                echo 'N/A'; // Or leave blank if preferred: echo '';
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($user_data['created_at']))); ?></td>
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
