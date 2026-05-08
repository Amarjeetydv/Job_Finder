<?php
session_start();
require_once 'db.php';
require_once 'ui_helpers.php';

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
		FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE SET NULL,
		posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

function seedJobsIfNeeded(mysqli $conn): void
{
	$result = $conn->query("SELECT COUNT(*) AS total FROM jobs");
	$row = $result->fetch_assoc();

	if ((int) $row['total'] > 0) {
		return;
	}

	$conn->query(<<<'SQL'
INSERT INTO jobs (title, company, location, job_type, salary, description) VALUES
('Frontend Developer', 'Bright Labs', 'BD', 'Full Time', '$1800 - $2400', 'Build responsive web interfaces and work closely with the product team.'),
('UI/UX Designer', 'Pixel Works', 'PK', 'Remote', '$1500 - $2200', 'Create user flows, wireframes, and polished interface designs.'),
('Marketing Specialist', 'Growth Forge', 'US', 'Full Time', '$2500 - $3200', 'Plan campaigns, optimize acquisition, and support launch strategy.'),
('Data Analyst', 'Insight Stack', 'UK', 'Contract', '$2200 - $2800', 'Analyze business data, build reports, and surface actionable insights.')
SQL);
}

function getCategoryDefinitions(): array
{
	return [
		'design-creative' => [
			'label' => 'Design & Creative',
			'icon' => 'fa-address-book-o',
			'pattern' => '(design|creative|ui|ux|graphic)',
		],
		'design-development' => [
			'label' => 'Design & Development',
			'icon' => 'fa-desktop',
			'pattern' => '(development|developer|frontend|backend|software|web)',
		],
		'sales-marketing' => [
			'label' => 'Sales & Marketing',
			'icon' => 'fa-bar-chart',
			'pattern' => '(sales|marketing|seo|brand|campaign|content)',
		],
		'mobile-application' => [
			'label' => 'Mobile Application',
			'icon' => 'fa-mobile',
			'pattern' => '(mobile|android|ios|app)',
		],
		'construction' => [
			'label' => 'Construction',
			'icon' => 'fa-connectdevelop',
			'pattern' => '(construction|civil|architecture|site)',
		],
		'information-technology' => [
			'label' => 'Information Technology',
			'icon' => 'fa-newspaper-o',
			'pattern' => '(it|technology|tech|data|analyst|computer)',
		],
		'real-estate' => [
			'label' => 'Real Estate',
			'icon' => 'fa-home',
			'pattern' => '(real estate|property|agent|broker)',
		],
		'content-writer' => [
			'label' => 'Content Writer',
			'icon' => 'fa-pencil-square-o',
			'pattern' => '(writer|content|copy|editor|blog)',
		],
	];
}

function countCategoryJobs(array $jobs, string $categorySlug): int
{
	$categories = getCategoryDefinitions();
	if (!isset($categories[$categorySlug])) {
		return 0;
	}

	$pattern = '/' . $categories[$categorySlug]['pattern'] . '/i';
	$count = 0;
	foreach ($jobs as $job) {
		$searchableText = strtolower(implode(' ', [
			(string) ($job['title'] ?? ''),
			(string) ($job['company'] ?? ''),
			(string) ($job['company_description'] ?? ''),
			(string) ($job['description'] ?? ''),
			(string) ($job['job_type'] ?? ''),
			(string) ($job['location'] ?? ''),
		]));

		if (preg_match($pattern, $searchableText)) {
			$count++;
		}
	}

	return $count;
}

