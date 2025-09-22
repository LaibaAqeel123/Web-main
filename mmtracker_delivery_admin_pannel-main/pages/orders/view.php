<?php
require_once '../../includes/config.php';
requireLogin();

$order = null;
$status_logs = [];
$manifest = null;
$products = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);

    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND o.company_id = " . $_SESSION['company_id'] : "";

    // Fetch order details with company name, organization, products, customer, and address
    $query = "SELECT o.*, 
               c.name as company_name, 
               org.name as organization_name, org.id as organization_id,
               cust.name as customer_name, cust.email, cust.phone, 
               addr.address_line1, addr.address_line2, addr.city, addr.state, addr.postal_code, addr.country,
               GROUP_CONCAT(p.name) as product_names,
               GROUP_CONCAT(po.quantity) as quantities,
               GROUP_CONCAT(p.id) as product_ids
               FROM Orders o
               LEFT JOIN Companies c ON o.company_id = c.id
               LEFT JOIN Organizations org ON o.organization_id = org.id
               LEFT JOIN Customers cust ON o.customer_id = cust.id
               LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
               LEFT JOIN ProductOrders po ON o.id = po.order_id
               LEFT JOIN Products p ON po.product_id = p.id
               WHERE o.id = ? $company_condition
               GROUP BY o.id";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    if (!$order) {
        header('Location: index.php');
        exit();
    }

    // Process products data
    if ($order['product_names']) {
        $product_names = explode(',', $order['product_names']);
        $quantities = explode(',', $order['quantities']);
        $product_ids = explode(',', $order['product_ids']);

        for ($i = 0; $i < count($product_names); $i++) {
            $products[] = [
                'id' => $product_ids[$i],
                'name' => $product_names[$i],
                'quantity' => $quantities[$i]
            ];
        }
    }

    // Fetch status logs with user details
    $logs_query = "SELECT l.*, u.name as changed_by_name 
                   FROM OrderStatusLogs l
                   LEFT JOIN Users u ON l.changed_by = u.id
                   WHERE l.order_id = ?
                   ORDER BY l.changed_at DESC";

    $logs_stmt = mysqli_prepare($conn, $logs_query);
    mysqli_stmt_bind_param($logs_stmt, "i", $id);
    mysqli_stmt_execute($logs_stmt);
    $logs_result = mysqli_stmt_get_result($logs_stmt);
    while ($log = mysqli_fetch_assoc($logs_result)) {
        $status_logs[] = $log;
    }

    // Fetch manifest details if assigned
    $manifest_query = "SELECT m.*, u.name as rider_name 
                      FROM Manifests m
                      LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                      LEFT JOIN Users u ON m.rider_id = u.id
                      WHERE mo.order_id = ?";

    $manifest_stmt = mysqli_prepare($conn, $manifest_query);
    mysqli_stmt_bind_param($manifest_stmt, "i", $id);
    mysqli_stmt_execute($manifest_stmt);
    $manifest_result = mysqli_stmt_get_result($manifest_stmt);
    $manifest = mysqli_fetch_assoc($manifest_result);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Order - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">
                        Order #<?php echo htmlspecialchars($order['order_number']); ?>
                    </h1>
                    <div class="space-x-2">
                        <?php if ($order['status'] === 'delivered'): ?>
                            <a href="generate_view_pdf.php?id=<?php echo $order['id']; ?>" target="_blank"
                                class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 inline-flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                View POD PDF
                            </a>
                        <?php endif; ?>
                        <a href="edit.php?id=<?php echo $order['id']; ?>"
                            class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                            Edit Order
                        </a>
                        <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                            Back to Orders
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Order Details -->
                    <div class="space-y-6">
                        <div class="bg-white shadow rounded-lg p-6">
                            <h2 class="text-xl font-semibold mb-4">Order Information</h2>

                            <!-- Products Section -->
                            <h3 class="text-lg font-semibold mt-6 mb-4">Products</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($product['name']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                                                    <?php echo number_format($product['quantity']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="space-y-4 mt-6">
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Status</span>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php
                                switch ($order['status']) {
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
                                </div>
                                <?php if ($order['organization_id']): ?>
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Organization</span>
                                    <a href="../organizations/view.php?id=<?php echo $order['organization_id']; ?>" class="text-blue-600 hover:underline">
                                        <?php echo htmlspecialchars($order['organization_name']); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Created At</span>
                                    <span><?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></span>
                                </div>
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Delivery Date</span>
                                    <span><?php echo $order['delivery_date'] ? date('M d, Y', strtotime($order['delivery_date'])) : 'Not specified'; ?></span>
                                </div>
                            </div>

                            <h3 class="text-lg font-semibold mt-6 mb-4">Customer Details</h3>
                            <div class="space-y-4">
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Name</span>
                                    <span><?php echo htmlspecialchars($order['customer_name'] ?? ''); ?></span>
                                </div>
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Email</span>
                                    <span><?php echo htmlspecialchars($order['email'] ?? ''); ?></span>
                                </div>
                                <div class="flex justify-between border-b pb-2">
                                    <span class="font-medium">Phone</span>
                                    <span><?php echo htmlspecialchars($order['phone'] ?? ''); ?></span>
                                </div>
                            </div>

                            <h3 class="text-lg font-semibold mt-6 mb-4">Delivery Address</h3>
                            <div class="space-y-4">
                                <p class="text-gray-700">
                                    <?php echo htmlspecialchars($order['address_line1'] ?? ''); ?><br>
                                    <?php if (!empty($order['address_line2'])) echo htmlspecialchars($order['address_line2'] ?? '') . '<br>'; ?>
                                    <?php echo htmlspecialchars($order['city'] ?? ''); ?>,
                                    <?php if (!empty($order['state'])) echo htmlspecialchars($order['state'] ?? '') . ', '; ?>
                                    <?php echo htmlspecialchars($order['postal_code'] ?? ''); ?><br>
                                    <?php echo htmlspecialchars($order['country'] ?? ''); ?>
                                </p>
                                <?php if (!empty($order['latitude']) && !empty($order['longitude'])): ?>
                                <p class="text-sm text-gray-500 mt-2">
                                    Lat: <?php echo htmlspecialchars(number_format((float)$order['latitude'], 6)); ?>, 
                                    Lng: <?php echo htmlspecialchars(number_format((float)$order['longitude'], 6)); ?>
                                </p>
                                <?php endif; ?>
                            </div>

                            <?php if ($order['notes']): ?>
                                <h3 class="text-lg font-semibold mt-6 mb-4">Notes</h3>
                                <div class="bg-gray-50 p-4 rounded-md">
                                    <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Delivery Proofs Section -->
                            <?php if (!empty($order['proof_photo_url']) || !empty($order['proof_signature_path'])): ?>
                                <h3 class="text-lg font-semibold mt-6 mb-4">Delivery Proofs</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <?php if (!empty($order['proof_photo_url'])): ?>
                                        <div>
                                            <p class="text-sm font-medium text-gray-600 mb-2">Photo Proof:</p>
                                            <a href="<?php echo SITE_URL . 'api/' . htmlspecialchars($order['proof_photo_url']); ?>" target="_blank">
                                                <img src="<?php echo SITE_URL . 'api/' . htmlspecialchars($order['proof_photo_url']); ?>"
                                                     alt="Photo Proof"
                                                     class="max-w-full h-auto rounded-lg border border-gray-300 shadow-sm cursor-pointer hover:shadow-md transition-shadow">
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['proof_signature_path'])): ?>
                                        <div>
                                            <p class="text-sm font-medium text-gray-600 mb-2">Signature Proof:</p>
                                            <a href="<?php echo SITE_URL . 'api/' . htmlspecialchars($order['proof_signature_path']); ?>" target="_blank">
                                                <img src="<?php echo SITE_URL . 'api/' . htmlspecialchars($order['proof_signature_path']); ?>"
                                                     alt="Signature Proof"
                                                     class="max-w-full h-auto rounded-lg border border-gray-300 shadow-sm bg-white p-2 cursor-pointer hover:shadow-md transition-shadow"> 
                                                     <!-- Added bg-white and padding for better signature visibility -->
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <!-- End Delivery Proofs Section -->
                        </div>
                    </div>

                    <!-- Status History and Manifest Info -->
                    <div class="space-y-6">
                        <!-- Manifest Information -->
                        <?php if ($manifest): ?>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h2 class="text-xl font-semibold mb-4">Manifest Information</h2>
                                <div class="space-y-4">
                                    <div class="flex justify-between border-b pb-2">
                                        <span class="font-medium">Manifest Status</span>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                            <?php echo ucfirst($manifest['status']); ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between border-b pb-2">
                                        <span class="font-medium">Assigned Driver</span>
                                        <span><?php echo htmlspecialchars($manifest['rider_name']); ?></span>
                                    </div>
                                    <div class="flex justify-between border-b pb-2">
                                        <span class="font-medium">Created At</span>
                                        <span><?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Status History -->
                        <div class="bg-white shadow rounded-lg p-6">
                            <h2 class="text-xl font-semibold mb-4">Status History</h2>
                            <div class="flow-root">
                                <ul class="-mb-8">
                                    <?php foreach ($status_logs as $index => $log): ?>
                                        <li>
                                            <div class="relative pb-8">
                                                <?php if ($index !== count($status_logs) - 1): ?>
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
                                                    case 'pending':
                                                        echo 'bg-yellow-500';
                                                        break;
                                                    case 'failed':
                                                        echo 'bg-red-500';
                                                        break;
                                                    default:
                                                        echo 'bg-blue-500';
                                                }
                                                ?>">
                                                            <!-- Status Icon -->
                                                            <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                        </span>
                                                    </div>
                                                    <div class="min-w-0 flex-1 pt-1.5 flex justify-between space-x-4">
                                                        <div>
                                                            <p class="text-sm text-gray-500">
                                                                Status changed to <span class="font-medium text-gray-900"><?php echo ucfirst($log['status']); ?></span>
                                                                by <?php echo htmlspecialchars($log['changed_by_name']); ?>
                                                            </p>
                                                            <?php if ($log['reason']): ?>
                                                                <p class="mt-1 text-sm text-red-500">
                                                                    <strong><?php echo htmlspecialchars($log['reason']); ?></strong>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if ($log['photo_url']): ?>
                                                                <div class="mt-2">
                                                                    <img src="<?php echo "https://techryption.com/test/api/".htmlspecialchars($log['photo_url']); ?>"
                                                                        alt="Status update photo"
                                                                        class="h-32 w-auto rounded-lg shadow">
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($log['delivered_to']): ?>
                                                                <p class="mt-1 text-sm text-gray-500">
                                                                    Delivered to: <?php echo htmlspecialchars($log['delivered_to']); ?>
                                                                </p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-right text-sm whitespace-nowrap text-gray-500">
                                                            <?php echo date('M d, Y H:i', strtotime($log['changed_at'])); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <script>
            // Add print functionality if needed
            document.addEventListener('DOMContentLoaded', function() {
                // Add any JavaScript functionality here

                // Example: Print button functionality
                const printButton = document.getElementById('printOrder');
                if (printButton) {
                    printButton.addEventListener('click', function() {
                        window.print();
                    });
                }
            });
        </script>
</body>

</html>