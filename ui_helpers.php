<?php

function renderSignedInIdentity(string $username, string $role, string $subtitle = ''): string
{
	$displayRole = ucfirst(str_replace('_', ' ', $role));

	$html = '<div class="user-identity-badge">';
	$html .= '<div class="user-identity-badge__label">Signed in as</div>';
	$html .= '<div class="user-identity-badge__name">' . htmlspecialchars($username) . '</div>';
	$html .= '<div class="user-identity-badge__role">' . htmlspecialchars($displayRole) . '</div>';

	if ($subtitle !== '') {
		$html .= '<div class="user-identity-badge__subtitle">' . htmlspecialchars($subtitle) . '</div>';
	}

	$html .= '</div>';

	return $html;
}

function renderRoleAwareNav(string $role): string
{
	$links = [
		['label' => 'Home', 'href' => 'index.php'],
		['label' => 'About', 'href' => 'index.php#about']
	];

	if ($role === 'job_seeker') {
		$links[] = ['label' => 'My Applications', 'href' => 'my_applications.php'];
		$links[] = ['label' => 'Saved Jobs', 'href' => 'saved_jobs.php'];
		$links[] = ['label' => 'Profile', 'href' => 'profile.php'];
	} elseif ($role === 'employer') {
		$links[] = ['label' => 'Post Job', 'href' => 'post_job.php'];
		$links[] = ['label' => 'Applications', 'href' => 'applications_dashboard.php'];
		$links[] = ['label' => 'Profile', 'href' => 'profile.php'];
	} elseif ($role === 'admin') {
		$links[] = ['label' => 'Approvals', 'href' => 'admin_approval.php'];
		$links[] = ['label' => 'Profile', 'href' => 'profile.php'];
	}

	$html = '<ul>';
	foreach ($links as $link) {
		$html .= '<li><a href="' . htmlspecialchars($link['href']) . '">' . htmlspecialchars($link['label']) . '</a></li>';
	}
	$html .= '</ul>';

	return $html;
}

function renderDashboardSidebar(string $role, string $activePage = ''): string
{
	$sections = [
		[
			'heading' => 'Account',
			'links' => [
				['label' => 'Profile', 'href' => 'profile.php', 'key' => 'profile'],
				['label' => 'Home', 'href' => 'index.php', 'key' => 'home'],
			]
		]
	];

	if ($role === 'job_seeker') {
		array_splice($sections, 1, 0, [[
			'heading' => 'Work',
			'links' => [
				['label' => 'My Applications', 'href' => 'my_applications.php', 'key' => 'my_applications'],
				['label' => 'Saved Jobs', 'href' => 'saved_jobs.php', 'key' => 'saved_jobs'],
			]
		]]);
	} elseif ($role === 'employer') {
		array_splice($sections, 1, 0, [[
			'heading' => 'Employer Tools',
			'links' => [
				['label' => 'Post Job', 'href' => 'post_job.php', 'key' => 'post_job'],
				['label' => 'Applications', 'href' => 'applications_dashboard.php', 'key' => 'applications'],
			]
		]]);
	} elseif ($role === 'admin') {
		array_splice($sections, 1, 0, [[
			'heading' => 'Admin Tools',
			'links' => [
				['label' => 'Approvals', 'href' => 'admin_approval.php', 'key' => 'approvals'],
			]
		]]);
	}

	$html = '<aside class="dashboard-sidebar"><div class="dashboard-sidebar__identity">';
	$html = '<aside class="dashboard-sidebar">';
	$html .= '<div class="dashboard-sidebar__brand">';
	$html .= '<img class="dashboard-sidebar__brand-logo" src="img/logo.png.webp" alt="Job Finder">';
	$html .= '<div class="dashboard-sidebar__brand-copy">';
	$html .= '<div class="dashboard-sidebar__brand-name">Job Finder</div>';
	$html .= '<div class="dashboard-sidebar__brand-tag">Career dashboard</div>';
	$html .= '</div></div>';
	$html .= '<div class="dashboard-sidebar__identity">';
	$html .= renderSignedInIdentity((string) ($_SESSION['username'] ?? ''), $role, 'Dashboard');
	$html .= '</div>';

	foreach ($sections as $section) {
		$html .= '<div class="dashboard-sidebar__section">';
		$html .= '<div class="dashboard-sidebar__heading">' . htmlspecialchars($section['heading']) . '</div>';
		$html .= '<nav class="dashboard-sidebar__nav">';
		foreach ($section['links'] as $link) {
			$activeClass = $activePage === $link['key'] ? ' is-active' : '';
			$html .= '<a class="dashboard-sidebar__link' . $activeClass . '" href="' . htmlspecialchars($link['href']) . '">' . htmlspecialchars($link['label']) . '</a>';
		}
		$html .= '</nav></div>';
	}

	$html .= '<a class="dashboard-sidebar__logout" href="logout.php">Logout</a>';
	$html .= '</aside>';

	return $html;
}
