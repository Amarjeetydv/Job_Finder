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

    $companyNameColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'company_name'");
    if ($companyNameColumn && $companyNameColumn->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN company_name VARCHAR(160) DEFAULT NULL");
    }

    $companyDescriptionColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'company_description'");
    if ($companyDescriptionColumn && $companyDescriptionColumn->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN company_description TEXT DEFAULT NULL");
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

    $employerColumn = $conn->query("SHOW COLUMNS FROM jobs LIKE 'employer_id'");
    if ($employerColumn && $employerColumn->num_rows === 0) {
        $conn->query("ALTER TABLE jobs ADD COLUMN employer_id INT DEFAULT NULL");
    }
    $companyDescriptionColumn = $conn->query("SHOW COLUMNS FROM jobs LIKE 'company_description'");
    if ($companyDescriptionColumn && $companyDescriptionColumn->num_rows === 0) {
        $conn->query("ALTER TABLE jobs ADD COLUMN company_description TEXT DEFAULT NULL");
    }
    $approvalColumn = $conn->query("SHOW COLUMNS FROM jobs LIKE 'approval_status'");
    if ($approvalColumn && $approvalColumn->num_rows === 0) {
        $conn->query("ALTER TABLE jobs ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending'");
    }
}

ensureUsersTableExtensions($conn);
ensureJobsTable($conn);

$userId = (int) $_SESSION['user_id'];

$roleStmt = $conn->prepare('SELECT role, company_name, company_description FROM users WHERE id = ?');
$roleStmt->bind_param('i', $userId);
$roleStmt->execute();
$user = $roleStmt->get_result()->fetch_assoc() ?: [];
$roleStmt->close();

