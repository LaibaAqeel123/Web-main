<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400'); // cache preflight for 24 hours

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Send response headers for preflight requests
    http_response_code(200);
    header('Content-Length: 0');
    header('Content-Type: text/plain');
    exit();
}

// Debug: Print the attempted config file path
$config_path = dirname(__DIR__) . '/includes/config.php';
error_log("Attempting to load config from: " . $config_path);

// Check if includes/config.php exists using absolute path
$config_path = dirname(__DIR__) . '/includes/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration file not found']);
    exit();
}

require_once $config_path;

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . mysqli_connect_error()]);
    exit();
}

// Function to generate JWT token
function generateToken($user_id) {
    $secret_key = "your_secret_key_here"; // Change this to a secure secret key
    $issued_at = time();
    $expiration = $issued_at + (60 * 60 * 24); // Token expires in 24 hours

    $payload = array(
        "user_id" => $user_id,
        "iat" => $issued_at,
        "exp" => $expiration
    );

    // JWT Header
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    
    // JWT Payload
    $payload = base64_encode(json_encode($payload));
    
    // JWT Signature
    $signature = base64_encode(
        hash_hmac('sha256', "$header.$payload", $secret_key, true)
    );

    return "$header.$payload.$signature";
}

// Function to verify JWT token
function verifyToken() {
    if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
        error_log("ERROR: No authorization header found");
        return false;
    }

    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    $token = str_replace('Bearer ', '', $auth_header);
    error_log("DEBUG: Checking token: " . $token);
    
    $secret_key = "your_secret_key_here";

    // Check if token exists and is active in database
    global $conn;
    $check_token = "SELECT user_id FROM UserTokens 
                    WHERE token = ? AND is_active = 1 
                    AND expires_at > NOW()";
    $stmt = mysqli_prepare($conn, $check_token);
    if (!$stmt) {
        error_log("ERROR: Failed to prepare token check query: " . mysqli_error($conn));
        return false;
    }
    mysqli_stmt_bind_param($stmt, "s", $token);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("ERROR: Failed to execute token check query: " . mysqli_stmt_error($stmt));
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    
    error_log("DEBUG: Token DB check result rows: " . mysqli_num_rows($result));
    if (mysqli_num_rows($result) !== 1) {
        error_log("ERROR: Token not found in database or inactive");
        return false;
    }

    $token_parts = explode('.', $token);
    if (count($token_parts) !== 3) {
        error_log("Invalid token format");
        return false;
    }

    $header = base64_decode($token_parts[0]);
    $payload = base64_decode($token_parts[1]);
    $signature_provided = $token_parts[2];

    // Check signature
    $signature = base64_encode(
        hash_hmac('sha256', "$token_parts[0].$token_parts[1]", $secret_key, true)
    );

    if ($signature !== $signature_provided) {
        error_log("Invalid token signature");
        return false;
    }

    $payload_data = json_decode($payload, true);
    
    // Check if token has expired
    if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
        error_log("Token has expired");
        return false;
    }

    error_log("Token verified successfully for user: " . $payload_data['user_id']);
    return $payload_data;
}

// Function to require rider authentication
function requireRiderAuth() {
    $token_data = verifyToken();
    if (!$token_data) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    global $conn;
    $user_id = $token_data['user_id'];
    
    // Verify user is an active rider
    $query = "SELECT id FROM Users WHERE id = ? AND user_type = 'Rider' AND is_active = 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) !== 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    return $user_id;
}

// Add this to your config.php
define('MAPBOX_TOKEN', 'pk.eyJ1IjoibW5hMjU4NjciLCJhIjoiY2tldTZiNzlxMXJ6YzJ6cndqY2RocXkydiJ9.Tee5ksW6tXsXXc4HOPJAwg'); 