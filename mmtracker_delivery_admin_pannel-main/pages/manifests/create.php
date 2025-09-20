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

// Fetch available riders with company association
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

// Get search parameters
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? cleanInput($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? cleanInput($_GET['date_to']) : '';

// Build the orders query with search conditions
$orders_query = "SELECT
                    o.id, o.order_number, o.created_at, o.company_id,
                    c.name as company_name,
                    cust.name as customer_name,
                    addr.address_line1, addr.city
                FROM Orders o
                LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
                LEFT JOIN Companies c ON o.company_id = c.id
                LEFT JOIN Customers cust ON o.customer_id = cust.id
                LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
                WHERE mo.id IS NULL AND o.status = 'pending'";

if (!isSuperAdmin()) {
    $orders_query .= " AND o.company_id = " . $_SESSION['company_id'];
}

if ($search) {
    $orders_query .= " AND (o.order_number LIKE '%".mysqli_real_escape_string($conn, $search)."%' 
                      OR cust.name LIKE '%".mysqli_real_escape_string($conn, $search)."%' 
                      OR addr.address_line1 LIKE '%".mysqli_real_escape_string($conn, $search)."%' 
                      OR addr.city LIKE '%".mysqli_real_escape_string($conn, $search)."%')";
}

if ($date_from) {
    $orders_query .= " AND DATE(o.created_at) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}

if ($date_to) {
    $orders_query .= " AND DATE(o.created_at) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

$orders_query .= " ORDER BY o.created_at DESC";
$orders_result = mysqli_query($conn, $orders_query);

// Fetch available warehouses for the company
$warehouses_query = "SELECT id, name, address, city FROM Warehouses WHERE status = 'active'";
if (!isSuperAdmin()) {
    $warehouses_query .= " AND company_id = " . $_SESSION['company_id'];
}
$warehouses_result = mysqli_query($conn, $warehouses_query);
$warehouses = [];
while ($warehouse = mysqli_fetch_assoc($warehouses_result)) {
    $warehouses[] = $warehouse;
}

// Add this function at the top of the file
function sendRouteNotificationEmail($order_id, $conn) {  // Changed from sendManifestNotificationEmail
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
    
    // Set proper headers for HTML email
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

    // Debug logs
    error_log("Sending route notification email to: " . $order['email']);
    
    // Try to send email
    $mail_result = mail($to, $subject, $message, implode("\r\n", $headers));
    
    // Log the result
    if ($mail_result) {
        error_log("Route notification email sent successfully to {$order['email']} for order #{$order['order_number']}");
    } else {
        error_log("Failed to send route notification email to {$order['email']} for order #{$order['order_number']}");
    }

    return $mail_result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rider_id = !empty($_POST['rider_id']) ? cleanInput($_POST['rider_id']) : null;
    $warehouse_id = !empty($_POST['warehouse_id']) ? cleanInput($_POST['warehouse_id']) : null;
    $company_id = !isSuperAdmin() ? $_SESSION['company_id'] : cleanInput($_POST['company_id']);
    $selected_orders = isset($_POST['orders']) ? $_POST['orders'] : [];
    $status = 'assigned'; // Define status as a variable

    if (empty($warehouse_id)) {
        $error = 'Warehouse is required';
    } else if (empty($selected_orders)) {
        $error = 'Please select at least one order';
    } else {
        mysqli_begin_transaction($conn);
        try {
         

            // Determine initial route status based on rider assignment
            $status = !empty($rider_id) ? 'assigned' : 'pending';
            $is_rider_delivering = false;

            // Check if the selected rider is currently delivering another route
            if (!empty($rider_id)) {
                $check_delivery_status_query = "SELECT COUNT(*) as delivering_count 
                                                FROM Manifests 
                                                WHERE rider_id = ? AND status = 'delivering'";
                $stmt_check_delivery = mysqli_prepare($conn, $check_delivery_status_query);
                mysqli_stmt_bind_param($stmt_check_delivery, "i", $rider_id);
                mysqli_stmt_execute($stmt_check_delivery);
                $result_check_delivery = mysqli_stmt_get_result($stmt_check_delivery);
                $delivery_count = mysqli_fetch_assoc($result_check_delivery)['delivering_count'];
                $is_rider_delivering = ($delivery_count > 0);
                mysqli_stmt_close($stmt_check_delivery);
            }

            // Fetch rider's pending extra items if they are currently delivering
            $rider_extras = [];
            if ($is_rider_delivering) {
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
                error_log("Rider {$rider_id} is delivering. Pending extras found: " . print_r($rider_extras, true));
            }

            // Create route
            $query = "INSERT INTO Manifests (company_id, rider_id, status, warehouse_id) VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "iisi", $company_id, $rider_id, $status, $warehouse_id);
            mysqli_stmt_execute($stmt);
            $route_id = mysqli_insert_id($conn);  // Changed variable name from manifest_id to route_id
            $log_message = "Route #$route_id created by user #" . $_SESSION['user_id'];  // Changed log message
writeLog("route.log", $log_message);  // Changed log file name

            // Prepare statements for updates inside the loop
            $update_product_order_sql = "UPDATE ProductOrders SET picked_quantity = ?, warehouse_missing_quantity = ? WHERE order_id = ? AND product_id = ?";
            $stmt_update_po = mysqli_prepare($conn, $update_product_order_sql);

            $find_extra_log_sql = "SELECT id, quantity FROM ExtraItemsLog WHERE rider_id = ? AND product_id = ? AND status = 'pending' AND quantity > 0 ORDER BY created_at ASC LIMIT 1";
            $stmt_find_extra = mysqli_prepare($conn, $find_extra_log_sql);

            $update_extra_log_sql = "UPDATE ExtraItemsLog SET quantity = ?, status = ? WHERE id = ?";
            $stmt_update_extra = mysqli_prepare($conn, $update_extra_log_sql);
            
            // Add selected orders to route
            if (!empty($selected_orders)) {
                foreach ($selected_orders as $order_id) {
                    // Add order to route
                    $add_query = "INSERT INTO ManifestOrders (manifest_id, order_id) VALUES (?, ?)";
                    $stmt_add = mysqli_prepare($conn, $add_query);
                    mysqli_stmt_bind_param($stmt_add, "ii", $route_id, $order_id);
                    mysqli_stmt_execute($stmt_add);
                    mysqli_stmt_close($stmt_add); // Close statement after use
                    
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
                                    error_log("Auto-allocated {$allocate_now} of product {$product_id} to order {$order_id} from extras.");

                                    // Decrease ExtraItemsLog quantity (simple version: find first applicable log entry)
                                    mysqli_stmt_bind_param($stmt_find_extra, "ii", $rider_id, $product_id);
                                    mysqli_stmt_execute($stmt_find_extra);
                                    $result_find_extra = mysqli_stmt_get_result($stmt_find_extra);
                                    if ($extra_log = mysqli_fetch_assoc($result_find_extra)) {
                                        $extra_log_id = $extra_log['id'];
                                        $current_extra_qty = (int)$extra_log['quantity'];
                                        $new_extra_qty = $current_extra_qty - $allocate_now;
                                        $new_status = ($new_extra_qty <= 0) ? 'assigned' : 'pending'; // Update status if depleted
                                        
                                        mysqli_stmt_bind_param($stmt_update_extra, "isi", $new_extra_qty, $new_status, $extra_log_id);
                                        mysqli_stmt_execute($stmt_update_extra);
                                        error_log("Updated ExtraItemsLog ID {$extra_log_id} quantity from {$current_extra_qty} to {$new_extra_qty}, status to {$new_status}.");

                                        // Update the temporary rider_extras array
                                        $rider_extras[$product_id] -= $allocate_now;
                                    } else {
                                         error_log("WARNING: Could not find suitable ExtraItemsLog entry for rider {$rider_id}, product {$product_id} despite available count.");
                                    }
                                } // end if allocate_now > 0
                            } // end if extras available for product
                            
                            // If no extras were allocated, ensure missing quantity is set
                            if ($allocated_qty == 0) {
                                 mysqli_stmt_bind_param($stmt_update_po, "iiii", $allocated_qty, $missing_qty, $order_id, $product_id);
                                 mysqli_stmt_execute($stmt_update_po);
                                 error_log("No extras for product {$product_id}, setting picked=0, missing={$missing_qty} for order {$order_id}.");
                            }
                        } // end while loop for order products
                        mysqli_stmt_close($stmt_order_prods);
                    } // end if rider is delivering and has extras
                    // --- End Auto-allocate ---
                    
                    // Update order status (should still be 'assigned' even if allocated)
                    $update_order = "UPDATE Orders SET status = ? WHERE id = ?";
                    $stmt_update_order = mysqli_prepare($conn, $update_order);
                    mysqli_stmt_bind_param($stmt_update_order, "si", $status, $order_id);
                    mysqli_stmt_execute($stmt_update_order);
                    mysqli_stmt_close($stmt_update_order); // Close statement

                    // Send notification email to customer (only if rider assigned)
                    if (!empty($rider_id)) {
                         $email_sent = sendRouteNotificationEmail($order_id, $conn);  // Changed function name
                         if (!$email_sent) {
                            error_log("Failed to send route notification email for order #$order_id");
                        }
                    }
                } // end foreach selected_orders

                // Close prepared statements used in loop
                mysqli_stmt_close($stmt_update_po);
                mysqli_stmt_close($stmt_find_extra);
                mysqli_stmt_close($stmt_update_extra);
                
                // Update route total orders
                $update_route = "UPDATE Manifests SET total_orders_assigned = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_route);
                $count = count($selected_orders);
                mysqli_stmt_bind_param($stmt, "ii", $count, $route_id);
                mysqli_stmt_execute($stmt);
            }
            
            mysqli_commit($conn);
            
            // Send notification if rider is assigned
            if (!empty($rider_id)) {
                // Get route details for the notification
                $route_query = "SELECT m.id, m.total_orders_assigned, w.name as warehouse_name, w.city as warehouse_city 
                                  FROM Manifests m 
                                  LEFT JOIN Warehouses w ON m.warehouse_id = w.id 
                                  WHERE m.id = ?";
                $route_stmt = mysqli_prepare($conn, $route_query);
                mysqli_stmt_bind_param($route_stmt, "i", $route_id);
                mysqli_stmt_execute($route_stmt);
                $route_result = mysqli_stmt_get_result($route_stmt);
                $route_details = mysqli_fetch_assoc($route_result);
                
                // Create notification message
                $title = "New Route Assigned";  // Changed from "New Manifest Assigned"
                $message = "You have been assigned a new route #" . $route_id;  // Changed from "manifest" to "route"
                if ($route_details['warehouse_name']) {
                    $message .= " from " . $route_details['warehouse_name'] . " (" . $route_details['warehouse_city'] . ")";
                }
                $message .= " with " . $route_details['total_orders_assigned'] . " orders";
                
                // Send notification to rider
                sendFirebaseNotification($rider_id, $title, $message);
            }
            
            header("Location: index.php?success=Route created successfully");  // Changed success message
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
            error_log("Error creating route: " . $error);  // Changed error message
             logException($error);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Route - <?php echo SITE_NAME; ?></title>  <!-- Changed page title -->
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
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-900">Create New Route</h1>  <!-- Changed heading -->
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

                <!-- Search and Filter Form -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
                    <form method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                                <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Order #, Customer, Address..."
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="date_from" class="block text-sm font-medium text-gray-700">Date From</label>
                                <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="date_to" class="block text-sm font-medium text-gray-700">Date To</label>
                                <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                                Filter Orders
                            </button>
                        </div>
                    </form>
                </div>

                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
                    <form action="" method="POST" id="routeForm">  <!-- Changed form ID -->
                        <div class="space-y-6 bg-white px-4 py-5 sm:p-6">
                            <div>
                                <label for="warehouse_id" class="block text-sm font-medium text-gray-700">Warehouse *</label>
                                <select name="warehouse_id" id="warehouse_id" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?php echo $warehouse['id']; ?>">
                                            <?php echo htmlspecialchars($warehouse['name'] . ' - ' . $warehouse['city']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-6">
                                <label for="rider_id" class="block text-sm font-medium text-gray-700">Assign Rider</label>
                                <select name="rider_id" id="rider_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select Rider (Optional)</option>
                                    <?php while ($rider = mysqli_fetch_assoc($riders_result)): ?>
                                        <option value="<?php echo $rider['id']; ?>"><?php echo htmlspecialchars($rider['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">
                                    Note: If no rider is selected, the route and orders will remain in 'pending' status.  <!-- Changed text -->
                                    If a rider is selected, they will be set to 'assigned' status.
                                </p>
                            </div>

                            <div class="mb-6">
                                <div class="flex justify-between items-center mb-2">
                                    <label class="block text-sm font-medium text-gray-700">Select Orders</label>
                                    <div class="space-x-2">
                                        <button type="button" id="selectAll" class="text-sm text-indigo-600 hover:text-indigo-800">
                                            Select All
                                        </button>
                                        <button type="button" id="deselectAll" class="text-sm text-gray-600 hover:text-gray-800">
                                            Deselect All
                                        </button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 gap-4">
                                    <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                                        <div class="border rounded-lg p-4">
                                            <label class="flex items-start space-x-3">
                                                <input type="checkbox" name="orders[]" value="<?php echo $order['id']; ?>"
                                                    class="order-checkbox mt-1 h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
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
                                                </div>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>

                                    <?php if (mysqli_num_rows($orders_result) == 0): ?>
                                        <div class="text-center py-4 text-gray-500">
                                            No unassigned orders available
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                                Create Route  <!-- Changed button text -->
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            $(document).ready(function() {
                // Select/Deselect All functionality
                $('#selectAll').click(function() {
                    $('.order-checkbox').prop('checked', true);
                });

                $('#deselectAll').click(function() {
                    $('.order-checkbox').prop('checked', false);
                });

                // Form validation
                $('#routeForm').submit(function(e) {  // Changed form ID
                    const selectedOrders = $('.order-checkbox:checked').length;
                    if (selectedOrders === 0) {
                        e.preventDefault();
                        alert('Please select at least one order');
                        return false;
                    }
                    return true;
                });
            });
        </script>
</body>

</html>