function fetchJobs(mysqli $conn, string $keyword, string $location, string $category, int $viewerUserId, int $isAdmin): array
{
	$normalizedLocation = $location === 'all' ? '' : $location;
	$keywordFilter = '%' . $keyword . '%';
	$locationFilter = $normalizedLocation;
	$categoryDefinitions = getCategoryDefinitions();
	$categoryFilter = $category !== '' && isset($categoryDefinitions[$category]) ? $categoryDefinitions[$category]['pattern'] : '';

	$stmt = $conn->prepare(
		"SELECT id, employer_id, title, company, company_description, location, job_type, salary, description, approval_status, posted_at
		 FROM jobs
		 WHERE (? = '' OR title LIKE ? OR company LIKE ? OR description LIKE ?)
		   AND (? = '' OR location = ?)
		   AND (? = '' OR LOWER(CONCAT_WS(' ', title, company, company_description, description, job_type, location)) REGEXP ?)
		   AND (approval_status = 'approved' OR employer_id = ? OR ? = 1)
		 ORDER BY posted_at DESC"
	);
	$stmt->bind_param('ssssssssii', $keyword, $keywordFilter, $keywordFilter, $keywordFilter, $locationFilter, $locationFilter, $categoryFilter, $categoryFilter, $viewerUserId, $isAdmin);
	$stmt->execute();
	$result = $stmt->get_result();
	$jobs = $result->fetch_all(MYSQLI_ASSOC);
	$stmt->close();

	return $jobs;
}

function getAllJobs(mysqli $conn, int $viewerUserId, int $isAdmin): array
{
	$stmt = $conn->prepare(
		"SELECT id, employer_id, title, company, company_description, location, job_type, salary, description, approval_status, posted_at
		 FROM jobs
		 WHERE approval_status = 'approved' OR employer_id = ? OR ? = 1
		 ORDER BY posted_at DESC"
	);
	$stmt->bind_param('ii', $viewerUserId, $isAdmin);
	$stmt->execute();
	$result = $stmt->get_result();
	$jobs = $result->fetch_all(MYSQLI_ASSOC);
	$stmt->close();

	return $jobs;
}

function userHasApplied(mysqli $conn, int $user_id, int $job_id): bool
{
	$stmt = $conn->prepare("SELECT id FROM applications WHERE user_id = ? AND job_id = ?");
	$stmt->bind_param("ii", $user_id, $job_id);
	$stmt->execute();
	$result = $stmt->get_result();
	$has_applied = $result->num_rows > 0;
	$stmt->close();
	return $has_applied;
}

function getApplicationStatus(mysqli $conn, int $user_id, int $job_id): ?string
{
	$stmt = $conn->prepare("SELECT status FROM applications WHERE user_id = ? AND job_id = ?");
	$stmt->bind_param("ii", $user_id, $job_id);
	$stmt->execute();
	$result = $stmt->get_result();
	$status = null;
	
	if ($row = $result->fetch_assoc()) {
		$status = $row['status'];
	}
	$stmt->close();
	return $status;
}

function userHasSaved(mysqli $conn, int $user_id, int $job_id): bool
{
	$stmt = $conn->prepare("SELECT id FROM saved_jobs WHERE user_id = ? AND job_id = ?");
	$stmt->bind_param("ii", $user_id, $job_id);
	$stmt->execute();
	$result = $stmt->get_result();
	$has_saved = $result->num_rows > 0;
	$stmt->close();
	return $has_saved;
}

ensureUsersTableExtensions($conn);
ensureJobsTable($conn);
seedJobsIfNeeded($conn);
ensureApplicationsTable($conn);
ensureSavedJobsTable($conn);

$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$currentUserRole = $_SESSION['role'] ?? 'guest';
$isAdmin = $currentUserRole === 'admin' ? 1 : 0;
$keyword = trim($_GET['keyword'] ?? '');
$location = trim($_GET['location'] ?? 'all');
$category = trim($_GET['category'] ?? '');
$searchSubmitted = isset($_GET['search']) || $category !== '';
$jobs = $searchSubmitted ? fetchJobs($conn, $keyword, $location, $category, $currentUserId, $isAdmin) : [];
$allJobs = getAllJobs($conn, $currentUserId, $isAdmin);
$categoryDefinitions = getCategoryDefinitions();
$selectedCategoryLabel = $category !== '' && isset($categoryDefinitions[$category]) ? $categoryDefinitions[$category]['label'] : '';
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
$postJobUrl = 'login.php?redirect=post_job.php';
if ($currentUserId > 0) {
	$postJobUrl = $currentUserRole === 'employer' ? 'post_job.php' : 'javascript:void(0);';
}
// move the 'Only employer accounts can post jobs.' message to show beside the Post a Job button
$postJobNotice = '';
if ($flashMessage === 'Only employer accounts can post jobs.') {
	$postJobNotice = $flashMessage;
	$flashMessage = '';
}
if ($currentUserId > 0 && $currentUserRole !== 'employer' && $postJobNotice === '') {
	$postJobNotice = 'Only employer accounts can post jobs.';
}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
	<title>Job Finder - Find Your Dream Job | Job Search Platform</title>
	<link rel="stylesheet" type="text/css" href="style.css">
	<link rel="stylesheet" type="text/css" href="font-awesome\css\font-awesome.css">
