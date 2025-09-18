<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$product = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights - Only show product if it belongs to user's company
    $company_condition = !isSuperAdmin() ? "AND p.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch product details with company name and order stats
    $query = "SELECT p.*, c.name as company_name,
              (SELECT COUNT(*) FROM ProductOrders WHERE product_id = p.id) as order_count,
              (SELECT SUM(quantity) FROM ProductOrders WHERE product_id = p.id) as total_quantity,
              (SELECT COUNT(DISTINCT order_id) FROM ProductOrders WHERE product_id = p.id) as unique_orders
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $id = cleanInput($_POST['id']);
    
    // Check if product has any orders
    if ($product['order_count'] > 0) {
        $error = 'Cannot delete product: Has existing orders. Consider deactivating instead.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // Delete the product
            $delete_query = "DELETE FROM Products WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $id);
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_commit($conn);
                header('Location: index.php?deleted=1');
                exit();
            } else {
                throw new Exception('Error deleting product: ' . mysqli_error($conn));
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Product - <?php echo SITE_NAME; ?></title>
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
            <h1 class="text-2xl font-bold text-gray-900">Delete Product</h1>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Warning</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <p>You are about to delete the product: <strong><?php echo htmlspecialchars($product['name']); ?></strong></p>
                            <?php if ($product['order_count'] > 0): ?>
                                <p class="mt-2 font-bold">This product cannot be deleted because it has existing orders.</p>
                                <ul class="list-disc list-inside mt-1">
                                    <li><?php echo $product['unique_orders']; ?> orders contain this product</li>
                                    <li><?php echo $product['total_quantity']; ?> total units ordered</li>
                                </ul>
                            <?php else: ?>
                                <p class="mt-2">This action cannot be undone and will:</p>
                                <ul class="list-disc list-inside text-sm text-gray-600 mt-2">
                                    <li>Remove the product from the system</li>
                                    <li>Make the barcode invalid</li>
                                    <li>Delete all associated records</li>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Product Details -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Product Information</h3>
                        <ul class="list-disc list-inside text-sm text-gray-600 mt-4">
                            <li><strong>Name:</strong> <?php echo htmlspecialchars($product['name']); ?></li>
                            <li><strong>Barcode:</strong> <?php echo htmlspecialchars($product['qrcode_number']); ?></li>
                            <li><strong>Company:</strong> <?php echo htmlspecialchars($product['company_name']); ?></li>
                            <li><strong>Created:</strong> <?php echo date('M d, Y', strtotime($product['created_at'])); ?></li>
                        </ul>
                    </div>

                    <!-- Description -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Description</h3>
                        <p class="text-sm text-gray-600">
                            <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                        </p>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 mt-6">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" name="confirm_delete" 
                            class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700"
                            <?php echo $product['order_count'] > 0 ? 'disabled' : ''; ?>>
                        <?php echo $product['order_count'] > 0 ? 'Cannot Delete' : 'Confirm Delete'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    </div>
</body>
</html>