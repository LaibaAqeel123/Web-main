<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Get JSON data and clean it
$json_data = file_get_contents('php://input');

// If data is in single-line format, try to format it
if (strpos($json_data, '\n') === false && strpos($json_data, "\n") === false) {
    // Remove any extra spaces
    $json_data = preg_replace('/\s+/', ' ', $json_data);
    // Clean up single-line format
    $json_data = str_replace(['[{', '}]'], ['[{', '}]'], $json_data);
    $json_data = preg_replace('/},\s*{/', '}, {', $json_data);
}

// Clean the JSON data
$json_data = str_replace(['\\', '\u00a0'], ['', ' '], $json_data); // Remove escapes and non-breaking spaces
$json_data = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $json_data); // Remove control characters
$json_data = preg_replace('/\s+/', ' ', $json_data); // Normalize whitespace
$json_data = trim($json_data); // Remove leading/trailing whitespace

// Convert JavaScript object format to JSON format
$json_data = preg_replace('/(\w+):/i', '"$1":', $json_data); // Add quotes to property names
$json_data = preg_replace('/:(\w+)([,}])/i', ':"$1"$2', $json_data); // Add quotes to string values

// Debug logging
error_log("Received JSON data: " . $json_data);
error_log("Cleaned JSON data: " . $json_data);

// Decode JSON with error checking
$updates = json_decode($json_data, true);
$json_error = json_last_error();

if ($json_error !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON format',
        'details' => json_last_error_msg(),
        'received_data' => $json_data,
        'error_code' => $json_error
    ]);
    exit();
}

// Validate array structure
if (!is_array($updates)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid data format',
        'details' => 'Expected array of updates',
        'received_type' => gettype($updates),
        'received_data' => $json_data
    ]);
    exit();
}

// Validate and sanitize each update item
foreach ($updates as $index => $update) {
    // Convert object properties to array keys if needed
    if (is_object($update)) {
        $update = (array)$update;
    }

    // Check required fields
    $required_fields = ['order_id', 'product_id', 'company_id', 'drop_number'];
    $missing_fields = array_diff($required_fields, array_keys($update));
    
    if (!empty($missing_fields)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid update format',
            'details' => 'Missing required fields: ' . implode(', ', $missing_fields),
            'index' => $index,
            'update' => $update
        ]);
        exit();
    }
    
    // Ensure numeric values and sanitize
    $updates[$index] = [
        'order_id' => (int)$update['order_id'],
        'product_id' => (int)$update['product_id'],
        'company_id' => (int)$update['company_id'],
        'drop_number' => (int)$update['drop_number'],
        'picked_quantity' => isset($update['picked_quantity']) ? (int)$update['picked_quantity'] : 0,
        'missing_quantity' => isset($update['missing_quantity']) ? (int)$update['missing_quantity'] : 0
    ];
}