</head>
<body>
<nav>
	<div class="logo">
		<img src="img/logo.png.webp">
	</div>
	<input type="checkbox" id="check">
	<div class="menu">
		<?php echo renderRoleAwareNav($currentUserRole); ?>
	</div>
		<div class="button">
			<?php if(isset($_SESSION['user_id'])): ?>
				<?php echo renderSignedInIdentity((string) $_SESSION['username'], (string) $currentUserRole, 'Welcome back'); ?>
				<a href="logout.php"><button class="btn-2">Logout</button></a>
			<?php else: ?>
				<a href="register.php"><button class="btn-1">Register</button></a>
				<a href="login.php"><button class="btn-2">Login</button></a>
			<?php endif; ?>
		</div>
			
			<label for="check">MENU <i class="fa fa-bars"></i></label>
			
</nav>

<div class="container">
	<div class="text">
		<h1>Find The Most Exiting Startup Jobs</h1>
	</div>
	<div class="input">
		<form method="GET" action="index.php" class="search-form">
			<div class="search-wrapper">
				<input type="search" id="keywordInput" name="keyword" placeholder="Job title or keyword" value="<?php echo htmlspecialchars($keyword); ?>" autocomplete="off">
				<ul id="suggestionsList" class="suggestions-list"></ul>
			</div>
			<select name="location" class="location-select">
				<option value="all" <?php echo $location === 'all' ? 'selected' : ''; ?>>All locations</option>
				<option value="BD" <?php echo $location === 'BD' ? 'selected' : ''; ?>>Location BD</option>
				<option value="PK" <?php echo $location === 'PK' ? 'selected' : ''; ?>>Location PK</option>
				<option value="US" <?php echo $location === 'US' ? 'selected' : ''; ?>>Location US</option>
				<option value="UK" <?php echo $location === 'UK' ? 'selected' : ''; ?>>Location UK</option>
			</select>
			<input type="hidden" name="search" value="1">
			<button type="submit" class="search-btn">Search Jobs</button>
		</form>
	</div>	
</div> 

	<?php if ($flashMessage): ?>
		<div class="flash-message"><?php echo htmlspecialchars($flashMessage); ?></div>
	<?php endif; ?>

