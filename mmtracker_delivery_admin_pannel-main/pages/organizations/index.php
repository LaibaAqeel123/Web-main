<?php
require_once '../../includes/config.php';
requireLogin();

// Only admins can access organization management
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

// Build WHERE clause
$where_conditions = [];

if (!isSuperAdmin()) {
    $where_conditions[] = "o.company_id = " . $_SESSION['company_id'];
}
if ($status_filter !== '') {
    $where_conditions[] = "o.is_active = " . ($status_filter === 'active' ? '1' : '0');
}
if ($search) {
    $where_conditions[] = "(o.name LIKE '%$search%' OR o.email LIKE '%$search%' OR o.phone LIKE '%$search%')";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total records
$count_query = "SELECT COUNT(o.id) as total FROM Organizations o $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $limit);

// Get organizations list
$query = "SELECT o.*, c.name as company_name 
          FROM Organizations o 
          LEFT JOIN Companies c ON o.company_id = c.id
          $where_clause
          ORDER BY o.created_at DESC
          LIMIT $offset, $limit";

$result = mysqli_query($conn, $query);
$organizations = [];
while ($row = mysqli_fetch_assoc($result)) {
    $organizations[] = $row;
}

// Handle organization status toggle
if (isset($_POST['toggle_status']) && isset($_POST['organization_id'])) {
    $organization_id = (int)$_POST['organization_id'];
    $current_status = (int)$_POST['current_status'];
    $new_status = $current_status ? 0 : 1;
    
    // Make sure user has permission to modify this organization
    $check_query = "SELECT company_id FROM Organizations WHERE id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "i", $organization_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $organization_data = mysqli_fetch_assoc($check_result);
    
    if ($organization_data && (isSuperAdmin() || $organization_data['company_id'] == $_SESSION['company_id'])) {
        $update_query = "UPDATE Organizations SET is_active = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "ii", $new_status, $organization_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $status_message = $new_status ? "Organization activated successfully" : "Organization deactivated successfully";
        } else {
            $error_message = "Failed to update organization status";
        }
        
        // Redirect to avoid form resubmission
        header("Location: index.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit();
    }
}

// Get companies for filter (Super Admin only)
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <div class="flex flex-col md:flex-row justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900 mb-4 md:mb-0">Organizations</h1>
            <a href="create.php" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                Add New Organization
            </a>
        </div>

        <?php if (isset($status_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                <p><?php echo $status_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="bg-white shadow-md rounded-lg p-4 mb-6">
            <form action="" method="GET" class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Name, email, phone...">
                </div>
                
                <div class="w-full md:w-auto">
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select id="status" name="status"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <?php if (isSuperAdmin()): ?>
                <div class="w-full md:w-auto">
                    <label for="company_id" class="block text-sm font-medium text-gray-700 mb-1">Company</label>
                    <select id="company_id" name="company_id"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All Companies</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>" <?php echo isset($_GET['company_id']) && $_GET['company_id'] == $company['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="flex gap-2">
                    <button type="submit"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                        Filter
                    </button>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded">
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Organizations Table -->
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Name
                        </th>
                        <?php if (isSuperAdmin()): ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Company
                        </th>
                        <?php endif; ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Contact
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Created
                        </th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($organizations)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-gray-500">
                                No organizations found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($organizations as $organization): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <!-- Remove Logo Display -->
                                    <!--
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 flex items-center justify-center bg-gray-200 rounded-full">
                                            <?php if ($organization['logo_url']): ?>
                                                <img class="h-10 w-10 rounded-full object-cover" src="<?php echo SITE_URL . htmlspecialchars($organization['logo_url']); ?>" alt="Logo">
                                            <?php else: ?>
                                                <span class="text-gray-500 text-xl"><?php echo substr(htmlspecialchars($organization['name']), 0, 1); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    -->
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($organization['name']); ?>
                                        </div>
                                    </div>
                                </td>
                                <?php if (isSuperAdmin()): ?>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($organization['company_name']); ?></div>
                                </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($organization['email']): ?>
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($organization['email']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($organization['phone']): ?>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($organization['phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $organization['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $organization['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($organization['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-2">
                                        <a href="view.php?id=<?php echo $organization['id']; ?>" class="text-blue-600 hover:text-blue-900">View</a>
                                        <a href="edit.php?id=<?php echo $organization['id']; ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                        <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to <?php echo $organization['is_active'] ? 'deactivate' : 'activate'; ?> this organization?');">
                                            <input type="hidden" name="organization_id" value="<?php echo $organization['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $organization['is_active']; ?>">
                                            <button type="submit" name="toggle_status" class="<?php echo $organization['is_active'] ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'; ?>">
                                                <?php echo $organization['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="mt-4 flex justify-center">
                <nav class="inline-flex rounded-md shadow">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $status_filter !== '' ? '&status=' . urlencode($status_filter) : ''; ?>"
                            class="px-4 py-2 <?php echo $i === $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'; ?> text-sm font-medium border <?php echo $i === 1 ? 'rounded-l-md' : ''; ?> <?php echo $i === $total_pages ? 'rounded-r-md' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</body>
</html> 