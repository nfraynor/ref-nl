<?php
require_once __DIR__ . '/../utils/session_auth.php';
// Ensure user is admin or super_admin
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'super_admin' && $_SESSION['user_role'] !== 'user_admin')) {
    header('Location: users.php?error=unauthorized');
    exit;
}

// Database connection
require_once __DIR__ . '/../utils/db.php';
$pdo = Database::getConnection();

$divisions = [];
$districts = [];
$permission_pairs = []; // To store division-district pairs
$error_message = '';
$success_message = '';

// Variables to hold form data on POST to repopulate the form
$username_form = '';
$global_role_form = 'none'; // Default to 'none'
$user_permission_pairs_form = []; // Array of "div_id:dist_id"

try {
    // Fetch divisions for the form
    $stmt_divisions = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC");
    $divisions = $stmt_divisions->fetchAll();

    // Fetch districts for the form
    $stmt_districts = $pdo->query("SELECT id, name FROM districts ORDER BY name ASC");
    $districts = $stmt_districts->fetchAll();

    // Fetch all division-district pairs for the permissions table
    $stmt_pairs = $pdo->query("
        SELECT dd.division_id, dd.district_id, divis.name AS division_name, d.name AS district_name
        FROM division_districts dd
        JOIN divisions divis ON dd.division_id = divis.id
        JOIN districts d ON dd.district_id = d.id
        ORDER BY divis.name ASC, d.name ASC
    ");
    $permission_pairs = $stmt_pairs->fetchAll();

} catch (PDOException $e) {
    $error_message = "Error fetching divisions or districts for form: " . $e->getMessage();
    error_log($error_message);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Repopulate form variables from POST data
    $username_form = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $global_role_form = $_POST['global_role'] ?? 'none';
    $user_permission_pairs_form = $_POST['permissions'] ?? [];

    if (empty($username_form) || empty($password) || empty($password_confirm)) {
        $error_message = "Username and password (including confirmation) are required.";
    } elseif ($password !== $password_confirm) {
        $error_message = "Passwords do not match.";
    } else {
        try {
            $pdo->beginTransaction();

            // Generate a UUID for the new user
            $temp_uuid_bytes = bin2hex(random_bytes(16));
            $user_uuid_actual = sprintf('%s-%s-%s-%s-%s',
                substr($temp_uuid_bytes, 0, 8),
                substr($temp_uuid_bytes, 8, 4),
                substr($temp_uuid_bytes, 12, 4),
                substr($temp_uuid_bytes, 16, 4),
                substr($temp_uuid_bytes, 20, 12)
            );
            if (strlen($user_uuid_actual) > 36) {
                $user_uuid_actual = substr($user_uuid_actual, 0, 36);
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role_to_insert = ($global_role_form === 'none') ? null : $global_role_form;

            // Insert user
            $sql_user = "INSERT INTO users (uuid, username, password_hash, role) VALUES (:uuid, :username, :password_hash, :role)";
            $stmt_user = $pdo->prepare($sql_user);
            $stmt_user->execute([
                ':uuid' => $user_uuid_actual,
                ':username' => $username_form,
                ':password_hash' => $password_hash,
                ':role' => $role_to_insert
            ]);

            // Only add to user_permissions if no global role
            if ($role_to_insert === null && !empty($user_permission_pairs_form)) {
                $sql_permission = "INSERT INTO user_permissions (user_id, division_id, district_id) VALUES (:user_id, :division_id, :district_id)";
                $stmt_permission = $pdo->prepare($sql_permission);

                $inserted_permissions = [];
                foreach ($user_permission_pairs_form as $pair) {
                    list($div_id, $dist_id) = explode(':', $pair);
                    $permission_key = $div_id . '-' . $dist_id;
                    if (!isset($inserted_permissions[$permission_key])) {
                        $stmt_permission->execute([
                            ':user_id' => $user_uuid_actual,
                            ':division_id' => $div_id,
                            ':district_id' => $dist_id
                        ]);
                        $inserted_permissions[$permission_key] = true;
                    }
                }
            }

            $pdo->commit();
            $success_message = "User '{$username_form}' added successfully!";
            $username_form = '';
            $global_role_form = 'none';
            $user_permission_pairs_form = [];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() == 23000) {
                $error_message = "Error adding user: Username '{$username_form}' already exists.";
            } else {
                $error_message = "Database error during user creation: " . $e->getMessage();
            }
            error_log($error_message);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "An unexpected error occurred: " . $e->getMessage();
            error_log($error_message);
        }
    }
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

    <div class="container mt-4">
        <div class="content-card">
            <h2>Add New User</h2>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>

            <form action="add_user.php" method="POST" id="addUserForm">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" value="<?php echo htmlspecialchars($username_form); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="mb-3">
                    <label for="password_confirm" class="form-label">Confirm Password</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                </div>
                <div class="mb-3">
                    <label for="global_role" class="form-label">Global Role</label>
                    <select class="form-select" id="global_role" name="global_role">
                        <option value="none" <?php echo ($global_role_form === 'none') ? 'selected' : ''; ?>>None (Use District Permissions)</option>
                        <option value="super_admin" <?php echo ($global_role_form === 'super_admin') ? 'selected' : ''; ?>>Super Admin (All Access)</option>
                        <option value="user_admin" <?php echo ($global_role_form === 'user_admin') ? 'selected' : ''; ?>>User Admin (Manage Users)</option>
                    </select>
                </div>

                <fieldset class="mb-3" id="permissions_fieldset">
                    <legend class="h6">District Permissions (Active if Global Role is 'None')</legend>
                    <div class="mb-3">
                        <label class="form-label">Select Division-District Combinations (Access to the specific division-district pair)</label>
                        <?php if (!empty($permission_pairs)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered table-hover district-permissions-table">
                                    <thead>
                                    <tr>
                                        <th scope="col">Division</th>
                                        <th scope="col">District</th>
                                        <th scope="col" class="text-center">Select</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    // Group pairs by division
                                    $pairs_by_division = [];
                                    foreach ($permission_pairs as $pair) {
                                        $div_id = $pair['division_id'] ?? 0;
                                        $div_name = $pair['division_name'] ?? 'Unassigned Divisions';
                                        if (!isset($pairs_by_division[$div_id])) {
                                            $pairs_by_division[$div_id] = [
                                                'name' => $div_name,
                                                'pairs' => []
                                            ];
                                        }
                                        $pairs_by_division[$div_id]['pairs'][] = $pair;
                                    }
                                    ksort($pairs_by_division); // Sort by division ID
                                    ?>
                                    <?php foreach ($pairs_by_division as $div_id => $div_data): ?>
                                        <tr class="division-header">
                                            <td colspan="3"><?php echo htmlspecialchars($div_data['name']); ?></td>
                                        </tr>
                                        <?php foreach ($div_data['pairs'] as $pair): ?>
                                            <tr>
                                                <td></td>
                                                <td><?php echo htmlspecialchars($pair['district_name']); ?></td>
                                                <td class="text-center">
                                                    <input class="form-check-input district-checkbox" type="checkbox" name="permissions[]" value="<?php echo $pair['division_id'] . ':' . $pair['district_id']; ?>" id="perm_<?php echo $pair['division_id'] . '_' . $pair['district_id']; ?>" <?php echo in_array($pair['division_id'] . ':' . $pair['district_id'], $user_permission_pairs_form) ? 'checked' : ''; ?>>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (empty($error_message)): ?>
                            <p class="text-muted">No division-district pairs found. Please add districts and link them to divisions first.</p>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-main-action">Add User</button>
                    <a href="users.php" class="btn btn-secondary-action">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const globalRoleSelect = document.getElementById('global_role');
            const permissionsFieldset = document.getElementById('permissions_fieldset');
            const permissionCheckboxes = permissionsFieldset.querySelectorAll('.district-checkbox');

            function togglePermissionsFieldset() {
                const isNoneRole = (globalRoleSelect.value === 'none' || globalRoleSelect.value === '');
                permissionsFieldset.disabled = !isNoneRole;
                permissionsFieldset.style.opacity = isNoneRole ? '1' : '0.5';

                permissionCheckboxes.forEach(cb => {
                    cb.disabled = !isNoneRole;
                    if (!isNoneRole) {
                        cb.checked = false; // Uncheck if global role is selected
                    }
                });
            }

            globalRoleSelect.addEventListener('change', togglePermissionsFieldset);
            togglePermissionsFieldset(); // Call on page load to set initial state
        });
    </script>

<?php
require_once 'includes/footer.php';
?>