<?php if ($searchSubmitted): ?>
<div class="job-search-results" id="job-search-results">
	<div class="results-header">
		<span>JOB SEARCH</span>
		<h3><?php echo $selectedCategoryLabel !== '' ? htmlspecialchars($selectedCategoryLabel) . ' Jobs' : 'Available job matches'; ?></h3>
		<p>
			<?php
			if ($selectedCategoryLabel !== '') {
				echo 'Showing jobs that match ' . htmlspecialchars($selectedCategoryLabel) . '.';
			} elseif ($keyword !== '' || $location !== 'all') {
				echo 'Showing results for your selected keyword and location.';
			} else {
				echo 'Showing all available jobs from the database.';
			}
			?>
		</p>
	</div>

	<?php if ($jobs): ?>
		<div class="results-grid">
			<?php foreach ($jobs as $job): 
				$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
				$has_applied = $user_id ? userHasApplied($conn, $user_id, $job['id']) : false;
				$application_status = $user_id && $has_applied ? getApplicationStatus($conn, $user_id, $job['id']) : null;
				$has_saved = $user_id ? userHasSaved($conn, $user_id, $job['id']) : false;
			?>
				<article class="job-card" data-job-id="<?php echo $job['id']; ?>">
					<div class="job-card-top">
						<h4><?php echo htmlspecialchars($job['title']); ?></h4>
						<span><?php echo htmlspecialchars($job['job_type']); ?></span>
					</div>
					<p class="job-company"><?php echo htmlspecialchars($job['company']); ?></p>
					<?php if (!empty($job['company_description'])): ?>
						<p class="job-meta"><?php echo htmlspecialchars($job['company_description']); ?></p>
					<?php endif; ?>
					<?php if (($currentUserRole === 'employer') && ((int) ($job['employer_id'] ?? 0) === $currentUserId)): ?>
						<p class="job-meta">Status: <?php echo htmlspecialchars(ucfirst($job['approval_status'])); ?></p>
					<?php endif; ?>
					<p class="job-meta"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($job['location']); ?></p>
					<p class="job-description"><?php echo htmlspecialchars($job['description']); ?></p>
					<div class="job-footer">
						<strong><?php echo htmlspecialchars($job['salary']); ?></strong>
						<span><?php echo htmlspecialchars(date('M d, Y', strtotime($job['posted_at']))); ?></span>
					</div>
					<div class="job-actions">
						<?php if ($user_id && $has_applied): ?>
							<button class="apply-btn applied" disabled title="Application Status: <?php echo ucfirst($application_status); ?>">
								<i class="fa fa-check"></i> Applied (<?php echo ucfirst($application_status); ?>)
							</button>
							<button class="withdraw-btn" data-job-id="<?php echo $job['id']; ?>">
								<i class="fa fa-times"></i> Withdraw
							</button>
						<?php elseif ($user_id): ?>
							<button class="apply-btn" data-job-id="<?php echo $job['id']; ?>">
								<i class="fa fa-paper-plane"></i> Apply Now
							</button>
						<?php else: ?>
							<button class="apply-btn login-required" onclick="alert('Please login to apply for jobs.')">
								<i class="fa fa-paper-plane"></i> Apply Now
							</button>
						<?php endif; ?>
						<?php if ($user_id): ?>
							<form method="POST" action="save_job.php" class="save-job-form">
								<input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
								<input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
								<input type="hidden" name="action" value="<?php echo $has_saved ? 'unsave' : 'save'; ?>">
								<button type="submit" class="save-btn <?php echo $has_saved ? 'saved' : ''; ?>">
									<i class="fa <?php echo $has_saved ? 'fa-heart' : 'fa-heart-o'; ?>"></i>
									<?php echo $has_saved ? 'Saved' : 'Bookmark'; ?>
								</button>
							</form>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php else: ?>
		<div class="no-results">
			No jobs found. Try a different keyword or location.
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>


<div class="categories" id="browse-categories">
	<span>Browse Top Categaries</span>
</div>

<div class="flex">
	<?php foreach ($categoryDefinitions as $slug => $categoryDefinition): ?>
		<a class="category-link" href="index.php?search=1&category=<?php echo urlencode($slug); ?>#job-search-results">
			<div class="item item-<?php echo htmlspecialchars((string) $slug); ?>">
				<div class="img">
					<i class="fa <?php echo htmlspecialchars($categoryDefinition['icon']); ?>"></i>
				</div>
				<div class="flex-text">
					<h3><?php echo htmlspecialchars($categoryDefinition['label']); ?></h3>
					<span>(<?php echo countCategoryJobs($allJobs, $slug); ?>)</span>
				</div>
			</div>
		</a>
	<?php endforeach; ?>

</div>

<div class="sections"><a href="index.php?search=1#job-search-results">BROWSE ALL SECTIONS</a></div>

<div class="blue">

	<div class="overlay">
		<div class="overlay-text">
			<h6>FEATURED TOURS PACKAGES</h6>
			<label>MAKE a Difference with your Online Resume!</label>
		</div>
		<div class="overlay-cta">
			<a href="<?php echo isset($_SESSION['user_id']) ? 'profile.php' : 'login.php'; ?>" class="cv-btn">Upload Your CV</a>
		</div>
	</div>
