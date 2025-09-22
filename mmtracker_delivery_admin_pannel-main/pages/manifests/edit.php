<?php
require_once '../../includes/config.php';
include_once('../../server/log_helper.php');
function logException($exceptionMessage) {
    $logFile = __DIR__ . '/route_exception_log.log';  // Changed from manifest_exception_log.log
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Exception: $exceptionMessage" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
requireLogin();

$error = '';
$success = '';
$route = null;  // Changed from $manifest to $route
$route_orders = [];  // Changed from $manifest_orders to $route_orders
$is_delivered = false;

// Get search parameters
$search_current = isset($_GET['search_current']) ? cleanInput($_GET['search_current']) : '';
$search_unassigned = isset($_GET['search_unassigned']) ? cleanInput($_GET['search_unassigned']) : '';

// Fetch available warehouses
$warehouses_query = "SELECT id, name, address, city FROM Warehouses WHERE status = 'active'";
if (!isSuperAdmin()) {
    $warehouses_query .= " AND company_id = " . $_SESSION['company_id'];
}
$warehouses_result = mysqli_query($conn, $warehouses_query);
$warehouses = [];
while ($warehouse = mysqli_fetch_assoc($warehouses_result)) {
    $warehouses[] = $warehouse;
}

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);

    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";

    // Fetch route details
    $query = "SELECT m.*, u.name as rider_name, c.name as company_name,
              COUNT(mo.id) as total_orders,
              SUM(o.total_amount) as total_amount,
              w.name as warehouse_name, w.address as warehouse_address,
              w.city as warehouse_city, w.state as warehouse_state
              FROM Manifests m
              LEFT JOIN Users u ON m.rider_id = u.id
              LEFT JOIN Companies c ON m.company_id = c.id
              LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
              LEFT JOIN Orders o ON mo.order_id = o.id
              LEFT JOIN Warehouses w ON m.warehouse_id = w.id
              WHERE m.id = ? $company_condition
              GROUP BY m.id";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $route = mysqli_fetch_assoc($result);  // Changed from $manifest to $route

    if (!$route) {
        header('Location: index.php');
        exit();
    }

    // Check if route is delivered
    $is_delivered = $route['status'] === 'delivered';

    // Fetch current orders in route with search
    $orders_query = "SELECT o.id, o.order_number, o.created_at, o.total_amount,
                    cust.name as customer_name, addr.address_line1, addr.city,
                    GROUP_CONCAT(p.name SEPARATOR ', ') as products,
                    GROUP_CONCAT(po.quantity SEPARATOR ', ') as quantities
                    FROM Orders o
                    JOIN ManifestOrders mo ON o.id = mo.order_id
                    LEFT JOIN ProductOrders po ON o.id = po.order_id
                    LEFT JOIN Products p ON po.product_id = p.id
                    LEFT JOIN Customers cust ON o.customer_id = cust.id
                    LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
                    WHERE mo.manifest_id = ?";
    if ($search_current) {
        $orders_query .= " AND (o.order_number LIKE ? OR cust.name LIKE ? 
                          OR addr.address_line1 LIKE ? OR addr.city LIKE ?)";
    }
    $orders_query .= " GROUP BY o.id ORDER BY o.created_at DESC";

    $stmt = mysqli_prepare($conn, $orders_query);
    if ($search_current) {
        $search_param = "%$search_current%";
        mysqli_stmt_bind_param($stmt, "issss", $id, $search_param, $search_param, $search_param, $search_param);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $id);
    }
    mysqli_stmt_execute($stmt);
    $orders_result = mysqli_stmt_get_result($stmt);
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $route_orders[] = $order;  // Changed from $manifest_orders to $route_orders
    }
}

// Only fetch available unassigned orders and riders if route is not delivered
if (!$is_delivered) {
    // Fetch available unassigned orders with search
    $unassigned_orders_query = "SELECT o.id, o.order_number, o.created_at, o.total_amount, 
                               cust.name as customer_name, addr.address_line1, addr.city,
                               GROUP_CONCAT(p.name SEPARATOR ', ') as products,
                               GROUP_CONCAT(po.quantity SEPARATOR ', ') as quantities
                               FROM Orders o 
                               LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
                               LEFT JOIN ProductOrders po ON o.id = po.order_id
                               LEFT JOIN Products p ON po.product_id = p.id
                               LEFT JOIN Customers cust ON o.customer_id = cust.id
                               LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
                               WHERE mo.id IS NULL AND o.status = 'pending'";
    if (!isSuperAdmin()) {
        $unassigned_orders_query .= " AND o.company_id = " . $_SESSION['company_id'];
    }
    if ($search_unassigned) {
        $unassigned_orders_query .= " AND (o.order_number LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR cust.name LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR addr.address_line1 LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR addr.city LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%')";
    }
    $unassigned_orders_query .= " GROUP BY o.id ORDER BY o.created_at DESC";
    $unassigned_orders_result = mysqli_query($conn, $unassigned_orders_query);

    // Fetch available riders
    $riders_query = "SELECT DISTINCT u.id, u.name 
                    FROM Users u 
                    LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
                    WHERE u.user_type = 'Rider' AND u.is_active = 1";

    if (!isSuperAdmin()) {
        $riders_query .= " AND (u.company_id = " . $_SESSION['company_id'] .
            " OR rc.company_id = " . $_SESSION['company_id'] . ")";
    }
    $riders_query .= " ORDER BY u.name";
    $riders_result = mysqli_query($conn, $riders_query);
}

