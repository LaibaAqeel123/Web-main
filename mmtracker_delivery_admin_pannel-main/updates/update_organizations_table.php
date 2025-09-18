<?php
/**
 * Update Organizations Table
 * 
 * This script adds the missing columns (city, postal_code, country) to the Organizations table
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

// Check if columns already exist
$column_check_postal = mysqli_query($conn, "SHOW COLUMNS FROM `Organizations` LIKE 'postal_code'");
$column_check_city = mysqli_query($conn, "SHOW COLUMNS FROM `Organizations` LIKE 'city'");
$column_check_country = mysqli_query($conn, "SHOW COLUMNS FROM `Organizations` LIKE 'country'");

$postal_exists = mysqli_num_rows($column_check_postal) > 0;
$city_exists = mysqli_num_rows($column_check_city) > 0;
$country_exists = mysqli_num_rows($column_check_country) > 0;

// If all columns exist, no update needed
if ($postal_exists && $city_exists && $country_exists) {
    $success = "All required columns already exist in the Organizations table.";
} else {
    try {
        mysqli_begin_transaction($conn);
        
        // Add missing columns as needed
        if (!$city_exists) {
            $add_city = "ALTER TABLE `Organizations` ADD COLUMN `city` varchar(100) DEFAULT NULL AFTER `address`";
            if (!mysqli_query($conn, $add_city)) {
                throw new Exception("Error adding city column: " . mysqli_error($conn));
            }
        }
        
        if (!$postal_exists) {
            $add_postal = "ALTER TABLE `Organizations` ADD COLUMN `postal_code` varchar(20) DEFAULT NULL AFTER `city`";
            if (!mysqli_query($conn, $add_postal)) {
                throw new Exception("Error adding postal_code column: " . mysqli_error($conn));
            }
        }
        
        if (!$country_exists) {
            $add_country = "ALTER TABLE `Organizations` ADD COLUMN `country` varchar(100) DEFAULT 'United Kingdom' AFTER `postal_code`";
            if (!mysqli_query($conn, $add_country)) {
                throw new Exception("Error adding country column: " . mysqli_error($conn));
            }
        }
        
        mysqli_commit($conn);
        $success = "Organizations table updated successfully with the new address fields.";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Organizations Table - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../includes/navbar.php'; ?>
    
    <div class="container mx-auto px-4 py-6">
        <div class="max-w-4xl mx-auto">
            <h1 class="text-2xl font-bold text-gray-900 mb-6">Update Organizations Table</h1>
            
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
                
                <div class="flex justify-between mt-6">
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded">
                        Back to Dashboard
                    </a>
                    <a href="../pages/organizations/index.php" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                        Go to Organizations
                    </a>
                </div>
            <?php else: ?>
                <div class="bg-white shadow-md rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Update Information</h2>
                    <p class="mb-4">This script will add the following columns to the Organizations table if they don't exist:</p>
                    <ul class="list-disc pl-5 space-y-2 mb-6">
                        <li>city - For storing the city/town of the organization</li>
                        <li>postal_code - For storing UK postcodes</li>
                        <li>country - Set to default as "United Kingdom"</li>
                    </ul>
                    
                    <form action="" method="POST" class="mt-6">
                        <button type="submit" name="update" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                            Update Organizations Table
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 