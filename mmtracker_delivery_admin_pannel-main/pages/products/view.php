<?php
require_once '../../includes/config.php';
requireLogin();

$product = null;
$recent_orders = [];
$monthly_stats = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights - Only show product if it belongs to user's company
    $company_condition = !isSuperAdmin() ? "AND p.company_id = " . $_SESSION['company_id'] : "";
    
    $query = "SELECT p.*, c.name as company_name 
              FROM Products p 
              LEFT JOIN Companies c ON p.company_id = c.id 
              WHERE p.id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $product = mysqli_fetch_assoc($result);

    if (!$product) {
        header('Location: index.php');
        exit();
    }

    // Get product usage statistics
    $stats_query = "SELECT 
        COUNT(DISTINCT po.order_id) as total_orders,
        SUM(po.quantity) as total_quantity,
        SUM(po.price * po.quantity) as total_revenue
        FROM ProductOrders po 
        JOIN Orders o ON po.order_id = o.id
        WHERE po.product_id = ?";
    $stmt = mysqli_prepare($conn, $stats_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Get recent orders
    $orders_query = "SELECT o.*, cust.name as customer_name, po.quantity, po.price, 
                    (po.quantity * po.price) as total_amount
                    FROM Orders o
                    JOIN ProductOrders po ON o.id = po.order_id
                    LEFT JOIN Customers cust ON o.customer_id = cust.id
                    WHERE po.product_id = ?
                    ORDER BY o.created_at DESC
                    LIMIT 10";
    $stmt = mysqli_prepare($conn, $orders_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $orders_result = mysqli_stmt_get_result($stmt);
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $recent_orders[] = $order;
    }

    // Get monthly stats for the last 6 months
    $monthly_query = "SELECT 
        DATE_FORMAT(o.created_at, '%Y-%m') as month,
        COUNT(DISTINCT o.id) as orders_count,
        SUM(po.quantity) as total_quantity,
        SUM(po.price * po.quantity) as revenue
        FROM Orders o
        JOIN ProductOrders po ON o.id = po.order_id
        WHERE po.product_id = ?
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
        ORDER BY month DESC";
    $stmt = mysqli_prepare($conn, $monthly_query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $monthly_result = mysqli_stmt_get_result($stmt);
    while ($month = mysqli_fetch_assoc($monthly_result)) {
        $monthly_stats[] = $month;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Product - <?php echo SITE_NAME; ?></title>
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
            <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($product['name']); ?></h1>
            <div class="space-x-2">
                <a href="edit.php?id=<?php echo $product['id']; ?>" 
                   class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                    Edit Product
                </a>
                <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                    Back to List
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Product Information -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Product Information</h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Barcode</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($product['qrcode_number']); ?></dd>
                            </div>
                            
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Company</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($product['company_name']); ?></dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($product['description'])); ?></dd>
                            </div>

                            <div>
                                <dt class="text-sm font-medium text-gray-500">Created</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo date('M d, Y', strtotime($product['created_at'])); ?></dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Overall Statistics -->
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Overall Statistics</h2>
                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Total Orders</dt>
                                <dd class="mt-1 text-2xl font-semibold text-gray-900"><?php echo $stats['total_orders'] ?? 0; ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Total Quantity Ordered</dt>
                                <dd class="mt-1 text-2xl font-semibold text-indigo-600"><?php echo $stats['total_quantity'] ?? 0; ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Total Revenue</dt>
                                <dd class="mt-1 text-2xl font-semibold text-green-600">$<?php echo number_format(($stats['total_revenue'] ?? 0), 2); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Monthly Stats -->
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Monthly Performance</h2>
                        <div class="mt-6">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Month</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Orders</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($monthly_stats as $month): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('F Y', strtotime($month['month'] . '-01')); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $month['orders_count']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $month['total_quantity']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            $<?php echo number_format($month['revenue'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($monthly_stats)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No monthly data available
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Orders -->
                <div class="bg-white shadow rounded-lg">
                    <div class="p-6">
                        <h2 class="text-lg font-medium text-gray-900">Recent Orders</h2>
                        <div class="mt-6">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order #</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <a href="../orders/view.php?id=<?php echo $order['id']; ?>" 
                                               class="text-indigo-600 hover:text-indigo-900">
                                                <?php echo htmlspecialchars($order['order_number']); ?>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($order['customer_name'] ?? 'N/A'); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $order['quantity']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            $<?php echo number_format($order['total_amount'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php
                                                switch($order['status']) {
                                                    case 'delivered':
                                                        echo 'bg-green-100 text-green-800';
                                                        break;
                                                    case 'pending':
                                                        echo 'bg-yellow-100 text-yellow-800';
                                                        break;
                                                    case 'failed':
                                                        echo 'bg-red-100 text-red-800';
                                                        break;
                                                    default:
                                                        echo 'bg-blue-100 text-blue-800';
                                                }
                                                ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recent_orders)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            No orders found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
</body>
</html>