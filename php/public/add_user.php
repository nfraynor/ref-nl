<?php
require_once __DIR__ . '/../utils/session_auth.php';
// Ensure user is admin or super_admin
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'super_admin' && $_SESSION['user_role'] !== 'user_admin')) {
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
$global_role_form = 'none';
$user_division_ids_form = [];
$user_district_ids_form = [];

/** Helpers (no typed hints to avoid older PHP parse issues) */
$colExists = function($pdo, $table, $col) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
};
$pickCol = function($pdo, $table, $candidates) use ($colExists) {
    foreach ($candidates as $c) {
        if ($colExists($pdo, $table, $c)) return $c;
    }
    return null;
};

try {
    // Divisions are known: id + name
    $stmt_divisions = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC");
    $divisions = $stmt_divisions->fetchAll();

    // Detect districts PK
    $districtPK = $pickCol($pdo, 'districts', ['id','uuid']);
    if (!$districtPK) {
        throw new RuntimeException("districts primary key column not found (expected 'id' or 'uuid').");
    }

    // Detect ways to link districts -> divisions
    $districtDivisionIdFK   = $pickCol($pdo, 'districts', ['division_id']);      // numeric FK
    $districtDivisionUuidFK = $pickCol($pdo, 'districts', ['division_uuid']);    // UUID FK
    $districtDivisionNameFK = $pickCol($pdo, 'districts', ['division_name','division']); // name FK

    // Build the list of districts for the UI, aliasing to id + division_id for grouping
    if ($districtDivisionIdFK) {
        // Straight numeric FK
        $sql = "
            SELECT {$districtPK} AS id, name, {$districtDivisionIdFK} AS division_id
            FROM districts
            ORDER BY {$districtDivisionIdFK}, name ASC
        ";
    } elseif ($districtDivisionNameFK) {
        // Link by name to get a concrete divisions.id
        $sql = "
            SELECT dists.{$districtPK} AS id,
                   dists.name,
                   divs.id AS division_id
            FROM districts dists
            LEFT JOIN divisions divs ON divs.name = dists.{$districtDivisionNameFK}
            ORDER BY divs.name IS NULL, divs.name, dists.name
        ";
    } elseif ($districtDivisionUuidFK) {
        // We have a UUID FK but no divisions.uuid in your divisions (you only showed id+name).
        // Try to map via a divisions.uuid if it exists; otherwise show Unknown Division in UI.
        $divisionsUuidCol = $pickCol($pdo, 'divisions', ['uuid']);
        if ($divisionsUuidCol) {
            $sql = "
                SELECT dists.{$districtPK} AS id,
                       dists.name,
                       divs.id AS division_id
                FROM districts dists
                LEFT JOIN divisions divs ON divs.{$divisionsUuidCol} = dists.{$districtDivisionUuidFK}
                ORDER BY divs.name IS NULL, divs.name, dists.name
            ";
        } else {
            // No way to map; fall back to NULL division_id for display
            $sql = "
                SELECT {$districtPK} AS id, name, NULL AS division_id
                FROM districts
                ORDER BY name ASC
            ";
        }
    } else {
        // No FK at all; still let the UI render (under "Unknown Division")
        $sql = "
            SELECT {$districtPK} AS id, name, NULL AS division_id
            FROM districts
            ORDER BY name ASC
        ";
    }

    $stmt_districts = $pdo->query($sql);
    $districts = $stmt_districts->fetchAll();

} catch (PDOException $e) {
    $error_message = "Error fetching divisions or districts for form: " . $e->getMessage();
} catch (Throwable $e) {
    $error_message = "Error preparing form: " . $e->getMessage();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
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
        try {
            $pdo->beginTransaction();

            // UUID-ish
            $bytes = bin2hex(random_bytes(16));
            $user_uuid_actual = sprintf('%s-%s-%s-%s-%s',
                substr($bytes, 0, 8),
                substr($bytes, 8, 4),
                substr($bytes, 12, 4),
                substr($bytes, 16, 4),
                substr($bytes, 20, 12)
            );
            if (strlen($user_uuid_actual) > 36) $user_uuid_actual = substr($user_uuid_actual, 0, 36);

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role_to_insert = (empty($global_role_form) || $global_role_form === 'none') ? null : $global_role_form;

            $stmt_user = $pdo->prepare("INSERT INTO users (uuid, username, password_hash, role)
                                        VALUES (:uuid, :username, :password_hash, :role)");
            $stmt_user->execute([
                ':uuid' => $user_uuid_actual,
                ':username' => $username_form,
                ':password_hash' => $password_hash,
                ':role' => $role_to_insert
            ]);

            // Only add district permissions if no global role
            if ($role_to_insert === null && !empty($user_district_ids_form)) {
                $stmt_permission = $pdo->prepare("
                    INSERT INTO user_permissions (user_id, division_id, district_id)
                    VALUES (:user_id, :division_id, :district_id)
                ");

                // Re-detect linking columns for mapping
                $districtPK = $pickCol($pdo, 'districts', ['id','uuid']);
                $districtDivisionIdFK   = $pickCol($pdo, 'districts', ['division_id']);
                $districtDivisionUuidFK = $pickCol($pdo, 'districts', ['division_uuid']);
                $districtDivisionNameFK = $pickCol($pdo, 'districts', ['division_name','division']);
                $divisionsUuidCol       = $pickCol($pdo, 'divisions', ['uuid']); // optional

                // Prepare lookups based on what exists
                if ($districtDivisionIdFK) {
                    $stmt_get_div = $pdo->prepare("
                        SELECT {$districtDivisionIdFK} AS division_id
                        FROM districts
                        WHERE {$districtPK} = :district_id
                    ");
                } elseif ($districtDivisionNameFK) {
                    $stmt_get_div = $pdo->prepare("
                        SELECT dv.id AS division_id
                        FROM districts ds
                        JOIN divisions dv ON dv.name = ds.{$districtDivisionNameFK}
                        WHERE ds.{$districtPK} = :district_id
                    ");
                } elseif ($districtDivisionUuidFK && $divisionsUuidCol) {
                    $stmt_get_div = $pdo->prepare("
                        SELECT dv.id AS division_id
                        FROM districts ds
                        JOIN divisions dv ON dv.{$divisionsUuidCol} = ds.{$districtDivisionUuidFK}
                        WHERE ds.{$districtPK} = :district_id
                    ");
                } else {
                    $stmt_get_div = null; // no way to map; we will skip with logging
                }

                $inserted = [];
                foreach ($user_district_ids_form as $dist_id) {
                    $division_id = null;

                    if ($stmt_get_div) {
                        $stmt_get_div->execute([':district_id' => $dist_id]);
                        $row = $stmt_get_div->fetch();
                        if ($row && !empty($row['division_id'])) {
                            $division_id = $row['division_id'];
                        }
                    }

                    if ($division_id === null) {
                        error_log("add_user.php: could not resolve division_id for district {$dist_id}; skipping permission for user {$user_uuid_actual}");
                        continue; // skip this district; cannot insert without division_id
                    }

                    $key = $division_id . '-' . $dist_id;
                    if (!isset($inserted[$key])) {
                        $stmt_permission->execute([
                            ':user_id'     => $user_uuid_actual,
                            ':division_id' => $division_id,
                            ':district_id' => $dist_id
                        ]);
                        $inserted[$key] = true;
                    }
                }
            }

            $pdo->commit();
            $success_message = "User '{$username_form}' added successfully!";
            // Clear form fields
            $username_form = '';
            $global_role_form = 'none';
            $user_division_ids_form = [];
            $user_district_ids_form = [];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) {
                $error_message = "Error adding user: Username '{$username_form}' already exists.";
            } else {
                $error_message = "Database error during user creation: " . $e->getMessage();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
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
            <legend class="h6">District Permissions (Active if Global Role is 'None')</legend>
            <div class="mb-3">
                <label class="form-label">Districts (Select the districts the user should have access to. Access to the parent division is implied.)</label>
                <?php if (!empty($districts)):
                    $current_division_id_for_layout = null;
                    foreach ($districts as $district):
                        if ($district['division_id'] !== $current_division_id_for_layout) {
                            if ($current_division_id_for_layout !== null) echo '</div>'; // close previous group
                            $division_name_for_layout = 'Unknown Division';
                            foreach($divisions as $div) {
                                if ($div['id'] == $district['division_id']) { $division_name_for_layout = $div['name']; break; }
                            }
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
                    if ($current_division_id_for_layout !== null) echo '</div>'; // close last group
                    ?>
                <?php elseif (empty($error_message)): ?>
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
                if (!isNoneRole) cb.checked = false;
            });
        }

        globalRoleSelect.addEventListener('change', togglePermissionsFieldset);
        togglePermissionsFieldset(); // initial state
    });
</script>

<?php
require_once 'includes/footer.php';
?>
