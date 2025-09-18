<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';

// Generate unique order number
function generateOrderNumber($conn) {
    do {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check_query = "SELECT id FROM Orders WHERE order_number = '$order_number'";
        $result = mysqli_query($conn, $check_query);
    } while (mysqli_num_rows($result) > 0);
    
    return $order_number;
}

// Fetch companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $customer_name = cleanInput($_POST['customer_name']);
    $phone = cleanInput($_POST['phone']);
    $address_line1 = cleanInput($_POST['address_line1']);
    $address_line2 = cleanInput($_POST['address_line2']);
    $city = cleanInput($_POST['city']);
    $state = cleanInput($_POST['state']);
    $postal_code = cleanInput($_POST['postal_code']);
    $country = cleanInput($_POST['country']);
    $notes = cleanInput($_POST['notes']);
    $total_amount = cleanInput($_POST['total_amount']);
    $company_id = isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id'];

    // Validate required fields
    if (empty($customer_name) || empty($address_line1) || empty($city)) {
        $error = 'Please fill in all required fields';
    } else {
        // Generate order number
        $order_number = generateOrderNumber($conn);

        // Insert order
        $query = "INSERT INTO Orders (order_number, customer_name, phone, status, address_line1, 
                                    address_line2, city, state, postal_code, country, notes, 
                                    total_amount, company_id) 
                  VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssssssssssdl", 
            $order_number, $customer_name, $phone, $address_line1, $address_line2, 
            $city, $state, $postal_code, $country, $notes, $total_amount, $company_id
        );
        
        if (mysqli_stmt_execute($stmt)) {
            $order_id = mysqli_insert_id($conn);
            
            // Create initial status log
            $user_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, 'pending', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "ii", $order_id, $user_id);
            mysqli_stmt_execute($log_stmt);
            
            $success = 'Order created successfully';
            header("refresh:1;url=index.php");
        } else {
            $error = 'Error creating order: ' . mysqli_error($conn);
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
    <title>Create Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Create New Order</h1>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $success; ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <form action="" method="POST">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Customer Information -->
                    <div class="space-y-6">
                        <h2 class="text-xl font-semibold">Customer Information</h2>
                        
                        <div>
                            <label for="customer_name" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                            <input type="text" name="customer_name" id="customer_name" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                            <input type="text" name="phone" id="phone"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <?php if (isSuperAdmin()): ?>
                        <div>
                            <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                            <select name="company_id" id="company_id" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Delivery Address -->
                    <div class="space-y-6">
                        <h2 class="text-xl font-semibold">Delivery Address</h2>
                        
                        <div>
                            <label for="address_line1" class="block text-sm font-medium text-gray-700">Address Line 1 *</label>
                            <input type="text" name="address_line1" id="address_line1" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="address_line2" class="block text-sm font-medium text-gray-700">Address Line 2</label>
                            <input type="text" name="address_line2" id="address_line2"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                            <input type="text" name="city" id="city" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="state" class="block text-sm font-medium text-gray-700">State/Province</label>
                            <input type="text" name="state" id="state"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code</label>
                            <input type="text" name="postal_code" id="postal_code"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                            <input type="text" name="country" id="country"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="mt-6 space-y-6">
                    <h2 class="text-xl font-semibold">Order Details</h2>
                    
                    <div>
                        <label for="total_amount" class="block text-sm font-medium text-gray-700">Total Amount</label>
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">$</span>
                            </div>
                            <input type="number" name="total_amount" id="total_amount" step="0.01"
                                   class="pl-7 mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" id="notes" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Order</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>