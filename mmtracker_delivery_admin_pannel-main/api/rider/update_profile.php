<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php';

// Validate token
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (isset(getallheaders()['Authorization']) ? getallheaders()['Authorization'] : '');
if (empty($auth_header)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing']);
    exit();
}

$token = str_replace('Bearer ', '', $auth_header);

// Check token
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

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit();
}

try {
    mysqli_begin_transaction($conn);

    // Handle password update separately
    if (isset($data['current_password']) && isset($data['new_password'])) {
        // Verify current password
        $verify_query = "SELECT password FROM Users WHERE id = ?";
        $verify_stmt = mysqli_prepare($conn, $verify_query);
        mysqli_stmt_bind_param($verify_stmt, "i", $rider_id);
        mysqli_stmt_execute($verify_stmt);
        $verify_result = mysqli_stmt_get_result($verify_stmt);
        $user_data = mysqli_fetch_assoc($verify_result);

        if (!password_verify($data['current_password'], $user_data['password'])) {
            throw new Exception('Current password is incorrect');
        }

        // Update password
        $new_password_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
        $update_password = "UPDATE Users SET password = ? WHERE id = ?";
        $password_stmt = mysqli_prepare($conn, $update_password);
        mysqli_stmt_bind_param($password_stmt, "si", $new_password_hash, $rider_id);
        
        if (!mysqli_stmt_execute($password_stmt)) {
            throw new Exception('Failed to update password');
        }

        // After successful password update, fetch and return user data
        $fetch_query = "SELECT 
                        u.id, 
                        u.name, 
                        u.username,
                        u.email,
                        u.phone,
                        u.user_type,
                        u.company_id,
                        u.is_active,
                        c.name as company_name,
                        GROUP_CONCAT(DISTINCT rc.company_id) as assigned_company_ids,
                        GROUP_CONCAT(DISTINCT c2.name) as assigned_company_names
                       FROM Users u
                       LEFT JOIN Companies c ON u.company_id = c.id
                       LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id AND rc.is_active = 1
                       LEFT JOIN Companies c2 ON rc.company_id = c2.id
                       WHERE u.id = ?
                       GROUP BY u.id";

        $fetch_stmt = mysqli_prepare($conn, $fetch_query);
        mysqli_stmt_bind_param($fetch_stmt, "i", $rider_id);
        mysqli_stmt_execute($fetch_stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch_stmt));

        // Prepare assigned companies data
        $assigned_company_ids = $user['assigned_company_ids'] ? 
            array_map('intval', explode(',', $user['assigned_company_ids'])) : [];
        $assigned_company_names = $user['assigned_company_names'] ? 
            explode(',', $user['assigned_company_names']) : [];

        if ($user['company_id']) {
            $assigned_company_ids[] = (int)$user['company_id'];
            $assigned_company_names[] = $user['company_name'];
        }

        $assigned_company_ids = array_values(array_unique($assigned_company_ids));
        $assigned_company_names = array_values(array_unique($assigned_company_names));

        mysqli_commit($conn);
        echo json_encode([
            'success' => true,
            'message' => 'Password updated successfully',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'user_type' => $user['user_type'],
                'company_id' => (int)$user['company_id'],
                'company_name' => $user['company_name'],
                'is_active' => (bool)$user['is_active'],
                'assigned_companies' => [
                    'ids' => $assigned_company_ids,
                    'names' => $assigned_company_names
                ]
            ]
        ]);
        exit();
    }

    // Handle other profile updates
    $updates = [];
    $types = "";
    $params = [];

    // Validate and build update query
    if (isset($data['email'])) {
        // Check if email is unique
        $check_email = "SELECT id FROM Users WHERE email = ? AND id != ?";
        $email_stmt = mysqli_prepare($conn, $check_email);
        mysqli_stmt_bind_param($email_stmt, "si", $data['email'], $rider_id);
        mysqli_stmt_execute($email_stmt);
        if (mysqli_stmt_get_result($email_stmt)->num_rows > 0) {
            throw new Exception('Email already exists');
        }
        $updates[] = "email = ?";
        $types .= "s";
        $params[] = $data['email'];
    }

    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $types .= "s";
        $params[] = $data['name'];
    }

    if (isset($data['phone'])) {
        $updates[] = "phone = ?";
        $types .= "s";
        $params[] = $data['phone'];
    }

    if (empty($updates)) {
        throw new Exception('No fields to update');
    }

    // Build and execute update query
    $update_query = "UPDATE Users SET " . implode(", ", $updates) . " WHERE id = ?";
    $types .= "i";
    $params[] = $rider_id;

    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, $types, ...$params);

    if (!mysqli_stmt_execute($update_stmt)) {
        throw new Exception('Failed to update profile');
    }

    // Fetch updated user data
    $fetch_query = "SELECT 
                    u.id, 
                    u.name, 
                    u.username,
                    u.email,
                    u.phone,
                    u.user_type,
                    u.company_id,
                    u.is_active,
                    c.name as company_name,
                    GROUP_CONCAT(DISTINCT rc.company_id) as assigned_company_ids,
                    GROUP_CONCAT(DISTINCT c2.name) as assigned_company_names
                   FROM Users u
                   LEFT JOIN Companies c ON u.company_id = c.id
                   LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id AND rc.is_active = 1
                   LEFT JOIN Companies c2 ON rc.company_id = c2.id
                   WHERE u.id = ?
                   GROUP BY u.id";

    $fetch_stmt = mysqli_prepare($conn, $fetch_query);
    mysqli_stmt_bind_param($fetch_stmt, "i", $rider_id);
    mysqli_stmt_execute($fetch_stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch_stmt));

    // Prepare assigned companies data
    $assigned_company_ids = $user['assigned_company_ids'] ? 
        array_map('intval', explode(',', $user['assigned_company_ids'])) : [];
    $assigned_company_names = $user['assigned_company_names'] ? 
        explode(',', $user['assigned_company_names']) : [];

    if ($user['company_id']) {
        $assigned_company_ids[] = (int)$user['company_id'];
        $assigned_company_names[] = $user['company_name'];
    }

    $assigned_company_ids = array_values(array_unique($assigned_company_ids));
    $assigned_company_names = array_values(array_unique($assigned_company_names));

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'user_type' => $user['user_type'],
            'company_id' => (int)$user['company_id'],
            'company_name' => $user['company_name'],
            'is_active' => (bool)$user['is_active'],
            'assigned_companies' => [
                'ids' => $assigned_company_ids,
                'names' => $assigned_company_names
            ]
        ]
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
} 