<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin can access this page
if (!isSuperAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

// Handle delete action
if (isset($_POST['delete'])) {
    $id = cleanInput($_POST['id']);
    $delete_query = "DELETE FROM Companies WHERE id = $id";
    mysqli_query($conn, $delete_query);
}

// Fetch all companies with rider counts
$query = "SELECT 
    Companies.*,
    (SELECT COUNT(*) FROM RiderCompanies 
     WHERE company_id = Companies.id AND is_active = 1) as active_riders,
    (SELECT COUNT(*) FROM Orders WHERE company_id = Companies.id) as order_count
FROM Companies 
ORDER BY created_at DESC";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Companies - <?php echo SITE_NAME; ?></title>
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
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-2xl font-bold text-gray-900">Companies</h1>
                    <a href="create.php" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Add Company</a>
                </div>

                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Riders</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Orders</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($company = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($company['name']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($company['phone']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($company['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php
                                            $active_riders = $company['active_riders'];
                                            $total_allowed = $company['total_riders_allowed'] ?: '∞';
                                            $rider_status_class = $total_allowed !== '∞' && $active_riders >= $total_allowed ? 'text-red-600' : 'text-green-600';
                                            ?>
                                            <span class="<?php echo $rider_status_class; ?>">
                                                <?php echo $active_riders; ?>
                                            </span>
                                            / <?php echo $total_allowed; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $company['order_count']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M d, Y', strtotime($company['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="edit.php?id=<?php echo $company['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                        <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this company?');">
                                            <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                                            <button type="submit" name="delete" class="text-red-600 hover:text-red-900">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>


</body>

</html>