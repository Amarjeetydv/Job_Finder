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

function fetchJobs(mysqli $conn, string $keyword, string $location, int $viewerUserId, int $isAdmin): array
{
	$normalizedLocation = $location === 'all' ? '' : $location;
	$keywordFilter = '%' . $keyword . '%';
	$locationFilter = $normalizedLocation;

	$stmt = $conn->prepare(
		"SELECT id, employer_id, title, company, company_description, location, job_type, salary, description, approval_status, posted_at
		 FROM jobs
		 WHERE (? = '' OR title LIKE ? OR company LIKE ? OR description LIKE ?)
		   AND (? = '' OR location = ?)
		   AND (approval_status = 'approved' OR employer_id = ? OR ? = 1)
		 ORDER BY posted_at DESC"
	);
	$stmt->bind_param('ssssssii', $keyword, $keywordFilter, $keywordFilter, $keywordFilter, $locationFilter, $locationFilter, $viewerUserId, $isAdmin);
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
$searchSubmitted = isset($_GET['search']);
$jobs = $searchSubmitted ? fetchJobs($conn, $keyword, $location, $currentUserId, $isAdmin) : [];
$allJobs = getAllJobs($conn, $currentUserId, $isAdmin);
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=width-device, initial-scale=1.0, shrik-to-fit=yes">
	<title></title>
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
				<?php if ($currentUserRole === 'job_seeker'): ?>
					<a href="my_applications.php"><button class="btn-1">My Applications</button></a>
					<a href="saved_jobs.php"><button class="btn-1">Saved Jobs</button></a>
					<a href="profile.php"><button class="btn-1">Profile</button></a>
				<?php elseif ($currentUserRole === 'employer'): ?>
					<a href="post_job.php"><button class="btn-1">Post Job</button></a>
					<a href="applications_dashboard.php"><button class="btn-1">Applications</button></a>
					<a href="profile.php"><button class="btn-1">Profile</button></a>
				<?php elseif ($currentUserRole === 'admin'): ?>
					<a href="admin_approval.php"><button class="btn-1">Approvals</button></a>
					<a href="profile.php"><button class="btn-1">Profile</button></a>
				<?php endif; ?>
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
<div class="job-search-results">
	<div class="results-header">
		<span>JOB SEARCH</span>
		<h3>Available job matches</h3>
		<p>
			<?php echo $keyword !== '' || $location !== 'all'
				? 'Showing results for your selected keyword and location.'
				: 'Showing all available jobs from the database.'; ?>
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


<div class="categories">
	<label>FEATURED TOURS PACKAGES</label>
	<span>Browse Top Categaries</span>
</div>

<div class="flex">
	<div class="item item-1">
		<div class="img">
			<i class="fa fa-address-book-o"></i>
		</div>
		<div class="flex-text">
			<h3>Design & Creative</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-2">
		<div class="img">
			<i class="fa fa-desktop"></i>
		</div>
		<div class="flex-text">
			<h3>Design & Development</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-3">
		<div class="img">
			<i class="fa fa-bar-chart"></i>
		</div>
		<div class="flex-text">
			<h3> Sales & Marketing</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-4">
		<div class="img">
			<i class="fa fa-mobile"></i>
		</div>
		<div class="flex-text">
			<h3>Mobile Application</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-5">
		<div class="img">
			<i class="fa fa-connectdevelop"></i>
		</div>
		<div class="flex-text">
			<h3>Construction</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-6">
		<div class="img">
			<i class="fa fa-newspaper-o"></i>
		</div>
		<div class="flex-text">
			<h3>Information Technology</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-7">
		<div class="img">
			<i class="fa fa-home"></i>
		</div>
		<div class="flex-text">
			<h3>Real Estate</h3>
			<span>(658)</span>
		</div>
	</div>

	<div class="item item-8">
		<div class="img">
			<i class="fa fa-pencil-square-o"></i>
		</div>
		<div class="flex-text">
			<h3>Content Writer</h3>
			<span>(658)</span>
		</div>
	</div>

</div>

<div class="sections">BROWSE ALL SECTIONS</div>

<div class="blue">

	<div class="overlay">
		<h6>FEATURED TOURS PACKAGES</h6>
		<label>MAKE a Difference with your Online Resume!</label>
		<div class="cv">UPLOAD YOUR CV</div>
	</div>
</div>

<div class="recent">
	<span>RECENT JOB</span>
	<h3>Featured Jobs</h3>
</div>
<div class="table">
	<div class="line-1">
		<div class="div1">
		<img src="img/job-list1.png.webp">
		<div class="div1-text">	
		<h2>Digital Marketer</h2>
			<label class="text">Creative Agency</label>
			<label class="country"><i class="fa fa-map-marker"></i> Athens,Greece</label>
			<label class="num">$3500-$4000</label>
			</div>
		</div>
		<div class="div3">
		  <input type="button" value="Full Time">
			<label>7 hours ago</label>
		</div>
	</div>


	<div class="line-1">
		<div class="div1">
		<img src="img/job-list2.png%20(1).webp">
		<div class="div1-text">	
		<h2>Digital Marketer</h2>
			<label class="text">Creative Agency</label>
			<label class="country"><i class="fa fa-map-marker"></i> Athens,Greece</label>
			<label class="num">$3500-$4000</label>
			</div>
		</div>
		<div class="div3">
		  <input type="button" value="Full Time">
			<label>7 hours ago</label>
		</div>
	</div>


	<div class="line-1">
		<div class="div1">
		<img src="img/job-list3.png%20(1).webp">
		<div class="div1-text">	
		<h2>Digital Marketer</h2>
			<label class="text">Creative Agency</label>
			<label class="country"><i class="fa fa-map-marker"></i> Athens,Greece</label>
			<label class="num">$3500-$4000</label>
			</div>
		</div>
		<div class="div3">
		  <input type="button" value="Full Time">
			<label>7 hours ago</label>
		</div>
	</div>


	<div class="line-1">
		<div class="div1">
		<img src="img/job-list4.png%20(1).webp">
		<div class="div1-text">	
		<h2>Digital Marketer</h2>
			<label class="text">Creative Agency</label>
			<label class="country"><i class="fa fa-map-marker"></i> Athens,Greece</label>
			<label class="num">$3500-$4000</label>
			</div>
		</div>
		<div class="div3">
		  <input type="button" value="Full Time">
			<label>7 hours ago</label>
		</div>
	</div>
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
				<p>Sorem spsum dolor sit amsectetur adipisclit. seddo eiusmod tempor incididunt ut laboria.</p>
			</div>
		</div>
		
		<div class="card card-1">
			<div class="search">
				<i class="fa fa-address-card-o"></i>
				<h5>2.Apply for job</h5>
				<p>Sorem spsum dolor sit amsectetur adipisclit. seddo eiusmod tempor incididunt ut laboria.</p>
			</div>
		</div>
		

		<div class="card card-1">
			<div class="search">
				<i class="fa fa-user-circle"></i>
				<h5>3.Get your job</h5>
				<p>Sorem spsum dolor sit amsectetur adipisclit. seddo eiusmod tempor incididunt ut laboria.</p>
			</div>
		</div>
    </div>
</div>



<div class="checkslider">
  <div class="content">
  		 <input type="radio" name="r" id="r1" >
		  <input type="radio" name="r" id="r2">
          <input type="radio" name="r" id="r3">
    <div class="check check1">
	    <div class="img">
		   <img src="img/testimonial-founder.png.webp">
	      </div>
	    <div class="text">
		    <h5>Margaret Lawson</h5>
		    <label>Creative Director</label>
		    <p>"I am at an age where i just want to be fit and healthy our bodies are our responsibility! So start caring for your body and it will care for you.Eat clean it will care for you and workout hard."</p>
	   </div>
    </div>

     <div class="check check2">
	    <div class="img">
		   <img src="img/testimonial-founder.png.webp">
	      </div>
	    <div class="text">
		    <h5>Margaret Lawson</h5>
		    <label>Creative Director</label>
		    <p>"I am at an age where i just want to be fit and healthy our bodies are our responsibility! So start caring for your body and it will care for you.Eat clean it will care for you and workout hard."</p>
	   </div>
    </div>

     <div class="check check3">
	    <div class="img">
		   <img src="img/testimonial-founder.png.webp">
	      </div>-
	    <div class="text">
		    <h5>Margaret Lawson</h5>
		    <label>Creative Director</label>
		    <p>"I am at an age where i just want to be fit and healthy our bodies are our responsibility! So start caring for your body and it will care for you.Eat clean it will care for you and workout hard."</p>
	   </div>
    </div>
    </div>
	      <div class="dots">
          <label for="r1"></label>
          <label for="r2"></label>
           <label for="r3"></label>
     </div>
</div>


<div class="container1">
	<div class=" column column1">
		<span>What we are doing</span>
		<h3>25k Talented people are getting jobs</h3>
		<p class="p1">Molit anim laborum duis au doalor in voluptate velit ess cillum dolore eu lore dsu quality molit anim laborumuis au dolor in voluptate velit cilum.</p>
		<p class="p2">Molit anim laborum.Duis aut irufg dhjkolohr in re voluptate velit esscillulore eu quife nrulla parihatur.Excghcepteur signijnt occa cupidatat non inulpadeserunt molit abour.temnthp incididbnt ut jabore molitanim laborum suis aut.</p>
		<button>Post A job</button>
	</div>
	<div class=" column column2">
        <div class="since">
        	<h6>Since</h6> 1994
        </div>
		
	</div>
</div>

<div class="head">
		<span>OUR LATEST BLOG</span>
		<h3>Our recent news</h3>
	</div>
<div class="zoom">	
	
	<div class="c1">
		<div class="image">
		<img src="img/home-blog1.jpg.webp">
		<div class="overlay1">
			<h1>24 NOW</h1>
		</div>
		</div>
        <div class="txt1"> 
        	<span>|  Properties</span>
        	<p>Footprints in Time is perfect
        	House in Kurashiki</p>
        	<a href="#">READ MORE<i class="fa fa-angle-double-right"></i></a>
        </div>
	</div>


<div class="c2">
		<div class="image">
		<img src="img/home-blog2.jpg.webp">
		<div class="overlay2">
			<h1>24 NOW</h1>
		</div>
		</div>
        <div class="txt2"> 
        	<span>| Properties</span>
        	<p>Footprints in Time is perfect
        	House in Kurashiki</p>
        	<a href="#">READ MORE<i class="fa fa-angle-double-right"></i></a>
        </div>
	</div>


</div>

<div class="footer" id="about">
	<div class="row1">
		<div class="div1">
			<h3>ABOUT US</h3>
			<p>Heaven frucvitful doesn't cover lesser dvsays appear creeping seasons so behold.
		</div>
		<div class="div2">
			<h3>CONTACT INFO </h3>
			<span>Address:Your address goes here.</span>
			<span>Your demo address.</span>
			<label>Phone:+888044338899</label>
			<label>Email:info@colorlib.com</label>
		</div>
		<div class="div3">
			<h3>IMPORTANT LINK</h3>
			<label>View Project</label>
			<label>Contact Us</label>
			<label>Testimonial</label>
			<label>Properties</label>
			<label>Support</label>
		</div>
		<div class="div4">
			<h3>NEWS LETTER</h3>
			<P>Heaven fruitful doesn't over lesser in days. Appear creeping.</P>
			<input type="email" name="email"><i class="fa fa-send"></i>
		</div>
	</div>
	<div class="row2">
		<div class="div1">
			<img src="img/logo2_footer.png.webp">
		</div>
		<div class="div2">
			<h2>5000+</h2>
			<h5>Talented Hunter</h5>
		</div>
		<div class="div3">
			<h2>451</h2>
			<h5>Talented Hunter</h5>
		</div>
		<div class="div4">
			<h2>568</h2>
			<h5>Talented Hunter</h5>
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
