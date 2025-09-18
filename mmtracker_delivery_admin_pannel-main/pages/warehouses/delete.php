<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin and admin can access this page
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$warehouse = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND w.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch warehouse details with company name
    $query = "SELECT w.*, c.name as company_name
              FROM Warehouses w
              LEFT JOIN Companies c ON w.company_id = c.id
              WHERE w.id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $warehouse = mysqli_fetch_assoc($result);

    if (!$warehouse) {
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $id = cleanInput($_POST['id']);

    // First check if there are any manifests associated with this warehouse
    $check_query = "SELECT COUNT(*) as manifest_count FROM Manifests WHERE warehouse_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $manifest_count = mysqli_fetch_assoc($check_result)['manifest_count'];
    mysqli_stmt_close($check_stmt);

    if ($manifest_count > 0) {
        $error = 'Cannot delete this warehouse because it has associated manifests. Please delete or reassign the manifests first.';
    } else {
        $query = "DELETE FROM Warehouses WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $id);
        
        if (mysqli_stmt_execute($stmt)) {
            header('Location: index.php?deleted=1');
            exit();
        } else {
            $error = 'Error deleting warehouse: ' . mysqli_error($conn);
        }
        
        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Warehouse - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="flex-1 flex flex-col">
        <div class="bg-gray-800 p-4 flex justify-between items-center">
            <div class="text-white text-lg">Delete Warehouse</div>
            <div class="flex items-center">
                <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                <a href="<?php echo SITE_URL; ?>logout.php" class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Logout</a>
            </div>
        </div>

        <div class="p-6">
            <div class="max-w-3xl mx-auto">
                <div class="mb-6 flex justify-between items-center">
                    <h1 class="text-2xl font-bold text-gray-900">Delete Warehouse</h1>
                    <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Back to List</a>
                </div>

                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error; ?></span>
                </div>
                <?php endif; ?>

                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <div class="p-6">
                        <div class="mb-6">
                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-yellow-700">
                                            Are you sure you want to delete this warehouse? This action cannot be undone.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 px-4 py-5 sm:p-0">
                            <dl class="sm:divide-y sm:divide-gray-200">
                                <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
                                    <dt class="text-sm font-medium text-gray-500">Name</dt>
                                    <dd class="mt-1 text-sm text-gray-900 sm:col-span-2"><?php echo htmlspecialchars($warehouse['name']); ?></dd>
                                </div>
                                <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
                                    <dt class="text-sm font-medium text-gray-500">Company</dt>
                                    <dd class="mt-1 text-sm text-gray-900 sm:col-span-2"><?php echo htmlspecialchars($warehouse['company_name']); ?></dd>
                                </div>
                                <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
                                    <dt class="text-sm font-medium text-gray-500">Address</dt>
                                    <dd class="mt-1 text-sm text-gray-900 sm:col-span-2">
                                        <?php echo htmlspecialchars($warehouse['address']); ?><br>
                                        <?php echo htmlspecialchars($warehouse['city'] . ', ' . $warehouse['state'] . ' ' . $warehouse['postal_code']); ?><br>
                                        <?php echo htmlspecialchars($warehouse['country']); ?>
                                    </dd>
                                </div>
                                <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
                                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                                    <dd class="mt-1 text-sm text-gray-900 sm:col-span-2">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php echo $warehouse['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($warehouse['status']); ?>
                                        </span>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div class="mt-6 flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <form action="" method="POST" class="inline" onsubmit="return confirm('Are you absolutely sure you want to delete this warehouse?');">
                                <input type="hidden" name="id" value="<?php echo $warehouse['id']; ?>">
                                <button type="submit" name="confirm_delete" 
                                        class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700">
                                    Confirm Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 