<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/nav.php';

$pdo = Database::getConnection();

$userUuid   = $_SESSION['user_id'] ?? null;
$username   = $_SESSION['username'] ?? '';
$errors     = [];
$successMsg = '';

// --- CSRF helpers ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function checkCsrfToken($token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf           = $_POST['csrf_token'] ?? '';
    $current        = $_POST['current_password'] ?? '';
    $new            = $_POST['new_password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    if (!checkCsrfToken($csrf)) {
        $errors[] = 'Invalid request token. Please try again.';
    }

    // Basic validations
    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All fields are required.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }
    if ($new === $current && $new !== '') {
        $errors[] = 'New password must be different from your current password.';
    }
    // Password policy (tweak as you like)
    if (strlen($new) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if (!$userUuid) {
        $errors[] = 'Not authenticated.';
    }

    if (!$errors) {
        // Fetch current hash
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE uuid = ?");
        $stmt->execute([$userUuid]);
        $row = $stmt->fetch();

        if (!$row) {
            $errors[] = 'User not found.';
        } else {
            $hash = $row['password_hash'] ?? '';
            if (!password_verify($current, $hash)) {
                $errors[] = 'Your current password is incorrect.';
            } else {
                // Rehash if algorithm changed
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $hash = password_hash($current, PASSWORD_DEFAULT);
                    $upd  = $pdo->prepare("UPDATE users SET password_hash = ? WHERE uuid = ?");
                    $upd->execute([$hash, $userUuid]);
                }

                if (!$errors) {
                    $newHash = password_hash($new, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE uuid = ?");
                    $upd->execute([$newHash, $userUuid]);

                    // Optional: regenerate session id after credential change
                    session_regenerate_id(true);

                    $successMsg = 'Your password has been updated.';
                    // Rotate CSRF token after successful POST
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            }
        }
    }
}
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h1 class="h4 mb-0">Your Profile</h1>
                </div>
                <div class="card-body">
                    <p class="mb-3"><strong>Username:</strong> <?= htmlspecialchars($username) ?></p>

                    <?php if (!empty($successMsg)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li><?= htmlspecialchars($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <h2 class="h5 mt-3">Change Password</h2>
                    <form method="POST" action="/profile.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password">
                            <div class="form-text">
                                At least 10 characters, with upper &amp; lower case and a number.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
