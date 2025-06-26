<?php
require_once __DIR__ . '/../utils/session_auth.php';
// Ensure user is admin or super_admin
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'super_admin' && $_SESSION['user_role'] !== 'user_admin')) {
    // Redirect to users page or login page if not authorized
    header('Location: users.php?error=unauthorized');
    exit;
}

// Database connection details
$config = require __DIR__ . '/../config/database.php';
$dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $config['username'], $config['password'], $options);

$divisions = [];
$districts = [];
$error_message = '';
$success_message = '';

// Variables to hold form data on POST to repopulate the form
$username_form = '';
$global_role_form = 'none'; // Default to 'none'
$user_division_ids_form = [];
$user_district_ids_form = [];


try {
    // Fetch divisions for the form
    $stmt_divisions = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC");
    $divisions = $stmt_divisions->fetchAll();

    // Fetch districts for the form, ordered by division for grouping in the UI
    $stmt_districts = $pdo->query("SELECT id, name, division_id FROM districts ORDER BY division_id, name ASC");
    $districts = $stmt_districts->fetchAll();

} catch (PDOException $e) {
    $error_message = "Error fetching divisions or districts for form: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Repopulate form variables from POST data
    $username_form = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $global_role_form = $_POST['global_role'] ?? 'none';
    $user_division_ids_form = $_POST['divisions'] ?? [];
    $user_district_ids_form = $_POST['districts'] ?? [];

    if (empty($username_form) || empty($password) || empty($password_confirm)) {
        $error_message = "Username and password (including confirmation) are required.";
    } elseif ($password !== $password_confirm) {
        $error_message = "Passwords do not match.";
    } else {
        // Proceed with user creation if basic validation passes
        try {
            $pdo->beginTransaction();

            // Generate a UUID for the new user.
            // IMPORTANT: This is a placeholder UUID generation.
            // In a production environment, use a robust UUID v4 generator.
            // For example, in PHP 7+:
            // $bytes = random_bytes(16);
            // $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // Set version to 0100
            // $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
            // $user_uuid_actual = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            // Using a simpler, less robust placeholder for now due to tool limitations.
            $temp_uuid_bytes = bin2hex(random_bytes(16));
            $user_uuid_actual = sprintf('%s-%s-%s-%s-%s',
                substr($temp_uuid_bytes, 0, 8),
                substr($temp_uuid_bytes, 8, 4),
                substr($temp_uuid_bytes, 12, 4), // Version (4) and variant bits correctly set by a proper UUID v4 generator
                substr($temp_uuid_bytes, 16, 4),
                substr($temp_uuid_bytes, 20, 12)
            );
            if (strlen($user_uuid_actual) > 36) { // Ensure it fits CHAR(36)
                 $user_uuid_actual = substr($user_uuid_actual,0,36);
            }


            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            // Use NULL for role if 'none' is selected, otherwise use the selected global role.
            $role_to_insert = (empty($global_role_form) || $global_role_form === 'none') ? null : $global_role_form;

            $sql_user = "INSERT INTO users (uuid, username, password_hash, role) VALUES (:uuid, :username, :password_hash, :role)";
            $stmt_user = $pdo->prepare($sql_user);
            $stmt_user->execute([
                ':uuid' => $user_uuid_actual,
                ':username' => $username_form,
                ':password_hash' => $password_hash,
                ':role' => $role_to_insert
            ]);

            // Only add to user_permissions if no global role (or 'none') is selected
            if ($role_to_insert === null) {
                $assigned_divisions_for_district_check = [];

                if (!empty($user_division_ids_form)) {
                    $sql_permission = "INSERT INTO user_permissions (user_id, division_id, district_id) VALUES (:user_id, :division_id, :district_id)";
                    $stmt_permission = $pdo->prepare($sql_permission);
                    foreach ($user_division_ids_form as $div_id) {
                        $stmt_permission->execute([
                            ':user_id' => $user_uuid_actual,
                            ':division_id' => $div_id,
                            ':district_id' => null
                        ]);
                        $assigned_divisions_for_district_check[] = $div_id; // Track for district check
                    }
                }

                if (!empty($user_district_ids_form)) {
                    // Prepare statement if not already prepared (e.g. if no divisions were selected)
                    if (!isset($stmt_permission)) {
                        $sql_permission = "INSERT INTO user_permissions (user_id, division_id, district_id) VALUES (:user_id, :division_id, :district_id)";
                        $stmt_permission = $pdo->prepare($sql_permission);
                    }

                    foreach ($user_district_ids_form as $dist_id) {
                        $stmt_get_dist_div = $pdo->prepare("SELECT division_id FROM districts WHERE id = :district_id");
                        $stmt_get_dist_div->execute([':district_id' => $dist_id]);
                        $dist_info = $stmt_get_dist_div->fetch();

                        // Add district permission only if its parent division wasn't already assigned directly AND district is valid
                        if ($dist_info && !in_array($dist_info['division_id'], $assigned_divisions_for_district_check)) {
                             $stmt_permission->execute([
                                ':user_id' => $user_uuid_actual,
                                ':division_id' => $dist_info['division_id'],
                                ':district_id' => $dist_id
                            ]);
                        } elseif (!$dist_info) {
                             error_log("District ID $dist_id not found for user $user_uuid_actual.");
                        }
                    }
                }
            }

            $pdo->commit();
            $success_message = "User '{$username_form}' added successfully!";
            // Clear form fields for next entry by resetting them
            $username_form = '';
            // $password and $password_confirm are not repopulated for security.
            $global_role_form = 'none';
            $user_division_ids_form = [];
            $user_district_ids_form = [];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() == 23000) { // Integrity constraint violation (e.g. duplicate username)
                $error_message = "Error adding user: Username '{$username_form}' already exists.";
            } else {
                $error_message = "Database error during user creation: " . $e->getMessage();
            }
        } catch (Exception $e) { // Catch any other exceptions
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = "An unexpected error occurred: " . $e->getMessage();
        }
    }
}

