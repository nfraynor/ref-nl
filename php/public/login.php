<?php
// Start the session at the very beginning
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$error_message = '';

// Check if the user is already logged in, if so, redirect to index.php
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve username and password from POST request
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        try {
            // Include database configuration
            $config = require __DIR__ . '/../config/database.php';

            // DSN (Data Source Name)
            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";

            // PDO options
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            // Create a new PDO instance
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);

            // Prepare and execute SQL query to fetch user
            $stmt = $pdo->prepare("
                SELECT u.uuid, u.username, u.password_hash, u.role, GROUP_CONCAT(DISTINCT d.id) AS division_ids, GROUP_CONCAT(DISTINCT dist.id) AS district_ids
                FROM users u
                LEFT JOIN user_permissions up ON u.uuid = up.user_id
                LEFT JOIN divisions d ON up.division_id = d.id
                LEFT JOIN districts dist ON up.district_id = dist.id
                WHERE u.username = :username
                GROUP BY u.uuid, u.username, u.password_hash, u.role
            ");
            $stmt->bindParam(':username', $username);
            $stmt->execute();

            $user = $stmt->fetch();

            if ($user) {
                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Password is correct, regenerate session ID
                    session_regenerate_id(true);

                    // Store user details in session
                    $_SESSION['user_id'] = $user['uuid']; // uuid is the primary key
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role']; // Store the global role
                    $_SESSION['division_ids'] = ($user['role'] === 'super_admin') ? [] : explode(',', $user['division_ids'] ?? ''); // Super_admin has all divisions/districts implicitly
                    $_SESSION['district_ids'] = ($user['role'] === 'super_admin') ? [] : explode(',', $user['district_ids'] ?? ''); // Super_admin has all divisions/districts implicitly

                    // Redirect to a dashboard or home page
                    header("Location: index.php");
                    exit;
                } else {
                    // Password does not match
                    $error_message = "Invalid username or password.";
                }
            } else {
                // User not found
                $error_message = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            // Database connection or query error
            // $error_message = "Database error: " . $e->getMessage(); // For debugging
            $error_message = "An error occurred. Please try again later."; // User-friendly message
        } catch (Exception $e) {
            // Other errors (e.g., config file missing)
            $error_message = "An unexpected error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Referee Assignment System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom Login CSS -->
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <h1 class="text-center mb-4 login-page-title">Referee Assignment System</h1>
                <div class="card login-card">
                    <div class="card-body p-4">
                        <h5 class="card-title text-center mb-4">Login</h5>

                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="login.php">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle (includes Popper) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
