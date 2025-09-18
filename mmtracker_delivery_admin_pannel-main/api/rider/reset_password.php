<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php';

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || !isset($data['code']) || !isset($data['new_password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email, code and new password are required']);
    exit();
}

try {
    mysqli_begin_transaction($conn);

    // Get user and latest unused reset token
    $check_query = "SELECT u.id, u.name, prt.token, prt.expires_at, prt.is_used
                   FROM Users u
                   JOIN PasswordResetTokens prt ON u.id = prt.user_id
                   WHERE u.email = ? 
                   AND u.user_type = 'Rider'
                   AND u.is_active = 1
                   AND prt.is_used = 0
                   AND prt.expires_at > NOW()
                   ORDER BY prt.created_at DESC
                   LIMIT 1";

    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "s", $data['email']);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($result) === 0) {
        throw new Exception('Invalid or expired reset code');
    }

    $reset_data = mysqli_fetch_assoc($result);

    // Verify the code
    if (!password_verify($data['code'], $reset_data['token'])) {
        throw new Exception('Invalid reset code');
    }

    // Update password
    $new_password_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $update_password = "UPDATE Users SET password = ? WHERE id = ?";
    $password_stmt = mysqli_prepare($conn, $update_password);
    mysqli_stmt_bind_param($password_stmt, "si", $new_password_hash, $reset_data['id']);
    mysqli_stmt_execute($password_stmt);

    // Mark token as used
    $update_token = "UPDATE PasswordResetTokens SET is_used = 1 WHERE user_id = ?";
    $token_stmt = mysqli_prepare($conn, $update_token);
    mysqli_stmt_bind_param($token_stmt, "i", $reset_data['id']);
    mysqli_stmt_execute($token_stmt);

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Password has been reset successfully'
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} 