<?php
session_start();
require_once 'db.php';
require_once 'ui_helpers.php';
require_once 'ui_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

function ensureProfileTables(mysqli $conn): void
{
	$conn->query("CREATE TABLE IF NOT EXISTS user_profile (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NOT NULL UNIQUE,
		phone VARCHAR(20) DEFAULT NULL,
		bio TEXT DEFAULT NULL,
		location VARCHAR(120) DEFAULT NULL,
		website VARCHAR(255) DEFAULT NULL,
		resume_path VARCHAR(255) DEFAULT NULL,
		updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
	)");

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

function fetchProfileData(mysqli $conn, int $userId): array
{
	$stmt = $conn->prepare(
		'SELECT u.id, u.username, u.email, u.created_at,
				p.phone, p.bio, p.location, p.website, p.resume_path, p.updated_at
		 FROM users u
		 LEFT JOIN user_profile p ON p.user_id = u.id
		 WHERE u.id = ?'
	);
	$stmt->bind_param('i', $userId);
	$stmt->execute();
	$result = $stmt->get_result();
	$profile = $result->fetch_assoc() ?: [];
	$stmt->close();

	return $profile;
}

function normalizeFlashMessage(): string
{
	if (!isset($_SESSION['flash_message'])) {
		return '';
	}

	$message = (string) $_SESSION['flash_message'];
	unset($_SESSION['flash_message']);
	return $message;
}

ensureProfileTables($conn);

$userId = (int) $_SESSION['user_id'];
$currentRole = $_SESSION['role'] ?? 'job_seeker';
$isJobSeeker = $currentRole === 'job_seeker';
$isEmployer = $currentRole === 'employer';
$isAdmin = $currentRole === 'admin';
$message = normalizeFlashMessage();
$error = '';

$insertProfileStmt = $conn->prepare('INSERT IGNORE INTO user_profile (user_id) VALUES (?)');
$insertProfileStmt->bind_param('i', $userId);
$insertProfileStmt->execute();
$insertProfileStmt->close();

$profile = fetchProfileData($conn, $userId);
$formValues = [
	'username' => $profile['username'] ?? '',
	'email' => $profile['email'] ?? '',
	'phone' => $profile['phone'] ?? '',
	'bio' => $profile['bio'] ?? '',
	'location' => $profile['location'] ?? '',
	'website' => $profile['website'] ?? '',
	'resume_path' => $profile['resume_path'] ?? ''
];

$postedJobs = [];
$applicationsReceived = 0;

if ($isEmployer) {
    $postedJobsStmt = $conn->prepare(
        'SELECT j.id, j.title, j.company, j.location, j.job_type, j.salary, j.description, j.approval_status, j.posted_at,
            (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS application_count
         FROM jobs j
         WHERE j.employer_id = ?
         ORDER BY j.posted_at DESC'
    );
    $postedJobsStmt->bind_param('i', $userId);
    $postedJobsStmt->execute();
    $postedJobs = $postedJobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $postedJobsStmt->close();

    $appCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM applications a INNER JOIN jobs j ON j.id = a.job_id WHERE j.employer_id = ?');
    $appCountStmt->bind_param('i', $userId);
    $appCountStmt->execute();
    $appCountRow = $appCountStmt->get_result()->fetch_assoc() ?: ['total' => 0];
    $applicationsReceived = (int) ($appCountRow['total'] ?? 0);
    $appCountStmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
	$formValues = [
		'username' => trim($_POST['username'] ?? ''),
		'email' => trim($_POST['email'] ?? ''),
		'phone' => trim($_POST['phone'] ?? ''),
		'bio' => trim($_POST['bio'] ?? ''),
		'location' => trim($_POST['location'] ?? ''),
		'website' => trim($_POST['website'] ?? ''),
		'resume_path' => $profile['resume_path'] ?? ''
	];

	if ($formValues['username'] === '' || $formValues['email'] === '') {
		$error = 'Username and email are required.';
	} else {
		$duplicateStmt = $conn->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?');
		$duplicateStmt->bind_param('ssi', $formValues['username'], $formValues['email'], $userId);
		$duplicateStmt->execute();
		$duplicateResult = $duplicateStmt->get_result();

		if ($duplicateResult->num_rows > 0) {
			$error = 'Username or email is already in use by another account.';
		}
		$duplicateStmt->close();
	}

	if ($error === '' && isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
		$allowedExtensions = ['pdf', 'doc', 'docx'];
		$resumeExt = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));

		if (!in_array($resumeExt, $allowedExtensions, true)) {
			$error = 'Resume must be a PDF, DOC, or DOCX file.';
		} else {
			$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'resumes' . DIRECTORY_SEPARATOR;
			if (!is_dir($uploadDir)) {
				mkdir($uploadDir, 0777, true);
			}

			$safeName = 'resume_' . $userId . '_' . time() . '.' . $resumeExt;
			$destination = $uploadDir . $safeName;

			if (move_uploaded_file($_FILES['resume']['tmp_name'], $destination)) {
				$formValues['resume_path'] = 'uploads/resumes/' . $safeName;
			} else {
				$error = 'Unable to upload the resume file.';
			}
		}
	}

	if ($error === '') {
		$conn->begin_transaction();

		try {
			$userUpdate = $conn->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
			$userUpdate->bind_param('ssi', $formValues['username'], $formValues['email'], $userId);
			$userUpdate->execute();
			$userUpdate->close();

			$profileUpdate = $conn->prepare(
				'INSERT INTO user_profile (user_id, phone, bio, location, website, resume_path)
				 VALUES (?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE
					phone = VALUES(phone),
					bio = VALUES(bio),
					location = VALUES(location),
					website = VALUES(website),
					resume_path = VALUES(resume_path)'
			);
			$profileUpdate->bind_param('isssss', $userId, $formValues['phone'], $formValues['bio'], $formValues['location'], $formValues['website'], $formValues['resume_path']);
			$profileUpdate->execute();
			$profileUpdate->close();

			$conn->commit();
			$_SESSION['username'] = $formValues['username'];
			$_SESSION['flash_message'] = 'Profile updated successfully.';
			header('Location: profile.php');
			exit();
		} catch (Throwable $throwable) {
			$conn->rollback();
			$error = 'Unable to update your profile right now.';
		}
	}
}

$profile = fetchProfileData($conn, $userId);
$appliedJobs = [];
$savedJobs = [];

if ($isJobSeeker) {
    $appliedJobsStmt = $conn->prepare(
        'SELECT j.id, j.title, j.company, j.location, j.job_type, j.salary, j.description, a.status, a.applied_at
         FROM applications a
         INNER JOIN jobs j ON j.id = a.job_id
         WHERE a.user_id = ?
         ORDER BY a.applied_at DESC'
    );
    $appliedJobsStmt->bind_param('i', $userId);
    $appliedJobsStmt->execute();
    $appliedJobs = $appliedJobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $appliedJobsStmt->close();

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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Dashboard - Job Finder</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="font-awesome/css/font-awesome.css">
    <style>
        body {
            background: linear-gradient(180deg, #f7f9ff 0%, #eef2ff 100%);
        }
        .profile-shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 36px 20px 60px;
        }
        .profile-hero {
            background: linear-gradient(135deg, #242b5e 0%, #1a1f47 100%);
            color: white;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(36, 43, 94, 0.22);
            margin-bottom: 24px;
        }
        .profile-hero-top {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        .profile-hero h1 {
            font-size: 34px;
            margin-bottom: 8px;
        }
        .profile-hero p {
            max-width: 700px;
            color: rgba(255,255,255,0.82);
            line-height: 1.7;
        }
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }
        .stat-card {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 16px;
            padding: 18px;
        }
        .stat-card strong {
            display: block;
            font-size: 30px;
            margin-bottom: 6px;
        }
        .profile-grid {
            display: grid;
            grid-template-columns: minmax(320px, 1fr) minmax(320px, 1.1fr);
            gap: 24px;
            align-items: start;
        }
        .panel {
            background: white;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.08);
            border: 1px solid rgba(36, 43, 94, 0.08);
        }
        .panel h2 {
            font-size: 24px;
            margin-bottom: 18px;
            color: #1a1f47;
        }
        .message-box {
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .message-box.success {
            background: #eefaf0;
            color: #1b6b34;
            border: 1px solid #c5ead0;
        }
        .message-box.error {
            background: #fff0f0;
            color: #a12a2a;
            border: 1px solid #f0c4c4;
        }
        .profile-form {
            display: grid;
            gap: 14px;
        }
        .profile-form .field {
            display: grid;
            gap: 8px;
        }
        .profile-form label {
            font-weight: 600;
            color: #334155;
            font-size: 14px;
        }
        .profile-form input,
        .profile-form textarea {
            width: 100%;
            border: 1px solid #d9e0ef;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 15px;
            outline: none;
            background: #fafbff;
            box-sizing: border-box;
        }
        .profile-form input:focus,
        .profile-form textarea:focus {
            border-color: #242b5e;
            box-shadow: 0 0 0 4px rgba(36, 43, 94, 0.08);
            background: white;
        }
        .profile-form textarea {
            min-height: 130px;
            resize: vertical;
        }
        .profile-form .submit-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .primary-btn,
        .secondary-btn {
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }
        .primary-btn {
            background: linear-gradient(135deg, #242b5e 0%, #1a1f47 100%);
            color: white;
        }
        .secondary-btn {
            background: #f1f5f9;
            color: #334155;
        }
        .primary-btn:hover,
        .secondary-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(36, 43, 94, 0.15);
        }
        .resume-link {
            margin-top: 8px;
            display: inline-block;
            color: #1a1f47;
            font-weight: 600;
        }
        .jobs-section {
            margin-top: 24px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }
        .mini-card {
            border: 1px solid #e5eaf4;
            border-radius: 18px;
            padding: 18px;
            background: #fff;
        }
        .mini-card h3 {
            font-size: 19px;
            margin-bottom: 8px;
            color: #182033;
        }
        .mini-card .meta {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px;
            background: #eef2ff;
            color: #1a1f47;
        }
        .status-pill.pending { background: #fff4e5; color: #9a6700; }
        .status-pill.accepted { background: #e8fbef; color: #176a33; }
        .status-pill.rejected { background: #ffeaea; color: #a62e2e; }
        .card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .card-actions form {
            margin: 0;
        }
        .card-actions .withdraw-btn,
        .card-actions .save-btn {
            width: auto;
        }
        .empty-state {
            color: #64748b;
            background: #f8fafc;
            border: 1px dashed #d8e0eb;
            border-radius: 16px;
            padding: 20px;
        }
        .top-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        @media (max-width: 900px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            .profile-hero h1 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="profile-shell">
        <div class="dashboard-layout">
            <?php echo renderDashboardSidebar($currentRole, 'profile'); ?>
            <main>
        <div class="profile-hero">
            <div class="profile-hero-top">
                <div>
                    <h1>Profile Dashboard</h1>
                    <p>
                        <?php if ($isJobSeeker): ?>Manage your profile details, keep track of applications, and review the jobs you’ve saved for later.
                        <?php elseif ($isEmployer): ?>Manage your company profile, posted jobs, and incoming applications.
                        <?php else: ?>Review your account details and manage platform moderation.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="top-links">
                    <a class="secondary-btn" href="index.php"><i class="fa fa-home"></i> Home</a>
                    <a class="secondary-btn" href="logout.php"><i class="fa fa-sign-out"></i> Logout</a>
                </div>
            </div>
            <div class="profile-stats">
                <?php if ($isJobSeeker): ?>
                    <div class="stat-card"><strong><?php echo count($appliedJobs); ?></strong><span>Applied Jobs</span></div>
                    <div class="stat-card"><strong><?php echo count($savedJobs); ?></strong><span>Saved Jobs</span></div>
                    <div class="stat-card"><strong><?php echo !empty($profile['resume_path']) ? 'Yes' : 'No'; ?></strong><span>Resume Uploaded</span></div>
                <?php elseif ($isEmployer): ?>
                    <div class="stat-card"><strong><?php echo count($postedJobs); ?></strong><span>Posted Jobs</span></div>
                    <div class="stat-card"><strong><?php echo $applicationsReceived; ?></strong><span>Applications Received</span></div>
                    <div class="stat-card"><strong><?php echo !empty($profile['website']) ? 'Yes' : 'No'; ?></strong><span>Company Link</span></div>
                <?php else: ?>
                    <div class="stat-card"><strong>Admin</strong><span>Account Type</span></div>
                    <div class="stat-card"><strong>Full</strong><span>Access</span></div>
                    <div class="stat-card"><strong>Yes</strong><span>Moderation Tools</span></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message-box success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message-box error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <div class="panel">
                <h2>Edit Profile</h2>
                <form class="profile-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="field">
                        <label>Username</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($formValues['username']); ?>" required>
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($formValues['email']); ?>" required>
                    </div>
                    <div class="field">
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($formValues['phone']); ?>" placeholder="Your phone number">
                    </div>
                    <div class="field">
                        <label>Location</label>
                        <input type="text" name="location" value="<?php echo htmlspecialchars($formValues['location']); ?>" placeholder="City, country">
                    </div>
                    <div class="field">
                        <label>Website</label>
                        <input type="url" name="website" value="<?php echo htmlspecialchars($formValues['website']); ?>" placeholder="https://your-site.com">
                    </div>
                    <div class="field">
                        <label>Bio</label>
                        <textarea name="bio" placeholder="Write a short professional bio"><?php echo htmlspecialchars($formValues['bio']); ?></textarea>
                    </div>
                    <div class="field">
                        <label>Resume Upload</label>
                        <input type="file" name="resume" accept=".pdf,.doc,.docx">
                        <?php if (!empty($formValues['resume_path'])): ?>
                            <a class="resume-link" href="<?php echo htmlspecialchars($formValues['resume_path']); ?>" target="_blank" rel="noopener">View current resume</a>
                        <?php endif; ?>
                    </div>
                    <div class="submit-row">
                        <button class="primary-btn" type="submit"><i class="fa fa-save"></i> Save Changes</button>
                        <a class="secondary-btn" href="index.php"><i class="fa fa-search"></i> Back to Jobs</a>
                    </div>
                </form>
            </div>

            <div class="panel">
                <?php if ($isJobSeeker): ?>
                    <h2>Applied Jobs</h2>
                    <?php if ($appliedJobs): ?>
                        <div class="jobs-grid">
                            <?php foreach ($appliedJobs as $job): ?>
                                <article class="mini-card">
                                    <span class="status-pill <?php echo htmlspecialchars($job['status']); ?>"><?php echo ucfirst(htmlspecialchars($job['status'])); ?></span>
                                    <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="meta"><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?></div>
                                    <div class="meta"><?php echo htmlspecialchars($job['job_type']); ?> • <?php echo htmlspecialchars($job['salary']); ?></div>
                                    <p><?php echo htmlspecialchars($job['description']); ?></p>
                                    <div class="card-actions">
                                        <span class="meta">Applied <?php echo htmlspecialchars(date('M d, Y', strtotime($job['applied_at']))); ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">You have not applied for any jobs yet.</div>
                    <?php endif; ?>
                <?php elseif ($isEmployer): ?>
                    <h2>Posted Jobs</h2>
                    <?php if ($postedJobs): ?>
                        <div class="jobs-grid">
                            <?php foreach ($postedJobs as $job): ?>
                                <article class="mini-card">
                                    <span class="status-pill <?php echo htmlspecialchars($job['approval_status']); ?>"><?php echo htmlspecialchars(ucfirst($job['approval_status'])); ?></span>
                                    <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="meta"><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?></div>
                                    <div class="meta"><?php echo htmlspecialchars($job['job_type']); ?> • <?php echo htmlspecialchars($job['salary']); ?></div>
                                    <p><?php echo htmlspecialchars($job['description']); ?></p>
                                    <div class="card-actions">
                                        <span class="meta"><?php echo (int) $job['application_count']; ?> application(s)</span>
                                        <a class="secondary-btn" href="applications_dashboard.php?job_id=<?php echo (int) $job['id']; ?>">View Applications</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">You have not posted any jobs yet.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <h2>Admin Overview</h2>
                    <div class="empty-state">Admin accounts do not show seeker applications or saved jobs here. Use the approval and moderation pages to manage the platform.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isJobSeeker): ?>
            <div class="jobs-section">
                <div class="panel">
                    <h2>Saved Jobs</h2>
                    <?php if ($savedJobs): ?>
                        <div class="jobs-grid">
                            <?php foreach ($savedJobs as $job): ?>
                                <article class="mini-card">
                                    <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="meta"><?php echo htmlspecialchars($job['company']); ?> • <?php echo htmlspecialchars($job['location']); ?></div>
                                    <div class="meta"><?php echo htmlspecialchars($job['job_type']); ?> • <?php echo htmlspecialchars($job['salary']); ?></div>
                                    <p><?php echo htmlspecialchars($job['description']); ?></p>
                                    <div class="card-actions">
                                        <form method="POST" action="save_job.php">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <input type="hidden" name="action" value="unsave">
                                            <input type="hidden" name="return_to" value="profile.php">
                                            <button class="withdraw-btn" type="submit"><i class="fa fa-bookmark"></i> Unsave</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No saved jobs yet. Use the Save Job button on job cards to build your shortlist.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>