// Add the email notification function (changed from sendManifestNotificationEmail)
function sendRouteNotificationEmail($order_id, $conn) {
    // Get order and customer details
    $query = "SELECT cust.name as customer_name, cust.email, o.order_number, u.name as rider_name 
              FROM Orders o
              LEFT JOIN Customers cust ON o.customer_id = cust.id
              LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
              LEFT JOIN Manifests m ON mo.manifest_id = m.id
              LEFT JOIN Users u ON m.rider_id = u.id
              WHERE o.id = ?";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    if (!$order || !$order['email']) {
        error_log("Could not send route notification email: Invalid order or missing email for order ID: $order_id");
        return false;
    }

    $to = $order['email'];
    $subject = "Your Order #{$order['order_number']} Will Be Delivered Today";
    
    $headers = array(
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: DeliveryApp <no-reply@techryption.com>',
        'Reply-To: no-reply@techryption.com',
        'X-Mailer: PHP/' . phpversion()
    );

    $message = "
    <html>
    <head>
        <title>Delivery Notification</title>
    </head>
    <body style='font-family: Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #4CAF50;'>Delivery Notification</h2>
            <p>Dear {$order['customer_name']},</p>
            <p>Great news! Your order #{$order['order_number']} is scheduled for delivery today.</p>
            <p>Our delivery partner, {$order['rider_name']}, will be delivering your order.</p>
            <p>Please ensure someone is available to receive the delivery.</p>
            <br>
            <p>Best regards,</p>
            <p>Your Delivery Team</p>
        </div>
    </body>
    </html>";

    error_log("Sending route notification email to: " . $order['email']);
    
    $mail_result = mail($to, $subject, $message, implode("\r\n", $headers));
    
    if ($mail_result) {
        error_log("Route notification email sent successfully to {$order['email']} for order #{$order['order_number']}");
    } else {
        error_log("Failed to send route notification email to {$order['email']} for order #{$order['order_number']}");
    }

    return $mail_result;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $route_id = cleanInput($_POST['manifest_id']);  // Note: keeping POST field name as manifest_id for compatibility

        // Check if route is delivered before processing any action
        $check_status_query = "SELECT status FROM Manifests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $check_status_query);
        mysqli_stmt_bind_param($stmt, "i", $route_id);
        mysqli_stmt_execute($stmt);
        $status_result = mysqli_stmt_get_result($stmt);
        $current_status = mysqli_fetch_assoc($status_result)['status'];

        if ($current_status === 'delivered') {
            $error = 'Cannot modify a delivered route';  // Changed error message
        } else {
            mysqli_begin_transaction($conn);
            try {
               

                switch ($action) {
                    case 'remove_order':
                        $order_id = cleanInput($_POST['order_id']);

                        // Check if order is delivered
                        $order_status_check = "SELECT status FROM Orders WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $order_status_check);
                        mysqli_stmt_bind_param($stmt, "i", $order_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $order_row = mysqli_fetch_assoc($result);
                        if ($order_row && $order_row['status'] === 'delivered') {
                            $error = 'Cannot remove an order that is already delivered.';
                            break;
                        }

                        // Remove order from route
                        $remove_query = "DELETE FROM ManifestOrders WHERE manifest_id = ? AND order_id = ?";
                        $stmt = mysqli_prepare($conn, $remove_query);
                        mysqli_stmt_bind_param($stmt, "ii", $route_id, $order_id);
                        mysqli_stmt_execute($stmt);
                        $log_message = "Order #$order_id removed from Route #$route_id by user #" . $_SESSION['user_id'];  // Changed log message
writeLog("route.log", $log_message);  // Changed log file name

                        // Update order status back to pending
                        $update_order = "UPDATE Orders SET status = 'pending' WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $update_order);
                        mysqli_stmt_bind_param($stmt, "i", $order_id);
                        mysqli_stmt_execute($stmt);


                        // Add status log
                        $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, 'pending', ?)";
                        $stmt = mysqli_prepare($conn, $log_query);
                        mysqli_stmt_bind_param($stmt, "ii", $order_id, $_SESSION['user_id']);
                        mysqli_stmt_execute($stmt);

                        // Update route total orders
                        $update_route = "UPDATE Manifests SET total_orders_assigned = total_orders_assigned - 1 WHERE id = ?";  // Changed variable name
                        $stmt = mysqli_prepare($conn, $update_route);
                        mysqli_stmt_bind_param($stmt, "i", $route_id);
                        mysqli_stmt_execute($stmt);

                        mysqli_commit($conn);
                        $success = 'Order removed from route successfully';  // Changed success message
                        break;

                    case 'add_orders':
                        if (empty($_POST['orders'])) {
                            throw new Exception('Please select at least one order');
                        }

                        $selected_orders = $_POST['orders'];

                        // Get current orders in the route
                        $current_orders_query = "SELECT order_id FROM ManifestOrders WHERE manifest_id = ?";
                        $stmt = mysqli_prepare($conn, $current_orders_query);
                        mysqli_stmt_bind_param($stmt, "i", $route_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $current_orders = array();
                        while ($row = mysqli_fetch_assoc($result)) {
                            $current_orders[] = $row['order_id'];
                        }

                        // Find newly added orders
                        $new_orders = array_diff($selected_orders, $current_orders);

                        // Add only new orders to route
                        $is_rider_delivering = ($current_status === 'delivering');
                        $rider_id = $route['rider_id']; // Get rider ID from the route being edited
                        $rider_extras = [];

                         // Fetch rider's pending extra items if they are currently delivering
                        if ($is_rider_delivering && !empty($rider_id)) {
                            $extras_query = "SELECT product_id, SUM(quantity) as available_qty 
                                             FROM ExtraItemsLog 
                                             WHERE rider_id = ? AND status = 'pending' 
                                             GROUP BY product_id";
                            $stmt_extras = mysqli_prepare($conn, $extras_query);
                            mysqli_stmt_bind_param($stmt_extras, "i", $rider_id);
                            mysqli_stmt_execute($stmt_extras);
                            $result_extras = mysqli_stmt_get_result($stmt_extras);
                            while ($extra = mysqli_fetch_assoc($result_extras)) {
                                $rider_extras[$extra['product_id']] = (int)$extra['available_qty'];
                            }
                            mysqli_stmt_close($stmt_extras);
                            error_log("Rider {$rider_id} is delivering. Pending extras found for edit: " . print_r($rider_extras, true));
                        }

                        // Prepare statements for updates inside the loop
                        $update_product_order_sql = "UPDATE ProductOrders SET picked_quantity = ?, warehouse_missing_quantity = ? WHERE order_id = ? AND product_id = ?";
                        $stmt_update_po = mysqli_prepare($conn, $update_product_order_sql);

                        $find_extra_log_sql = "SELECT id, quantity FROM ExtraItemsLog WHERE rider_id = ? AND product_id = ? AND status = 'pending' AND quantity > 0 ORDER BY created_at ASC LIMIT 1";
                        $stmt_find_extra = mysqli_prepare($conn, $find_extra_log_sql);

                        $update_extra_log_sql = "UPDATE ExtraItemsLog SET quantity = ?, status = ? WHERE id = ?";
                        $stmt_update_extra = mysqli_prepare($conn, $update_extra_log_sql);

                        foreach ($new_orders as $order_id) {
                            // Add order to route
                            $add_order = "INSERT INTO ManifestOrders (manifest_id, order_id) VALUES (?, ?)";
                            $stmt_add = mysqli_prepare($conn, $add_order);
                            mysqli_stmt_bind_param($stmt_add, "ii", $route_id, $order_id);
                            mysqli_stmt_execute($stmt_add);
                            mysqli_stmt_close($stmt_add);
                           
    $user_id = $_SESSION['user_id'];
    $log_message_route = "Order #$order_id added to Route #$route_id by user #$user_id";  // Changed log message
    writeLog("route.log", $log_message_route);  // Changed log file name

    $log_message_order = "Order #$order_id status changed to 'assigned' by user #$user_id";
    writeLog("order.log", $log_message_order);


                            // --- Auto-allocate from extras if rider is delivering ---
                            if ($is_rider_delivering && !empty($rider_extras)) {
                                $order_products_query = "SELECT product_id, quantity FROM ProductOrders WHERE order_id = ?";
                                $stmt_order_prods = mysqli_prepare($conn, $order_products_query);
                                mysqli_stmt_bind_param($stmt_order_prods, "i", $order_id);
                                mysqli_stmt_execute($stmt_order_prods);
                                $result_order_prods = mysqli_stmt_get_result($stmt_order_prods);
                                
                                while ($product = mysqli_fetch_assoc($result_order_prods)) {
                                    $product_id = $product['product_id'];
                                    $required_qty = (int)$product['quantity'];
                                    $allocated_qty = 0;
                                    $missing_qty = $required_qty;

                                    if (isset($rider_extras[$product_id]) && $rider_extras[$product_id] > 0) {
                                        $available_extra = $rider_extras[$product_id];
                                        $allocate_now = min($required_qty, $available_extra);
                                        
                                        if ($allocate_now > 0) {
                                            // Update ProductOrder: set picked_quantity and warehouse_missing_quantity
                                            $allocated_qty = $allocate_now;
                                            $missing_qty = $required_qty - $allocated_qty;
                                            mysqli_stmt_bind_param($stmt_update_po, "iiii", $allocated_qty, $missing_qty, $order_id, $product_id);
                                            mysqli_stmt_execute($stmt_update_po);
                                            error_log("Auto-allocated {$allocate_now} of product {$product_id} to order {$order_id} from extras (edit).");

                                            // Decrease ExtraItemsLog quantity
                                            mysqli_stmt_bind_param($stmt_find_extra, "ii", $rider_id, $product_id);
                                            mysqli_stmt_execute($stmt_find_extra);
                                            $result_find_extra = mysqli_stmt_get_result($stmt_find_extra);
                                            if ($extra_log = mysqli_fetch_assoc($result_find_extra)) {
                                                $extra_log_id = $extra_log['id'];
                                                $current_extra_qty = (int)$extra_log['quantity'];
                                                $new_extra_qty = $current_extra_qty - $allocate_now;
                                                $new_status = ($new_extra_qty <= 0) ? 'assigned' : 'pending';
                                                
                                                mysqli_stmt_bind_param($stmt_update_extra, "isi", $new_extra_qty, $new_status, $extra_log_id);
                                                mysqli_stmt_execute($stmt_update_extra);
                                                error_log("Updated ExtraItemsLog ID {$extra_log_id} quantity from {$current_extra_qty} to {$new_extra_qty}, status to {$new_status} (edit).");

                                                // Update temporary array
                                                $rider_extras[$product_id] -= $allocate_now;
                                            } else {
                                                error_log("WARNING: Could not find suitable ExtraItemsLog entry for rider {$rider_id}, product {$product_id} despite available count (edit).");
                                            }
                                        } // end if allocate_now > 0
                                    } // end if extras available for product

                                     // If no extras were allocated, ensure missing quantity is set
                                    if ($allocated_qty == 0) {
                                         mysqli_stmt_bind_param($stmt_update_po, "iiii", $allocated_qty, $missing_qty, $order_id, $product_id);
                                         mysqli_stmt_execute($stmt_update_po);
                                         error_log("No extras for product {$product_id}, setting picked=0, missing={$missing_qty} for order {$order_id} (edit).");
                                    }
                                } // end while loop for order products
                                mysqli_stmt_close($stmt_order_prods);
                            } // end if rider is delivering and has extras
                            // --- End Auto-allocate ---
                            
                            // Update order status to match route status
                            $update_status = "UPDATE Orders SET status = ? WHERE id = ?";
                            $stmt_update_status = mysqli_prepare($conn, $update_status);
                            mysqli_stmt_bind_param($stmt_update_status, "si", $current_status, $order_id);
                            mysqli_stmt_execute($stmt_update_status);
                            mysqli_stmt_close($stmt_update_status);

                            // Send email notification
                            $email_sent = sendRouteNotificationEmail($order_id, $conn);  // Changed function name
                            if (!$email_sent) {
                                error_log("Failed to send route notification email for new order #$order_id");
                            }
                        }

                        // Close prepared statements used in loop
                        mysqli_stmt_close($stmt_update_po);
                        mysqli_stmt_close($stmt_find_extra);
                        mysqli_stmt_close($stmt_update_extra);

                        // Update route total orders
                        $update_route = "UPDATE Manifests SET total_orders_assigned = (
                            SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = ?
                        ) WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $update_route);
                        mysqli_stmt_bind_param($stmt, "ii", $route_id, $route_id);
                        mysqli_stmt_execute($stmt);

                        mysqli_commit($conn);
                        $success = 'Orders added to route successfully';  // Changed success message
                        $route['warehouse_id'] = $warehouse_id;
                        break;

                    case 'update_manifest':
                        $rider_id = !empty($_POST['rider_id']) ? cleanInput($_POST['rider_id']) : null;
                        $status = cleanInput($_POST['status']);
                        $old_rider_id = $route['rider_id'];
                        $warehouse_id = cleanInput($_POST['warehouse_id']);

                        // Prevent setting status to delivered if there's no rider
                        if ($status === 'delivered' && empty($rider_id)) {
                            throw new Exception('A rider must be assigned before marking as delivered');
                        }

                        // Auto-adjust status based on rider assignment
                        if ($old_rider_id != $rider_id) {
                            if (empty($rider_id)) {
                                $status = 'pending';
                            } elseif (empty($old_rider_id)) {
                                $status = 'assigned';
                            }
                        }

                        // Validate status change if no rider assigned
                        if ($status !== 'pending' && empty($rider_id)) {
                            throw new Exception('A rider must be assigned before changing to ' . $status . ' status');
                        }

                        // Update route
                        $update_query = "UPDATE Manifests SET 
                                       rider_id = ?, 
                                       status = ?,
                                       warehouse_id = ?,
                                       updated_at = CURRENT_TIMESTAMP
                                       WHERE id = ?";
                        
                        $stmt = mysqli_prepare($conn, $update_query);
                        
                        // Fix: Bind parameters in correct order matching the query
                        if ($rider_id === null) {
                            $rider_id = NULL; // Ensure it's NULL for database
                        }
                        mysqli_stmt_bind_param($stmt, "isis", $rider_id, $status, $warehouse_id, $route_id);
                        mysqli_stmt_execute($stmt);

                        // Get non-failed orders from this route
                        $get_valid_orders = "SELECT o.id 
                                           FROM Orders o 
                                           JOIN ManifestOrders mo ON o.id = mo.order_id 
                                           WHERE mo.manifest_id = ? 
                                           AND o.status != 'failed'";
                        $valid_orders_stmt = mysqli_prepare($conn, $get_valid_orders);
                        mysqli_stmt_bind_param($valid_orders_stmt, "i", $route_id);
                        mysqli_stmt_execute($valid_orders_stmt);
                        $valid_orders_result = mysqli_stmt_get_result($valid_orders_stmt);

                        $valid_order_ids = [];
                        while ($order = mysqli_fetch_assoc($valid_orders_result)) {
                            $valid_order_ids[] = $order['id'];
                        }

                        // Update only non-failed orders
                        if (!empty($valid_order_ids)) {
                            $order_ids_string = implode(',', $valid_order_ids);
                            
                            // Update orders status
                            $update_orders = "UPDATE Orders 
                                            SET status = ? 
                                            WHERE id IN ($order_ids_string)";
                            $orders_stmt = mysqli_prepare($conn, $update_orders);
                            mysqli_stmt_bind_param($orders_stmt, "s", $status);
                            mysqli_stmt_execute($orders_stmt);

                            // Add status logs
                            $log_query = "INSERT INTO OrderStatusLogs 
                                        (order_id, status, changed_by) 
                                        VALUES (?, ?, ?)";
                            $log_stmt = mysqli_prepare($conn, $log_query);

                            foreach ($valid_order_ids as $order_id) {
                                mysqli_stmt_bind_param($log_stmt, "isi", 
                                    $order_id, 
                                    $status, 
                                    $_SESSION['user_id']
                                );
                                mysqli_stmt_execute($log_stmt);
                            }
                            writeLog("route.log", "Route #$route_id status changed to '$status' by user #" . $_SESSION['user_id']);  // Changed log message
                        }

                        // Send notification if a new rider is assigned
                        if ($rider_id && $old_rider_id != $rider_id) {
                            // Get route details for the notification
                            $route_query = "SELECT m.id, m.total_orders_assigned, w.name as warehouse_name, 
                                                    w.city as warehouse_city 
                                             FROM Manifests m 
                                             LEFT JOIN Warehouses w ON m.warehouse_id = w.id 
                                             WHERE m.id = ?";
                            $route_stmt = mysqli_prepare($conn, $route_query);
                            mysqli_stmt_bind_param($route_stmt, "i", $route_id);
                            mysqli_stmt_execute($route_stmt);
                            $route_result = mysqli_stmt_get_result($route_stmt);
                            $route_details = mysqli_fetch_assoc($route_result);

                            // Create notification message
                            $title = "Route Assigned";  // Changed notification title
                            $message = "You have been assigned route #" . $route_id;  // Changed message
                            if ($route_details['warehouse_name']) {
                                $message .= " from " . $route_details['warehouse_name'] . 
                                           " (" . $route_details['warehouse_city'] . ")";
                            }
                            $message .= " with " . $route_details['total_orders_assigned'] . " orders";

                            // Send notification to the new rider
                            sendFirebaseNotification($rider_id, $title, $message);
                        }

                        // If status changed to delivering/delivered, notify rider
                        if ($rider_id && $current_status != $status && 
                            in_array($status, ['delivering', 'delivered'])) {
                            $title = "Route Status Updated";  // Changed notification title
                            $message = "Route #" . $route_id . " status has been updated to " . ucfirst($status);  // Changed message
                            sendFirebaseNotification($rider_id, $title, $message);
                        }

                        // --- NEW: Delete ALL Extra items related to this route if marked as delivered or failed ---
                        if ($status === 'delivered' || $status === 'failed') {
                           $log_message_status = ($status === 'delivered') ? "delivered" : "failed";
                           error_log("Route {$route_id} marked as {$log_message_status}. Deleting ALL related ExtraItemsLog entries (from edit).");
                           
                           // Delete items from initial warehouse scan
                           $delete_scan_extras_sql = "DELETE FROM ExtraItemsLog WHERE source_manifest_id = ?";
                           $stmt_delete_scan = mysqli_prepare($conn, $delete_scan_extras_sql);
                           if ($stmt_delete_scan) {
                                mysqli_stmt_bind_param($stmt_delete_scan, "i", $route_id);
                                if (mysqli_stmt_execute($stmt_delete_scan)) {
                                    $deleted_scan_count = mysqli_stmt_affected_rows($stmt_delete_scan);
                                    error_log("Deleted {$deleted_scan_count} direct route scan extra item records for route {$route_id} (from edit).");
                                } else {
                                    error_log("Failed to execute delete scan extra items statement for route {$route_id} (from edit): " . mysqli_stmt_error($stmt_delete_scan));
                                }
                                mysqli_stmt_close($stmt_delete_scan);
                           } else {
                                error_log("Failed to prepare delete scan extra items statement for route {$route_id} (from edit): " . mysqli_error($conn));
                           }

                           // Delete items from failed/rejected orders within this route
                           $delete_order_extras_sql = "DELETE FROM ExtraItemsLog WHERE source_order_id IN (SELECT order_id FROM ManifestOrders WHERE manifest_id = ?)";
                           $stmt_delete_order = mysqli_prepare($conn, $delete_order_extras_sql);
                           if ($stmt_delete_order) {
                               mysqli_stmt_bind_param($stmt_delete_order, "i", $route_id);
                               if (mysqli_stmt_execute($stmt_delete_order)) {
                                   $deleted_order_count = mysqli_stmt_affected_rows($stmt_delete_order);
                                   error_log("Deleted {$deleted_order_count} failed/rejected order extra item records for route {$route_id} (from edit).");
                               } else {
                                   error_log("Failed to execute delete order extra items statement for route {$route_id} (from edit): " . mysqli_stmt_error($stmt_delete_order));
                               }
                               mysqli_stmt_close($stmt_delete_order);
                           } else {
                                error_log("Failed to prepare delete order extra items statement for route {$route_id} (from edit): " . mysqli_error($conn));
                           }
                        }
                        // --- END NEW ---

                        mysqli_commit($conn);
                        $success = 'Route updated successfully';  // Changed success message
                        $route['warehouse_id'] = $warehouse_id;
                        break;
                }

                // Refresh page to show updated data
                header("Location: edit.php?id=" . $route_id . "&success=" . urlencode($success));
                exit();
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
                error_log("Error updating route: " . $error);  // Changed error message
                 logException($error);
            }
        }
    }
}

