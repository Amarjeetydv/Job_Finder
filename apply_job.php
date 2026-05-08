<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

function ensureApplicationsTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        job_id INT NOT NULL,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        cover_letter TEXT,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        UNIQUE KEY unique_application (user_id, job_id)
    )");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must be logged in to apply for a job.']);
    exit();
}

// Enforce role: only job seekers may apply
$currentRole = $_SESSION['role'] ?? 'job_seeker';
if ($currentRole !== 'job_seeker') {
    // For form submissions redirect back with message
    if (!empty($_POST)) {
        $_SESSION['flash_message'] = 'Only job seeker accounts can apply to jobs.';
        $return = $_POST['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');
        header('Location: ' . $return);
        exit();
    }
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only job seeker accounts can apply to jobs.']);
    exit();
}

ensureApplicationsTable($conn);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
// fallback to regular POST form data when JSON body is not provided
if ((!is_array($data) || empty($data)) && !empty($_POST)) {
    $data = $_POST;
}
$user_id = $_SESSION['user_id'];
$job_id = isset($data['job_id']) ? (int)$data['job_id'] : null;
$action = isset($data['action']) ? trim($data['action']) : 'apply';
$cover_letter = isset($data['cover_letter']) ? trim($data['cover_letter']) : '';

if (!$job_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Job ID is required.']);
    exit();
}

// Determine if this was a traditional form submit (not JSON API)
$isForm = !empty($_POST) && (empty($raw) || json_decode($raw) === null);
$returnTo = trim($data['return_to'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
if ($returnTo === '' || strpos($returnTo, '://') !== false || str_starts_with($returnTo, '//')) {
    $returnTo = 'index.php';
}

// Verify job exists and is approved
$verify_stmt = $conn->prepare("SELECT id, approval_status FROM jobs WHERE id = ?");
$verify_stmt->bind_param("i", $job_id);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();

if ($verify_result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Job not found.']);
    $verify_stmt->close();
    exit();
}
$jobRow = $verify_result->fetch_assoc();
$verify_stmt->close();

if ($action === 'apply' && ($jobRow['approval_status'] ?? 'pending') !== 'approved') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This job is not open for applications yet.']);
    exit();
}

if ($action === 'apply') {
    // Check if already applied for this job
    $check_stmt = $conn->prepare("SELECT id FROM applications WHERE user_id = ? AND job_id = ?");
    $check_stmt->bind_param("ii", $user_id, $job_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You have already applied for this job.']);
        $check_stmt->close();
        exit();
    }
    $check_stmt->close();

    // Insert application
    $insert_stmt = $conn->prepare("INSERT INTO applications (user_id, job_id, cover_letter) VALUES (?, ?, ?)");
    $insert_stmt->bind_param("iis", $user_id, $job_id, $cover_letter);

    if ($insert_stmt->execute()) {
        $applicationId = $insert_stmt->insert_id;

        // Fetch employer email and job/applicant details to notify employer
        $job_notify_stmt = $conn->prepare(
            'SELECT j.id AS job_id, j.title, j.company, emp.email AS employer_email, u.username AS applicant_name, u.email AS applicant_email
             FROM jobs j
             LEFT JOIN users emp ON emp.id = j.employer_id
             LEFT JOIN users u ON u.id = ?
             WHERE j.id = ?'
        );
        $job_notify_stmt->bind_param('ii', $user_id, $job_id);
        $job_notify_stmt->execute();
        $notify_result = $job_notify_stmt->get_result();

        if ($notify_result && $notify_result->num_rows > 0) {
            $notifyRow = $notify_result->fetch_assoc();
            $employerEmail = $notifyRow['employer_email'];
            if (!empty($employerEmail)) {
                $subject = 'New application for: ' . $notifyRow['title'];
                $message = "Hello,\n\nYou have received a new application for your job listing '" . $notifyRow['title'] . "' at " . $notifyRow['company'] . ".\n\n";
                $message .= "Applicant: " . $notifyRow['applicant_name'] . " (" . $notifyRow['applicant_email'] . ")\n";
                if (!empty($cover_letter)) {
                    $message .= "\nCover Letter:\n" . $cover_letter . "\n\n";
                }
                $message .= "View applications: " . (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/applications_dashboard.php?job_id=' . $job_id) : 'applications_dashboard.php?job_id=' . $job_id) . "\n\nRegards,\nJob Finder\n";

                $headers = 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n" .
                    'Reply-To: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n" .
                    'X-Mailer: PHP/' . phpversion();

                @mail($employerEmail, $subject, $message, $headers);
            }
        }
        $job_notify_stmt->close();

        $msg = 'Application submitted successfully!';
        if ($isForm) {
            $_SESSION['flash_message'] = $msg;
            header('Location: ' . $returnTo);
            exit();
        }
        echo json_encode([
            'success' => true,
            'message' => $msg,
            'application_id' => $applicationId
        ]);
    } else {
        http_response_code(500);
        if ($isForm) {
            $_SESSION['flash_message'] = 'Error submitting application.';
            header('Location: ' . $returnTo);
            exit();
        }
        echo json_encode(['success' => false, 'message' => 'Error submitting application: ' . $conn->error]);
    }
    $insert_stmt->close();

} elseif ($action === 'withdraw') {
    // Withdraw application
    $withdraw_stmt = $conn->prepare("DELETE FROM applications WHERE user_id = ? AND job_id = ?");
    $withdraw_stmt->bind_param("ii", $user_id, $job_id);

    if ($withdraw_stmt->execute()) {
        if ($withdraw_stmt->affected_rows > 0) {
            $msg = 'Application withdrawn successfully.';
            if ($isForm) {
                $_SESSION['flash_message'] = $msg;
                header('Location: ' . $returnTo);
                exit();
            }
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            if ($isForm) {
                $_SESSION['flash_message'] = 'No application found to withdraw.';
                header('Location: ' . $returnTo);
                exit();
            }
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No application found to withdraw.']);
        }
    } else {
        if ($isForm) {
            $_SESSION['flash_message'] = 'Error withdrawing application.';
            header('Location: ' . $returnTo);
            exit();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error withdrawing application: ' . $conn->error]);
    }
    $withdraw_stmt->close();

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

$conn->close();
?>
