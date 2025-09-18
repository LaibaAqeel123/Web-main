<?php
require_once 'includes/config.php';

// Secret key to prevent unauthorized access
$SECRET_KEY = 'thisismysecurekey983u*#&@HC*&H#C*&H#sdkjvb2c9ejhvc92u3env39uehv38urebv&@#*&@@#3er4uyvb';

// Only proceed if the correct key is provided
if (!isset($_GET['key']) || $_GET['key'] !== $SECRET_KEY) {
    die("Unauthorized access!");
}

// Check if super admin already exists
$check_query = "SELECT COUNT(*) as count FROM Users WHERE user_type = 'Super Admin'";
$result = mysqli_query($conn, $check_query);
$row = mysqli_fetch_assoc($result);

if ($row['count'] > 0) {
    die("Super Admin already exists!");
}

// Super Admin details
$name = "Muhammad Naveed";
$username = "mnaveedpaki";
$email = "mnaveedpaki@gmail.com";
$phone = "+923405138556";
$password = "Mnaveed@coM25867##"; // You should change this password
$user_type = "Super Admin";

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert Super Admin
$query = "INSERT INTO Users (name, username, email, phone, password, user_type, is_active) 
          VALUES (?, ?, ?, ?, ?, ?, 1)";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ssssss", $name, $username, $email, $phone, $hashed_password, $user_type);

if (mysqli_stmt_execute($stmt)) {
    echo "Super Admin created successfully!<br>";
    echo "Username: " . $username . "<br>";
    echo "Password: " . $password . "<br>";
    echo "Please change these credentials after first login.";
} else {
    echo "Error creating Super Admin: " . mysqli_error($conn);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>