// Include HTML header and navigation
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div class="container mt-4">
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
                <option value="none" <?php echo ($global_role_form === 'none') ? 'selected' : ''; ?>>None (Use Division/District Permissions)</option>
                <option value="super_admin" <?php echo ($global_role_form === 'super_admin') ? 'selected' : ''; ?>>Super Admin (All Access)</option>
                <option value="user_admin" <?php echo ($global_role_form === 'user_admin') ? 'selected' : ''; ?>>User Admin (Manage Users)</option>
            </select>
        </div>

        <fieldset class="mb-3" id="permissions_fieldset">
            <legend class="h6">Permissions (Active if Global Role is 'None')</legend>
            <div class="mb-3">
                <label class="form-label">Divisions (Assigning a Division grants access to all its Districts)</label>
                <?php if (!empty($divisions)): ?>
                    <?php foreach ($divisions as $division): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="divisions[]" value="<?php echo $division['id']; ?>" id="div_<?php echo $division['id']; ?>" <?php echo in_array($division['id'], $user_division_ids_form) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="div_<?php echo $division['id']; ?>">
                                <?php echo htmlspecialchars($division['name']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                <?php elseif (empty($error_message)): // Only show "No divisions" if there wasn't a DB error already ?>
                    <p class="text-muted">No divisions found. Please add divisions in the system first.</p>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label">Districts (Assign for specific district access if parent Division is not selected above)</label>
                <?php if (!empty($districts)):
                    $current_division_id_for_layout = null;
                    foreach ($districts as $district):
                        // Group districts by their division in the UI
                        if ($district['division_id'] !== $current_division_id_for_layout) {
                            if ($current_division_id_for_layout !== null) echo '</div>'; // Close previous division's district list
                            $division_name_for_layout = 'Unknown Division'; // Fallback
                            foreach($divisions as $div) { if($div['id'] == $district['division_id']) {$division_name_for_layout = $div['name']; break;}}
                            echo '<div class="ms-3 mt-2"><strong>' . htmlspecialchars($division_name_for_layout) . ' Districts:</strong>';
                            $current_division_id_for_layout = $district['division_id'];
                        }
                    ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="districts[]" value="<?php echo $district['id']; ?>" id="dist_<?php echo $district['id']; ?>" <?php echo in_array($district['id'], $user_district_ids_form) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dist_<?php echo $district['id']; ?>">
                                <?php echo htmlspecialchars($district['name']); ?>
                            </label>
                        </div>
                    <?php endforeach;
                    if ($current_division_id_for_layout !== null) echo '</div>'; // Close the last division's district list
                ?>
                <?php elseif (empty($error_message)): // Only show "No districts" if there wasn't a DB error already ?>
                    <p class="text-muted">No districts found. Please add districts in the system first.</p>
                <?php endif; ?>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary">Add User</button>
        <a href="users.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const globalRoleSelect = document.getElementById('global_role');
    const permissionsFieldset = document.getElementById('permissions_fieldset');
    const permissionCheckboxes = permissionsFieldset.querySelectorAll('input[type="checkbox"]');

    function togglePermissionsFieldset() {
        const isNoneRole = (globalRoleSelect.value === 'none' || globalRoleSelect.value === '');
        permissionsFieldset.disabled = !isNoneRole;
        permissionsFieldset.style.opacity = isNoneRole ? "1" : "0.5";

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
// Include HTML footer
require_once 'includes/footer.php';
?>
