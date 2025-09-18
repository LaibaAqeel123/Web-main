<?php
require_once '../config.php';

// Verify rider authentication
$rider_id = requireRiderAuth();

// Get the current token
$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
$token = str_replace('Bearer ', '', $auth_header);

// Deactivate the token
$update_query = "UPDATE UserTokens SET is_active = 0 WHERE token = ? AND user_id = ?";
$stmt = mysqli_prepare($conn, $update_query);
mysqli_stmt_bind_param($stmt, "si", $token, $rider_id);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to logout',
        'message' => mysqli_error($conn)
    ]);
}
 