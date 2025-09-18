<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';
$rider = null;
$current_manifest = null;
$recent_manifests = [];
$delivery_stats = [];
$manifest_products = [];
$order_products = [];
$today_logs = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights and add company condition
    $company_condition = !isSuperAdmin() ? "AND rc.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch rider details
    $query = "SELECT u.*, rc.is_active as rider_company_active, c.name as company_name
              FROM Users u
              LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
              LEFT JOIN Companies c ON rc.company_id = c.id
              WHERE u.id = ? AND u.user_type = 'Rider' $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rider = mysqli_fetch_assoc($result);

    if (!$rider) {
        header('Location: index.php');
        exit();
    }

    // Handle product status updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $tracking_id = cleanInput($_POST['tracking_id']);

        mysqli_begin_transaction($conn);
        try {
            if ($action === 'mark_picked') {
                $update = "UPDATE RiderProductTracking 
                          SET is_picked = 1, picked_at = CURRENT_TIMESTAMP 
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "i", $tracking_id);
                mysqli_stmt_execute($stmt);
            } elseif ($action === 'mark_delivered') {
                $update = "UPDATE RiderProductTracking 
                          SET is_delivered = 1, delivered_at = CURRENT_TIMESTAMP 
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update);
                mysqli_stmt_bind_param($stmt, "i", $tracking_id);
                mysqli_stmt_execute($stmt);
            }
            mysqli_commit($conn);
            $success = 'Status updated successfully';

            // Refresh page to show updated status
            header("Location: view.php?id=" . $id . "&success=" . urlencode($success));
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }

    // Company condition for manifests and orders
    $manifest_company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";

    // Get current active manifest for company
    $current_manifest_query = "SELECT m.*, 
                             COUNT(mo.order_id) as total_orders,
                             COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as delivered_orders
                             FROM Manifests m
                             LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                             LEFT JOIN Orders o ON mo.order_id = o.id
                             WHERE m.rider_id = ? AND m.status != 'delivered'
                             $manifest_company_condition
                             GROUP BY m.id";
    $stmt = mysqli_prepare($conn, $current_manifest_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $manifest_result = mysqli_stmt_get_result($stmt);
    $current_manifest = mysqli_fetch_assoc($manifest_result);

    // If there's a current manifest, fetch its products
    if ($current_manifest) {
        $products_query = "SELECT 
            p.id as product_id,
            p.name as product_name,
            p.qrcode_number,
            o.id as order_id,
            o.order_number,
            po.quantity,
            pt.picked_quantity as picked_quantity,
            pt.delivered_quantity as delivered_quantity,
            pt.picked_at,
            pt.delivered_at
        FROM ManifestOrders mo
        JOIN Orders o ON mo.order_id = o.id
        JOIN ProductOrders po ON o.id = po.order_id
        JOIN Products p ON po.product_id = p.id
        LEFT JOIN ProductTracking pt ON (
            pt.product_id = p.id AND 
            pt.rider_id = ? AND 
            pt.company_id = ?
        )
        WHERE mo.manifest_id = ?
        ORDER BY o.order_number, p.name";

        $stmt = mysqli_prepare($conn, $products_query);
        $company_id = !isSuperAdmin() ? $_SESSION['company_id'] : $rider['company_id'];
        mysqli_stmt_bind_param($stmt, "iii", $id, $company_id, $current_manifest['id']);
        mysqli_stmt_execute($stmt);
        $products_result = mysqli_stmt_get_result($stmt);

        $total_assigned = 0;
        $total_picked = 0;
        $total_delivered = 0;

        while ($product = mysqli_fetch_assoc($products_result)) {
            $total_assigned += $product['quantity'];
            $total_picked += $product['picked_quantity'] ?? 0;
            $total_delivered += $product['delivered_quantity'] ?? 0;
            $manifest_products[] = $product;
        }
    }

    // Get recent manifests for company
    $recent_manifests_query = "SELECT m.*, 
                              COUNT(mo.order_id) as total_orders,
                              COUNT(CASE WHEN o.status = 'delivered' THEN 1 END) as delivered_orders,
                              COUNT(CASE WHEN o.status = 'failed' THEN 1 END) as failed_orders
                              FROM Manifests m
                              LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                              LEFT JOIN Orders o ON mo.order_id = o.id
                              WHERE m.rider_id = ? $manifest_company_condition
                              GROUP BY m.id
                              ORDER BY m.created_at DESC
                              LIMIT 5";
    $stmt = mysqli_prepare($conn, $recent_manifests_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $manifests_result = mysqli_stmt_get_result($stmt);
    while ($manifest = mysqli_fetch_assoc($manifests_result)) {
        $recent_manifests[] = $manifest;
    }

    // Calculate delivery stats for company
    $stats_query = "SELECT 
                    COUNT(DISTINCT m.id) as total_manifests,
                    COUNT(DISTINCT CASE WHEN m.status = 'delivered' THEN m.id END) as completed_manifests,
                    COUNT(DISTINCT o.id) as total_orders,
                    COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) as delivered_orders,
                    COUNT(DISTINCT CASE WHEN o.status = 'failed' THEN o.id END) as failed_orders,
                    AVG(CASE WHEN o.status = 'delivered' 
                        THEN TIMESTAMPDIFF(HOUR, m.created_at, o.updated_at) 
                        END) as avg_delivery_time
                    FROM Users u
                    LEFT JOIN Manifests m ON u.id = m.rider_id
                    LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                    LEFT JOIN Orders o ON mo.order_id = o.id
                    WHERE u.id = ? $manifest_company_condition";
    $stmt = mysqli_prepare($conn, $stats_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $stats_result = mysqli_stmt_get_result($stmt);
    $delivery_stats = mysqli_fetch_assoc($stats_result);

    // Calculate success rate
    $delivery_stats['success_rate'] = $delivery_stats['total_orders'] > 0 
        ? ($delivery_stats['delivered_orders'] / $delivery_stats['total_orders']) * 100 
        : 0;

    // Fetch today's logs for the rider
    $today = date('Y-m-d');
    $logs_query = "SELECT osl.*, o.order_number, cust.name as customer_name, o.status as order_status,
                   o.delivery_date, addr.address_line1, addr.city,
                   GROUP_CONCAT(p.name SEPARATOR ', ') as products
                   FROM OrderStatusLogs osl
                   JOIN Orders o ON osl.order_id = o.id
                   LEFT JOIN Customers cust ON o.customer_id = cust.id
                   LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
                   LEFT JOIN ProductOrders po ON o.id = po.order_id
                   LEFT JOIN Products p ON po.product_id = p.id
                   WHERE osl.changed_by = ?
                   AND DATE(osl.changed_at) = ?
                   GROUP BY osl.id
                   ORDER BY osl.changed_at DESC";

    $stmt = mysqli_prepare($conn, $logs_query);
    mysqli_stmt_bind_param($stmt, "is", $id, $today);
    mysqli_stmt_execute($stmt);
    $logs_result = mysqli_stmt_get_result($stmt);
    while ($log = mysqli_fetch_assoc($logs_result)) {
        $today_logs[] = $log;
    }

    // Get product statistics from ProductOrders
    $product_stats_query = "
        SELECT 
            p.id as product_id,
            p.name as product_name,
            p.qrcode_number,
            SUM(po.picked_quantity) as total_picked,
            SUM(po.missing_quantity) as total_missing,
            SUM(CASE 
                WHEN o.status = 'delivered' THEN po.picked_quantity 
                ELSE 0 
            END) as total_delivered
        FROM Products p
        JOIN ProductOrders po ON p.id = po.product_id
        JOIN Orders o ON po.order_id = o.id
        JOIN ManifestOrders mo ON o.id = mo.order_id
        JOIN Manifests m ON mo.manifest_id = m.id
        WHERE m.rider_id = ?
        GROUP BY p.id, p.name, p.qrcode_number
        ORDER BY p.name";

    $stmt = mysqli_prepare($conn, $product_stats_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $product_stats_result = mysqli_stmt_get_result($stmt);
    $product_stats = [];

    while ($row = mysqli_fetch_assoc($product_stats_result)) {
        $product_stats[] = $row;
    }

    // Calculate totals for summary cards
    $total_picked = 0;
    $total_missing = 0;
    $total_delivered = 0;

    foreach ($product_stats as $product) {
        $total_picked += $product['total_picked'];
        $total_missing += $product['total_missing'];
        $total_delivered += $product['total_delivered'];
    }

    // Fetch Extra Items Log data for this rider
    $extra_items = [];
    $extra_items_query = "SELECT 
                            eil.product_id,
                            p.name as product_name,
                            eil.reason,
                            eil.created_at,
                            SUM(eil.quantity) as total_quantity
                          FROM ExtraItemsLog eil
                          JOIN Products p ON eil.product_id = p.id
                          WHERE eil.rider_id = ? 
                          -- Add status filter later if needed, e.g., AND eil.status = 'pending'
                          GROUP BY eil.product_id, p.name, eil.reason, eil.created_at -- Group by created_at too if showing individual logs
                          ORDER BY eil.created_at DESC";
    $stmt_extra = mysqli_prepare($conn, $extra_items_query);
    mysqli_stmt_bind_param($stmt_extra, "i", $id);
    mysqli_stmt_execute($stmt_extra);
    $extra_items_result = mysqli_stmt_get_result($stmt_extra);
    while ($item = mysqli_fetch_assoc($extra_items_result)) {
        $extra_items[] = $item;
    }
    mysqli_stmt_close($stmt_extra);
}

// Get success message from URL if it exists
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get current manifest if exists
$current_manifest = null;
$manifest_products_stats = [];
$manifest_orders = [];

$manifest_query = "SELECT m.*, c.name as company_name 
                  FROM Manifests m 
                  LEFT JOIN Companies c ON m.company_id = c.id
                  WHERE m.rider_id = ? 
                  AND m.status IN ('assigned', 'delivering')
                  ORDER BY m.created_at DESC 
                  LIMIT 1";
$stmt = mysqli_prepare($conn, $manifest_query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$current_manifest = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// If current manifest exists, get its orders and product statistics
if ($current_manifest) {
    // Get manifest orders
    $orders_query = "SELECT o.* FROM Orders o
                    JOIN ManifestOrders mo ON o.id = mo.order_id
                    WHERE mo.manifest_id = ?";
    $stmt = mysqli_prepare($conn, $orders_query);
    mysqli_stmt_bind_param($stmt, "i", $current_manifest['id']);
    mysqli_stmt_execute($stmt);
    $orders_result = mysqli_stmt_get_result($stmt);
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $manifest_orders[] = $order['id'];
    }

    if (!empty($manifest_orders)) {
        // Get product statistics for current manifest orders
        $products_query = "SELECT 
            p.id,
            p.name,
            p.qrcode_number,
            SUM(po.quantity) as total_quantity,
            SUM(po.picked_quantity) as total_picked,
            SUM(po.missing_quantity) as total_missing,
            SUM(CASE 
                WHEN o.status = 'delivered' THEN po.picked_quantity 
                ELSE 0 
            END) as total_delivered,
            COUNT(DISTINCT o.id) as order_count
            FROM Products p
            JOIN ProductOrders po ON p.id = po.product_id
            JOIN Orders o ON po.order_id = o.id
            WHERE o.id IN (" . implode(',', $manifest_orders) . ")
            GROUP BY p.id, p.name
            ORDER BY p.name";
        
        $products_result = mysqli_query($conn, $products_query);
        while ($product = mysqli_fetch_assoc($products_result)) {
            $manifest_products_stats[] = $product;
        }
    }
}

// Get all companies for super admin
$rider_companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT DISTINCT c.id, c.name, c.phone, c.email, c.address,
                       rc.is_active as assignment_active
                       FROM Companies c
                       LEFT JOIN RiderCompanies rc ON c.id = rc.company_id AND rc.rider_id = ?
                       WHERE rc.rider_id IS NOT NULL
                       ORDER BY c.name";
    $stmt = mysqli_prepare($conn, $companies_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $companies_result = mysqli_stmt_get_result($stmt);
    while ($company = mysqli_fetch_assoc($companies_result)) {
        $rider_companies[] = $company;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Rider - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
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

        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($rider['name']); ?></h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $rider['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Rider
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to List
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Rider Information -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Rider Information</h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Status</dt>
                                <dd class="mt-1">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $rider['rider_company_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $rider['rider_company_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <?php if ($current_manifest): ?>
                                    <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                        On Delivery
                                    </span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Username</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($rider['username']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($rider['company_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Contact</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($rider['email']); ?><br>
                                    <?php echo htmlspecialchars($rider['phone']); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Member Since</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($rider['created_at'])); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Last Active</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y H:i', strtotime($rider['updated_at'])); ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                

                <!-- Delivery Stats for Company -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">
                            Statistics
                            <?php if (!isSuperAdmin()): ?>
                                <span class="text-sm font-normal text-gray-500">
                                    (For <?php echo htmlspecialchars($rider['company_name']); ?>)
                                </span>
                            <?php endif; ?>
                        </h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Success Rate</dt>
                                <dd class="mt-1 text-3xl font-semibold text-indigo-600">
                                    <?php echo number_format($delivery_stats['success_rate'], 1); ?>%
                                </dd>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Total Manifests</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                        <?php echo $delivery_stats['total_manifests']; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Completed</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-gray-900">
                                        <?php echo $delivery_stats['completed_manifests']; ?>
                                    </dd>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Delivered</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-green-600">
                                        <?php echo $delivery_stats['delivered_orders']; ?>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Failed</dt>
                                    <dd class="mt-1 text-2xl font-semibold text-red-600">
                                        <?php echo $delivery_stats['failed_orders']; ?>
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Current Manifest -->
                <?php if ($current_manifest && ($current_manifest['status'] === 'assigned' || $current_manifest['status'] === 'delivering')): ?>
                <a href="../manifests/view.php?id=<?php echo $current_manifest['id']; ?>" class="block">
                    <div class="bg-white shadow hover:shadow-lg transition-shadow duration-200 rounded-lg mb-6">
                        <div class="px-6 py-5 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="text-lg font-medium leading-6 text-gray-900">
                                Current Manifest #<?php echo $current_manifest['id']; ?>
                            </h3>
                            <span class="px-3 py-1 text-sm rounded-full 
                                <?php echo $current_manifest['status'] === 'delivering' ? 
                                    'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst($current_manifest['status']); ?>
                            </span>
                        </div>
                        <div class="px-6 py-5">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="flex items-center space-x-3">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Company</div>
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($current_manifest['company_name']); ?></div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Total Orders</div>
                                        <div class="text-sm text-gray-900"><?php echo $current_manifest['total_orders_assigned']; ?></div>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Created At</div>
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('M d, Y h:i A', strtotime($current_manifest['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="px-6 py-3 bg-gray-50 flex justify-end">
                            <span class="text-sm text-indigo-600 font-medium">View Details →</span>
                        </div>
                    </div>
                </a>
                <?php endif; ?>

                <!-- Product Statistics Section -->
                <div class="bg-white shadow rounded-lg mb-6">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Current Manifest Product Statistics</h3>
                    </div>
                    <div class="px-6 py-5">
                        <!-- Summary Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                            <div class="bg-green-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-green-800">Total Picked</h4>
                                <p class="mt-2 text-2xl font-semibold text-green-900">
                                    <?php 
                                    echo array_sum(array_column($manifest_products_stats, 'total_picked')); 
                                    ?>
                                </p>
                            </div>
                            <div class="bg-red-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-red-800">Total Missing</h4>
                                <p class="mt-2 text-2xl font-semibold text-red-900">
                                    <?php 
                                    echo array_sum(array_column($manifest_products_stats, 'total_missing')); 
                                    ?>
                                </p>
                            </div>
                            <div class="bg-blue-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-blue-800">Total Delivered</h4>
                                <p class="mt-2 text-2xl font-semibold text-blue-900">
                                    <?php 
                                    echo array_sum(array_column($manifest_products_stats, 'total_delivered')); 
                                    ?>
                                </p>
                            </div>
                        </div>

                        <!-- Products Table -->
                        <div class="mt-6">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Barcode</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Picked</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Missing</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Delivered</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($manifest_products_stats as $product): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($product['qrcode_number']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-right">
                                            <?php echo $product['total_picked'] ?: 0; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 text-right">
                                            <?php echo $product['total_missing'] ?: 0; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 text-right">
                                            <?php echo $product['total_delivered'] ?: 0; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($manifest_products_stats)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No products found in current manifest
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Extra Items Log Section -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Extra Items Logged</h2>
                        <div class="mt-6 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date Logged
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Product
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Quantity
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Reason
                                        </th>
                                        <!-- Add Status column later if needed -->
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($extra_items)): ?>
                                        <?php foreach ($extra_items as $item): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                     <?php echo date('M d, Y H:i', strtotime($item['created_at'])); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($item['product_name']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                                    <?php echo $item['total_quantity']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($item['reason']); ?>
                                                </td>
                                                <!-- Add actions/status display later -->
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                                No extra items logged for this rider.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- End Extra Items Log Section -->

                <!-- Companies Section (Super Admin Only) -->
                <?php if (isSuperAdmin() && !empty($rider_companies)): ?>
                <div class="bg-white shadow rounded-lg mb-6">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Assigned Companies</h3>
                    </div>
                    <div class="px-6 py-5">
                        <div class="grid grid-cols-1 gap-4">
                            <?php foreach ($rider_companies as $company): ?>
                            <div class="border rounded-lg p-4 <?php echo $company['assignment_active'] ? 'border-green-200 bg-green-50' : 'border-gray-200'; ?>">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h4 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($company['name']); ?></h4>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($company['email']); ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($company['phone']); ?></p>
                                        <p class="mt-2 text-sm text-gray-500"><?php echo htmlspecialchars($company['address']); ?></p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $company['assignment_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo $company['assignment_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Product Tracking Stats -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Product Statistics</h2>
                        
                        <!-- Summary Cards -->
                        <div class="grid grid-cols-3 gap-4 mt-4">
                            <div class="bg-blue-50 rounded-lg p-4">
                                <div class="text-blue-900 text-sm font-medium">Total Picked</div>
                                <div class="text-2xl font-bold text-blue-700"><?php echo $total_picked; ?></div>
                            </div>
                            <div class="bg-yellow-50 rounded-lg p-4">
                                <div class="text-yellow-900 text-sm font-medium">Total Missing</div>
                                <div class="text-2xl font-bold text-yellow-700"><?php echo $total_missing; ?></div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-4">
                                <div class="text-green-900 text-sm font-medium">Total Delivered</div>
                                <div class="text-2xl font-bold text-green-700"><?php echo $total_delivered; ?></div>
                            </div>
                        </div>

                        <!-- Products Table -->
                        <div class="mt-6">
                            <h3 class="text-md font-medium text-gray-900 mb-4">Product Details</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Product
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Barcode
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Picked
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Missing
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Delivered
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Remaining
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($product_stats)): ?>
                                            <?php foreach ($product_stats as $product): ?>
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($product['product_name']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($product['qrcode_number']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-blue-600 font-medium">
                                                            <?php echo $product['total_picked']; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-yellow-600 font-medium">
                                                            <?php echo $product['total_missing']; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-green-600 font-medium">
                                                            <?php echo $product['total_delivered']; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm text-gray-600 font-medium">
                                                            <?php echo $product['total_picked'] - $product['total_delivered']; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                                    No product data available
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Manifests -->
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Recent Manifests</h2>
                        <div class="mt-6 space-y-4">
                            <?php foreach ($recent_manifests as $manifest): ?>
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <a href="../manifests/view.php?id=<?php echo $manifest['id']; ?>" 
                                           class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                            Manifest #<?php echo $manifest['id']; ?>
                                        </a>
                                        <p class="mt-1 text-sm text-gray-500">
                                            <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?>
                                        </p>
                                    </div>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch($manifest['status']) {
                                            case 'delivered':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'delivering':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucfirst($manifest['status']); ?>
                                    </span>
                                </div>
                                <div class="mt-4">
                                    <div class="grid grid-cols-3 gap-4 text-sm text-gray-500">
                                        <div>
                                            <span class="text-gray-900 font-medium"><?php echo $manifest['total_orders']; ?></span> Orders
                                        </div>
                                        <div>
                                            <span class="text-green-600 font-medium"><?php echo $manifest['delivered_orders']; ?></span> Delivered
                                        </div>
                                        <div>
                                            <span class="text-red-600 font-medium"><?php echo $manifest['failed_orders']; ?></span> Failed
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($recent_manifests)): ?>
                            <div class="text-center py-4 text-gray-500">
                                No manifests found
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                

                <!-- Today's Activity Logs -->
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Today's Activity (<?php echo date('M d, Y'); ?>)</h2>
                        
                        <?php if (!empty($today_logs)): ?>
                            <div class="mt-6 flow-root">
                                <ul class="-mb-8">
                                    <?php foreach ($today_logs as $index => $log): ?>
                                        <li>
                                            <div class="relative pb-8">
                                                <?php if ($index !== count($today_logs) - 1): ?>
                                                    <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                                <?php endif; ?>
                                                <div class="relative flex space-x-3">
                                                    <div>
                                                        <span class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white 
                                                            <?php
                                                            switch ($log['status']) {
                                                                case 'delivered':
                                                                    echo 'bg-green-500';
                                                                    break;
                                                                case 'failed':
                                                                    echo 'bg-red-500';
                                                                    break;
                                                                default:
                                                                    echo 'bg-blue-500';
                                                            }
                                                            ?>">
                                                            <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                        </span>
                                                    </div>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            Order #<?php echo htmlspecialchars($log['order_number']); ?>
                                                            <span class="ml-2 text-sm text-gray-500">
                                                                - <?php echo htmlspecialchars($log['customer_name'] ?? 'N/A'); ?>
                                                            </span>
                                                        </div>
                                                        <div class="mt-1 text-sm text-gray-500">
                                                            <p>Status changed to <span class="font-medium">
                                                                <?php echo ucfirst($log['status']); ?></span>
                                                            </p>
                                                            <?php if ($log['products']): ?>
                                                                <p class="mt-1">Products: <?php echo htmlspecialchars($log['products']); ?></p>
                                                            <?php endif; ?>
                                                            <?php if ($log['reason']): ?>
                                                                <p class="mt-1">Reason: <?php echo htmlspecialchars($log['reason']); ?></p>
                                                            <?php endif; ?>
                                                            <p class="mt-1">
                                                                Address: <?php echo htmlspecialchars($log['address_line1'] . ', ' . $log['city']); ?>
                                                            </p>
                                                            <?php if ($log['delivery_date']): ?>
                                                                <p class="mt-1">Delivery Date: <?php echo date('M d, Y', strtotime($log['delivery_date'])); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="mt-2 text-sm text-gray-500">
                                                            <?php echo date('h:i A', strtotime($log['changed_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="mt-6 text-center py-4 text-gray-500">
                                No activity logs for today
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</body>
</html>