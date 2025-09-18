<?php
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    header('Content-Length: 0');
    header('Content-Type: text/plain');
    exit();
}

// Get and validate token
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (isset(getallheaders()['Authorization']) ? getallheaders()['Authorization'] : '');
if (empty($auth_header)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing']);
    exit();
}

$token = str_replace('Bearer ', '', $auth_header);

// Check token in database
$check_query = "SELECT ut.user_id 
                FROM UserTokens ut
                JOIN Users u ON ut.user_id = u.id
                WHERE ut.token = ? 
                AND ut.is_active = 1 
                AND u.user_type = 'Rider'
                AND u.is_active = 1";
                
$check_stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($check_stmt, "s", $token);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);

if (mysqli_num_rows($check_result) !== 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$token_data = mysqli_fetch_assoc($check_result);
$rider_id = $token_data['user_id'];

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (!isset($data['fcm_token']) || empty($data['fcm_token'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation error',
        'message' => 'FCM token is required'
    ]);
    exit();
}

$fcm_token = $data['fcm_token'];

try {
    // Update the user's FCM token
    $update_query = "UPDATE Users SET 
                    fcm_token = ?,
                    fcm_token_updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?";
                    
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "si", $fcm_token, $rider_id);
    
    if (mysqli_stmt_execute($stmt)) {
        if (mysqli_affected_rows($conn) > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'FCM token updated successfully'
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'User not found',
                'message' => 'Unable to update FCM token'
            ]);
        }
    } else {
        throw new Exception(mysqli_error($conn));
    }

} catch (Exception $e) {
    error_log("Error updating FCM token: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'Failed to update FCM token'
    ]);
} 