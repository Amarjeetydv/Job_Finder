<?php
session_start();
require_once 'db.php';
require_once 'ui_helpers.php';
require_once 'ui_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];

// Fetch user's applications
$stmt = $conn->prepare(
    'SELECT a.id AS application_id, a.job_id, a.status, a.cover_letter, a.applied_at, j.title, j.company, j.location
     FROM applications a
     JOIN jobs j ON j.id = a.job_id
     WHERE a.user_id = ?
     ORDER BY a.applied_at DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function h($s) { return htmlspecialchars($s); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>My Applications</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .application { border:1px solid #e3e6ee;padding:12px;border-radius:8px;margin-bottom:10px; }
    </style>
</head>
<body>
    <div class="dashboard-layout" style="max-width: 1240px; margin: 0 auto; padding: 28px 18px 50px;">
        <?php echo renderDashboardSidebar($_SESSION['role'] ?? 'job_seeker', 'my_applications'); ?>
        <main class="panel">
        <h1>My Applications</h1>
        <p><a href="profile.php">Profile</a> | <a href="index.php">Jobs</a> | <a href="logout.php">Logout</a></p>

        <?php if (!$applications): ?>
            <div class="empty-state">You have not applied to any jobs yet.</div>
        <?php else: ?>
            <?php foreach ($applications as $app): ?>
                <div class="application">
                    <h3><?php echo h($app['title']); ?> — <?php echo h($app['company']); ?></h3>
                    <div><?php echo h($app['location']); ?> • Applied <?php echo h($app['applied_at']); ?></div>
                    <div>Status: <strong><?php echo h($app['status']); ?></strong></div>
                    <?php if (!empty($app['cover_letter'])): ?><pre style="white-space:pre-wrap;background:#fafafa;padding:8px;border-radius:6px;margin-top:8px;"><?php echo h($app['cover_letter']); ?></pre><?php endif; ?>
                    <div style="margin-top:8px;">
                        <form method="POST" action="apply_job.php" style="display:inline-block;">
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="job_id" value="<?php echo h($app['job_id']); ?>">
                            <input type="hidden" name="return_to" value="my_applications.php">
                            <button type="submit">Withdraw Application</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </main>
    </div>
</body>
</html>
