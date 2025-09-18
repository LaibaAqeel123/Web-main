<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin can access this page
if (!isSuperAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$company = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    $query = "SELECT * FROM Companies WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $company = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$company) {
        header('Location: index.php');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = cleanInput($_POST['id']);
    $name = cleanInput($_POST['name']);
    $phone = cleanInput($_POST['phone']);
    $email = cleanInput($_POST['email']);
    $address = cleanInput($_POST['address']);
    $total_riders_allowed = cleanInput($_POST['total_riders_allowed']);
    $default_requires_image_proof = isset($_POST['default_requires_image_proof']) ? 1 : 0;
    $default_requires_signature_proof = isset($_POST['default_requires_signature_proof']) ? 1 : 0;

    if (empty($name)) {
        $error = 'Company name is required';
    } else {
        $query = "UPDATE Companies SET 
                  name = ?, 
                  phone = ?, 
                  email = ?, 
                  address = ?, 
                  total_riders_allowed = ?, 
                  default_requires_image_proof = ?, 
                  default_requires_signature_proof = ?
                  WHERE id = ?";

        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssssiii", $name, $phone, $email, $address, $total_riders_allowed, $default_requires_image_proof, $default_requires_signature_proof, $id);

        if (mysqli_stmt_execute($stmt)) {
            $success = 'Company updated successfully';
            // Refresh company data
            $company['name'] = $name;
            $company['phone'] = $phone;
            $company['email'] = $email;
            $company['address'] = $address;
            $company['total_riders_allowed'] = $total_riders_allowed;
            $company['default_requires_image_proof'] = $default_requires_image_proof;
            $company['default_requires_signature_proof'] = $default_requires_signature_proof;
        } else {
            $error = 'Error updating company: ' . mysqli_error($conn);
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
    <title>Edit Company - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Edit Company</h1>
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
                        <input type="hidden" name="id" value="<?php echo $company['id']; ?>">

                        <div class="mb-4">
                            <label for="name" class="block text-sm font-medium text-gray-700">Company Name</label>
                            <input type="text" name="name" id="name" required
                                value="<?php echo htmlspecialchars($company['name']); ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div class="mb-4">
                            <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                            <input type="text" name="phone" id="phone"
                                value="<?php echo htmlspecialchars($company['phone']); ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div class="mb-4">
                            <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                            <input type="email" name="email" id="email"
                                value="<?php echo htmlspecialchars($company['email']); ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div class="mb-4">
                            <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                            <textarea name="address" id="address" rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"><?php echo htmlspecialchars($company['address']); ?></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="total_riders_allowed" class="block text-sm font-medium text-gray-700">Total Riders Allowed</label>
                            <input type="number" name="total_riders_allowed" id="total_riders_allowed"
                                value="<?php echo htmlspecialchars($company['total_riders_allowed']); ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        </div>

                        <div class="md:col-span-2 border-t pt-6 mt-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Default Order Proof Requirements</h3>
                            <div class="space-y-4">
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="default_requires_image_proof" name="default_requires_image_proof" type="checkbox" value="1"
                                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                               <?php echo $company['default_requires_image_proof'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="default_requires_image_proof" class="font-medium text-gray-700">Require Image Proof</label>
                                        <p class="text-gray-500">Require riders to upload an image as proof of delivery by default.</p>
                                    </div>
                                </div>
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="default_requires_signature_proof" name="default_requires_signature_proof" type="checkbox" value="1"
                                               class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded"
                                               <?php echo $company['default_requires_signature_proof'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="default_requires_signature_proof" class="font-medium text-gray-700">Require Signature Proof</label>
                                        <p class="text-gray-500">Require riders to capture a signature as proof of delivery by default.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Company</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
</body>

</html>