<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin and admin can access rider management
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtering
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? cleanInput($_GET['status']) : '';

// Build WHERE clause for riders list
$where_conditions = ["u.user_type = 'Rider'"];
if (!isSuperAdmin()) {
    $where_conditions[] = "rc.company_id = " . $_SESSION['company_id'];
}
if ($status_filter !== '') {
    $where_conditions[] = "rc.is_active = " . ($status_filter === 'active' ? '1' : '0');
}
if ($search) {
    $where_conditions[] = "(u.name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%')";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total records
$count_query = "SELECT COUNT(DISTINCT u.id) as total 
                FROM Users u 
                LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
                $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $limit);

// Company-specific condition for stats
$company_stats_condition = !isSuperAdmin() ? "AND m.company_id = " . $_SESSION['company_id'] : "";

// Fetch riders with their company-specific stats
$query = "SELECT DISTINCT u.*, rc.is_active as rider_active, c.name as company_name,
          (
              SELECT COUNT(*) 
              FROM Manifests m 
              WHERE m.rider_id = u.id 
              AND m.status != 'delivered'
              $company_stats_condition
          ) as active_manifests,
          (
              SELECT COUNT(*) 
              FROM Manifests m 
              WHERE m.rider_id = u.id
              $company_stats_condition
          ) as total_manifests,
          (
              SELECT COUNT(*) 
              FROM Orders o 
              JOIN ManifestOrders mo ON o.id = mo.order_id 
              JOIN Manifests m ON mo.manifest_id = m.id 
              WHERE m.rider_id = u.id 
              AND o.status = 'delivered'
              $company_stats_condition
          ) as delivered_orders
          FROM Users u
          LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
          LEFT JOIN Companies c ON rc.company_id = c.id
          $where_clause
          ORDER BY u.name
          LIMIT $offset, $limit";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riders - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Riders</h1>
                    <a href="create.php" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Add Rider</a>
                </div>

                <!-- Filters -->
                <div class="bg-white p-4 rounded-lg shadow mb-6">
                    <form method="GET" class="flex gap-4">
                        <div class="flex-1">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search riders..."
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Filter</button>
                        <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Reset</a>
                    </form>
                </div>

                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rider</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <?php if (isSuperAdmin()): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stats</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($rider = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($rider['name']); ?></div>
                                        <div class="text-sm text-gray-500">Joined <?php echo date('M Y', strtotime($rider['created_at'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($rider['email']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($rider['phone']); ?></div>
                                    </td>
                                    <?php if (isSuperAdmin()): ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($rider['company_name'] ?? 'N/A'); ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $rider['rider_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $rider['rider_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if ($rider['active_manifests'] > 0): ?>
                                            <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                On Delivery
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div>Manifests: <?php echo $rider['total_manifests']; ?></div>
                                        <div>Delivered: <?php echo $rider['delivered_orders']; ?> orders</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="view.php?id=<?php echo $rider['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                                        <!-- <a href="edit.php?id=<?php echo $rider['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a> -->
                                        <a href="delete.php?id=<?php echo $rider['id']; ?>" class="text-red-600 hover:text-red-900">Delete</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <?php if (mysqli_num_rows($result) == 0): ?>
                        <div class="text-center py-4 text-gray-500">
                            No riders found.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-4">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo ($page - 1); ?>&status=<?php echo $status_filter; ?>&search=<?php echo $search; ?>"
                                    class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo ($page + 1); ?>&status=<?php echo $status_filter; ?>&search=<?php echo $search; ?>"
                                    class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to
                                    <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> of
                                    <span class="font-medium"><?php echo $total_records; ?></span> riders
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&search=<?php echo $search; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                                      <?php echo $i === $page ? 'bg-gray-100' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
</body>

</html>