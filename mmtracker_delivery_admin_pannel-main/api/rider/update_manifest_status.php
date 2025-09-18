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

// Verify rider authentication
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

// Validate required fields
if (!isset($data['manifest_id']) || !isset($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: manifest_id and status are required']);
    exit();
}

$manifest_id = $data['manifest_id'];
$new_status = $data['status'];

// Validate status - only allowing delivering and delivered
$valid_statuses = ['delivering', 'delivered'];
if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status. Must be one of: delivering, delivered']);
    exit();
}

try {
    mysqli_begin_transaction($conn);

    // Verify manifest belongs to rider and check current status
    $manifest_query = "SELECT id, status FROM Manifests 
                      WHERE id = ? AND rider_id = ? 
                      AND status IN ('assigned', 'delivering')";  // Only allow updates from these statuses
    $manifest_stmt = mysqli_prepare($conn, $manifest_query);
    mysqli_stmt_bind_param($manifest_stmt, "ii", $manifest_id, $rider_id);
    mysqli_stmt_execute($manifest_stmt);
    $manifest_result = mysqli_stmt_get_result($manifest_stmt);

    if (mysqli_num_rows($manifest_result) === 0) {
        throw new Exception('Manifest not found, not assigned to this rider, or cannot be updated (already delivered)');
    }

    $manifest = mysqli_fetch_assoc($manifest_result);

    // Validate status transition
    if ($manifest['status'] === 'assigned' && $new_status !== 'delivering') {
        throw new Exception('Can only change status from assigned to delivering');
    }
    if ($manifest['status'] === 'delivering' && $new_status !== 'delivered') {
        throw new Exception('Can only change status from delivering to delivered');
    }
    
    // Update manifest status
    $update_manifest = "UPDATE Manifests SET status = ? WHERE id = ?";
    $manifest_stmt = mysqli_prepare($conn, $update_manifest);
    mysqli_stmt_bind_param($manifest_stmt, "si", $new_status, $manifest_id);
    mysqli_stmt_execute($manifest_stmt);

    // Get orders that are not failed
    $get_valid_orders = "SELECT o.id 
                        FROM Orders o 
                        JOIN ManifestOrders mo ON o.id = mo.order_id 
                        WHERE mo.manifest_id = ? 
                        AND o.status != 'failed'";
    $valid_orders_stmt = mysqli_prepare($conn, $get_valid_orders);
    mysqli_stmt_bind_param($valid_orders_stmt, "i", $manifest_id);
    mysqli_stmt_execute($valid_orders_stmt);
    $valid_orders_result = mysqli_stmt_get_result($valid_orders_stmt);

    $valid_order_ids = [];
    while ($order = mysqli_fetch_assoc($valid_orders_result)) {
        $valid_order_ids[] = $order['id'];
    }

    if (!empty($valid_order_ids)) {
        // Update non-failed orders status
        $order_status = $new_status;
        $update_orders = "UPDATE Orders 
                         SET status = ? 
                         WHERE id IN (" . implode(',', $valid_order_ids) . ")";
        $orders_stmt = mysqli_prepare($conn, $update_orders);
        mysqli_stmt_bind_param($orders_stmt, "s", $order_status);
        mysqli_stmt_execute($orders_stmt);

        // Add status logs for non-failed orders
        $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);

        foreach ($valid_order_ids as $order_id) {
            mysqli_stmt_bind_param($log_stmt, "isi", $order_id, $order_status, $rider_id);
            mysqli_stmt_execute($log_stmt);
        }
    }

    // --- NEW: Delete ALL Extra items related to this manifest when delivered ---
    if ($new_status === 'delivered') {
        error_log("Manifest {$manifest_id} marked as delivered. Deleting ALL related ExtraItemsLog entries.");
        
        // Delete items from initial warehouse scan
        $delete_scan_extras_sql = "DELETE FROM ExtraItemsLog WHERE source_manifest_id = ?";
        $stmt_delete_scan = mysqli_prepare($conn, $delete_scan_extras_sql);
        if ($stmt_delete_scan) {
             mysqli_stmt_bind_param($stmt_delete_scan, "i", $manifest_id);
             if (mysqli_stmt_execute($stmt_delete_scan)) {
                $deleted_scan_count = mysqli_stmt_affected_rows($stmt_delete_scan);
                error_log("Deleted {$deleted_scan_count} direct manifest scan extra item records for manifest {$manifest_id}.");
             } else {
                 error_log("Failed to execute delete scan extra items statement for manifest {$manifest_id}: " . mysqli_stmt_error($stmt_delete_scan));
             }
             mysqli_stmt_close($stmt_delete_scan);
        } else {
            error_log("Failed to prepare delete scan extra items statement for manifest {$manifest_id}: " . mysqli_error($conn));
        }

        // Delete items from failed/rejected orders within this manifest
        $delete_order_extras_sql = "DELETE FROM ExtraItemsLog WHERE source_order_id IN (SELECT order_id FROM ManifestOrders WHERE manifest_id = ?)";
        $stmt_delete_order = mysqli_prepare($conn, $delete_order_extras_sql);
        if ($stmt_delete_order) {
            mysqli_stmt_bind_param($stmt_delete_order, "i", $manifest_id);
            if (mysqli_stmt_execute($stmt_delete_order)) {
                $deleted_order_count = mysqli_stmt_affected_rows($stmt_delete_order);
                error_log("Deleted {$deleted_order_count} failed/rejected order extra item records for manifest {$manifest_id}.");
            } else {
                error_log("Failed to execute delete order extra items statement for manifest {$manifest_id}: " . mysqli_stmt_error($stmt_delete_order));
            }
            mysqli_stmt_close($stmt_delete_order);
        } else {
             error_log("Failed to prepare delete order extra items statement for manifest {$manifest_id}: " . mysqli_error($conn));
        }
    }
    // --- END NEW --- 

    mysqli_commit($conn);
    
    echo json_encode([
        'success' => true,
        'message' => 'Manifest status updated successfully',
        'data' => [
            'manifest_id' => $manifest_id,
            'new_status' => $new_status,
            'previous_status' => $manifest['status'],
            'orders_updated' => count($valid_order_ids),
            'skipped_failed_orders' => true
        ]
    ]);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error in update_manifest_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
} 

// Close statements
if (isset($manifest_stmt)) mysqli_stmt_close($manifest_stmt);
if (isset($orders_stmt)) mysqli_stmt_close($orders_stmt);
if (isset($log_stmt)) mysqli_stmt_close($log_stmt);
if (isset($valid_orders_stmt)) mysqli_stmt_close($valid_orders_stmt); 