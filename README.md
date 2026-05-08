# Job Finder

 A lightweight job board web application built with PHP and MySQL. Designed for easy local deployment (XAMPP/WAMP) and simple role-based workflows for job seekers, employers, and admins.

---

## Key Features

- Job search with keyword and location filters
- Autocomplete suggestions for job titles
- Role-based flows: job seekers, employers, and admins
- Apply, withdraw, and track application status from a profile dashboard
- Save/bookmark jobs
- Testimonial slider with autoplay and responsive layout

---

## Requirements

- PHP 7.4 or newer
- MySQL / MariaDB
- A local server such as XAMPP, WAMP, or a LAMP stack
- A web browser (Chrome/Firefox/Edge)

---

## Quick Setup (Local)

1. Place the project folder inside your web server's document root, for example:

   - `C:\xampp\htdocs\Job_Finder`

2. Create a MySQL database for the app (example name: `job_finder`).

3. Import the SQL schema if present (`database.sql`) using phpMyAdmin or the MySQL CLI:

```powershell
mysql -u root -p job_finder < database.sql
```

4. Update database credentials in `db.php` to match your environment. Example configuration (do NOT commit secrets):

```php
$servername = 'localhost';
$username = 'db_user_here';
$password = 'db_password_here';
$dbname = 'job_finder';
```

5. Open the app in your browser:

```
http://localhost/Job_Finder/
```

---

## Default Pages & Usage

- Home: Browse jobs, search, and view testimonials.
- Register / Login: Create accounts as job seekers or employers.
- Post a Job: Employers (and admins) can post new job listings. Non-employer accounts are shown a friendly access notice.
- My Applications: Track application status and withdraw applications.
- Saved Jobs: Bookmark jobs for later review.

---

## Customization Notes

- Images are stored in the `img/` folder. Replace assets there to change visuals.
- Styling lives in `style.css`.
- Small UI helper functions are in `ui_helpers.php`.

---

## Troubleshooting

- Blank page / PHP errors: enable `display_errors` in your `php.ini` or check Apache/PHP error logs.
- Database connection failures: verify credentials in `db.php` and that MySQL is running.
- Missing images: ensure the `img/` folder remains present and file names are not changed.

---

## Security & Deployment

- Replace default credentials and never commit real passwords.
- For production, run behind HTTPS and use a proper secrets/config system instead of committing `db.php`.

---

## License & Credits

This project is provided as-is for demonstration and educational purposes.

---

If you'd like, I can also add a minimal `README` badge, sample screenshots, or a deployment guide for production hosting.
