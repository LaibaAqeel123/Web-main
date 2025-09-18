<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';

// Initialize form data
$formData = [
    'name' => '',
    'description' => '',
    'qrcode_number' => '',
    'company_id' => ''
];

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
    // Store form data
    $formData = [
        'name' => cleanInput($_POST['name']),
        'description' => cleanInput($_POST['description']),
        'qrcode_number' => cleanInput($_POST['qrcode_number']),
        'company_id' => isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id']
    ];

    if (empty($formData['name']) || empty($formData['qrcode_number'])) {
        $error = 'Product name and Barcode are required';
    } else {
        // Check if QR code exists within the same company
        $check_qr = "SELECT id FROM Products WHERE qrcode_number = ? AND company_id = ?";
        $stmt = mysqli_prepare($conn, $check_qr);
        mysqli_stmt_bind_param($stmt, "si", $formData['qrcode_number'], $formData['company_id']);
        mysqli_stmt_execute($stmt);
        $qr_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($qr_result) > 0) {
            $error = 'This Barcode number already exists in your company';
        } else {
            $query = "INSERT INTO Products (name, description, qrcode_number, company_id) 
                     VALUES (?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssi", 
                $formData['name'], $formData['description'], $formData['qrcode_number'], $formData['company_id']
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $success = 'Product created successfully';
                header("refresh:1;url=index.php");
            } else {
                $error = 'Error creating product: ' . mysqli_error($conn);
            }
            
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - <?php echo SITE_NAME; ?></title>
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
            <h1 class="text-2xl font-bold text-gray-900">Add New Product</h1>
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
            <form action="" method="POST" class="space-y-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Product Name *</label>
                    <input type="text" name="name" id="name" required
                           value="<?php echo htmlspecialchars($formData['name']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="qrcode_number" class="block text-sm font-medium text-gray-700">Barcode Number *</label>
                    <input type="text" name="qrcode_number" id="qrcode_number" required
                           value="<?php echo htmlspecialchars($formData['qrcode_number']); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-sm text-gray-500">Enter a unique barcode identifier for this product</p>
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="3"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><?php echo htmlspecialchars($formData['description']); ?></textarea>
                </div>

                <?php if (isSuperAdmin()): ?>
                <div>
                    <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                    <select name="company_id" id="company_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Company</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>" 
                                <?php echo $company['id'] == $formData['company_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($company['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Product</button>
                </div>
            </form>
        </div>
    </div>
    </div>
</body>
</html>