</div>

<div class="recent">
	<span>RECENT JOB</span>
	<h3>Featured Jobs</h3>
</div>
<div class="table">
	<?php 
	// Display first 4 approved jobs from database
	$featuredJobs = array_slice($allJobs, 0, 4);
	if (count($featuredJobs) > 0):
		foreach ($featuredJobs as $featuredJob):
	?>
	<div class="line-1">
		<div class="div1">
		<div class="featured-job-avatar" aria-hidden="true">
			<i class="fa fa-briefcase"></i>
		</div>
		<div class="div1-text">	
		<h2><?php echo htmlspecialchars($featuredJob['title']); ?></h2>
			<label class="text"><?php echo htmlspecialchars($featuredJob['company']); ?></label>
			<label class="country"><i class="fa fa-map-marker"></i> <?php echo htmlspecialchars($featuredJob['location']); ?></label>
			<label class="num"><?php echo htmlspecialchars($featuredJob['salary']); ?></label>
			</div>
		</div>
		<div class="div3">
		  <input type="button" value="<?php echo htmlspecialchars($featuredJob['job_type']); ?>">
			<label><?php echo htmlspecialchars(date('M d, Y', strtotime($featuredJob['posted_at']))); ?></label>
		</div>
	</div>
	<?php 
		endforeach;
	else:
	?>
	<div style="padding: 40px; text-align: center; color: #999;">
		<p>No featured jobs available yet.</p>
	</div>
	<?php endif; ?>
</div>
<div class="apply">
		<div class="head">
		    <label>APPLY PROCESS</label>
		    <h2>How it works</h2>
		</div>
		<div class="sub">
		<div class="card card-1">
			<div class="search">
				<i class="fa fa-leanpub"></i>
				<h5>1.Search a job</h5>
				<p>Use the search bar, location filter, or category cards to quickly find roles that match your skills and career goals.</p>
			</div>
		</div>
		
		<div class="card card-1">
			<div class="search">
				<i class="fa fa-address-card-o"></i>
				<h5>2.Apply for job</h5>
				<p>Open a job, review the requirements, and submit your application in one click from your dashboard account.</p>
			</div>
		</div>
		

		<div class="card card-1">
			<div class="search">
				<i class="fa fa-user-circle"></i>
				<h5>3.Get your job</h5>
				<p>Track your application status and respond to updates so you can move from interview to offer with confidence.</p>
			</div>
		</div>
    </div>
</div>



<div class="checkslider" id="testimonials">
	<div class="content">
		<?php
		function isFemaleName($name){
				$femaleNames = ['neha','priya','anita','sara','sarah','maria','neha'];
				$parts = preg_split('/\s+/', trim($name));
				$first = strtolower($parts[0] ?? '');
				if(in_array($first, $femaleNames, true)) return true;
				if(preg_match('/[aeiou]$/i', $first)) return true;
				return false;
		}
			// prefer a female avatar if present (check multiple file types), otherwise fall back to the default founder image
			$femaleCandidates = [
				__DIR__ . '/img/testimonial-woman.png.webp',
				__DIR__ . '/img/testimonial-woman.png',
				__DIR__ . '/img/testimonial-woman.svg'
			];
			$femaleImg = 'img/testimonial-founder.png.webp';
			foreach ($femaleCandidates as $c) {
				if (file_exists($c)) {
					$femaleImg = 'img/' . basename($c);
					break;
				}
			}
		?>
  		 <input type="radio" name="r" id="r1" >
		  <input type="radio" name="r" id="r2">
          <input type="radio" name="r" id="r3">
    <div class="check check1">
    	<div class="img">
    	   <?php $t_name = 'Neha Kapoor'; $t_img = 'img/testimonial-woman-1.png'; ?>
    	   <img src="<?php echo $t_img; ?>">
    	  </div>
    	<div class="text">
    	    <h5><?php echo $t_name; ?></h5>
    	    <label>UI/UX Designer</label>
    	    <p>"Job Finder made my search simple. I filtered by role and location, applied in minutes, and got interview calls within the same week."</p>
    	</div>
    </div>

     <div class="check check2">
    	<div class="img">
    	   <?php $t_name = 'Rohan Verma'; $t_img = isFemaleName($t_name) ? $femaleImg : 'img/testimonial-founder.png.webp'; ?>
    	   <img src="<?php echo $t_img; ?>">
    	  </div>
    	<div class="text">
    	    <h5><?php echo $t_name; ?></h5>
    	    <label>Frontend Developer</label>
    	    <p>"The job listings are clear and the application flow is smooth. I could track each status update from my profile dashboard without confusion."</p>
    	</div>
    </div>

     <div class="check check3">
    	<div class="img">
    	   <?php $t_name = 'Priya Sharma'; $t_img = 'img/testimonial-woman-2.png'; ?>
    	   <img src="<?php echo $t_img; ?>">
    	  </div>
    	<div class="text">
    	    <h5><?php echo $t_name; ?></h5>
    	    <label>Marketing Specialist</label>
    	    <p>"I liked how fast I could shortlist jobs and apply. The platform feels practical, especially for anyone who wants a focused and stress-free search."</p>
    	</div>
    </div>
    </div>
	      <div class="dots">
          <label for="r1"></label>
          <label for="r2"></label>
           <label for="r3"></label>
     </div>
