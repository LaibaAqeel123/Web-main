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
require_once __DIR__ . '/../../includes/generate_pdf.php'; // Include the PDF generator

// Add debug logging
// error_log("POST Data: " . print_r($_POST, true));
// error_log("FILES Data: " . print_r($_FILES, true));

// Get Authorization header
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($auth_header)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing']);
    exit();
}

$token = str_replace('Bearer ', '', $auth_header);

// Check token in database
$check_query = "SELECT ut.user_id, u.name, u.user_type
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

// Get input data - handle both JSON and form-data
$input = [];
$contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';

// More robust content type check
if (stripos($contentType, 'application/json') !== false) {
    // Handle JSON input
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Decode Error: " . json_last_error_msg() . " | Received: " . $json);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload', 'details' => json_last_error_msg()]);
        exit();
    }
    $input = $input ?: []; // Ensure $input is an array even if JSON is null/empty
    error_log("Received JSON data for order status update.");
} elseif (stripos($contentType, 'multipart/form-data') !== false) {
    // Handle form-data
    $input = $_POST;
    error_log("Received POST form-data for order status update.");
} else {
    // Try parsing php://input as form data if content type is different or missing
    // This handles cases where Flutter might send form-data without the correct header
    parse_str(file_get_contents('php://input'), $parsed_input);
    $input = array_merge($input, $parsed_input); // Merge with potentially empty $input
    error_log("Received data (parsed from input stream) for order status update.");
}


// Get data from the combined input sources
$order_id = isset($input['order_id']) ? (int)$input['order_id'] : null;
$status = isset($input['status']) ? $input['status'] : null;
$latitude = isset($input['latitude']) ? $input['latitude'] : null;
$longitude = isset($input['longitude']) ? $input['longitude'] : null;
$delivered_to = isset($input['delivered_to']) ? trim($input['delivered_to']) : null;
$reason = isset($input['reason']) ? trim($input['reason']) : null; // Reason for failed or forced delivery
$signature_data = isset($input['signature_data']) ? $input['signature_data'] : null; // Base64 signature

// --- NEW: Expect product updates --- 
$product_updates_json = isset($input['product_updates']) ? $input['product_updates'] : '[]';
$product_updates = json_decode($product_updates_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Product Updates JSON Decode Error: " . json_last_error_msg() . " | Received: " . $product_updates_json);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product_updates JSON format', 'details' => json_last_error_msg()]);
    exit();
}
$product_updates = $product_updates ?: []; // Ensure it's an array
error_log("Received Product Updates: " . print_r($product_updates, true));
// --- END NEW ---


// --- Refined Lat/Lng Parsing (Keep existing robust logic) ---
// Process and normalize coordinates
if ($latitude !== null) {
    if (is_string($latitude) && !is_numeric($latitude)) $latitude = null;
    else $latitude = floatval($latitude);
}
if ($longitude !== null) {
    if (is_string($longitude) && !is_numeric($longitude)) $longitude = null;
    else $longitude = floatval($longitude);
}
error_log("Parsed Location - Lat: " . var_export($latitude, true) . ", Lng: " . var_export($longitude, true));
// --- End Lat/Lng Parsing ---

// Validate required fields
if (!$order_id || !$status) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields: order_id and status are required'
    ]);
    exit();
}

