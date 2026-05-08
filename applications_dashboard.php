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
$role = $_SESSION['role'] ?? 'job_seeker';

if ($role !== 'employer' && $role !== 'admin') {
    echo "<p>Access denied. Employers only.</p>";
    exit();
}

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $appId = (int) ($_POST['application_id'] ?? 0);
    $newStatus = in_array($_POST['status'] ?? '', ['pending','accepted','rejected'], true) ? $_POST['status'] : 'pending';

    if ($appId > 0) {
        $u = $conn->prepare('UPDATE applications SET status = ? WHERE id = ?');
        $u->bind_param('si', $newStatus, $appId);
        if ($u->execute()) {
            // notify applicant by email
            $notify = $conn->prepare('SELECT u.email, u.username, j.title FROM applications a JOIN users u ON u.id = a.user_id JOIN jobs j ON j.id = a.job_id WHERE a.id = ?');
            $notify->bind_param('i', $appId);
            $notify->execute();
            $nr = $notify->get_result();
            if ($nr && $nr->num_rows > 0) {
                $row = $nr->fetch_assoc();
                $to = $row['email'];
                $subject = 'Update on your application for ' . $row['title'];
                $msg = "Hello " . $row['username'] . ",\n\nYour application status for '" . $row['title'] . "' has been updated to: " . strtoupper($newStatus) . ".\n\nRegards,\nJob Finder";
                $headers = 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n" . 'X-Mailer: PHP/' . phpversion();
                @mail($to, $subject, $msg, $headers);
            }
            $notify->close();
        }
        $u->close();
    }
}

$filterJob = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;

// Fetch jobs for this employer
$jobsStmt = $conn->prepare('SELECT id, title, company FROM jobs WHERE employer_id = ?' . ($filterJob ? ' AND id = ?' : ''));
if ($filterJob) {
    $jobsStmt->bind_param('ii', $userId, $filterJob);
} else {
    $jobsStmt->bind_param('i', $userId);
}
$jobsStmt->execute();
$jobs = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$jobsStmt->close();

// Collect applications for this employer's jobs (optionally filtered by job)
$applications = [];
$sql = 'SELECT a.id AS application_id, a.user_id, a.job_id, a.status, a.cover_letter, a.applied_at, u.username, u.email, p.resume_path, j.title, j.company
        FROM applications a
        JOIN users u ON u.id = a.user_id
        LEFT JOIN user_profile p ON p.user_id = u.id
        JOIN jobs j ON j.id = a.job_id
        WHERE j.employer_id = ?'
        . ($filterJob ? ' AND j.id = ?' : '')
        . ' ORDER BY a.applied_at DESC';

$stmt = $conn->prepare($sql);
if ($filterJob) {
    $stmt->bind_param('ii', $userId, $filterJob);
} else {
    $stmt->bind_param('i', $userId);
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function h($s) { return htmlspecialchars($s); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Applications Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-layout" style="max-width: 1240px; margin: 0 auto; padding: 28px 18px 50px;">
        <?php echo renderDashboardSidebar($role, 'applications'); ?>
        <main class="panel">
        <h1>Applications Dashboard</h1>
        <p><a href="index.php">Back to Jobs</a> | <a href="logout.php">Logout</a></p>

        <?php if (!$jobs): ?>
            <p>No job listings found. Post a job first.</p>
        <?php else: ?>
            <?php foreach ($jobs as $job): ?>
                <section style="margin-bottom:24px;">
                    <h2><?php echo h($job['title']); ?> — <?php echo h($job['company']); ?></h2>
                    <?php $found = false; foreach ($applications as $app): if ($app['job_id'] == $job['id']) { $found = true; ?>
                        <div style="border:1px solid #ddd;padding:12px;margin-bottom:8px;border-radius:8px;">
                            <strong><?php echo h($app['username']); ?> (<?php echo h($app['email']); ?>)</strong>
                            <div>Applied: <?php echo h($app['applied_at']); ?> — Status: <span style="font-weight:700;"><?php echo h($app['status']); ?></span></div>
                            <?php if (!empty($app['resume_path'])): ?><div><a href="<?php echo h($app['resume_path']); ?>" target="_blank">Download Resume</a></div><?php endif; ?>
                            <?php if (!empty($app['cover_letter'])): ?><pre style="white-space:pre-wrap;background:#f8f8f8;padding:8px;border-radius:6px;"><?php echo h($app['cover_letter']); ?></pre><?php endif; ?>
                            <div style="margin-top:8px;">
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="application_id" value="<?php echo h($app['application_id']); ?>">
                                    <select name="status">
                                        <option value="pending" <?php echo $app['status']=='pending'?'selected':''; ?>>Pending</option>
                                        <option value="accepted" <?php echo $app['status']=='accepted'?'selected':''; ?>>Accept</option>
                                        <option value="rejected" <?php echo $app['status']=='rejected'?'selected':''; ?>>Reject</option>
                                    </select>
                                    <button type="submit">Update</button>
                                </form>
                                <a href="applications_dashboard.php?view_user=<?php echo h($app['user_id']); ?>">View Profile</a>
                            </div>
                        </div>
                    <?php } endforeach; if (!$found) { echo '<div class="empty-state">No applications yet for this job.</div>'; } ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (isset($_GET['view_user'])):
            $viewUser = (int) $_GET['view_user'];
            $pstmt = $conn->prepare('SELECT u.username, u.email, p.phone, p.bio, p.location, p.website, p.resume_path FROM users u LEFT JOIN user_profile p ON p.user_id = u.id WHERE u.id = ?');
            $pstmt->bind_param('i', $viewUser);
            $pstmt->execute();
            $pr = $pstmt->get_result()->fetch_assoc();
            $pstmt->close();
            if ($pr): ?>
                <hr>
                <h3>Applicant Profile: <?php echo h($pr['username']); ?></h3>
                <div>Email: <?php echo h($pr['email']); ?></div>
                <div>Phone: <?php echo h($pr['phone']); ?></div>
                <div>Location: <?php echo h($pr['location']); ?></div>
                <div>Website: <?php echo h($pr['website']); ?></div>
                <?php if (!empty($pr['resume_path'])): ?><div><a href="<?php echo h($pr['resume_path']); ?>" target="_blank">View Resume</a></div><?php endif; ?>
                <div style="white-space:pre-wrap;margin-top:12px;">Bio: <?php echo h($pr['bio']); ?></div>
        <?php else: echo '<div class="message-box error">Applicant not found.</div>'; endif; endif; ?>

        </main>
    </div>
</body>
</html>
