<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';
$manifest = null;
$manifest_orders = [];
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

    // Fetch manifest details
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
    $manifest = mysqli_fetch_assoc($result);

    if (!$manifest) {
        header('Location: index.php');
        exit();
    }

    // Check if manifest is delivered
    $is_delivered = $manifest['status'] === 'delivered';

    // Fetch current orders in manifest with search
    $orders_query = "SELECT o.*, 
                    GROUP_CONCAT(p.name SEPARATOR ', ') as products,
                    GROUP_CONCAT(po.quantity SEPARATOR ', ') as quantities
                    FROM Orders o
                    JOIN ManifestOrders mo ON o.id = mo.order_id
                    LEFT JOIN ProductOrders po ON o.id = po.order_id
                    LEFT JOIN Products p ON po.product_id = p.id
                    WHERE mo.manifest_id = ?";
    if ($search_current) {
        $orders_query .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? 
                          OR o.address_line1 LIKE ? OR o.city LIKE ?)";
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
        $manifest_orders[] = $order;
    }
}

// Only fetch available unassigned orders and riders if manifest is not delivered
if (!$is_delivered) {
    // Fetch available unassigned orders with search
    $unassigned_orders_query = "SELECT o.*, 
                               GROUP_CONCAT(p.name SEPARATOR ', ') as products,
                               GROUP_CONCAT(po.quantity SEPARATOR ', ') as quantities
                               FROM Orders o 
                               LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
                               LEFT JOIN ProductOrders po ON o.id = po.order_id
                               LEFT JOIN Products p ON po.product_id = p.id
                               WHERE mo.id IS NULL AND o.status = 'pending'";
    if (!isSuperAdmin()) {
        $unassigned_orders_query .= " AND o.company_id = " . $_SESSION['company_id'];
    }
    if ($search_unassigned) {
        $unassigned_orders_query .= " AND (o.order_number LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR o.customer_name LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR o.address_line1 LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%'
                                     OR o.city LIKE '%" . mysqli_real_escape_string($conn, $search_unassigned) . "%')";
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $manifest_id = cleanInput($_POST['manifest_id']);

        // Check if manifest is delivered before processing any action
        $check_status_query = "SELECT status FROM Manifests WHERE id = ?";
        $stmt = mysqli_prepare($conn, $check_status_query);
        mysqli_stmt_bind_param($stmt, "i", $manifest_id);
        mysqli_stmt_execute($stmt);
        $status_result = mysqli_stmt_get_result($stmt);
        $current_status = mysqli_fetch_assoc($status_result)['status'];

        if ($current_status === 'delivered') {
            $error = 'Cannot modify a delivered manifest';
        } else {
            mysqli_begin_transaction($conn);
            try {
                switch ($action) {
                    case 'remove_order':
                        $order_id = cleanInput($_POST['order_id']);

                        // Remove order from manifest
                        $remove_query = "DELETE FROM ManifestOrders WHERE manifest_id = ? AND order_id = ?";
                        $stmt = mysqli_prepare($conn, $remove_query);
                        mysqli_stmt_bind_param($stmt, "ii", $manifest_id, $order_id);
                        mysqli_stmt_execute($stmt);

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

                        // Update manifest total orders
                        $update_manifest = "UPDATE Manifests SET total_orders_assigned = total_orders_assigned - 1 WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $update_manifest);
                        mysqli_stmt_bind_param($stmt, "i", $manifest_id);
                        mysqli_stmt_execute($stmt);

                        mysqli_commit($conn);
                        $success = 'Order removed from manifest successfully';
                        break;

                    case 'add_orders':
                        if (empty($_POST['orders'])) {
                            throw new Exception('Please select at least one order');
                        }

                        $selected_orders = $_POST['orders'];

                        foreach ($selected_orders as $order_id) {
                            // Add order to manifest
                            $add_query = "INSERT INTO ManifestOrders (manifest_id, order_id) VALUES (?, ?)";
                            $stmt = mysqli_prepare($conn, $add_query);
                            mysqli_stmt_bind_param($stmt, "ii", $manifest_id, $order_id);
                            mysqli_stmt_execute($stmt);

                            // Set order status based on manifest status and rider
                            $new_status = !empty($manifest['rider_id']) ? $manifest['status'] : 'pending';
                            $update_order = "UPDATE Orders SET status = ? WHERE id = ?";
                            $stmt = mysqli_prepare($conn, $update_order);
                            mysqli_stmt_bind_param($stmt, "si", $new_status, $order_id);
                            mysqli_stmt_execute($stmt);

                            // Add status log
                            $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $log_query);
                            mysqli_stmt_bind_param($stmt, "isi", $order_id, $new_status, $_SESSION['user_id']);
                            mysqli_stmt_execute($stmt);
                        }

                        // Update manifest total orders
                        $update_manifest = "UPDATE Manifests 
                                          SET total_orders_assigned = total_orders_assigned + ?
                                          WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $update_manifest);
                        $count = count($selected_orders);
                        mysqli_stmt_bind_param($stmt, "ii", $count, $manifest_id);
                        mysqli_stmt_execute($stmt);

                        mysqli_commit($conn);
                        $success = 'Orders added to manifest successfully';
                        break;

                    case 'update_manifest':
                        try {
                            $rider_id = !empty($_POST['rider_id']) ? cleanInput($_POST['rider_id']) : null;
                            $status = cleanInput($_POST['status']);
                            $old_rider_id = $manifest['rider_id'];
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

                            // Update manifest
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
                            mysqli_stmt_bind_param($stmt, "isis", $rider_id, $status, $warehouse_id, $manifest_id);
                            mysqli_stmt_execute($stmt);

                            // Get non-failed orders from this manifest
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
                            }

                            mysqli_commit($conn);
                            $success = 'Manifest updated successfully';
                            $manifest['warehouse_id'] = $warehouse_id;
                            break;
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                            $error = $e->getMessage();
                        }
                }

                // Refresh page to show updated data
                header("Location: edit.php?id=" . $manifest_id . "&success=" . urlencode($success));
                exit();
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
    <title>Edit Manifest - <?php echo SITE_NAME; ?></title>
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
                        <h1 class="text-2xl font-bold text-gray-900">Edit Manifest #<?php echo $manifest['id']; ?></h1>
                        <p class="mt-1 text-sm text-gray-500">
                            Created <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?>
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
                        <span class="block sm:inline">This manifest is delivered and cannot be modified.</span>
                    </div>
                <?php endif; ?>

                <!-- Manifest Details Form -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
                    <form action="" method="POST" id="manifestForm">
                        <input type="hidden" name="action" value="update_manifest">
                        <input type="hidden" name="manifest_id" value="<?php echo $manifest['id']; ?>">

                        <div class="space-y-6 bg-white px-4 py-5 sm:p-6">
                            <div>
                                <label for="warehouse_id" class="block text-sm font-medium text-gray-700">Warehouse *</label>
                                <select name="warehouse_id" id="warehouse_id" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?php echo $warehouse['id']; ?>" 
                                                <?php echo ($manifest['warehouse_id'] == $warehouse['id']) ? 'selected' : ''; ?>>
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
                                                    <?php echo $rider['id'] == $manifest['rider_id'] ? 'selected' : ''; ?>>
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
                                        <option value="pending" <?php echo $manifest['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="assigned" <?php echo $manifest['status'] === 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                        <option value="delivering" <?php echo $manifest['status'] === 'delivering' ? 'selected' : ''; ?>>Delivering</option>
                                        <option value="delivered" <?php echo $manifest['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    </select>
                                </div>
                            </div>

                            <?php if (!$is_delivered): ?>
                                <div class="mt-4">
                                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                                        Update Manifest Details
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Current Orders Section with Products -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-medium text-gray-900">Current Orders in Manifest</h2>
                        <form method="GET" class="flex gap-2">
                            <input type="hidden" name="id" value="<?php echo $manifest['id']; ?>">
                            <input type="text" name="search_current"
                                value="<?php echo htmlspecialchars($search_current); ?>"
                                placeholder="Search current orders..."
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            <button type="submit"
                                class="bg-indigo-600 text-white px-3 py-1 rounded-md hover:bg-indigo-700 text-sm">
                                Search
                            </button>
                            <?php if ($search_current): ?>
                                <a href="?id=<?php echo $manifest['id']; ?>"
                                    class="bg-gray-200 text-gray-700 px-3 py-1 rounded-md hover:bg-gray-300 text-sm">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if (empty($manifest_orders)): ?>
                        <p class="text-gray-500">No orders in this manifest.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($manifest_orders as $order): ?>
                                <div class="border rounded-lg p-4">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <div class="text-sm font-medium text-gray-900">
                                                Order #<?php echo $order['order_number']; ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['customer_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['address_line1']); ?>,
                                                <?php echo htmlspecialchars($order['city']); ?>
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
                                                <input type="hidden" name="manifest_id" value="<?php echo $manifest['id']; ?>">
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
                            <h2 class="text-lg font-medium text-gray-900">Add Orders to Manifest</h2>
                            <form method="GET" class="flex gap-2">
                                <input type="hidden" name="id" value="<?php echo $manifest['id']; ?>">
                                <input type="text" name="search_unassigned"
                                    value="<?php echo htmlspecialchars($search_unassigned); ?>"
                                    placeholder="Search available orders..."
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <button type="submit"
                                    class="bg-indigo-600 text-white px-3 py-1 rounded-md hover:bg-indigo-700 text-sm">
                                    Search
                                </button>
                                <?php if ($search_unassigned): ?>
                                    <a href="?id=<?php echo $manifest['id']; ?>"
                                        class="bg-gray-200 text-gray-700 px-3 py-1 rounded-md hover:bg-gray-300 text-sm">
                                        Clear
                                    </a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <?php if (mysqli_num_rows($unassigned_orders_result) > 0): ?>
                            <form action="" method="POST">
                                <input type="hidden" name="action" value="add_orders">
                                <input type="hidden" name="manifest_id" value="<?php echo $manifest['id']; ?>">

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
                                                        <?php echo htmlspecialchars($order['customer_name']); ?> -
                                                        <?php echo htmlspecialchars($order['address_line1']); ?>,
                                                        <?php echo htmlspecialchars($order['city']); ?>
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
                    return confirm('Are you sure you want to add the selected orders to this manifest?');
                });

                // Handle rider and status changes
                $('#manifestForm').on('submit', function(e) {
                    const status = $('#status').val();

                    if (status === 'delivered') {
                        return confirm('Are you sure you want to mark this manifest as delivered? This action cannot be undone and all products will be marked as delivered.');
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