</div>


<div class="container1" id="post-job-cta">
	<div class=" column column1">
		<span>What We Do</span>
		<h3>25,000+ talented professionals found jobs with us</h3>
		<p class="p1">We help job seekers discover relevant roles quickly — filter by role, location, and skills, then apply in just a few clicks.</p>
		<p class="p2">Employers can post openings fast and manage applications from a simple dashboard. Our streamlined process connects great candidates to great opportunities.</p>
		<?php if (!empty($postJobNotice)): ?>
			<div class="post-job-note" style="background:#ffe6e6; border:1px solid #c33; border-radius:4px; padding:8px 12px; margin-bottom:12px; color:#c33; font-weight:500; font-size:13px; display:flex; align-items:center; gap:6px; max-width:400px;">
				<span style="font-size:14px; flex-shrink:0;">⚠️</span>
				<span><?php echo htmlspecialchars($postJobNotice); ?></span>
			</div>
		<?php endif; ?>
		<a href="<?php echo htmlspecialchars($postJobUrl); ?>" style="text-decoration: none;">
			<button onclick="return true;">Post a Job</button>
		</a>
	</div>
	<div class=" column column2">
        <div class="since">
        	<h6>Since</h6> 1994
        </div>
		
	</div>
</div>

<div class="head">
		<span>CAREER RESOURCES</span>
		<h3>Job search tips and industry insights</h3>
	</div>
<div class="zoom">	
	
	<div class="c1">
		<div class="image">
		<img src="img/home-blog1.jpg.webp">
		<div class="overlay1">
			<h1>CAREER TIP</h1>
		</div>
		</div>
        <div class="txt1"> 
        	<span>|  Career Development</span>
        	<p>Master your interview skills and land your dream job with confidence</p>
        	<a href="javascript:void(0);" style="cursor:pointer;">READ MORE<i class="fa fa-angle-double-right"></i></a>
        </div>
	</div>


<div class="c2">
		<div class="image">
		<img src="img/home-blog2.jpg.webp">
		<div class="overlay2">
			<h1>INDUSTRY NEWS</h1>
		</div>
		</div>
        <div class="txt2"> 
        	<span>| Market Trends</span>
        	<p>Top 10 in-demand skills employers are looking for in 2024</p>
        	<a href="javascript:void(0);" style="cursor:pointer;">READ MORE<i class="fa fa-angle-double-right"></i></a>
        </div>
	</div>


</div>