if (($user['role'] ?? 'job_seeker') !== 'employer') {
    // For logged-in non-employer users, show a friendly notice page
    // instead of bouncing them back to the homepage.
    $notice = 'Only employer accounts can post jobs.';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Post a Job — Access Restricted</title>
        <link rel="stylesheet" type="text/css" href="style.css">
        <style>
            .notice-wrap{max-width:700px;margin:80px auto;padding:24px;border:1px solid #eee;border-radius:8px;background:#fff;text-align:left}
            .notice-wrap h2{margin-top:0;color:#222}
            .notice-actions{margin-top:18px;display:flex;gap:12px}
            .notice-actions a{padding:10px 14px;border-radius:6px;text-decoration:none}
            .btn-primary{background:#242b5e;color:#fff}
            .btn-outline{border:1px solid #ccc;color:#333}
        </style>
    </head>
    <body>
    <div class="notice-wrap">
        <h2>Employer Account Required</h2>
        <p><?php echo htmlspecialchars($notice); ?></p>
        <p>If you want to post jobs, convert your account to an employer or contact our support team for assistance.</p>
        <div class="notice-actions">
            <a class="btn-primary" href="profile.php">Go to Profile</a>
            <a class="btn-outline" href="mailto:support@jobfinder.com">Contact Support</a>
            <a class="btn-outline" href="index.php">Back to Home</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit();
}

$message = '';
$error = '';

$form = [
    'title' => '',
    'company' => (string) ($user['company_name'] ?? ''),
    'company_description' => (string) ($user['company_description'] ?? ''),
    'location' => '',
    'job_type' => '',
    'salary' => '',
    'description' => ''
];

$editJobId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $jobId = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;

    if ($action === 'delete' && $jobId > 0) {
        $deleteStmt = $conn->prepare('DELETE FROM jobs WHERE id = ? AND employer_id = ?');
        $deleteStmt->bind_param('ii', $jobId, $userId);
        $deleteStmt->execute();
        $message = $deleteStmt->affected_rows > 0 ? 'Job deleted successfully.' : 'Job not found or not owned by you.';
        $deleteStmt->close();
    }

    if ($action === 'save') {
        $form = [
            'title' => trim($_POST['title'] ?? ''),
            'company' => trim($_POST['company'] ?? ''),
            'company_description' => trim($_POST['company_description'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'job_type' => trim($_POST['job_type'] ?? ''),
            'salary' => trim($_POST['salary'] ?? ''),
            'description' => trim($_POST['description'] ?? '')
        ];

        if (
            $form['title'] === '' ||
            $form['company'] === '' ||
            $form['location'] === '' ||
            $form['job_type'] === '' ||
            $form['salary'] === '' ||
            $form['description'] === ''
        ) {
            $error = 'All fields except company description are required.';
        } else {
            if ($jobId > 0) {
                $updateStmt = $conn->prepare(
                    "UPDATE jobs
                     SET title = ?, company = ?, company_description = ?, location = ?, job_type = ?, salary = ?, description = ?
                     WHERE id = ? AND employer_id = ?"
                );
                $updateStmt->bind_param(
                    'sssssssii',
                    $form['title'],
                    $form['company'],
                    $form['company_description'],
                    $form['location'],
                    $form['job_type'],
                    $form['salary'],
                    $form['description'],
                    $jobId,
                    $userId
                );
                $updateStmt->execute();
                $message = $updateStmt->affected_rows >= 0 ? 'Job updated successfully.' : 'Unable to update this job.';
                $updateStmt->close();
            } else {
                $approvalStatus = 'pending';
                $insertStmt = $conn->prepare(
                    'INSERT INTO jobs (employer_id, title, company, company_description, location, job_type, salary, description, approval_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insertStmt->bind_param(
                    'issssssss',
                    $userId,
                    $form['title'],
                    $form['company'],
                    $form['company_description'],
                    $form['location'],
                    $form['job_type'],
                    $form['salary'],
                    $form['description'],
                    $approvalStatus
                );
                $insertStmt->execute();
                $message = 'Job posted and sent for admin approval.';
                $insertStmt->close();
            }

            $companyUpdate = $conn->prepare('UPDATE users SET company_name = ?, company_description = ? WHERE id = ?');
            $companyUpdate->bind_param('ssi', $form['company'], $form['company_description'], $userId);
            $companyUpdate->execute();
            $companyUpdate->close();

            $_SESSION['company_name'] = $form['company'];
            $_SESSION['company_description'] = $form['company_description'];
        }
    }
}

if ($editJobId > 0) {
    $editStmt = $conn->prepare(
        'SELECT id, title, company, company_description, location, job_type, salary, description
         FROM jobs WHERE id = ? AND employer_id = ?'
    );
    $editStmt->bind_param('ii', $editJobId, $userId);
    $editStmt->execute();
    $editRow = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if ($editRow) {
        $form = [
            'title' => $editRow['title'],
            'company' => $editRow['company'],
            'company_description' => $editRow['company_description'] ?? '',
            'location' => $editRow['location'],
            'job_type' => $editRow['job_type'],
            'salary' => $editRow['salary'],
            'description' => $editRow['description']
        ];
    } else {
        $editJobId = 0;
    }
}

$jobsStmt = $conn->prepare(
    'SELECT id, title, company, location, job_type, salary, approval_status, posted_at
     FROM jobs
     WHERE employer_id = ?
     ORDER BY posted_at DESC'
);
$jobsStmt->bind_param('i', $userId);
$jobsStmt->execute();
$myJobs = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$jobsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Job - Job Finder</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="font-awesome/css/font-awesome.css">
    <style>
        body { background: #f6f8ff; }
        .post-shell { max-width: 1180px; margin: 0 auto; padding: 28px 18px 55px; }
        .post-grid { display: grid; grid-template-columns: minmax(320px, 1fr) minmax(320px, 1.1fr); gap: 20px; }
        .post-card { background: #fff; border: 1px solid #e6eaf5; border-radius: 16px; padding: 18px; box-shadow: 0 8px 22px rgba(17, 24, 39, 0.08); }
        .post-title { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .post-form { display: grid; gap: 10px; }
        .post-form input, .post-form textarea, .post-form select { width: 100%; border: 1px solid #dce3f1; border-radius: 10px; padding: 11px 12px; box-sizing: border-box; }
        .post-form textarea { min-height: 100px; resize: vertical; }
        .post-btn { border: none; border-radius: 10px; padding: 10px 14px; background: #1f2b5e; color: #fff; cursor: pointer; font-weight: 700; }
        .post-btn.secondary { background: #eef2ff; color: #1f2b5e; border: 1px solid #cdd7f5; }
        .post-message { margin-bottom: 10px; color: #166534; }
        .post-error { margin-bottom: 10px; color: #b91c1c; }
        .my-job { border: 1px solid #e3e8f3; border-radius: 14px; padding: 14px; margin-bottom: 12px; }
        .status { font-size: 12px; font-weight: 700; color: #334155; background: #eaf0ff; border-radius: 999px; padding: 5px 10px; display: inline-block; margin-bottom: 8px; }
        .status.pending { background: #fff6db; color: #9a6700; }
        .status.approved { background: #e9faef; color: #166534; }
        .status.rejected { background: #ffe7e7; color: #b91c1c; }
        .job-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .job-actions form { margin: 0; }
        @media (max-width: 920px) { .post-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="dashboard-layout" style="max-width: 1240px; margin: 0 auto; padding: 28px 18px 50px;">
        <?php echo renderDashboardSidebar($user['role'] ?? 'employer', 'post_job'); ?>
        <main class="post-shell" style="padding: 0;">
        <div class="post-title">
            <h1><?php echo $editJobId > 0 ? 'Edit Job' : 'Post a New Job'; ?></h1>
            <div>
                <a class="post-btn secondary" href="index.php"><i class="fa fa-home"></i> Home</a>
                <a class="post-btn secondary" href="profile.php"><i class="fa fa-user"></i> Profile</a>
            </div>
        </div>

        <div class="post-grid">
            <div class="post-card">
                <?php if ($message): ?><div class="post-message"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                <?php if ($error): ?><div class="post-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <form class="post-form" method="POST">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="job_id" value="<?php echo $editJobId; ?>">
                    <input type="text" name="title" placeholder="Job title" value="<?php echo htmlspecialchars($form['title']); ?>" required>
                    <input type="text" name="company" placeholder="Company name" value="<?php echo htmlspecialchars($form['company']); ?>" required>
                    <textarea name="company_description" placeholder="Company description"><?php echo htmlspecialchars($form['company_description']); ?></textarea>
                    <input type="text" name="location" placeholder="Location (e.g., BD, US)" value="<?php echo htmlspecialchars($form['location']); ?>" required>
                    <select name="job_type" required>
                        <option value="">Select job type</option>
                        <option value="Full Time" <?php echo $form['job_type'] === 'Full Time' ? 'selected' : ''; ?>>Full Time</option>
                        <option value="Part Time" <?php echo $form['job_type'] === 'Part Time' ? 'selected' : ''; ?>>Part Time</option>
                        <option value="Remote" <?php echo $form['job_type'] === 'Remote' ? 'selected' : ''; ?>>Remote</option>
                        <option value="Contract" <?php echo $form['job_type'] === 'Contract' ? 'selected' : ''; ?>>Contract</option>
                    </select>
                    <input type="text" name="salary" placeholder="Salary range" value="<?php echo htmlspecialchars($form['salary']); ?>" required>
                    <textarea name="description" placeholder="Job description" required><?php echo htmlspecialchars($form['description']); ?></textarea>
                    <button class="post-btn" type="submit"><i class="fa fa-save"></i> <?php echo $editJobId > 0 ? 'Update Job' : 'Publish Job'; ?></button>
                </form>
            </div>

            <div class="post-card">
                <h2>Your Job Posts</h2>
                <?php if ($myJobs): ?>
                    <?php foreach ($myJobs as $job): ?>
                        <article class="my-job">
                            <span class="status <?php echo htmlspecialchars($job['approval_status']); ?>"><?php echo htmlspecialchars(ucfirst($job['approval_status'])); ?></span>
                            <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                            <p><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?></p>
                            <p><?php echo htmlspecialchars($job['job_type']); ?> • <?php echo htmlspecialchars($job['salary']); ?></p>
                            <p>Posted <?php echo htmlspecialchars(date('M d, Y', strtotime($job['posted_at']))); ?></p>
                            <div class="job-actions">
                                <a class="post-btn secondary" href="post_job.php?edit=<?php echo $job['id']; ?>"><i class="fa fa-pencil"></i> Edit</a>
                                <form method="POST">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                    <button class="withdraw-btn" type="submit"><i class="fa fa-trash"></i> Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-results">No job posts yet.</div>
                <?php endif; ?>
            </div>
        </div>
        </main>
    </div>
</body>
</html>
