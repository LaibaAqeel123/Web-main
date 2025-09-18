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

if (!isset($data['email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email is required']);
    exit();
}

$email = $data['email'];

try {
    // Check if email exists and user is a rider
    $check_query = "SELECT id, name FROM Users 
                   WHERE email = ? AND user_type = 'Rider' 
                   AND is_active = 1";
    
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "s", $email);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($result) === 0) {
        throw new Exception('No active rider account found with this email');
    }

    $user = mysqli_fetch_assoc($result);
    
    // Generate a random 6-digit code
    $reset_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Hash the code for storage
    $hashed_token = password_hash($reset_code, PASSWORD_DEFAULT);
    
    // Set expiry time (15 minutes from now)
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    mysqli_begin_transaction($conn);

    // Deactivate any existing tokens
    $deactivate_query = "UPDATE PasswordResetTokens 
                        SET is_used = 1 
                        WHERE user_id = ? AND is_used = 0";
    $deactivate_stmt = mysqli_prepare($conn, $deactivate_query);
    mysqli_stmt_bind_param($deactivate_stmt, "i", $user['id']);
    mysqli_stmt_execute($deactivate_stmt);

    // Store new reset token
    $store_token = "INSERT INTO PasswordResetTokens 
                    (user_id, token, expires_at) 
                    VALUES (?, ?, ?)";
    $token_stmt = mysqli_prepare($conn, $store_token);
    mysqli_stmt_bind_param($token_stmt, "iss", $user['id'], $hashed_token, $expires_at);
    mysqli_stmt_execute($token_stmt);

    // Send email with reset code
    $to = $email;
    $subject = "Password Reset Code";
    $message = "Hello " . $user['name'] . ",\n\n"
             . "Your password reset code is: " . $reset_code . "\n"
             . "This code will expire in 15 minutes.\n\n"
             . "If you didn't request this, please ignore this email.";
    $headers = "From: noreply@yourapp.com";

    mail($to, $subject, $message, $headers);

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Reset code has been sent to your email',
        'email' => $email
    ]);

} catch (Exception $e) {
    if (isset($conn)) mysqli_rollback($conn);
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} 