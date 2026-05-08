<?php
session_start();
require_once 'db.php';
require_once 'ui_helpers.php';
require_once 'ui_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function ensureUsersTableExtensions(mysqli $conn): void
{
    $roleColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    if ($roleColumn && $roleColumn->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN role ENUM('job_seeker', 'employer', 'admin') NOT NULL DEFAULT 'job_seeker'");
    } else {
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('job_seeker', 'employer', 'admin') NOT NULL DEFAULT 'job_seeker'");
    }
}

function ensureJobsTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employer_id INT DEFAULT NULL,
        title VARCHAR(120) NOT NULL,
        company VARCHAR(120) NOT NULL,
        company_description TEXT DEFAULT NULL,
        location VARCHAR(60) NOT NULL,
        job_type VARCHAR(40) NOT NULL,
        salary VARCHAR(80) NOT NULL,
        description TEXT NOT NULL,
        approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $approvalColumn = $conn->query("SHOW COLUMNS FROM jobs LIKE 'approval_status'");
    if ($approvalColumn && $approvalColumn->num_rows === 0) {
        $conn->query("ALTER TABLE jobs ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }
}

ensureUsersTableExtensions($conn);
ensureJobsTable($conn);

$userId = (int) $_SESSION['user_id'];
$roleStmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
$roleStmt->bind_param('i', $userId);
$roleStmt->execute();
$roleRow = $roleStmt->get_result()->fetch_assoc() ?: [];
$roleStmt->close();

if (($roleRow['role'] ?? '') !== 'admin') {
    $_SESSION['flash_message'] = 'Only admins can access job approvals.';
    header('Location: index.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jobId = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;
    $action = trim($_POST['action'] ?? '');
    $nextStatus = '';

    if ($action === 'approve') {
        $nextStatus = 'approved';
    } elseif ($action === 'reject') {
        $nextStatus = 'rejected';
    }

    if ($jobId > 0 && $nextStatus !== '') {
        $updateStmt = $conn->prepare('UPDATE jobs SET approval_status = ? WHERE id = ?');
        $updateStmt->bind_param('si', $nextStatus, $jobId);
        $updateStmt->execute();
        $message = $updateStmt->affected_rows > 0 ? 'Job status updated.' : 'No changes made.';
        $updateStmt->close();
    }
}

$pendingStmt = $conn->prepare(
    "SELECT j.id, j.title, j.company, j.location, j.job_type, j.salary, j.description, j.posted_at, u.username
     FROM jobs j
     LEFT JOIN users u ON u.id = j.employer_id
     WHERE j.approval_status = 'pending'
     ORDER BY j.posted_at DESC"
);
$pendingStmt->execute();
$pendingJobs = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Job Approvals - Job Finder</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="font-awesome/css/font-awesome.css">
    <style>
        body { background: #f6f8ff; }
        .admin-shell { max-width: 1160px; margin: 0 auto; padding: 28px 18px 50px; }
        .admin-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .admin-link { border: 1px solid #cfd8ef; border-radius: 10px; padding: 10px 14px; text-decoration: none; color: #1f2b5e; background: #fff; font-weight: 600; }
        .admin-message { margin-bottom: 12px; color: #166534; }
        .admin-grid { display: grid; gap: 14px; }
        .admin-card { background: #fff; border: 1px solid #e2e8f4; border-radius: 14px; padding: 16px; box-shadow: 0 8px 20px rgba(17, 24, 39, 0.08); }
        .admin-card h3 { margin-bottom: 6px; color: #1e293b; }
        .admin-meta { color: #64748b; font-size: 14px; margin-bottom: 8px; }
        .admin-actions { margin-top: 12px; display: flex; gap: 10px; }
        .admin-actions form { margin: 0; }
        .approve-btn, .reject-btn { border: none; border-radius: 9px; padding: 10px 12px; color: #fff; cursor: pointer; font-weight: 700; }
        .approve-btn { background: #15803d; }
        .reject-btn { background: #b91c1c; }
        .empty { background: #fff; border: 1px dashed #d4dce9; border-radius: 14px; padding: 18px; color: #64748b; }
    </style>
</head>
<body>
    <div class="dashboard-layout" style="max-width: 1240px; margin: 0 auto; padding: 28px 18px 50px;">
        <?php echo renderDashboardSidebar('admin', 'approvals'); ?>
        <main class="admin-shell" style="padding: 0;">
        <div class="admin-top">
            <h1><i class="fa fa-check-square-o"></i> Job Approval Queue</h1>
            <div>
                <a class="admin-link" href="index.php"><i class="fa fa-home"></i> Back to Home</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="admin-message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($pendingJobs): ?>
            <div class="admin-grid">
                <?php foreach ($pendingJobs as $job): ?>
                    <article class="admin-card">
                        <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                        <div class="admin-meta"><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?> • <?php echo htmlspecialchars($job['job_type']); ?></div>
                        <div class="admin-meta">Salary: <?php echo htmlspecialchars($job['salary']); ?></div>
                        <div class="admin-meta">Posted by: <?php echo htmlspecialchars($job['username'] ?: 'Unknown'); ?> • <?php echo htmlspecialchars(date('M d, Y', strtotime($job['posted_at']))); ?></div>
                        <p><?php echo htmlspecialchars($job['description']); ?></p>
                        <div class="admin-actions">
                            <form method="POST">
                                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button class="approve-btn" type="submit"><i class="fa fa-check"></i> Approve</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="reject-btn" type="submit"><i class="fa fa-times"></i> Reject</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty">No pending jobs right now.</div>
        <?php endif; ?>
        </main>
    </div>
</body>
</html>