try {
    mysqli_begin_transaction($conn);

    // Get rider ID from token
    $token_data = mysqli_fetch_assoc($check_result);
    $rider_id = $token_data['user_id'];

    // Prepare statements
    $update_order_stmt = mysqli_prepare($conn, 
        "UPDATE Orders SET drop_number = ? WHERE id = ? AND company_id = ?"
    );

    $update_product_stmt = mysqli_prepare($conn, 
        "UPDATE ProductOrders 
         SET picked_quantity = ?, missing_quantity = ? 
         WHERE order_id = ? AND product_id = ?"
    );

    // Modify the check products query to look at picked quantities
    $check_products_stmt = mysqli_prepare($conn,
        "SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN picked_quantity = 0 THEN 1 ELSE 0 END) as zero_picked_products
        FROM ProductOrders 
        WHERE order_id = ?"
    );

    // Prepare statement for order status update
    $update_status_stmt = mysqli_prepare($conn,
        "UPDATE Orders SET status = 'failed' WHERE id = ?"
    );

    // Prepare statement for status log
    $insert_status_log_stmt = mysqli_prepare($conn,
        "INSERT INTO OrderStatusLogs (order_id, status, changed_by, reason) 
         VALUES (?, 'failed', ?, ?)"
    );

    $processed_orders = [];
    $success_count = 0;
    $error_count = 0;

    foreach ($updates as $update) {
        // Validate required fields
        if (!isset($update['order_id']) || !isset($update['product_id']) || 
            !isset($update['company_id']) || !isset($update['drop_number'])) {
            continue;
        }

        // Update Orders table (only once per order)
        if (!in_array($update['order_id'], $processed_orders)) {
            mysqli_stmt_bind_param($update_order_stmt, "iii", 
                $update['drop_number'],
                $update['order_id'],
                $update['company_id']
            );
            
            if (!mysqli_stmt_execute($update_order_stmt)) {
                throw new Exception("Error updating order: " . mysqli_error($conn));
            }
            $processed_orders[] = $update['order_id'];
        }

        // Update ProductOrders table
        $picked_qty = isset($update['picked_quantity']) ? $update['picked_quantity'] : 0;
        $missing_qty = isset($update['missing_quantity']) ? $update['missing_quantity'] : 0;

        mysqli_stmt_bind_param($update_product_stmt, "iiii",
            $picked_qty,
            $missing_qty,
            $update['order_id'],
            $update['product_id']
        );

        if (mysqli_stmt_execute($update_product_stmt)) {
            $success_count++;

            // Check if all products in this order have zero picked quantity
            mysqli_stmt_bind_param($check_products_stmt, "i", $update['order_id']);
            mysqli_stmt_execute($check_products_stmt);
            $products_result = mysqli_stmt_get_result($check_products_stmt);
            $products_data = mysqli_fetch_assoc($products_result);

            // Only mark as failed if ALL products have picked_quantity = 0
            if ($products_data['total_products'] > 0 && 
                $products_data['total_products'] == $products_data['zero_picked_products']) {
                
                // Get customer details by joining Orders and Customers tables
                $get_customer_query = "SELECT c.name as customer_name, c.email, o.order_number 
                                       FROM Orders o
                                       LEFT JOIN Customers c ON o.customer_id = c.id
                                       WHERE o.id = ?";
                $get_customer_stmt = mysqli_prepare($conn, $get_customer_query);
                mysqli_stmt_bind_param($get_customer_stmt, "i", $update['order_id']);
                mysqli_stmt_execute($get_customer_stmt);
                $customer_result = mysqli_stmt_get_result($get_customer_stmt);
                $customer_data = mysqli_fetch_assoc($customer_result);

                // Update order status to failed
                mysqli_stmt_bind_param($update_status_stmt, "i", $update['order_id']);
                mysqli_stmt_execute($update_status_stmt);

                // Add status log entry
                $reason = "No products were picked for this order";
                mysqli_stmt_bind_param($insert_status_log_stmt, "iis", 
                    $update['order_id'],
                    $rider_id,
                    $reason
                );
                mysqli_stmt_execute($insert_status_log_stmt);

                // Send email to customer
                if ($customer_data && $customer_data['email']) {
                    $to = $customer_data['email'];
                    $subject = "Order #{$customer_data['order_number']} Failed";
                    $message = "Dear {$customer_data['customer_name']},\n\n";
                    $message .= "We regret to inform you that your order #{$customer_data['order_number']} could not be fulfilled as the products were not available.\n\n";
                    $message .= "Please contact our support team for assistance or to place a new order.\n\n";
                    $message .= "Best regards,\nYour Support Team";
                    
                    $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
                    $headers .= "Reply-To: support@" . $_SERVER['HTTP_HOST'] . "\r\n";
                    $headers .= "X-Mailer: PHP/" . phpversion();

                    mail($to, $subject, $message, $headers);
                }

                mysqli_stmt_close($get_customer_stmt);
            }
        } else {
            $error_count++;
            error_log("Error updating product quantities: " . mysqli_error($conn));
        }
    }

    mysqli_commit($conn);

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated $success_count items. Failed: $error_count",
        'updates_processed' => count($updates),
        'successful_updates' => $success_count,
        'failed_updates' => $error_count,
        'received_data' => $updates
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error in update_product_quantities.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'received_data' => $updates
    ]);
}

// Close statements
if (isset($update_order_stmt)) mysqli_stmt_close($update_order_stmt);
if (isset($update_product_stmt)) mysqli_stmt_close($update_product_stmt);
if (isset($check_products_stmt)) mysqli_stmt_close($check_products_stmt);
if (isset($update_status_stmt)) mysqli_stmt_close($update_status_stmt);
if (isset($insert_status_log_stmt)) mysqli_stmt_close($insert_status_log_stmt); 