<?php
require_once '../../includes/config.php';
requireLogin();

$manifest = null;
$manifest_orders = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch manifest details
    $query = "SELECT m.*, 
              u.name as rider_name, u.phone as rider_phone, u.email as rider_email,
              c.name as company_name,
              w.name as warehouse_name, w.address as warehouse_address,
              w.city as warehouse_city, w.state as warehouse_state
              FROM Manifests m
              LEFT JOIN Users u ON m.rider_id = u.id
              LEFT JOIN Companies c ON m.company_id = c.id
              LEFT JOIN Warehouses w ON m.warehouse_id = w.id
              WHERE m.id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $manifest = mysqli_fetch_assoc($result);

    if (!$manifest) {
        header('Location: index.php');
        exit();
    }

    // Fetch orders in manifest with their statuses
    $orders_query = "SELECT o.id, o.order_number, o.status, o.notes, 
                           c.name as customer_name, 
                           a.address_line1, a.address_line2, a.city,
                           (SELECT COUNT(*) FROM OrderStatusLogs WHERE order_id = o.id) as status_count
                    FROM Orders o
                    JOIN ManifestOrders mo ON o.id = mo.order_id
                    LEFT JOIN Customers c ON o.customer_id = c.id
                    LEFT JOIN Addresses a ON o.delivery_address_id = a.id
                    WHERE mo.manifest_id = ?
                    ORDER BY o.created_at DESC";
    $stmt = mysqli_prepare($conn, $orders_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $orders_result = mysqli_stmt_get_result($stmt);
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $manifest_orders[] = $order;
    }

    // Calculate statistics
    $total_orders = count($manifest_orders);
    $delivered_orders = array_filter($manifest_orders, function($order) {
        return $order['status'] === 'delivered';
    });
    $delivery_progress = $total_orders > 0 ? (count($delivered_orders) / $total_orders) * 100 : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Manifest - <?php echo SITE_NAME; ?></title>
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
        <div class="mb-6 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">Manifest #<?php echo $manifest['id']; ?></h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $manifest['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Manifest
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to List
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Manifest Details -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Manifest Information</h3>
                    </div>
                    <div class="px-6 py-5">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Status</dt>
                                <dd class="mt-1">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch($manifest['status']) {
                                            case 'delivered':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'delivering':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'pending':
                                                echo 'bg-yellow-100 text-yellow-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucfirst($manifest['status']); ?>
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Created</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?>
                                </dd>
                            </div>
                            <?php if (isSuperAdmin()): ?>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($manifest['company_name']); ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <!-- Rider Information -->
                <?php if ($manifest['rider_id']): ?>
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Assigned Driver</h3>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Name</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($manifest['rider_name']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Contact</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($manifest['rider_phone']); ?><br>
                                    <?php echo htmlspecialchars($manifest['rider_email']); ?>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Delivery Progress -->
                <div class="bg-white shadow rounded-lg mt-6">
                    <div class="px-6 py-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Manifest Progress</h3>
                        <div class="mt-4">
                            <div class="relative pt-1">
                                <div class="flex mb-2 items-center justify-between">
                                    <div>
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            <?php echo number_format($delivery_progress, 1); ?>% Complete
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs font-semibold inline-block text-indigo-600">
                                            <?php echo count($delivered_orders); ?>/<?php echo $total_orders; ?> Orders
                                        </span>
                                    </div>
                                </div>
                                <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-indigo-200">
                                    <div style="width:<?php echo $delivery_progress; ?>%" 
                                         class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Information -->
                <div class="bg-white shadow rounded-lg mb-6">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Warehouse Information</h3>
                    </div>
                    <div class="px-6 py-5">
                        <?php if ($manifest['warehouse_name']): ?>
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="p-2 bg-indigo-100 rounded-lg">
                                        <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                        </svg>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <h4 class="text-lg font-medium text-gray-900">
                                        <?php echo htmlspecialchars($manifest['warehouse_name']); ?>
                                    </h4>
                                    <div class="mt-2 space-y-2">
                                        <div class="flex items-center text-sm text-gray-500">
                                            <svg class="h-5 w-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            <span><?php echo htmlspecialchars($manifest['warehouse_address']); ?></span>
                                        </div>
                                        <div class="flex items-center text-sm text-gray-500">
                                            <svg class="h-5 w-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                      d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9" />
                                            </svg>
                                            <span>
                                                <?php echo htmlspecialchars($manifest['warehouse_city'] . ', ' . $manifest['warehouse_state']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($manifest['status'] === 'assigned' || $manifest['status'] === 'delivering'): ?>
                                        <div class="mt-4">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                                                <?php echo $manifest['status'] === 'delivering' ? 
                                                    'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                <?php echo ucfirst($manifest['status']); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <div class="mx-auto h-12 w-12 text-gray-400">
                                    <svg class="h-full w-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <h3 class="mt-2 text-sm font-medium text-gray-900">No Warehouse Assigned</h3>
                                <p class="mt-1 text-sm text-gray-500">This manifest doesn't have a warehouse assigned yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Orders List -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-5 border-b border-gray-200">
                        <h3 class="text-lg font-medium leading-6 text-gray-900">Orders (<?php echo count($manifest_orders); ?>)</h3>
                    </div>
                    
                    <?php
                    // Group orders by drop number
                    $grouped_orders = [];
                    foreach ($manifest_orders as $order) {
                        $drop_number = $order['drop_number'] ?? 0; // Use 0 if drop_number is null
                        if (!isset($grouped_orders[$drop_number])) {
                            $grouped_orders[$drop_number] = [];
                        }
                        $grouped_orders[$drop_number][] = $order;
                    }
                    // Sort by drop number
                    ksort($grouped_orders);
                    ?>

                    <div class="divide-y divide-gray-200">
                        <?php foreach ($grouped_orders as $drop_number => $orders): ?>
                            <!-- Drop Number Header -->
                            <div class="px-6 py-3 bg-gray-50">
                                <h4 class="text-sm font-medium text-gray-700">
                                    Drop #<?php echo $drop_number ? $drop_number : 'Unassigned'; ?>
                                    <span class="text-gray-500">(<?php echo count($orders); ?> orders)</span>
                                </h4>
                            </div>

                            <!-- Orders in this drop -->
                            <?php foreach ($orders as $order): ?>
                                <div class="px-6 py-5">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <div class="flex items-center space-x-3">
                                                <span class="text-lg font-medium text-gray-900">
                                                    #<?php echo $order['order_number']; ?>
                                                </span>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?php
                                                    switch($order['status']) {
                                                        case 'delivered':
                                                            echo 'bg-green-100 text-green-800';
                                                            break;
                                                        case 'delivering':
                                                            echo 'bg-blue-100 text-blue-800';
                                                            break;
                                                        case 'pending':
                                                            echo 'bg-yellow-100 text-yellow-800';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-100 text-gray-800';
                                                    }
                                                    ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </div>
                                            <div class="mt-1">
                                                <p class="text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></p>
                                                <p class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($order['address_line1'] ?? 'No Address'); ?>,
                                                    <?php if (!empty($order['address_line2'])) echo htmlspecialchars($order['address_line2']) . ', '; ?>
                                                    <?php echo htmlspecialchars($order['city'] ?? 'N/A'); ?>
                                                </p>
                                            </div>
                                            <?php if ($order['notes']): ?>
                                            <div class="mt-2">
                                                <p class="text-sm text-gray-500"><?php echo htmlspecialchars($order['notes']); ?></p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <a href="../orders/view.php?id=<?php echo $order['id']; ?>" 
                                               class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                                                View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>

                        <?php if (empty($manifest_orders)): ?>
                            <div class="px-6 py-5">
                                <p class="text-gray-500">No orders in this manifest.</p>
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