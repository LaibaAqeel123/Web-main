<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config.php'; // Ensure this path is correct

// --- Authentication ---
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($auth_header) || !preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing or invalid']);
    exit();
}
$token = $matches[1];

$check_query = "SELECT ut.user_id, u.user_type 
                FROM UserTokens ut 
                JOIN Users u ON ut.user_id = u.id 
                WHERE ut.token = ? AND ut.is_active = 1 AND u.user_type = 'Rider' AND u.is_active = 1";
$check_stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($check_stmt, "s", $token);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);

if (mysqli_num_rows($check_result) !== 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid or expired token']);
    exit();
}
$token_data = mysqli_fetch_assoc($check_result);
$rider_id = (int)$token_data['user_id'];
mysqli_stmt_close($check_stmt);
// --- End Authentication ---


// --- Input Parsing ---
$json = file_get_contents('php://input');
$input = json_decode($json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON Decode Error in update_picked_quantities: " . json_last_error_msg() . " | Received: " . $json);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload', 'details' => json_last_error_msg()]);
    exit();
}

$manifest_id = isset($input['manifest_id']) ? (int)$input['manifest_id'] : null;
$product_updates = isset($input['product_updates']) && is_array($input['product_updates']) ? $input['product_updates'] : [];
$extra_items = isset($input['extra_items']) && is_array($input['extra_items']) ? $input['extra_items'] : [];

error_log("Received picked quantities update for Manifest ID: {$manifest_id}, Rider ID: {$rider_id}");
error_log("Product Updates Data: " . print_r($product_updates, true));
error_log("Extra Items Data: " . print_r($extra_items, true));