// Validate status values
if (!in_array($status, ['delivered', 'failed'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status. Only delivered or failed status is allowed']);
    exit();
}

// Validate product updates structure if status is 'delivered'
$total_order_picked_quantity = 0;
$total_update_quantity = 0;
if ($status === 'delivered' && !empty($product_updates)) {
    foreach ($product_updates as $index => $update) {
        if (!isset($update['product_id']) || 
            !isset($update['delivered_quantity']) || 
            !isset($update['delivery_missing_quantity']) || 
            !isset($update['rejected_quantity'])) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid product_updates structure at index $index. Required fields: product_id, delivered_quantity, delivery_missing_quantity, rejected_quantity"]);
            exit();
        }
         // Accumulate quantities for validation
        $total_update_quantity += (int)$update['delivered_quantity'] + (int)$update['delivery_missing_quantity'] + (int)$update['rejected_quantity'];
    }
}

// Initialize proof paths
$photo_url = null;
$signature_path = null;

// --- Handle file uploads (Keep existing robust logic) ---
// Handle photo upload
if (isset($_FILES['photo'])) {
    error_log("Photo upload details: " . print_r($_FILES['photo'], true));
    if ($_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif']; // Added GIF
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid photo file type. Only JPG, PNG, GIF allowed');
        }
        // Use simpler relative path from script location
        $upload_dir = '../images'; 
        // $upload_dir = __DIR__ . '../../api/images'; // Use absolute path relative to this script
        
        // Check if directory exists using is_dir() before creating
        if (!is_dir($upload_dir)) { // Use is_dir()
            // Suppress mkdir warning if dir exists race condition, handle actual failure
            if (!@mkdir($upload_dir, 0775, true)) { 
                // Check again if it was created by a concurrent process
                if (!is_dir($upload_dir)) {
                    error_log("Failed to create images directory after check: {$upload_dir}");
                    throw new Exception('Server error: Could not create images directory.');
                }
            }
        } 
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'delivery_' . $order_id . '_' . time() . '_' . uniqid() . '.' . $extension;
        $upload_path = $upload_dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            throw new Exception('Failed to move uploaded photo');
        }
        $photo_url = 'images/' . $filename; // Store relative path
        error_log("Photo saved: $photo_url");
    } else {
        error_log("Photo upload error code: " . $_FILES['photo']['error']);
        // Potentially throw error or just log it, depending on requirements
        // throw new Exception('Photo upload failed with error code: ' . $_FILES['photo']['error']);
    }
}

