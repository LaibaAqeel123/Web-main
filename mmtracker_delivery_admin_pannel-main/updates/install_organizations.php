<?php
/**
 * Organization Feature Installation Script
 * 
 * This script creates the Organizations table and updates the Orders table
 * to add the organization_id foreign key.
 */

require_once '../includes/config.php';

// Check if user is logged in and is an admin
requireLogin();
if (!isSuperAdmin()) {
    echo "You must be a super admin to run this script.";
    exit();
}

$error = '';
$success = '';

try {
    // Start transaction
    mysqli_begin_transaction($conn);

    // Check if Organizations table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'Organizations'");
    if (mysqli_num_rows($table_check) > 0) {
        $error = "Organizations table already exists.";
        throw new Exception($error);
    }

    // Create Organizations table
    $create_org_table = "
    CREATE TABLE `Organizations` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(255) NOT NULL,
      `company_id` int(11) NOT NULL,
      `address` text DEFAULT NULL,
      `city` varchar(100) DEFAULT NULL,
      `postal_code` varchar(20) DEFAULT NULL,
      `country` varchar(100) DEFAULT 'United Kingdom',
      `phone` varchar(20) DEFAULT NULL,
      `email` varchar(255) DEFAULT NULL,
      `logo_url` varchar(255) DEFAULT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `fk_org_company_id` (`company_id`),
      CONSTRAINT `fk_org_company_id` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";

    if (!mysqli_query($conn, $create_org_table)) {
        $error = "Error creating Organizations table: " . mysqli_error($conn);
        throw new Exception($error);
    }

    // Check if Orders table has organization_id column
    $column_check = mysqli_query($conn, "SHOW COLUMNS FROM `Orders` LIKE 'organization_id'");
    if (mysqli_num_rows($column_check) > 0) {
        $error = "organization_id column already exists in Orders table.";
        throw new Exception($error);
    }

    // Add organization_id column to Orders table
    $alter_orders_table = "
    ALTER TABLE `Orders` 
    ADD COLUMN `organization_id` int(11) DEFAULT NULL AFTER `company_id`,
    ADD CONSTRAINT `fk_order_organization` FOREIGN KEY (`organization_id`) REFERENCES `Organizations` (`id`) ON DELETE SET NULL
    ";

    if (!mysqli_query($conn, $alter_orders_table)) {
        $error = "Error adding organization_id to Orders table: " . mysqli_error($conn);
        throw new Exception($error);
    }

    // Add indexes for performance
    $add_indexes = "
    CREATE INDEX `idx_organization_company` ON `Organizations` (`company_id`);
    CREATE INDEX `idx_order_organization` ON `Orders` (`organization_id`);
    ";

    if (!mysqli_query($conn, $add_indexes)) {
        $error = "Error adding indexes: " . mysqli_error($conn);
        throw new Exception($error);
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/logos';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $error = "Failed to create uploads directory.";
            throw new Exception($error);
        }
    }
    
    // Create sample organization for each company
    $get_companies = "SELECT id, name FROM Companies";
    $company_result = mysqli_query($conn, $get_companies);
    
    while ($company = mysqli_fetch_assoc($company_result)) {
        $org_name = $company['name'] . " Main Office";
        $company_id = $company['id'];
        
        $insert_org = "
        INSERT INTO Organizations (name, company_id, address, phone, email, is_active) 
        VALUES (?, ?, ?, ?, ?, 1)
        ";
        
        $stmt = mysqli_prepare($conn, $insert_org);
        mysqli_stmt_bind_param($stmt, "sisss", $org_name, $company_id, $company['address'], $company['phone'], $company['email']);
        
        if (!mysqli_stmt_execute($stmt)) {
            $error = "Error creating sample organization for " . $company['name'] . ": " . mysqli_stmt_error($stmt);
            throw new Exception($error);
        }
    }

    // Commit transaction
    mysqli_commit($conn);
    $success = "Organizations feature installed successfully!";
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    $error = $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Organizations Feature - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../includes/navbar.php'; ?>
    
    <div class="container mx-auto px-4 py-6">
        <div class="max-w-4xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Organizations Feature Installation</h1>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Error</p>
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Success</p>
                    <p><?php echo $success; ?></p>
                </div>
                
                <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Next Steps</h2>
                    <ul class="list-disc pl-5 space-y-2">
                        <li>Go to <a href="../pages/organizations/index.php" class="text-blue-600 hover:underline">Organizations Management</a> to manage your organizations.</li>
                        <li>When creating new orders, you can now select an organization.</li>
                        <li>Existing orders don't have an organization assigned.</li>
                    </ul>
                </div>
                
                <div class="flex justify-between">
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded">
                        Back to Dashboard
                    </a>
                    <a href="../pages/organizations/index.php" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                        Go to Organizations
                    </a>
                </div>
            <?php else: ?>
                <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Installation Information</h2>
                    <p class="mb-4">This script will create:</p>
                    <ul class="list-disc pl-5 space-y-2 mb-6">
                        <li>A new Organizations table with company relationships</li>
                        <li>Add organization_id foreign key to Orders table</li>
                        <li>Create required indexes for performance</li>
                        <li>Add sample organizations for existing companies</li>
                    </ul>
                    
                    <form action="" method="POST" class="mt-6">
                        <button type="submit" name="install" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                            Install Organizations Feature
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 