<?php
session_start();

// Add these at the top of the file after the <?php
require_once __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;


// Database configuration
$db_host = 'localhost';
$db_user = 'u767890297_testftp';
$db_pass = '$3O4H;NcIHM*aqUk';
$db_name = 'u767890297_testftp';




// Create connection
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set charset to handle special characters
mysqli_set_charset($conn, "utf8mb4");

// Define constants
define('SITE_URL', 'https://techryption.com/test/');
define('SITE_NAME', 'MMTracker');

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
    
    // Check if user is logged in
    if (!isLoggedIn()) {
        return false;
    }
    
    // Prepare the SQL query to check user
    $query = "SELECT id, is_active FROM Users WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    
    // Close the statement
    mysqli_stmt_close($stmt);
    
    // Check if user exists in database
    if (!$user) {
        // User not found in database
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    // Check if user is active
    if ($user['is_active'] != 1) {
        // User is not active
        session_unset();
        session_destroy();
        requireLogin();
        return false;
    }
    
    // User is valid
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

// Update the Firebase Configuration section
// Firebase Configuration
define('FIREBASE_CREDENTIALS_PATH', __DIR__ . '/../assets/firebase/mm-tracker-f54d9-firebase-adminsdk-fbsvc-088a5175d2.json');

// Function to send Firebase notification
function sendFirebaseNotification($user_id, $title, $message, $data = []) {
    global $conn;
    
    try {
        // Get user's FCM token
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
        
        // Initialize Firebase
        $factory = (new Factory)
            ->withServiceAccount(FIREBASE_CREDENTIALS_PATH);
        
        $messaging = $factory->createMessaging();
        
        // Create notification
        $notification = Notification::create($title, $message);
        
        // Merge default data with provided data
        $messageData = array_merge([
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'title' => $title,
            'body' => $message,
            'sound' => 'default',
        ], $data);
        
        // Create message
        $cloudMessage = CloudMessage::withTarget('token', $fcm_token)
            ->withNotification($notification)
            ->withData($messageData);
        
        // Send message
        $response = $messaging->send($cloudMessage);
        
        // Log successful notification
        $log_query = "INSERT INTO NotificationLogs (user_id, title, message, status, response) 
                     VALUES (?, ?, ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $response_json = json_encode($response);
        $message_text = $message; // Store message text before using $message variable
        mysqli_stmt_bind_param($log_stmt, "isss", $user_id, $title, $message_text, $response_json);
        mysqli_stmt_execute($log_stmt);
        
        error_log("Firebase notification sent successfully to user $user_id");
        return true;
        
    } catch (Exception $e) {
        error_log("Firebase Error: " . $e->getMessage());
        
        // Log failed notification
        $log_query = "INSERT INTO NotificationLogs (user_id, title, message, status, response) 
                     VALUES (?, ?, ?, 'failed', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $error_message = $e->getMessage();
        $message_text = $message; // Store message text before using $message variable
        mysqli_stmt_bind_param($log_stmt, "isss", $user_id, $title, $message_text, $error_message);
        mysqli_stmt_execute($log_stmt);
        
        return false;
    }
}

// sendFirebaseNotification(15, "New Order", "You have a new order to deliver");

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