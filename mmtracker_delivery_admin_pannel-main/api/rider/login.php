<?php
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

require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get JSON input
$json = file_get_contents('php://input');
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'No input data received']);
    exit();
}

$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

if (!isset($data['username']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit();
}

$username = $data['username'];
$password = $data['password'];

try {
    // Check credentials with more user details
    $query = "SELECT 
                u.id, 
                u.name, 
                u.username,
                u.email,
                u.phone,
                u.password,
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
              WHERE (u.username = ? OR u.email = ?) 
              AND u.user_type = 'Rider' 
              AND u.is_active = 1
              GROUP BY u.id";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Query preparation failed: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "ss", $username, $username);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
    }

    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            $token = generateToken($user['id']);
            
            // Store token in database
            $expires_at = date('Y-m-d H:i:s', time() + (60 * 60 * 24)); // 24 hours from now
            
            // First, deactivate any existing tokens for this user
            $deactivate_query = "UPDATE UserTokens SET is_active = 0 WHERE user_id = ?";
            $deactivate_stmt = mysqli_prepare($conn, $deactivate_query);
            mysqli_stmt_bind_param($deactivate_stmt, "i", $user['id']);
            mysqli_stmt_execute($deactivate_stmt);
            
            $store_token = "INSERT INTO UserTokens (user_id, token, expires_at) 
                           VALUES (?, ?, ?)";
            $token_stmt = mysqli_prepare($conn, $store_token);
            mysqli_stmt_bind_param($token_stmt, "iss", $user['id'], $token, $expires_at);
            
            if (!mysqli_stmt_execute($token_stmt)) {
                throw new Exception('Failed to store token');
            }
            
            // Verify token was stored
            $verify_query = "SELECT * FROM UserTokens WHERE user_id = ? AND token = ? AND is_active = 1";
            $verify_stmt = mysqli_prepare($conn, $verify_query);
            mysqli_stmt_bind_param($verify_stmt, "is", $user['id'], $token);
            mysqli_stmt_execute($verify_stmt);
            $verify_result = mysqli_stmt_get_result($verify_stmt);
            
            if (mysqli_num_rows($verify_result) !== 1) {
                throw new Exception('Token storage verification failed');
            }

            // Prepare assigned companies data
            $assigned_company_ids = $user['assigned_company_ids'] ? 
                array_map('intval', explode(',', $user['assigned_company_ids'])) : [];
            $assigned_company_names = $user['assigned_company_names'] ? 
                explode(',', $user['assigned_company_names']) : [];

            // Add primary company if exists
            if ($user['company_id']) {
                $assigned_company_ids[] = (int)$user['company_id'];
                $assigned_company_names[] = $user['company_name'];
            }

            // Remove duplicates
            $assigned_company_ids = array_values(array_unique($assigned_company_ids));
            $assigned_company_names = array_values(array_unique($assigned_company_names));
            
            echo json_encode([
                'success' => true,
                'token' => $token,
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
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
} 