<div class="footer" id="about">
	<div class="row1">
		<div class="div1">
			<h3>ABOUT US</h3>
			<p>Job Finder is a modern job search platform connecting talented professionals with leading employers. We make hiring and job hunting simple, fast, and effective for everyone.
		</div>
		<div class="div2">
			<h3>CONTACT INFO </h3>
			<span>Address: Tech Hub, Innovation District</span>
			<span>Business Park, Metro City</span>
			<label>Phone: +1-800-JOB-FIND</label>
			<label>Email: support@jobfinder.com</label>
		</div>
		<div class="div3">
			<h3>IMPORTANT LINK</h3>
			<a href="index.php" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">Browse Jobs</a>
			<a href="index.php#about" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">About Us</a>
			<a href="index.php#testimonials" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">Testimonials</a>
			<?php if ($currentUserId > 0 && ($currentUserRole === 'employer' || $currentUserRole === 'admin')): ?>
				<a href="post_job.php" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">Post a Job</a>
			<?php elseif ($currentUserId > 0): ?>
				<a href="javascript:void(0);" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;opacity:0.6;cursor:not-allowed;" title="Employer account required">Post a Job</a>
			<?php else: ?>
				<a href="login.php?redirect=post_job.php" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">Post a Job</a>
			<?php endif; ?>
			<a href="mailto:support@jobfinder.com" style="display:block;color:inherit;text-decoration:none;margin-bottom:10px;">Support</a>
		</div>
		<div class="div4">
			<h3>NEWS LETTER</h3>
			<P>Subscribe to get job alerts and career tips delivered to your inbox.</P>
			<input type="email" name="email" placeholder="Enter your email"><i class="fa fa-send"></i>
		</div>
	</div>
	<div class="row2">
		<div class="div1">
			<img src="img/logo2_footer.png.webp">
		</div>
		<div class="div2">
			<h2>25000+</h2>
			<h5>Job Seekers</h5>
		</div>
		<div class="div3">
			<h2>5000+</h2>
			<h5>Active Jobs</h5>
		</div>
		<div class="div4">
			<h2>800+</h2>
			<h5>Employers</h5>
		</div>
	</div>
	<div class="row3">
		<div class="div1">
		<label>Copyright @2026 All rights reserved | This template is made with <i class="fa fa-heart"></i> by <span>Amarjeet Yadav</span></label>
		</div>
		<div class="div2">
			<i class="fa fa-facebook"></i>
			<i class="fa fa-twitter"></i>
			<i class="fa fa-youtube"></i>
			<i class="fa fa-instagram"></i>		
		</div>
	</div>
</div>

<script>
// Robust autoplay for testimonial radio slider
document.addEventListener('DOMContentLoaded', function(){
	const radios = Array.from(document.querySelectorAll('input[name="r"]'));
	if (!radios.length) return;

	// Ensure a radio is checked initially
	if (!radios.some(r=>r.checked)) {
		radios[0].checked = true;
	}

	function getCurrentIndex(){
		return radios.findIndex(r=>r.checked);
	}

	function goTo(index){
		radios[index].checked = true;
		// dispatch change so any listeners respond
		radios[index].dispatchEvent(new Event('change', { bubbles: true }));
	}

	let autoplayInterval = 4000;
	let autoplayId = setInterval(()=>{
		const next = (getCurrentIndex() + 1) % radios.length;
		goTo(next);
	}, autoplayInterval);

	const slider = document.querySelector('.checkslider');
	if (slider) {
		slider.addEventListener('mouseenter', ()=>{ clearInterval(autoplayId); autoplayId = null; });
		slider.addEventListener('mouseleave', ()=>{ if (!autoplayId) autoplayId = setInterval(()=>{ const next = (getCurrentIndex() + 1) % radios.length; goTo(next); }, autoplayInterval); });
		slider.addEventListener('focusin', ()=>{ clearInterval(autoplayId); autoplayId = null; });
		slider.addEventListener('focusout', ()=>{ if (!autoplayId) autoplayId = setInterval(()=>{ const next = (getCurrentIndex() + 1) % radios.length; goTo(next); }, autoplayInterval); });
	}

	radios.forEach(r=> r.addEventListener('change', ()=>{ if (autoplayId) { clearInterval(autoplayId); autoplayId = setInterval(()=>{ const next = (getCurrentIndex() + 1) % radios.length; goTo(next); }, autoplayInterval); } }));
});
</script>
<script>
// Job data for autocomplete - passed from PHP
const allJobsData = <?php echo json_encode(array_map(function($job) { return ['title' => $job['title'], 'company' => $job['company'], 'id' => $job['id']]; }, $allJobs)); ?>;

