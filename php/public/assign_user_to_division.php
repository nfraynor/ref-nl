<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

// Ensure the user is an admin
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'super_admin' && $_SESSION['user_role'] !== 'user_admin')) {
    // Redirect to login page or show an error
    header('Location: /login.php');
    exit;
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? null;
    $division_id = $_POST['division_id'] ?? null;
    $district_id = $_POST['district_id'] ?? null;

    if ($user_id && $division_id && $district_id) {
        try {
            $pdo = Database::getConnection();
            // Check if the permission already exists
            $stmt = $pdo->prepare("SELECT 1 FROM user_permissions WHERE user_id = ? AND division_id = ? AND district_id = ?");
            $stmt->execute([$user_id, $division_id, $district_id]);
            if ($stmt->fetch()) {
                $error_message = 'This user already has this permission.';
            } else {
                // Add the new permission
                $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, division_id, district_id) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $division_id, $district_id]);
                $success_message = 'User successfully assigned to the division and district.';
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error_message = 'Please select a user, division, and district.';
    }
}

// Fetch users, divisions, and districts for the dropdowns
try {
    $pdo = Database::getConnection();
    $users = $pdo->query("SELECT uuid, username FROM users ORDER BY username ASC")->fetchAll();
    $divisions = $pdo->query("SELECT id, name FROM divisions ORDER BY name ASC")->fetchAll();
    // Districts will be loaded dynamically via AJAX based on division selection
} catch (PDOException $e) {
    $error_message = 'Failed to load data for the form: ' . $e->getMessage();
    $users = [];
    $divisions = [];
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div class="container">
    <h1>Assign User to Division/District</h1>

    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form action="assign_user_to_division.php" method="POST">
        <div class="mb-3">
            <label for="user_id" class="form-label">User</label>
            <select name="user_id" id="user_id" class="form-select" required>
                <option value="">Select User</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo htmlspecialchars($user['uuid']); ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="division_id" class="form-label">Division</label>
            <select name="division_id" id="division_id" class="form-select" required>
                <option value="">Select Division</option>
                <?php foreach ($divisions as $division): ?>
                    <option value="<?php echo htmlspecialchars($division['id']); ?>"><?php echo htmlspecialchars($division['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label for="district_id" class="form-label">District</label>
            <select name="district_id" id="district_id" class="form-select" required>
                <option value="">Select Division First</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Assign</button>
    </form>
</div>

<script>
    document.getElementById('division_id').addEventListener('change', function() {
        var divisionId = this.value;
        var districtSelect = document.getElementById('district_id');
        districtSelect.innerHTML = '<option value="">Loading...</option>';

        if (divisionId) {
            fetch('/ajax/district_options.php?division_id=' + divisionId)
                .then(response => response.json())
                .then(data => {
                    districtSelect.innerHTML = '<option value="">Select District</option>';
                    data.forEach(function(district) {
                        var option = document.createElement('option');
                        option.value = district.id;
                        option.textContent = district.name;
                        districtSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error fetching districts:', error);
                    districtSelect.innerHTML = '<option value="">Failed to load districts</option>';
                });
        } else {
            districtSelect.innerHTML = '<option value="">Select Division First</option>';
        }
    });
</script>

<?php
require_once 'includes/footer.php';
?>
