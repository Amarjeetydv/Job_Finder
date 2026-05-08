<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function ensureSavedJobsTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS saved_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        job_id INT NOT NULL,
        saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        UNIQUE KEY unique_saved_job (user_id, job_id)
    )");
}

function ensureJobsTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(120) NOT NULL,
        company VARCHAR(120) NOT NULL,
        location VARCHAR(60) NOT NULL,
        job_type VARCHAR(40) NOT NULL,
        salary VARCHAR(80) NOT NULL,
        description TEXT NOT NULL,
        posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

ensureJobsTable($conn);
ensureSavedJobsTable($conn);

$userId = (int) $_SESSION['user_id'];
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$savedJobsStmt = $conn->prepare(
    'SELECT j.id, j.title, j.company, j.location, j.job_type, j.salary, j.description, s.saved_at
     FROM saved_jobs s
     INNER JOIN jobs j ON j.id = s.job_id
     WHERE s.user_id = ?
     ORDER BY s.saved_at DESC'
);
$savedJobsStmt->bind_param('i', $userId);
$savedJobsStmt->execute();
$savedJobs = $savedJobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$savedJobsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Jobs - Job Finder</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="font-awesome/css/font-awesome.css">
    <style>
        body { background: #f7f9ff; }
        .saved-shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 34px 20px 60px;
        }
        .saved-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .saved-head h1 {
            color: #1a1f47;
            font-size: 34px;
        }
        .saved-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .saved-link-btn {
            border: 1px solid #d9e0ef;
            background: white;
            color: #1a1f47;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
        }
        .saved-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
            gap: 18px;
        }
        .saved-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e3e9f5;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(20, 33, 61, 0.08);
        }
        .saved-card h3 {
            margin-bottom: 8px;
            color: #15203a;
            font-size: 20px;
        }
        .saved-meta {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .saved-date {
            color: #b91c1c;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
            display: block;
        }
        .saved-card p {
            color: #475569;
            line-height: 1.6;
        }
        .saved-card-actions {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .saved-card-actions form {
            margin: 0;
        }
        .saved-empty {
            background: white;
            border: 1px dashed #d8e0eb;
            color: #64748b;
            border-radius: 14px;
            padding: 22px;
        }
    </style>
</head>
<body>
    <div class="saved-shell">
        <div class="saved-head">
            <h1><i class="fa fa-heart"></i> Saved Jobs</h1>
            <div class="saved-actions">
                <a href="index.php" class="saved-link-btn"><i class="fa fa-search"></i> Browse Jobs</a>
                <a href="profile.php" class="saved-link-btn"><i class="fa fa-user"></i> Profile</a>
            </div>
        </div>

        <?php if ($flashMessage): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flashMessage); ?></div>
        <?php endif; ?>

        <?php if ($savedJobs): ?>
            <div class="saved-grid">
                <?php foreach ($savedJobs as $job): ?>
                    <article class="saved-card">
                        <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                        <div class="saved-meta"><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?></div>
                        <div class="saved-meta"><?php echo htmlspecialchars($job['job_type']); ?> • <?php echo htmlspecialchars($job['salary']); ?></div>
                        <span class="saved-date">Saved on <?php echo htmlspecialchars(date('M d, Y', strtotime($job['saved_at']))); ?></span>
                        <p><?php echo htmlspecialchars($job['description']); ?></p>
                        <div class="saved-card-actions">
                            <form method="POST" action="save_job.php">
                                <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                <input type="hidden" name="action" value="unsave">
                                <input type="hidden" name="return_to" value="saved_jobs.php">
                                <button type="submit" class="withdraw-btn"><i class="fa fa-bookmark"></i> Remove</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="saved-empty">You have not saved any jobs yet. Use the heart/bookmark button on job cards to save jobs.</div>
        <?php endif; ?>
    </div>
</body>
</html>
