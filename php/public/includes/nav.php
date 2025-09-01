<?php
// Ensure session is started to access session variables
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm mb-4 main-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="/index.php">Referee Assignment</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="/matches.php">Matches</a></li>
                <li class="nav-item"><a class="nav-link" href="/referees/referees.php">Referees</a></li>
                <li class="nav-item"><a class="nav-link" href="/clubs.php">Clubs</a></li>
                <li class="nav-item"><a class="nav-link" href="/divisions.php">Districts</a></li>
                <li class="nav-item"><a class="nav-link" href="/teams.php">Teams</a></li>
                <li class="nav-item"><a class="nav-link" href="/users.php">Users</a></li>
            </ul>
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
                    <li class="nav-item">
                        <span class="navbar-text me-2">
                            <a class="nav-link d-inline p-0" href="/profile.php" title="View profile">
                                <?= htmlspecialchars($_SESSION['username']); ?>
                            </a>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/login.php">Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
