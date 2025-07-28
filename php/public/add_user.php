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
$error_message = '';
$success_message = '';

// Variables to hold form data on POST to repopulate the form
$username_form = '';
$global_role_form = 'none';
$user_district_ids_form = [];

try {
    // Fetch divisions for the form
    $stmt_divisions = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC");
    $divisions = $stmt_divisions->fetchAll();

    // Fetch districts with their associated divisions
    $stmt_districts = $pdo->query("
        SELECT DISTINCT d.id, d.name, dd.division_id, divis.name AS division_name
        FROM districts d
        LEFT JOIN division_districts dd ON d.id = dd.district_id
        LEFT JOIN divisions divis ON dd.division_id = divis.id
        ORDER BY divis.name ASC, d.name ASC
    ");
    $districts = $stmt_districts->fetchAll();

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
    $user_district_ids_form = $_POST['districts'] ?? [];

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
            if ($role_to_insert === null && !empty($user_district_ids_form)) {
                $sql_permission = "INSERT INTO user_permissions (user_id, division_id, district_id) VALUES (:user_id, :division_id, :district_id)";
                $stmt_permission = $pdo->prepare($sql_permission);

                $inserted_permissions = [];
                foreach ($user_district_ids_form as $dist_id) {
                    // Fetch division_id(s) for this district
                    $stmt_get_dist_div = $pdo->prepare("
                        SELECT division_id
                        FROM division_districts
                        WHERE district_id = :district_id
                    ");
                    $stmt_get_dist_div->execute([':district_id' => $dist_id]);
                    $division_ids = $stmt_get_dist_div->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($division_ids)) {
                        foreach ($division_ids as $div_id) {
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
                    } else {
                        error_log("District ID $dist_id has no associated divisions for user $user_uuid_actual.");
                    }
                }
            }

            $pdo->commit();
            $success_message = "User '{$username_form}' added successfully!";
            $username_form = '';
            $global_role_form = 'none';
            $user_district_ids_form = [];

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
                        <label class="form-label">Select Districts (Access to associated divisions is implied)</label>
                        <?php if (!empty($districts)): ?>
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
                                    // Group districts by division
                                    $districts_by_division = [];
                                    foreach ($districts as $district) {
                                        $div_id = $district['division_id'] ?? 0;
                                        $div_name = $district['division_name'] ?? 'Unassigned Districts';
                                        if (!isset($districts_by_division[$div_id])) {
                                            $districts_by_division[$div_id] = [
                                                'name' => $div_name,
                                                'districts' => []
                                            ];
                                        }
                                        $districts_by_division[$div_id]['districts'][] = $district;
                                    }
                                    ksort($districts_by_division); // Sort by division ID
                                    ?>
                                    <?php foreach ($districts_by_division as $div_id => $div_data): ?>
                                        <tr class="division-header">
                                            <td colspan="3"><?php echo htmlspecialchars($div_data['name']); ?></td>
                                        </tr>
                                        <?php foreach ($div_data['districts'] as $district): ?>
                                            <tr>
                                                <td></td>
                                                <td><?php echo htmlspecialchars($district['name']); ?></td>
                                                <td class="text-center">
                                                    <input class="form-check-input district-checkbox" type="checkbox" name="districts[]" value="<?php echo $district['id']; ?>" id="dist_<?php echo $district['id']; ?>" <?php echo in_array($district['id'], $user_district_ids_form) ? 'checked' : ''; ?>>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php elseif (empty($error_message)): ?>
                            <p class="text-muted">No districts found. Please add districts in the system first.</p>
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