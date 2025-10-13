<?php
require_once '../../includes/config.php';
requireLogin();

// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtering
$status_filter = isset($_GET['status']) ? cleanInput($_GET['status']) : '';
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? cleanInput($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? cleanInput($_GET['date_to']) : '';

// Build WHERE clause and parameters for prepared statements
$where_clauses = [];
$params = [];
$types = '';

if (!isSuperAdmin()) {
    $where_clauses[] = "o.company_id = ?";
    $params[] = $_SESSION['company_id'];
    $types .= 'i';
}
if ($status_filter) {
    $where_clauses[] = "o.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($search) {
    $search_term = "%{$search}%";
    $where_clauses[] = "(o.order_number LIKE ? OR cust.name LIKE ? OR cust.email LIKE ? OR cust.phone LIKE ?)";
    // Add the search term 4 times for each LIKE clause
    array_push($params, $search_term, $search_term, $search_term, $search_term);
    $types .= 'ssss';
}
if ($date_from) {
    $where_clauses[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where_clauses[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

// Count total records using prepared statement
$count_query = "SELECT COUNT(*) as count 
                FROM Orders o 
                LEFT JOIN Customers cust ON o.customer_id = cust.id"
    . $where_sql;

$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_records = mysqli_fetch_assoc($count_result)['count'];
$total_pages = ceil($total_records / $limit);
mysqli_stmt_close($count_stmt);

// Fetch orders using prepared statement
$query = "SELECT o.*, c.name as company_name, mo.manifest_id, cust.name as customer_name, cust.phone as phone
          FROM Orders o
          LEFT JOIN Companies c ON o.company_id = c.id
          LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
          LEFT JOIN Customers cust ON o.customer_id = cust.id"
    . $where_sql .
    " ORDER BY o.created_at DESC
          LIMIT ? OFFSET ?";

// Add LIMIT and OFFSET params
$limit_offset_types = 'ii';
$params[] = $limit;
$params[] = $offset;
$final_types = $types . $limit_offset_types;

$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $final_types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Prepare base query string for pagination links
$pagination_params = [];
if ($status_filter)
    $pagination_params['status'] = $status_filter;
if ($search)
    $pagination_params['search'] = $search;
if ($date_from)
    $pagination_params['date_from'] = $date_from;
if ($date_to)
    $pagination_params['date_to'] = $date_to;
$base_query_string = http_build_query($pagination_params);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom styles to prevent horizontal scroll */
        .container-full {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .table-container {
            overflow-x: auto;
            max-width: 100%;
        }

        /* Ensure the table doesn't exceed container width */
        .responsive-table {
            min-width: 100%;
            table-layout: auto;
        }

        /* Make table cells wrap content properly */
        .responsive-table th,
        .responsive-table td {
            white-space: nowrap;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body class="bg-gray-100 overflow-x-hidden">
    <?php include_once '../../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="container-full flex-1 flex flex-col">
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
        <div class="p-4 overflow-x-hidden">
            <div class="max-w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <h1 class="text-2xl font-bold text-gray-900">Orders</h1>
                    <div class="flex flex-wrap gap-2">
                        <a href="import.php"
                            class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 text-sm">Import
                            Orders</a>
                        <a href="create.php"
                            class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 text-sm">Create
                            Order</a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white p-4 rounded-lg shadow mb-6">
                    <form method="GET" class="flex flex-col md:flex-row gap-4 items-start md:items-end">
                        <div class="w-full md:flex-1">
                            <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                            <input type="text" id="search" name="search"
                                value="<?php echo htmlspecialchars($search); ?>" placeholder="Order#, Customer..."
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                        <div class="w-full md:w-auto">
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select id="status" name="status"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>
                                    Pending</option>
                                <option value="assigned" <?php echo $status_filter === 'assigned' ? 'selected' : ''; ?>>
                                    Assigned</option>
                                <option value="delivering" <?php echo $status_filter === 'delivering' ? 'selected' : ''; ?>>Delivering</option>
                                <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>
                                    Delivered</option>
                                <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed
                                </option>
                            </select>
                        </div>
                        <div class="w-full md:w-auto">
                            <label for="date_from" class="block text-sm font-medium text-gray-700">Date From</label>
                            <input type="date" id="date_from" name="date_from"
                                value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                        <div class="w-full md:w-auto">
                            <label for="date_to" class="block text-sm font-medium text-gray-700">Date To</label>
                            <input type="date" id="date_to" name="date_to"
                                value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>"
                                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                        <div class="flex gap-2 w-full md:w-auto">
                            <button type="submit"
                                class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 text-sm">Filter</button>
                            <a href="index.php"
                                class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 text-sm">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <div class="table-container">
                        <table class="responsive-table min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Order Number</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Customer</th>
                                    <?php if (isSuperAdmin()): ?>
                                        <th
                                            class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Company</th>
                                    <?php endif; ?>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Route</th>
                                    <th
                                        class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Created</th>
                                    <th
                                        class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php while ($order = mysqli_fetch_assoc($result)): ?>
                                    <tr>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($order['order_number']); ?></div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($order['customer_name'] ?? ''); ?></div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($order['phone'] ?? ''); ?></div>
                                        </td>
                                        <?php if (isSuperAdmin()): ?>
                                            <td class="px-4 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($order['company_name']); ?></div>
                                            </td>
                                        <?php endif; ?>
                                        <td class="px-4 py-4 whitespace-nowrap">
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
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <?php if ($order['manifest_id']): ?>
                                                <a href="../manifests/view.php?id=<?php echo $order['manifest_id']; ?>"
                                                    class="text-indigo-600 hover:text-indigo-900 text-sm">
                                                    View Route
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-500 text-sm">Not Assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="edit.php?id=<?php echo $order['id']; ?>"
                                                class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                            <a href="view.php?id=<?php echo $order['id']; ?>"
                                                class="text-green-600 hover:text-green-900 mr-3">View</a>
                                            <a href="delete.php?id=<?php echo $order['id']; ?>"
                                                class="text-red-600 hover:text-red-900">Delete</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div
                        class="bg-white px-4 py-3 flex flex-col sm:flex-row items-center justify-between border-t border-gray-200 mt-4 gap-3">
                        <!-- Mobile pagination -->
                        <div class="flex-1 flex justify-between items-center w-full sm:hidden">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo ($page - 1); ?>&<?php echo $base_query_string; ?>"
                                    class="relative inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </a>
                            <?php else: ?>
                                <span class="invisible px-3 py-2">Previous</span>
                            <?php endif; ?>

                            <span class="text-sm text-gray-700 px-3">
                                Page <span class="font-medium"><?php echo $page; ?></span> of <span
                                    class="font-medium"><?php echo $total_pages; ?></span>
                            </span>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo ($page + 1); ?>&<?php echo $base_query_string; ?>"
                                    class="relative inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </a>
                            <?php else: ?>
                                <span class="invisible px-3 py-2">Next</span>
                            <?php endif; ?>
                        </div>

                        <!-- Desktop pagination -->
                        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between w-full">
                            <div class="flex-shrink-0">
                                <p class="text-sm text-gray-700 whitespace-nowrap">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to
                                    <span class="font-medium"><?php echo min($offset + $limit, $total_records); ?></span> of
                                    <span class="font-medium"><?php echo $total_records; ?></span> results
                                </p>
                            </div>
                            <div class="flex-shrink-0 ml-4">
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px overflow-hidden"
                                    aria-label="Pagination">
                                    <?php if ($page > 1): ?>
                                        <a href="?page=<?php echo ($page - 1); ?>&<?php echo $base_query_string; ?>"
                                            class="relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Previous</span>
                                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                                fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd"
                                                    d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Show limited page numbers for better mobile experience -->
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    if ($start_page > 1): ?>
                                        <a href="?page=1&<?php echo $base_query_string; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            1
                                        </a>
                                        <?php if ($start_page > 2): ?>
                                            <span
                                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                                ...
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="?page=<?php echo $i; ?>&<?php echo $base_query_string; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium <?php echo $i === $page ? 'bg-indigo-50 border-indigo-500 text-indigo-600 z-10' : 'bg-white text-gray-700 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <span
                                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                                ...
                                            </span>
                                        <?php endif; ?>
                                        <a href="?page=<?php echo $total_pages; ?>&<?php echo $base_query_string; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            <?php echo $total_pages; ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($page < $total_pages): ?>
                                        <a href="?page=<?php echo ($page + 1); ?>&<?php echo $base_query_string; ?>"
                                            class="relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <span class="sr-only">Next</span>
                                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                                fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd"
                                                    d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                                    clip-rule="evenodd" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>