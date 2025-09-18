<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php';

// Verify rider authentication first
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

// Get JSON input
$json = file_get_contents('php://input');
if (!$json) {
    http_response_code(400);
    echo json_encode(['error' => 'No input data received']);
    exit();
}

error_log("Received request body: " . file_get_contents('php://input'));

error_log("Attempting to decode JSON: " . $json);
$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON: ' . json_last_error_msg(),
        'received_data' => $json
    ]);
    exit();
}

// Validate required fields
if (!isset($data['admin_pin']) || !isset($data['company_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: admin_pin and company_id are required']);
    exit();
}

$admin_pin = $data['admin_pin'];
$company_id = $data['company_id'];

try {
    // Find admin user with matching company_id
    $query = "SELECT id, admin_pin 
              FROM Users 
              WHERE company_id = ? 
              AND user_type = 'Admin' 
              AND is_active = 1 
              AND admin_pin IS NOT NULL";

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        throw new Exception('Query preparation failed: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "i", $company_id);
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
    }

    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'No admin found for this company'
        ]);
        exit();
    }

    $admin = mysqli_fetch_assoc($result);
    
    // Verify the PIN - IMPORTANT CHANGE: Return 200 status with success: false for wrong PIN
    if (password_verify($admin_pin, $admin['admin_pin'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Admin PIN verified successfully'
        ]);
    } else {
        // Changed from 401 to 200 with success: false
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid PIN'
        ]);
    }

} catch (Exception $e) {
    error_log("Error in verify_admin_pin.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}

// Old code
// <?php
// header('Content-Type: application/json');
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: POST, OPTIONS');
// header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
// header('Access-Control-Allow-Credentials: true');
// header('Access-Control-Max-Age: 86400');

// if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
//     http_response_code(200);
//     exit();
// }

// require_once '../config.php';

// // Verify rider authentication first
// $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (isset(getallheaders()['Authorization']) ? getallheaders()['Authorization'] : '');
// if (empty($auth_header)) {
//     http_response_code(401);
//     echo json_encode(['error' => 'Authorization header missing']);
//     exit();
// }

// $token = str_replace('Bearer ', '', $auth_header);

// // Check token in database
// $check_query = "SELECT ut.user_id 
//                 FROM UserTokens ut
//                 JOIN Users u ON ut.user_id = u.id
//                 WHERE ut.token = ? 
//                 AND ut.is_active = 1 
//                 AND u.user_type = 'Rider'
//                 AND u.is_active = 1";
                
// $check_stmt = mysqli_prepare($conn, $check_query);
// mysqli_stmt_bind_param($check_stmt, "s", $token);
// mysqli_stmt_execute($check_stmt);
// $check_result = mysqli_stmt_get_result($check_stmt);

// if (mysqli_num_rows($check_result) !== 1) {
//     http_response_code(401);
//     echo json_encode(['error' => 'Unauthorized']);
//     exit();
// }

// // Get JSON input
// $json = file_get_contents('php://input');
// if (!$json) {
//     http_response_code(400);
//     echo json_encode(['error' => 'No input data received']);
//     exit();
// }

// error_log("Received request body: " . file_get_contents('php://input'));

// error_log("Attempting to decode JSON: " . $json);
// $data = json_decode($json, true);
// if (json_last_error() !== JSON_ERROR_NONE) {
//     http_response_code(400);
//     echo json_encode([
//         'error' => 'Invalid JSON: ' . json_last_error_msg(),
//         'received_data' => $json
//     ]);
//     exit();
// }

// // Validate required fields
// if (!isset($data['admin_pin']) || !isset($data['company_id'])) {
//     http_response_code(400);
//     echo json_encode(['error' => 'Missing required fields: admin_pin and company_id are required']);
//     exit();
// }

// $admin_pin = $data['admin_pin'];
// $company_id = $data['company_id'];

// try {
//     // Find admin user with matching company_id
//     $query = "SELECT id, admin_pin 
//               FROM Users 
//               WHERE company_id = ? 
//               AND user_type = 'Admin' 
//               AND is_active = 1 
//               AND admin_pin IS NOT NULL";

//     $stmt = mysqli_prepare($conn, $query);
//     if (!$stmt) {
//         throw new Exception('Query preparation failed: ' . mysqli_error($conn));
//     }

//     mysqli_stmt_bind_param($stmt, "i", $company_id);
    
//     if (!mysqli_stmt_execute($stmt)) {
//         throw new Exception('Query execution failed: ' . mysqli_stmt_error($stmt));
//     }

//     $result = mysqli_stmt_get_result($stmt);
    
//     if (mysqli_num_rows($result) === 0) {
//         http_response_code(404);
//         echo json_encode([
//             'success' => false,
//             'error' => 'No admin found for this company'
//         ]);
//         exit();
//     }

//     $admin = mysqli_fetch_assoc($result);
    
//     // Verify the PIN
//     if (password_verify($admin_pin, $admin['admin_pin'])) {
//         echo json_encode([
//             'success' => true,
//             'message' => 'Admin PIN verified successfully'
//         ]);
//     } else {
//         http_response_code(401);
//         echo json_encode([
//             'success' => false,
//             'error' => 'Invalid PIN'
//         ]);
//     }

// } catch (Exception $e) {
//     error_log("Error in verify_admin_pin.php: " . $e->getMessage());
//     http_response_code(500);
//     echo json_encode([
//         'success' => false,
//         'error' => 'Server error',
//         'message' => $e->getMessage()
//     ]);
//} 