<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';
$product = null;

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
}

// Fetch companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

// Get product usage statistics
$stats_query = "SELECT 
    COUNT(DISTINCT po.order_id) as total_orders,
    SUM(po.quantity) as total_quantity
    FROM ProductOrders po 
    WHERE po.product_id = ?";
$stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = cleanInput($_POST['id']);
    $name = cleanInput($_POST['name']);
    $description = cleanInput($_POST['description']);
    $qrcode_number = cleanInput($_POST['qrcode_number']);
    $company_id = isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id'];

    if (empty($name) || empty($qrcode_number)) {
        $error = 'Product name and Barcode are required';
    } else {
        // Check if QR code exists for other products in the same company
        $check_qr = "SELECT id FROM Products WHERE qrcode_number = ? AND id != ? AND company_id = ?";
        $stmt = mysqli_prepare($conn, $check_qr);
        mysqli_stmt_bind_param($stmt, "sii", $qrcode_number, $id, $company_id);
        mysqli_stmt_execute($stmt);
        $qr_result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($qr_result) > 0) {
            $error = 'This Barcode number already exists in your company';
        } else {
            // If superadmin is changing company, check if product has any orders
            if (isSuperAdmin() && $company_id != $product['company_id']) {
                $check_orders = "SELECT COUNT(*) as count FROM ProductOrders WHERE product_id = ?";
                $stmt = mysqli_prepare($conn, $check_orders);
                mysqli_stmt_bind_param($stmt, "i", $id);
                mysqli_stmt_execute($stmt);
                $order_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'];

                if ($order_count > 0) {
                    $error = 'Cannot change company: Product has existing orders';
                }
            }

            if (!$error) {
                $query = "UPDATE Products SET 
                         name = ?, 
                         description = ?,
                         qrcode_number = ?"
                    . (isSuperAdmin() ? ", company_id = ?" : "") .
                    " WHERE id = ?";

                if (isSuperAdmin()) {
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssii", $name, $description, $qrcode_number, $company_id, $id);
                } else {
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssi", $name, $description, $qrcode_number, $id);
                }

                if (mysqli_stmt_execute($stmt)) {
                    $success = 'Product updated successfully';
                    // Refresh product data
                    $product['name'] = $name;
                    $product['description'] = $description;
                    $product['qrcode_number'] = $qrcode_number;
                    if (isSuperAdmin()) {
                        $product['company_id'] = $company_id;
                    }
                } else {
                    $error = 'Error updating product: ' . mysqli_error($conn);
                }

                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - <?php echo SITE_NAME; ?></title>
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
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-900">Edit Product</h1>
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

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Edit Form -->
                    <div class="lg:col-span-2">
                        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
                            <form action="" method="POST">
                                <input type="hidden" name="id" value="<?php echo $product['id']; ?>">

                                <div class="space-y-6">
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-700">Product Name *</label>
                                        <input type="text" name="name" id="name" required
                                            value="<?php echo htmlspecialchars($product['name']); ?>"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>

                                    <div>
                                        <label for="qrcode_number" class="block text-sm font-medium text-gray-700">Barcode Number *</label>
                                        <input type="text" name="qrcode_number" id="qrcode_number" required
                                            value="<?php echo htmlspecialchars($product['qrcode_number']); ?>"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <p class="mt-1 text-sm text-gray-500">Enter a unique barcode identifier for this product</p>
                                    </div>

                                    <div>
                                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                                        <textarea name="description" id="description" rows="3"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><?php echo htmlspecialchars($product['description']); ?></textarea>
                                    </div>

                                    <?php if (isSuperAdmin()): ?>
                                        <div>
                                            <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                                            <select name="company_id" id="company_id" required
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <?php foreach ($companies as $company): ?>
                                                    <option value="<?php echo $company['id']; ?>"
                                                        <?php echo $company['id'] == $product['company_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($company['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-6 flex justify-end space-x-3">
                                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Product</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Product Info -->
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
                                        <dt class="text-sm font-medium text-gray-500">Created</dt>
                                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('M d, Y', strtotime($product['created_at'])); ?></dd>
                                    </div>

                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Last Updated</dt>
                                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('M d, Y H:i', strtotime($product['updated_at'])); ?></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="p-6">
                                <h2 class="text-lg font-medium text-gray-900">Usage Statistics</h2>
                                <dl class="mt-4 space-y-4">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Total Orders</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900"><?php echo $stats['total_orders'] ?? 0; ?></dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Total Quantity Ordered</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-indigo-600"><?php echo $stats['total_quantity'] ?? 0; ?></dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</body>

</html>