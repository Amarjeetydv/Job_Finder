<?php
require_once 'db.php';
session_start();
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header("Location: index.php");
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
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        <p><a href="register.php">New here? Register</a></p>
        <p><a href="index.php">Back to Home</a></p>
    </div>
</body>
</html>
