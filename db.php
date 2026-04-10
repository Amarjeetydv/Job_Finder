<?php
$servername = "localhost";
$username = "root";
$password = "q525bs67";
$dbname = "job_finder";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
