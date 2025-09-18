<?php
require_once 'includes/config.php';
include __DIR__ . '/server/log_helper.php';

// Get user info before session is destroyed
$userId = $_SESSION['user_id'] ?? 'Unknown';
$username = $_SESSION['user_name'] ?? 'Unknown';
$ip = $_SERVER['REMOTE_ADDR'];

// Write logout log
writeLog('auth.log', "User #$userId ($username) logged out from IP $ip.");

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: index.php');
exit();
?>
