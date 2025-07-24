<?php
require_once __DIR__ . '/../utils/session_auth.php';
require_once __DIR__ . '/../utils/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['districtName'])) {
    $districtName = trim($_POST['districtName']);

    if (!empty($districtName)) {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("INSERT INTO districts (name) VALUES (?)");
            $stmt->execute([$districtName]);
            header('Location: districts.php');
            exit;
        } catch (PDOException $e) {
            // Handle potential errors, like duplicate district name
            die("Error adding district: " . $e->getMessage());
        }
    }
}
?>
