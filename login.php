<?php
require_once 'db.php';
session_start();
$error = "";
$redirect = trim($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if ($redirect !== '') {
    // Allow only local relative targets to prevent open redirects.
    if (strpos($redirect, '://') !== false || strpos($redirect, '..') !== false || str_starts_with($redirect, '/')) {
        $redirect = '';
    }
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

ensureUsersTableExtensions($conn);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password, role, company_name, company_description FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'job_seeker';
                $_SESSION['company_name'] = $user['company_name'] ?? '';
                $_SESSION['company_description'] = $user['company_description'] ?? '';
                $target = $redirect !== '' ? $redirect : 'index.php';
                header("Location: " . $target);
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No user found with that email.";
        }
        $stmt->close();
    } else {
        $error = "Please fill all fields.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Job Finder</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .auth-form { width: 300px; margin: 100px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px; }
        .auth-form input { width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        .auth-form button { width: 100%; padding: 10px; background-color: #242b5e; color: white; border: none; cursor: pointer; }
        .message { margin-bottom: 10px; font-size: 14px; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="auth-form">
        <h2>Login</h2>
        <?php if($error) echo "<div class='message error'>$error</div>"; ?>
        <form method="POST">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        <p><a href="register.php">New here? Register</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
</body>
</html>
