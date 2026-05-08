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

ensureSavedJobsTable($conn);

$userId = (int) $_SESSION['user_id'];
$jobId = isset($_POST['job_id']) ? (int) $_POST['job_id'] : 0;
$action = trim($_POST['action'] ?? 'save');
$returnTo = trim($_POST['return_to'] ?? 'index.php');

if ($returnTo === '' || strpos($returnTo, '://') !== false || str_starts_with($returnTo, '//')) {
    $returnTo = 'index.php';
}

if ($returnTo[0] === '/') {
    $returnTo = ltrim($returnTo, '/');
}

if ($jobId <= 0) {
    $_SESSION['flash_message'] = 'Invalid job selection.';
    header('Location: ' . $returnTo);
    exit();
}

$jobStmt = $conn->prepare('SELECT id FROM jobs WHERE id = ?');
$jobStmt->bind_param('i', $jobId);
$jobStmt->execute();
$jobResult = $jobStmt->get_result();

if ($jobResult->num_rows === 0) {
    $_SESSION['flash_message'] = 'Job not found.';
    $jobStmt->close();
    header('Location: ' . $returnTo);
    exit();
}
$jobStmt->close();

if ($action === 'unsave') {
    $deleteStmt = $conn->prepare('DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?');
    $deleteStmt->bind_param('ii', $userId, $jobId);

    if ($deleteStmt->execute()) {
        $_SESSION['flash_message'] = $deleteStmt->affected_rows > 0 ? 'Job removed from saved jobs.' : 'This job was not saved.';
    } else {
        $_SESSION['flash_message'] = 'Unable to update saved jobs right now.';
    }
    $deleteStmt->close();
} else {
    $checkStmt = $conn->prepare('SELECT id FROM saved_jobs WHERE user_id = ? AND job_id = ?');
    $checkStmt->bind_param('ii', $userId, $jobId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $_SESSION['flash_message'] = 'Job is already saved.';
    } else {
        $insertStmt = $conn->prepare('INSERT INTO saved_jobs (user_id, job_id) VALUES (?, ?)');
        $insertStmt->bind_param('ii', $userId, $jobId);

        if ($insertStmt->execute()) {
            $_SESSION['flash_message'] = 'Job saved successfully.';
        } else {
            $_SESSION['flash_message'] = 'Unable to save this job right now.';
        }
        $insertStmt->close();
    }
    $checkStmt->close();
}

header('Location: ' . $returnTo);
exit();