// Basic Validation
if (empty($manifest_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required field: manifest_id']);
    exit();
}
if (empty($product_updates) && empty($extra_items)) {
     http_response_code(400);
    echo json_encode(['error' => 'Missing data: Both product_updates and extra_items are empty']);
    exit();
}

// Validate product_updates structure
foreach ($product_updates as $index => $update) {
    if (!isset($update['order_id']) || !isset($update['product_id']) || !isset($update['picked_quantity']) || !isset($update['missing_quantity'])) {
        http_response_code(400);
        echo json_encode(['error' => "Invalid product_updates structure at index $index. Required fields: order_id, product_id, picked_quantity, missing_quantity"]);
        exit();
    }
}

// Validate extra_items structure (keys should be numeric strings, values should be integers)
foreach ($extra_items as $prod_id_str => $qty) {
    if (!is_numeric($prod_id_str) || !is_int($qty) || $qty <= 0) {
         http_response_code(400);
        echo json_encode(['error' => "Invalid extra_items structure: Key '{$prod_id_str}' must be a numeric string and value '{$qty}' must be a positive integer."]);
        exit();
    }
}
// --- End Input Parsing ---


// --- Database Operations ---
mysqli_begin_transaction($conn);

try {
    // 1. Update ProductOrders
    if (!empty($product_updates)) {
        $update_po_sql = "UPDATE ProductOrders SET 
                            picked_quantity = ?, 
                            warehouse_missing_quantity = ?
                          WHERE order_id = ? AND product_id = ?";
        $po_stmt = mysqli_prepare($conn, $update_po_sql);
        if (!$po_stmt) {
             throw new Exception("Prepare failed for ProductOrders update: " . mysqli_error($conn));
        }

        foreach ($product_updates as $update) {
            $picked_qty = (int)$update['picked_quantity'];
            $missing_qty = (int)$update['missing_quantity'];
            $order_id = (int)$update['order_id'];
            $product_id = (int)$update['product_id'];

            mysqli_stmt_bind_param($po_stmt, "iiii", $picked_qty, $missing_qty, $order_id, $product_id);
            if (!mysqli_stmt_execute($po_stmt)) {
                 error_log("Failed execution updating ProductOrders for order {$order_id}, product {$product_id}: " . mysqli_stmt_error($po_stmt));
                 // Decide if one failure should stop the whole batch or just be logged
                 // For now, let's throw to rollback the transaction
                 throw new Exception("Failed to update ProductOrders for order {$order_id}, product {$product_id}: " . mysqli_stmt_error($po_stmt));
            } else {
                 error_log("Updated ProductOrders for order {$order_id}, product {$product_id}: Picked={$picked_qty}, WarehouseMissing={$missing_qty}");
            }
        }
        mysqli_stmt_close($po_stmt);
        error_log("Finished updating ProductOrders for Manifest ID: {$manifest_id}");
    }

    // 2. Insert ExtraItemsLog
    if (!empty($extra_items)) {
        $reason_extra = 'Warehouse Scan Extra';
        $insert_extra_sql = "INSERT INTO ExtraItemsLog (rider_id, product_id, quantity, reason, source_order_id, source_manifest_id) 
                             VALUES (?, ?, ?, ?, NULL, ?)"; // Set source_order_id to NULL
        $extra_stmt = mysqli_prepare($conn, $insert_extra_sql);
         if (!$extra_stmt) {
             throw new Exception("Prepare failed for ExtraItemsLog insert: " . mysqli_error($conn));
        }

        foreach ($extra_items as $prod_id_str => $qty) {
            $product_id = (int)$prod_id_str; // Convert key to integer
            $quantity = (int)$qty;

            // Add a check to ensure quantity is positive before inserting
             if ($quantity > 0) {
                mysqli_stmt_bind_param($extra_stmt, "iiisi", $rider_id, $product_id, $quantity, $reason_extra, $manifest_id);
                if (!mysqli_stmt_execute($extra_stmt)) {
                    error_log("Failed execution inserting ExtraItemsLog for product {$product_id}, manifest {$manifest_id}: " . mysqli_stmt_error($extra_stmt));
                    throw new Exception("Failed to insert ExtraItemsLog for product {$product_id}, manifest {$manifest_id}: " . mysqli_stmt_error($extra_stmt));
                } else {
                    error_log("Inserted ExtraItemsLog for product {$product_id}, manifest {$manifest_id}, qty: {$quantity}");
                }
             } else {
                  error_log("Skipping ExtraItemsLog insert for product {$product_id} due to non-positive quantity: {$quantity}");
             }
        }
        mysqli_stmt_close($extra_stmt);
        error_log("Finished inserting ExtraItemsLog for Manifest ID: {$manifest_id}");
    }
    
    // 3. Optionally: Update Manifest status (e.g., to 'scanned' or 'ready_for_delivery') if needed
    //    This depends on your workflow. The Flutter app currently sets it to 'delivering'.
    //    Example:
    //    $update_manifest_sql = "UPDATE Manifests SET status = 'scanned' WHERE id = ? AND status = 'assigned'"; // Avoid overwriting later statuses
    //    $manifest_stmt = mysqli_prepare($conn, $update_manifest_sql);
    //    mysqli_stmt_bind_param($manifest_stmt, "i", $manifest_id);
    //    mysqli_stmt_execute($manifest_stmt);
    //    mysqli_stmt_close($manifest_stmt);
    //    error_log("Manifest status potentially updated for ID: {$manifest_id}");


    // --- Commit Transaction ---
    if (!mysqli_commit($conn)) {
         throw new Exception("Transaction commit failed: " . mysqli_error($conn));
    }
    error_log("Transaction committed successfully for Manifest ID: {$manifest_id} update.");

    echo json_encode(['success' => true, 'message' => 'Picked quantities and extra items updated successfully.']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error processing picked quantities update for Manifest ID {$manifest_id}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server error during update.', 
        'message' => $e->getMessage() // Provide specific error in dev, maybe generic in prod
    ]);
} finally {
    // Close connection if necessary, though script execution end usually handles this
    // mysqli_close($conn); 
}

?> 