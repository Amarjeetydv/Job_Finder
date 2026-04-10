<?php
require_once 'db.php';
$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($email) && !empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $email, $hashed_password);

        try {
            if ($stmt->execute()) {
                $success = "Registration successful! <a href='login.php'>Login here</a>";
            }
        } catch (mysqli_sql_exception $e) {
            $error = "Username or Email already exists.";
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
    <title>Register - Job Finder</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .auth-form { width: 300px; margin: 100px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px; }
        .auth-form input { width: 100%; margin-bottom: 10px; padding: 10px; box-sizing: border-box; }
        .auth-form button { width: 100%; padding: 10px; background-color: #fb246a; color: white; border: none; cursor: pointer; }
        .message { margin-bottom: 10px; font-size: 14px; }
        .error { color: red; }
        .success { color: green; }
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
            <button type="submit">Sign Up</button>
        </form>
        <p><a href="login.php">Already have an account? Login</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
</body>
</html>
