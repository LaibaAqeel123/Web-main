<?php
session_start();

// Add these at the top of the file after the <?php
require_once __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

// Database configuration
$db_host = '127.0.0.1';   // better than 'localhost' for TCP
$db_user = 'root';
$db_pass = '';            // no password
$db_name = 'u337053559_delivery';
$db_port = 3306;          // force correct port

// Create connection
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to handle special characters
mysqli_set_charset($conn, "utf8mb4");

// Define constants
define('SITE_URL', 'http://localhost/Web-main/mmtracker_delivery_admin_pannel-main/');
  // updated to match your htdocs folder
define('SITE_NAME', 'MMTracker');
define('MAPBOX_TOKEN', 'pk.eyJ1IjoibW5hMjU4NjciLCJhIjoiY2tldTZiNzlxMXJ6YzJ6cndqY2RocXkydiJ9.Tee5ksW6tXsXXc4HOPJAwg');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Authentication check function
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check user type
function isSuperAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Super Admin';
}

function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Admin';
}

function isRider() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'Rider';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'index.php');
        exit();
    }
}

// Add this function to your existing config.php file
function validUser() {
    global $conn;
    
    if (!isLoggedIn()) {
        return false;
    }
    
    $query = "SELECT id, is_active FROM Users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$user) {
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    if ($user['is_active'] != 1) {
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    return true;
}

// Clean input data
function cleanInput($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}

// Firebase Configuration
define('FIREBASE_CREDENTIALS_PATH', __DIR__ . '/../assets/firebase/mm-tracker-f54d9-firebase-adminsdk-fbsvc-088a5175d2.json');

// Function to send Firebase notification
function sendFirebaseNotification($user_id, $title, $message, $data = []) {
    global $conn;
    
    try {
        $query = "SELECT fcm_token FROM Users WHERE id = ? AND fcm_token IS NOT NULL";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if (!$user || empty($user['fcm_token'])) {
            error_log("No valid FCM token found for user ID: " . $user_id);
            return false;
        }
        
        $fcm_token = $user['fcm_token'];
        
        $factory = (new Factory)->withServiceAccount(FIREBASE_CREDENTIALS_PATH);
        $messaging = $factory->createMessaging();
        
        $notification = Notification::create($title, $message);
        
        $messageData = array_merge([
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'title' => $title,
            'body' => $message,
            'sound' => 'default',
        ], $data);
        
        $cloudMessage = CloudMessage::withTarget('token', $fcm_token)
            ->withNotification($notification)
            ->withData($messageData);
        
        $response = $messaging->send($cloudMessage);
        
        $log_query = "INSERT INTO NotificationLogs (user_id, title, message, status, response) 
                     VALUES (?, ?, ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $response_json = json_encode($response);
        $message_text = $message;
        mysqli_stmt_bind_param($log_stmt, "isss", $user_id, $title, $message_text, $response_json);
        mysqli_stmt_execute($log_stmt);
        
        error_log("Firebase notification sent successfully to user $user_id");
        return true;
        
    } catch (Exception $e) {
        error_log("Firebase Error: " . $e->getMessage());
        
        $log_query = "INSERT INTO NotificationLogs (user_id, title, message, status, response) 
                     VALUES (?, ?, ?, 'failed', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $error_message = $e->getMessage();
        $message_text = $message;
        mysqli_stmt_bind_param($log_stmt, "isss", $user_id, $title, $message_text, $error_message);
        mysqli_stmt_execute($log_stmt);
        
        return false;
    }
}

// Create NotificationLogs table if it doesn't exist
$create_logs_table = "CREATE TABLE IF NOT EXISTS NotificationLogs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('success', 'failed') NOT NULL,
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id)
)";
mysqli_query($conn, $create_logs_table);
?>