// Handle signature data (Base64)
if ($signature_data) {
    try {
        // Remove header if present (e.g., "data:image/png;base64,")
        if (preg_match('/^data:image\/(\w+);base64,/', $signature_data, $type)) {
            $signature_data = substr($signature_data, strpos($signature_data, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            if (!in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
                throw new Exception('Invalid signature image type detected in base64 header.');
            }
            $decoded_data = base64_decode($signature_data);
            if ($decoded_data === false) {
                throw new Exception('Base64 signature decoding failed.');
            }
        } else {
            // Assume raw base64 data, try decoding
            $decoded_data = base64_decode($signature_data, true); // Use strict mode
            if ($decoded_data === false) {
                 throw new Exception('Base64 signature decoding failed (strict mode).');
            }
            // Attempt to determine type from magic numbers (optional but good)
            // For simplicity, we'll assume PNG if no header
            $type = 'png'; 
        }
        
        // Store in main /api/images/ directory
        $upload_dir = '../images';
        // $upload_dir = '../images/signatures'; // Previous nested path
       
         // Directory check/creation for ../images happens earlier for photos, no need to repeat here
        /* 
        if (!is_dir($upload_dir)) { // Use is_dir()
            // Suppress mkdir warning if dir exists race condition, handle actual failure
            if (!@mkdir($upload_dir, 0775, true)) {
                // Check again if it was created by a concurrent process
                if (!is_dir($upload_dir)) {
                    error_log("Failed to create signatures directory after check: {$upload_dir}");
                    throw new Exception('Server error: Could not create signatures directory.');
                }
            }
        }
        */

        $filename = 'sig_' . $order_id . '_' . time() . '_' . uniqid() . '.' . $type;
        $upload_path = $upload_dir . '/' . $filename; 
        error_log("Attempting to save signature to: " . $upload_path); 
        error_log("Decoded signature data size: " . strlen($decoded_data) . " bytes");

        // Detailed check for file_put_contents result
        $bytes_written = file_put_contents($upload_path, $decoded_data); // Capture return value

        if ($bytes_written === false) {
            error_log("file_put_contents returned false. Error details: " . print_r(error_get_last(), true)); // Log detailed error
            throw new Exception('Failed to save signature file (file_put_contents returned false).');
        } else {
            error_log("file_put_contents wrote $bytes_written bytes.");
            // Check if file actually exists immediately after writing
            if (file_exists($upload_path)) {
                error_log("Verified file exists at: $upload_path");
                $signature_path = 'images/' . $filename; // Store relative path in DB
                error_log("Signature saved: $signature_path");
            } else {
                error_log("CRITICAL ERROR: file_put_contents reported success, but file does not exist at: $upload_path");
                throw new Exception('Failed to save signature file (file missing after write).');
            }
        }
    } catch (Exception $e) {
        error_log("Signature processing error: " . $e->getMessage());
        // Fallback: Set path to '0' on error
        error_log("Setting signature path to '0' due to processing error.");
        $signature_path = '0'; // Set to '0' on error

        // Don't exit or set http code here, allow transaction processing
    }
}

// Ensure signature path is '0' if it's still null (meaning no signature data was sent)
if ($signature_path === null) {
    $signature_path = '0';
}
// --- End file uploads ---

// Fetch original picked quantities for validation and failed order handling
$original_products = [];
$fetch_picked_query = "SELECT product_id, quantity, picked_quantity FROM ProductOrders WHERE order_id = ?";
$fetch_picked_stmt = mysqli_prepare($conn, $fetch_picked_query);
mysqli_stmt_bind_param($fetch_picked_stmt, "i", $order_id);
mysqli_stmt_execute($fetch_picked_stmt);
$fetch_result = mysqli_stmt_get_result($fetch_picked_stmt);
while ($row = mysqli_fetch_assoc($fetch_result)) {
    $original_products[$row['product_id']] = $row; 
    $total_order_picked_quantity += (int)$row['picked_quantity'];
}
mysqli_stmt_close($fetch_picked_stmt);

// --- Validation: Check if update quantities match picked quantity --- 
if ($status === 'delivered' && $total_update_quantity !== $total_order_picked_quantity) {
    error_log("Quantity Mismatch Error: Order ID: {$order_id}, Total Picked: {$total_order_picked_quantity}, Total in Update: {$total_update_quantity}");
    http_response_code(400);
    echo json_encode(['error' => "Quantity mismatch. Total delivered/missing/rejected ({$total_update_quantity}) does not match total picked quantity ({$total_order_picked_quantity}) for this order."]);
    exit();
}
// --- End Quantity Validation ---


// --- Database Operations ---
try {
    mysqli_begin_transaction($conn);

    // 1. Update Main Order Status, Proofs, Lat/Lng
    $update_order_sql = "UPDATE Orders SET 
                           status = ?, 
                           proof_photo_url = IF(? IS NOT NULL, ?, proof_photo_url), 
                           proof_signature_path = ?, 
                           latitude = ?, 
                           longitude = ?
                         WHERE id = ?";
    $order_stmt = mysqli_prepare($conn, $update_order_sql);
    mysqli_stmt_bind_param($order_stmt, "ssssddi", 
                           $status, 
                           $photo_url, $photo_url, // Photo URL still needs IF check
                           $signature_path,      // Directly bind the final value ('images/...' or '0')
                           $latitude, 
                           $longitude, 
                           $order_id);
    if (!mysqli_stmt_execute($order_stmt)) {
        throw new Exception("Failed to update order status: " . mysqli_stmt_error($order_stmt));
    }
    mysqli_stmt_close($order_stmt);
    error_log("Order table updated for ID: {$order_id} with status: {$status}");

    // 2. Update ProductOrders quantities (delivered, missing, rejected)
    if (!empty($product_updates)) {
        $update_po_sql = "UPDATE ProductOrders SET 
                            delivered_quantity = ?, 
                            delivery_missing_quantity = ?, 
                            rejected_quantity = ?
                          WHERE order_id = ? AND product_id = ?";
        $po_stmt = mysqli_prepare($conn, $update_po_sql);
        
        foreach ($product_updates as $update) {
            $prod_id = (int)$update['product_id'];
            $del_qty = (int)$update['delivered_quantity'];
            $miss_qty = (int)$update['delivery_missing_quantity'];
            $rej_qty = (int)$update['rejected_quantity'];

            mysqli_stmt_bind_param($po_stmt, "iiiii", $del_qty, $miss_qty, $rej_qty, $order_id, $prod_id);
            if (!mysqli_stmt_execute($po_stmt)) {
                error_log("Failed to update ProductOrders for order {$order_id}, product {$prod_id}: " . mysqli_stmt_error($po_stmt));
                // Decide whether to throw or just log
            } else {
                error_log("Updated ProductOrders for order {$order_id}, product {$prod_id}: Del={$del_qty}, Miss={$miss_qty}, Rej={$rej_qty}");
            }

            // 3. Add Rejected items to ExtraItemsLog
            if ($rej_qty > 0) {
                $reason_rejected = 'Customer Rejected';
                $insert_extra_sql = "INSERT INTO ExtraItemsLog (rider_id, product_id, quantity, reason, source_order_id) VALUES (?, ?, ?, ?, ?)";
                $extra_rej_stmt = mysqli_prepare($conn, $insert_extra_sql);
                mysqli_stmt_bind_param($extra_rej_stmt, "iiisi", $rider_id, $prod_id, $rej_qty, $reason_rejected, $order_id);
                if (!mysqli_stmt_execute($extra_rej_stmt)) {
                    error_log("Failed to insert rejected item into ExtraItemsLog for order {$order_id}, product {$prod_id}: " . mysqli_stmt_error($extra_rej_stmt));
                    // Decide whether to throw or just log
                } else {
                    error_log("Inserted rejected item into ExtraItemsLog for order {$order_id}, product {$prod_id}, qty: {$rej_qty}");
                }
                mysqli_stmt_close($extra_rej_stmt);
            }
        }
        mysqli_stmt_close($po_stmt);
    }

    // 4. Add Failed items to ExtraItemsLog (if status is failed)
    if ($status === 'failed') {
        $reason_failed = 'Order Failed';
        $insert_extra_sql = "INSERT INTO ExtraItemsLog (rider_id, product_id, quantity, reason, source_order_id) VALUES (?, ?, ?, ?, ?)";
        $extra_fail_stmt = mysqli_prepare($conn, $insert_extra_sql);

        foreach ($original_products as $prod_id => $prod_data) {
            $picked_qty = (int)$prod_data['picked_quantity'];
            if ($picked_qty > 0) { // Only log items that were actually picked
                mysqli_stmt_bind_param($extra_fail_stmt, "iiisi", $rider_id, $prod_id, $picked_qty, $reason_failed, $order_id);
                 if (!mysqli_stmt_execute($extra_fail_stmt)) {
                    error_log("Failed to insert failed item into ExtraItemsLog for order {$order_id}, product {$prod_id}: " . mysqli_stmt_error($extra_fail_stmt));
                    // Decide whether to throw or just log
                } else {
                    error_log("Inserted failed item into ExtraItemsLog for order {$order_id}, product {$prod_id}, qty: {$picked_qty}");
                }
            }
        }
         mysqli_stmt_close($extra_fail_stmt);
    }

    // 5. Add entry to OrderStatusLogs (include reason/delivered_to)
    $log_sql = "INSERT INTO OrderStatusLogs (order_id, status, changed_by, latitude, longitude, reason, delivered_to) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
    $log_stmt = mysqli_prepare($conn, $log_sql);
    mysqli_stmt_bind_param($log_stmt, "isiddss", 
                           $order_id, 
                           $status, 
                           $rider_id, 
                           $latitude, 
                           $longitude, 
                           $reason, 
                           $delivered_to);
    if (!mysqli_stmt_execute($log_stmt)) {
        throw new Exception("Failed to insert status log: " . mysqli_stmt_error($log_stmt));
    }
    mysqli_stmt_close($log_stmt);
    error_log("Inserted OrderStatusLog for ID: {$order_id}");

    // 6. Generate and Email PDF (Keep existing logic)
    $pdf_path = null;
    if ($status === 'delivered') {
        $pdf_path = generateOrderPDF($order_id, $conn);
        if ($pdf_path) {
            sendOrderStatusEmail($order_id, $status, $conn, $pdf_path); 
            // Attempt to delete temporary PDF file
            if (file_exists($pdf_path)) {
                unlink($pdf_path);
            }
        } else {
            error_log("Failed to generate PDF for order ID: {$order_id}");
        }
    } elseif ($status === 'failed') {
         sendOrderStatusEmail($order_id, $status, $conn); // Send failed email without PDF
    }

    // --- Commit Transaction ---
    mysqli_commit($conn);
    error_log("Transaction committed successfully for Order ID: {$order_id}");

    echo json_encode(['success' => true, 'message' => 'Order status updated successfully']);

} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error processing order status update for ID {$order_id}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Server error', 
        'message' => $e->getMessage()
    ]);
}

