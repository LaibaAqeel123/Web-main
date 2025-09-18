<?php
require_once '../../includes/config.php';
requireLogin();

// Only admins can access organization management
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

// Get organization ID from URL
$organization_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$organization_id) {
    header('Location: index.php');
    exit();
}

// Check if organization exists and user has access
$org_query = "SELECT o.*, c.name as company_name 
              FROM Organizations o
              JOIN Companies c ON o.company_id = c.id
              WHERE o.id = ?";

if (!isSuperAdmin()) {
    $org_query .= " AND o.company_id = " . $_SESSION['company_id'];
}

$org_stmt = mysqli_prepare($conn, $org_query);
mysqli_stmt_bind_param($org_stmt, "i", $organization_id);
mysqli_stmt_execute($org_stmt);
$org_result = mysqli_stmt_get_result($org_stmt);

if (mysqli_num_rows($org_result) === 0) {
    header('Location: index.php');
    exit();
}

// Get organization data
$organization = mysqli_fetch_assoc($org_result);

// Get orders count for this organization
$orders_query = "SELECT COUNT(*) as count FROM Orders WHERE organization_id = ?";
$orders_stmt = mysqli_prepare($conn, $orders_query);
mysqli_stmt_bind_param($orders_stmt, "i", $organization_id);
mysqli_stmt_execute($orders_stmt);
$orders_result = mysqli_stmt_get_result($orders_stmt);
$orders_count = mysqli_fetch_assoc($orders_result)['count'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization Details - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Organization Details</h1>
            <div>
                <a href="edit.php?id=<?php echo $organization_id; ?>" class="text-white bg-blue-600 hover:bg-blue-700 py-2 px-4 rounded mr-2">
                    Edit
                </a>
                <a href="index.php" class="text-indigo-600 hover:text-indigo-900">
                    &larr; Back to Organizations
                </a>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <div class="md:flex">
                <!-- Logo Section -->
                <!-- Remove Logo Section -->
                <!--
                <div class="md:w-1/3 p-6 bg-gray-50 flex justify-center items-start">
                    <?php if ($organization['logo_url']): ?>
                        <img src="<?php echo SITE_URL . htmlspecialchars($organization['logo_url']); ?>" 
                             alt="<?php echo htmlspecialchars($organization['name']); ?>" 
                             class="max-w-full h-auto max-h-48 object-contain">
                    <?php else: ?>
                        <div class="w-40 h-40 bg-gray-200 flex items-center justify-center rounded-lg">
                            <span class="text-gray-500 text-4xl"><?php echo strtoupper(substr($organization['name'], 0, 1)); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                -->
                
                <!-- Details Section -->
                <div class="md:w-full p-6">
                    <div class="mb-4">
                        <div class="flex items-center">
                            <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($organization['name']); ?></h2>
                            <span class="ml-2 px-2 py-1 text-xs rounded-full <?php echo $organization['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo $organization['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1">Company: <?php echo htmlspecialchars($organization['company_name']); ?></p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Contact Information</h3>
                            <div class="mt-2 space-y-1">
                                <?php if ($organization['email']): ?>
                                    <p class="text-gray-800">
                                        <span class="text-gray-600">Email:</span> 
                                        <a href="mailto:<?php echo htmlspecialchars($organization['email']); ?>" class="text-blue-600 hover:underline">
                                            <?php echo htmlspecialchars($organization['email']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ($organization['phone']): ?>
                                    <p class="text-gray-800">
                                        <span class="text-gray-600">Phone:</span>
                                        <a href="tel:<?php echo htmlspecialchars($organization['phone']); ?>" class="text-blue-600 hover:underline">
                                            <?php echo htmlspecialchars($organization['phone']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="text-sm font-medium text-gray-500">Address</h3>
                            <p class="mt-2 text-gray-800">
                                <?php echo $organization['address'] ? nl2br(htmlspecialchars($organization['address'])) : 'No address provided'; ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-medium text-gray-500">Orders</h3>
                                <p class="mt-1 text-lg font-semibold text-gray-800"><?php echo number_format($orders_count); ?></p>
                            </div>
                            
                            <a href="../orders/index.php?organization_id=<?php echo $organization_id; ?>" 
                               class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200">
                                View Orders
                            </a>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-sm text-gray-500">
                        <p>Created: <?php echo date('M j, Y \a\t g:i A', strtotime($organization['created_at'])); ?></p>
                        <?php if ($organization['updated_at']): ?>
                            <p>Last Updated: <?php echo date('M j, Y \a\t g:i A', strtotime($organization['updated_at'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 