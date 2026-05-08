<?php
require_once 'db.php';
$error = "";
$success = "";

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
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = trim($_POST['role'] ?? 'job_seeker');
    $companyName = trim($_POST['company_name'] ?? '');
    $companyDescription = trim($_POST['company_description'] ?? '');

    if (!in_array($role, ['job_seeker', 'employer'], true)) {
        $role = 'job_seeker';
    }

    if ($role !== 'employer') {
        $companyName = '';
        $companyDescription = '';
    }

    if (!empty($username) && !empty($email) && !empty($password)) {
        if ($role === 'employer' && $companyName === '') {
            $error = "Company name is required for employer accounts.";
        }
    } else {
        $error = "Please fill all fields.";
    }

    if ($error === '') {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, company_name, company_description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $username, $email, $hashed_password, $role, $companyName, $companyDescription);

        try {
            if ($stmt->execute()) {
                $success = "Registration successful! <a href='login.php'>Login here</a>";
            }
        } catch (mysqli_sql_exception $e) {
            $error = "Username or Email already exists.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Register - Job Finder</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .auth-form { width: 300px; margin: 100px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px; }
        .auth-form input { width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        .auth-form select, .auth-form textarea { width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        .auth-form textarea { min-height: 80px; resize: vertical; }
        .auth-form button { width: 100%; padding: 10px; background-color: #fb246a; color: white; border: none; cursor: pointer; }
        .message { margin-bottom: 10px; font-size: 14px; }
        .error { color: red; }
        .success { color: green; }
        #companyFields { display: none; }
    </style>
</head>
<body>
    <div class="auth-form">
        <h2>Register</h2>
        <?php if($error) echo "<div class='message error'>$error</div>"; ?>
        <?php if($success) echo "<div class='message success'>$success</div>"; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <select name="role" id="roleSelect" required>
                <option value="job_seeker">Job Seeker</option>
                <option value="employer">Employer</option>
            </select>
            <div id="companyFields">
                <input type="text" name="company_name" placeholder="Company name">
                <textarea name="company_description" placeholder="Company description"></textarea>
            </div>
            <button type="submit">Sign Up</button>
        </form>
        <p><a href="login.php">Already have an account? Login</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
    <script>
        const roleSelect = document.getElementById('roleSelect');
        const companyFields = document.getElementById('companyFields');
        function toggleCompanyFields() {
            companyFields.style.display = roleSelect.value === 'employer' ? 'block' : 'none';
        }
        roleSelect.addEventListener('change', toggleCompanyFields);
        toggleCompanyFields();
    </script>
</body>
</html>
