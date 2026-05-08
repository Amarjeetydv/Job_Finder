<?php
// CLI test: create employer, job, applicant and submit application; log email content.
chdir(__DIR__ . '/..');
require_once 'db.php';

function info($s) { echo $s . PHP_EOL; }

$employerEmail = 'amarjeet@gmail.com';
$employerPassword = '123456';
$employerName = 'Amarjeet Employer';

$applicantEmail = 'testseeker@example.com';
$applicantPassword = 'password';
$applicantName = 'Test Seeker';

// Ensure users table has needed columns (login.php does similar)
$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('job_seeker','employer','admin') NOT NULL DEFAULT 'job_seeker'") or true;

// Create or find employer
$stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
$stmt->bind_param('s', $employerEmail);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $employerId = (int)$row['id'];
    info("Found employer id=$employerId");
} else {
    $hash = password_hash($employerPassword, PASSWORD_DEFAULT);
    $ins = $conn->prepare('INSERT INTO users (username,email,password,role) VALUES (?, ?, ?, "employer")');
    $ins->bind_param('sss', $employerName, $employerEmail, $hash);
    if ($ins->execute()) {
        $employerId = $ins->insert_id;
        info("Created employer id=$employerId");
    } else {
        die('Unable to create employer: ' . $conn->error . PHP_EOL);
    }
    $ins->close();
}
$stmt->close();

// Create or find a job for this employer
$jobTitle = 'Test Job for Email';
$jstmt = $conn->prepare('SELECT id FROM jobs WHERE employer_id = ? AND title = ?');
$jstmt->bind_param('is', $employerId, $jobTitle);
$jstmt->execute();
$jr = $jstmt->get_result();
if ($r = $jr->fetch_assoc()) {
    $jobId = (int)$r['id'];
    info("Found job id=$jobId");
} else {
    $insj = $conn->prepare('INSERT INTO jobs (employer_id,title,company,location,job_type,salary,description,approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, "approved")');
    $company = 'Acme Test Co'; $location='Remote'; $job_type='Full Time'; $salary='$0'; $desc='Test job used for email notification.';
    $insj->bind_param('issssss', $employerId, $jobTitle, $company, $location, $job_type, $salary, $desc);
    if ($insj->execute()) {
        $jobId = $insj->insert_id;
        info("Created job id=$jobId");
    } else {
        die('Unable to create job: ' . $conn->error . PHP_EOL);
    }
    $insj->close();
}
$jstmt->close();

// Create or find applicant
$astmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
$astmt->bind_param('s', $applicantEmail);
$astmt->execute();
$ar = $astmt->get_result();
if ($a = $ar->fetch_assoc()) {
    $applicantId = (int)$a['id'];
    info("Found applicant id=$applicantId");
} else {
    $hash = password_hash($applicantPassword, PASSWORD_DEFAULT);
    $insa = $conn->prepare('INSERT INTO users (username,email,password,role) VALUES (?, ?, ?, "job_seeker")');
    $insa->bind_param('sss', $applicantName, $applicantEmail, $hash);
    if ($insa->execute()) {
        $applicantId = $insa->insert_id;
        info("Created applicant id=$applicantId");
    } else {
        die('Unable to create applicant: ' . $conn->error . PHP_EOL);
    }
    $insa->close();
}
$astmt->close();

// Insert application if not exists
$check = $conn->prepare('SELECT id FROM applications WHERE user_id = ? AND job_id = ?');
$check->bind_param('ii', $applicantId, $jobId);
$check->execute();
$cr = $check->get_result();
if ($c = $cr->fetch_assoc()) {
    $applicationId = (int)$c['id'];
    info("Application already exists id=$applicationId");
} else {
    $cover = 'Hello, I am interested in this test role.';
    $insApp = $conn->prepare('INSERT INTO applications (user_id, job_id, cover_letter) VALUES (?, ?, ?)');
    $insApp->bind_param('iis', $applicantId, $jobId, $cover);
    if ($insApp->execute()) {
        $applicationId = $insApp->insert_id;
        info("Inserted application id=$applicationId");
    } else {
        die('Unable to create application: ' . $conn->error . PHP_EOL);
    }
    $insApp->close();
}
$check->close();

// Build email as apply_job would
$q = $conn->prepare('SELECT u.email AS employer_email, u.username AS employer_name, j.title, j.company FROM jobs j JOIN users u ON u.id = j.employer_id WHERE j.id = ?');
$q->bind_param('i', $jobId);
$q->execute();
$qr = $q->get_result()->fetch_assoc();
$q->close();

$em = $qr['employer_email'] ?? $employerEmail;
$subject = 'New application for: ' . ($qr['title'] ?? $jobTitle);
$message = "Hello,\n\nYou have received a new application for your job listing '" . ($qr['title'] ?? $jobTitle) . "' at " . ($qr['company'] ?? '') . ".\n\n";
$message .= "Applicant: $applicantName ($applicantEmail)\n";
$message .= "\nCover Letter:\n" . $cover . "\n\n";
$message .= "Regards,\nJob Finder\n";

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/email.log';
$entry = "---\nTo: $em\nSubject: $subject\n\n$message\n";
file_put_contents($logFile, $entry, FILE_APPEND);
info("Logged email to $logFile");

// attempt to send via mail() — may fail if server not configured
if (@mail($em, $subject, $message, 'From: no-reply@localhost')) {
    info('PHP mail() returned success (may still be queued by system).');
} else {
    info('PHP mail() returned failure (server likely not configured).');
}

info('Test complete.');

?>
