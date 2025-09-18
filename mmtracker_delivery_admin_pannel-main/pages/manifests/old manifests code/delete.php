<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$manifest = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);

    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";

    // Fetch manifest details
    $query = "SELECT m.*, u.name as rider_name, c.name as company_name,
              (SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = m.id) as order_count
              FROM Manifests m
              LEFT JOIN Users u ON m.rider_id = u.id
              LEFT JOIN Companies c ON m.company_id = c.id
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $id = cleanInput($_POST['id']);

    // Check manifest status
    if ($manifest['status'] === 'delivered') {
        $error = 'Cannot delete manifest: Orders have already been delivered';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // Add status logs for orders being reset
            $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by, reason) 
                         SELECT o.id, 'pending', ?, 'Manifest deleted'
                         FROM Orders o 
                         JOIN ManifestOrders mo ON o.id = mo.order_id 
                         WHERE mo.manifest_id = ?";
            $stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $id);
            mysqli_stmt_execute($stmt);

            // Reset order statuses
            $update_orders = "UPDATE Orders o
                            JOIN ManifestOrders mo ON o.id = mo.order_id
                            SET o.status = 'pending'
                            WHERE mo.manifest_id = ?";
            $stmt = mysqli_prepare($conn, $update_orders);
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);

            // Delete manifest orders
            $delete_orders = "DELETE FROM ManifestOrders WHERE manifest_id = ?";
            $stmt = mysqli_prepare($conn, $delete_orders);
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);

            // Delete manifest
            $delete_manifest = "DELETE FROM Manifests WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_manifest);
            mysqli_stmt_bind_param($stmt, "i", $id);
            mysqli_stmt_execute($stmt);

            mysqli_commit($conn);
            header('Location: index.php?deleted=1');
            exit();
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Error deleting manifest: ' . $e->getMessage();
        }
    }
}

// Get the deletion status message
$status_message = '';
switch ($manifest['status']) {
    case 'delivered':
        $status_message = 'This manifest cannot be deleted because it has been delivered.';
        break;
    default:
        $status_message = 'Are you sure you want to delete this manifest? This action cannot be undone.';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Manifest - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Delete Manifest #<?php echo $manifest['id']; ?></h1>
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
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Warning</h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <p><?php echo $status_message; ?></p>
                                    <?php if ($manifest['status'] !== 'delivering' && $manifest['status'] !== 'delivered'): ?>
                                        <p class="mt-2">This action will:</p>
                                        <ul class="list-disc list-inside mt-1">
                                            <li>Delete the manifest and all associated records</li>
                                            <li>Reset the status of all orders in this manifest to 'pending'</li>
                                            <li>Remove rider assignment from this manifest</li>
                                            <li>Make orders available for new manifest creation</li>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="border rounded-lg p-4 bg-gray-50">
                            <h3 class="font-medium text-gray-900 mb-2">Manifest Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm text-gray-700"><strong>Status:</strong>
                                        <span class="px-2 py-1 rounded-full text-xs font-medium
                                    <?php
                                    switch ($manifest['status']) {
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
                                    </p>
                                    <p class="text-sm text-gray-700"><strong>Orders:</strong> <?php echo $manifest['order_count']; ?></p>
                                    <p class="text-sm text-gray-700"><strong>Assigned Rider:</strong> <?php echo htmlspecialchars($manifest['rider_name'] ?? 'Not Assigned'); ?></p>
                                </div>
                                <div>
                                    <?php if (isSuperAdmin()): ?>
                                        <p class="text-sm text-gray-700"><strong>Company:</strong> <?php echo htmlspecialchars($manifest['company_name']); ?></p>
                                    <?php endif; ?>
                                    <p class="text-sm text-gray-700"><strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($manifest['created_at'])); ?></p>
                                    <p class="text-sm text-gray-700"><strong>Last Updated:</strong> <?php echo date('M d, Y H:i', strtotime($manifest['updated_at'])); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <?php if ($manifest['status'] !== 'delivered'): ?>
                                <button type="submit" name="confirm_delete" form="deleteForm"
                                    class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                                    Confirm Delete
                                </button>
                            <?php endif; ?>
                        </div>

                        <form id="deleteForm" action="" method="POST" class="hidden">
                            <input type="hidden" name="id" value="<?php echo $manifest['id']; ?>">
                            <input type="hidden" name="confirm_delete" value="1">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($manifest['status'] !== 'delivered'): ?>
            <script>
                document.getElementById('deleteForm').onsubmit = function(e) {
                    if (!confirm('This action cannot be undone. Are you sure you want to delete this manifest?')) {
                        e.preventDefault();
                        return false;
                    }
                    return true;
                };
            </script>
        <?php endif; ?>
</body>

</html>