// Autocomplete functionality
document.addEventListener('DOMContentLoaded', function() {
    const keywordInput = document.getElementById('keywordInput');
    const suggestionsList = document.getElementById('suggestionsList');
    
    if (!keywordInput || !suggestionsList) return;
    
    // Show suggestions on input
    keywordInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.trim().toLowerCase();
        
        // Clear suggestions if input is empty
        if (searchTerm.length === 0) {
            suggestionsList.classList.remove('active');
            suggestionsList.innerHTML = '';
            return;
        }
        
        // Filter jobs based on search term
        const filteredJobs = allJobsData.filter(job => 
            job.title.toLowerCase().includes(searchTerm) || 
            job.company.toLowerCase().includes(searchTerm)
        ).slice(0, 6); // Show max 6 suggestions
        
        // Display suggestions
        if (filteredJobs.length > 0) {
            suggestionsList.innerHTML = filteredJobs.map(job => 
                `<li data-title="${escapeHtml(job.title)}">
                    <div class="job-title">${highlightMatch(job.title, searchTerm)}</div>
                    <div class="job-company">${escapeHtml(job.company)}</div>
                </li>`
            ).join('');
            suggestionsList.classList.add('active');
            
            // Add click handlers to suggestions
			document.querySelectorAll('#suggestionsList li').forEach(li => {
                li.addEventListener('click', function() {
                    keywordInput.value = this.dataset.title;
                    suggestionsList.classList.remove('active');
                    suggestionsList.innerHTML = '';
                    // Optional: auto-submit or focus on location select
					const locationSelect = document.querySelector('.location-select');
					if (locationSelect) {
						locationSelect.focus();
					}
                });
            });
        } else {
            suggestionsList.innerHTML = '<li style="padding: 12px 16px; color: #999; text-align: center;">No jobs found</li>';
            suggestionsList.classList.add('active');
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-wrapper') && !e.target.closest('#suggestionsList')) {
            suggestionsList.classList.remove('active');
        }
    });
});

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to highlight matching text
function highlightMatch(text, searchTerm) {
    const regex = new RegExp(`(${searchTerm})`, 'gi');
    return text.replace(regex, '<strong>$1</strong>');
}

// Job Application Handler
function handleJobApplication(jobId, action = 'apply') {
    // For now, just submit without cover letter (user can add modal form later)
    const coverLetter = '';

    const payload = {
        job_id: jobId,
        action: action,
        cover_letter: coverLetter
    };

    fetch('apply_job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload(); // Reload page to show updated status
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Attach event listeners to apply and withdraw buttons
document.addEventListener('DOMContentLoaded', function() {
    // Apply buttons
    document.querySelectorAll('.apply-btn:not(.login-required)').forEach(btn => {
        btn.addEventListener('click', function() {
            const jobId = this.getAttribute('data-job-id');
            handleJobApplication(jobId, 'apply');
        });
    });

    // Withdraw buttons
    document.querySelectorAll('.withdraw-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Are you sure you want to withdraw your application?')) {
                const jobId = this.getAttribute('data-job-id');
                handleJobApplication(jobId, 'withdraw');
            }
        });
    });
});

// Smooth scrolling for About link
const aboutLink = document.querySelector('a[href="#about"]');
if (aboutLink) {
	aboutLink.addEventListener('click', function(e) {
		e.preventDefault();
		const target = document.querySelector('#about');
		const navbar = document.querySelector('nav');
		if (!target || !navbar) {
			return;
		}
		const navbarHeight = navbar.offsetHeight;
		const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navbarHeight;

		window.scrollTo({
			top: targetPosition,
			behavior: 'smooth'
		});
	});
}
</script>

</body>
</html>