// Get success message from URL if it exists
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Route - <?php echo SITE_NAME; ?></title>  <!-- Changed page title -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <!-- Top Bar -->
        <div class="bg-gray-800 p-4 flex justify-between items-center">
            <div class="text-white text-lg">Admin Panel</div>
            <div class="flex items-center">
                <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                <a href="<?php echo SITE_URL; ?>logout.php"
                    class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">
                    Logout
                </a>
            </div>
        </div>
        <!-- Page Content -->
        <div class="p-4">

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Edit Route #<?php echo $route['id']; ?></h1>  <!-- Changed heading -->
                        <p class="mt-1 text-sm text-gray-500">
                            Created <?php echo date('M d, Y H:i', strtotime($route['created_at'])); ?>  <!-- Changed from $manifest to $route -->
                        </p>
                    </div>
                    <div class="space-x-2">
                        <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Back to List</a>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($is_delivered): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline">This route is delivered and cannot be modified.</span>  <!-- Changed text -->
                    </div>
                <?php endif; ?>

                <!-- Route Details Form -->  <!-- Changed section title -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
                    <form action="" method="POST" id="routeForm">  <!-- Changed form ID -->
                        <input type="hidden" name="action" value="update_manifest">
                        <input type="hidden" name="manifest_id" value="<?php echo $route['id']; ?>">

                        <div class="space-y-6 bg-white px-4 py-5 sm:p-6">
                            <div>
                                <label for="warehouse_id" class="block text-sm font-medium text-gray-700">Warehouse *</label>
                                <select name="warehouse_id" id="warehouse_id" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?php echo $warehouse['id']; ?>" 
                                                <?php echo ($route['warehouse_id'] == $warehouse['id']) ? 'selected' : ''; ?>>  <!-- Changed from $manifest to $route -->
                                            <?php echo htmlspecialchars($warehouse['name'] . ' - ' . $warehouse['city']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label for="rider_id" class="block text-sm font-medium text-gray-700">Assigned Rider</label>
                                    <select name="rider_id" id="rider_id" <?php echo $is_delivered ? 'disabled' : ''; ?>
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                        <option value="">Select Rider</option>
                                        <?php if (!$is_delivered): while ($rider = mysqli_fetch_assoc($riders_result)): ?>
                                                <option value="<?php echo $rider['id']; ?>"
                                                    <?php echo $rider['id'] == $route['rider_id'] ? 'selected' : ''; ?>>  <!-- Changed from $manifest to $route -->
                                                    <?php echo htmlspecialchars($rider['name']); ?>
                                                </option>
                                        <?php endwhile;
                                        endif; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                    <select name="status" id="status" required <?php echo $is_delivered ? 'disabled' : ''; ?>
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>">
                                        <option value="pending" <?php echo $route['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>  <!-- Changed from $manifest to $route -->
                                        <option value="assigned" <?php echo $route['status'] === 'assigned' ? 'selected' : ''; ?>>Assigned</option>  <!-- Changed from $manifest to $route -->
                                        <option value="delivering" <?php echo $route['status'] === 'delivering' ? 'selected' : ''; ?>>Delivering</option>  <!-- Changed from $manifest to $route -->
                                        <option value="delivered" <?php echo $route['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>  <!-- Changed from $manifest to $route -->
                                    </select>
                                </div>
                            </div>

                            <?php if (!$is_delivered): ?>
                                <div class="mt-4">
                                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                                        Update Route Details  <!-- Changed button text -->
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Current Orders Section with Products -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-medium text-gray-900">Current Orders in Route</h2>  <!-- Changed heading -->
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="id" value="<?php echo $route['id']; ?>">  <!-- Changed from $manifest to $route -->
                            <input type="text" name="search_current"
                                value="<?php echo htmlspecialchars($search_current); ?>"
                                placeholder="Search current orders..."
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <button type="submit"
                                class="bg-indigo-600 text-white px-3 py-1 rounded-md hover:bg-indigo-700 text-sm">
                                Search
                            </button>
                            <?php if ($search_current): ?>
                                <a href="?id=<?php echo $route['id']; ?>"  <!-- Changed from $manifest to $route -->
                                    class="bg-gray-200 text-gray-700 px-3 py-1 rounded-md hover:bg-gray-300 text-sm">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if (empty($route_orders)): ?>  <!-- Changed from $manifest_orders to $route_orders -->
                        <p class="text-gray-500">No orders in this route.</p>  <!-- Changed text -->
                    <?php else: ?>
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($route_orders as $order): ?>  <!-- Changed from $manifest_orders to $route_orders -->
                                <div class="border rounded-lg p-4">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <div class="text-sm font-medium text-gray-900">
                                                Order #<?php echo $order['order_number']; ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['address_line1'] ?? 'No Address'); ?>,
                                                <?php echo htmlspecialchars($order['city'] ?? 'N/A'); ?>
                                            </div>
                                            <?php
                                            $products = !empty($order['products']) ? explode(', ', $order['products']) : [];
                                            $quantities = !empty($order['quantities']) ? explode(', ', $order['quantities']) : [];
                                            ?>
                                            <?php if (!empty($products) && !empty($quantities)): ?>
                                                <div class="mt-2">
                                                    <p class="text-sm font-medium text-gray-700">Products:</p>
                                                    <ul class="mt-1 text-sm text-gray-500">
                                                        <?php foreach ($products as $index => $product): ?>
                                                            <li>
                                                                <?php echo htmlspecialchars($product); ?>
                                                                <?php if (isset($quantities[$index])): ?>
                                                                    <span class="text-gray-400">
                                                                        (Qty: <?php echo htmlspecialchars($quantities[$index]); ?>)
                                                                    </span>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-sm text-gray-500 mt-1">
                                                Amount: $<?php echo number_format($order['total_amount'], 2); ?>
                                            </div>
                                        </div>
                                        <?php if (!$is_delivered): ?>
                                            <form method="POST" class="ml-4" onsubmit="return confirm('Are you sure you want to remove this order?');">
                                                <input type="hidden" name="action" value="remove_order">
                                                <input type="hidden" name="manifest_id" value="<?php echo $route['id']; ?>">  <!-- Changed from $manifest to $route -->
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                <button type="submit" class="bg-red-100 text-red-800 px-3 py-1 rounded-md hover:bg-red-200">
                                                    Remove
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$is_delivered): ?>
                    <!-- Add Orders Section with Products -->
                    <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-medium text-gray-900">Add Orders to Route</h2>  <!-- Changed heading -->
                            <form method="GET" class="flex gap-2">
                                <input type="hidden" name="id" value="<?php echo $route['id']; ?>">  <!-- Changed from $manifest to $route -->
                                <input type="text" name="search_unassigned"
                                    value="<?php echo htmlspecialchars($search_unassigned); ?>"
                                    placeholder="Search available orders..."
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <button type="submit"
                                    class="bg-indigo-600 text-white px-3 py-1 rounded-md hover:bg-indigo-700 text-sm">
                                    Search
                                </button>
                                <?php if ($search_unassigned): ?>
                                    <a href="?id=<?php echo $route['id']; ?>"  <!-- Changed from $manifest to $route -->
                                        class="bg-gray-200 text-gray-700 px-3 py-1 rounded-md hover:bg-gray-300 text-sm">
                                        Clear
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <?php if (mysqli_num_rows($unassigned_orders_result) > 0): ?>
                            <form action="" method="POST">
                                <input type="hidden" name="action" value="add_orders">
                                <input type="hidden" name="manifest_id" value="<?php echo $route['id']; ?>">  <!-- Changed from $manifest to $route -->

                                <div class="grid grid-cols-1 gap-4 mb-4">
                                    <?php while ($order = mysqli_fetch_assoc($unassigned_orders_result)): ?>
                                        <div class="border rounded-lg p-4">
                                            <label class="flex items-start space-x-3">
                                                <input type="checkbox" name="orders[]" value="<?php echo $order['id']; ?>"
                                                    class="mt-1 h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                                <div class="flex-1">
                                                    <div class="flex justify-between">
                                                        <span class="text-sm font-medium text-gray-900">
                                                            Order #<?php echo $order['order_number']; ?>
                                                        </span>
                                                        <span class="text-sm text-gray-500">
                                                            <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?> -
                                                        <?php echo htmlspecialchars($order['address_line1'] ?? 'No Address'); ?>,
                                                        <?php echo htmlspecialchars($order['city'] ?? 'N/A'); ?>
                                                    </p>
                                                    <?php
                                                    $products = !empty($order['products']) ? explode(', ', $order['products']) : [];
                                                    $quantities = !empty($order['quantities']) ? explode(', ', $order['quantities']) : [];
                                                    ?>
                                                    <?php if (!empty($products) && !empty($quantities)): ?>
                                                        <div class="mt-2">
                                                            <p class="text-sm font-medium text-gray-700">Products:</p>
                                                            <ul class="mt-1 text-sm text-gray-500">
                                                                <?php foreach ($products as $index => $product): ?>
                                                                    <li>
                                                                        <?php echo htmlspecialchars($product); ?>
                                                                        <?php if (isset($quantities[$index])): ?>
                                                                            <span class="text-gray-400">
                                                                                (Qty: <?php echo htmlspecialchars($quantities[$index]); ?>)
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                    <p class="text-sm text-gray-500 mt-1">
                                                        Amount: $<?php echo number_format($order['total_amount'], 2); ?>
                                                    </p>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>
                                </div>

                                <div class="flex justify-between items-center">
                                    <div>
                                        <button type="button" id="selectAll" class="text-sm text-indigo-600 hover:text-indigo-800 mr-4">
                                            Select All
                                        </button>
                                        <button type="button" id="deselectAll" class="text-sm text-gray-600 hover:text-gray-800">
                                            Deselect All
                                        </button>
                                    </div>
                                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                                        Add Selected Orders
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="text-gray-500">No unassigned orders available.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>


        <script>
            $(document).ready(function() {
                // Select/Deselect All functionality
                $('#selectAll').click(function() {
                    $('input[name="orders[]"]').prop('checked', true);
                });

                $('#deselectAll').click(function() {
                    $('input[name="orders[]"]').prop('checked', false);
                });

                // Form validation for adding orders
                $('form[action="add_orders"]').submit(function(e) {
                    if (!$('input[name="orders[]"]:checked').length) {
                        e.preventDefault();
                        alert('Please select at least one order to add');
                        return false;
                    }
                    return confirm('Are you sure you want to add the selected orders to this route?');  // Changed confirmation message
                });

                // Handle rider and status changes
                $('#routeForm').on('submit', function(e) {  // Changed form ID
                    const status = $('#status').val();

                    if (status === 'delivered') {
                        return confirm('Are you sure you want to mark this route as delivered? This action cannot be undone and all products will be marked as delivered.');  // Changed confirmation message
                    }

                    if ($('#rider_id').val() === '' && status !== 'pending') {
                        alert('Please assign a rider before changing the status.');
                        e.preventDefault();
                        return false;
                    }

                    return true;
                });

                // Handle rider selection change
                $('#rider_id').on('change', function() {
                    const riderSelected = $(this).val();
                    const statusSelect = $('#status');

                    if (!riderSelected) {
                        statusSelect.val('pending');
                    }
                });

                // Handle status change
                $('#status').on('change', function() {
                    const status = $(this).val();
                    const riderId = $('#rider_id').val();

                    if (status !== 'pending' && !riderId) {
                        alert('Please assign a rider before changing the status.');
                        $(this).val('pending');
                    }
                });
            });
        </script>
</body>

</html>