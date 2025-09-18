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

// Convert single object to array for consistent processing
if (!isset($data[0])) {
    $data = [$data];
}

try {
    $results = [];
    mysqli_begin_transaction($conn);

    foreach ($data as $index => $item) {
        // Validate required fields for each item
        if (!isset($item['product_id']) || !isset($item['company_id'])) {
            throw new Exception("Missing required fields at index $index: product_id and company_id are required");
        }

        $product_id = $item['product_id'];
        $company_id = $item['company_id'];
        $picked_quantity = isset($item['picked_quantity']) ? $item['picked_quantity'] : null;
        $delivered_quantity = isset($item['delivered_quantity']) ? $item['delivered_quantity'] : null;
        $missing_quantity = isset($item['missing_quantity']) ? $item['missing_quantity'] : null;

        // Build the query dynamically based on provided parameters
        $query = "INSERT INTO ProductTracking (
                    product_id, 
                    rider_id, 
                    company_id, 
                    picked_quantity, 
                    delivered_quantity,
                    missing_quantity,
                    picked_at, 
                    delivered_at
                ) VALUES (?, ?, ?, ?, ?, ?, 
                    CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END, 
                    CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END)
                ON DUPLICATE KEY UPDATE ";
        
        $updates = [];
        $params = [
            $product_id, 
            $rider_id, 
            $company_id, 
            $picked_quantity, 
            $delivered_quantity,
            $missing_quantity,
            $picked_quantity, 
            $delivered_quantity
        ];
        $types = "iiiiiiii";

        if ($picked_quantity !== null) {
            $updates[] = "picked_quantity = VALUES(picked_quantity)";
            $updates[] = "picked_at = CASE WHEN VALUES(picked_quantity) > 0 THEN NOW() ELSE picked_at END";
        }
        if ($delivered_quantity !== null) {
            $updates[] = "delivered_quantity = VALUES(delivered_quantity)";
            $updates[] = "delivered_at = CASE WHEN VALUES(delivered_quantity) > 0 THEN NOW() ELSE delivered_at END";
        }
        if ($missing_quantity !== null) {
            $updates[] = "missing_quantity = VALUES(missing_quantity)";
        }

        $query .= implode(", ", $updates);

        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to update product tracking for product ID $product_id: " . mysqli_stmt_error($stmt));
        }

        $results[] = [
            'product_id' => $product_id,
            'success' => true
        ];
    }

    mysqli_commit($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Product tracking updated successfully',
        'results' => $results
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
} 