// --- Email Sending Function (Keep existing logic, maybe add PDF path param) ---
function sendOrderStatusEmail($order_id, $status, $conn, $pdf_attachment_path = null) {
    $query = "SELECT 
                o.order_number, 
                c.name as customer_name, 
                c.email as customer_email,
                comp.name as company_name,
                comp.email as company_contact_email
              FROM Orders o
              JOIN Customers c ON o.customer_id = c.id
              JOIN Companies comp ON o.company_id = comp.id
              WHERE o.id = ?";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $details = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($details && $details['customer_email']) {
        $to = $details['customer_email'];
        $company_name = $details['company_name'] ?? 'Our Company';
        $support_email = $details['company_contact_email'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'); // Fallback
        $order_number = $details['order_number'];
        $customer_name = $details['customer_name'];

        $subject = "";
        $message_body = "";

        if ($status === 'delivered') {
            $subject = "Your Order #{$order_number} has been Delivered!";
            $message_body = "Dear {$customer_name},\n\nGood news! Your order #{$order_number} from {$company_name} has been successfully delivered.\n\n";
            if ($pdf_attachment_path) {
                 $message_body .= "Your Proof of Delivery is attached.\n\n";
            } else {
                 $message_body .= "You can view your order details online.\n\n";
            }
            $message_body .= "Thank you for your order!\n\nBest regards,\nThe {$company_name} Team";
        } elseif ($status === 'failed') {
            $subject = "Issue with your Order #{$order_number}";
            $message_body = "Dear {$customer_name},\n\nWe encountered an issue attempting to deliver your order #{$order_number} from {$company_name}.\n\n";
            $message_body .= "Please contact our support team at {$support_email} for more information or assistance.\n\n";
            $message_body .= "We apologize for any inconvenience.\n\nBest regards,\nThe {$company_name} Team";
        }

        if (!empty($subject)) {
            // Use PHPMailer or similar for robust email sending with attachments
            // For basic mail() function:
            $headers = "From: {$company_name} <{$support_email}>\r\n";
            $headers .= "Reply-To: {$support_email}\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            
            $message = $message_body;

            if ($pdf_attachment_path && file_exists($pdf_attachment_path)) {
                $boundary = md5(time());
                $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

                // Message part
                $message = "--{$boundary}\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $message .= $message_body . "\r\n";

                // Attachment part
                $file_content = file_get_contents($pdf_attachment_path);
                $encoded_content = chunk_split(base64_encode($file_content));
                $filename = basename($pdf_attachment_path);

                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
                $message .= $encoded_content . "\r\n";
                $message .= "--{$boundary}--";
            } else {
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            }

            if (!mail($to, $subject, $message, $headers)) {
                 error_log("Failed to send status email to {$to} for order ID: {$order_id}");
            }
        }
    } else {
        error_log("Could not send email notification for order ID: {$order_id} - Customer email not found.");
    }
}

?> 