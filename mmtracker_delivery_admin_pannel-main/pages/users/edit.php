<?php
require_once '../../includes/config.php';
  include_once '../../server/log_helper.php';
requireLogin();

if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$user = null;

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    $company_condition = !isSuperAdmin() ? "AND company_id = " . $_SESSION['company_id'] : "";
    $query = "SELECT * FROM Users WHERE id = ? $company_condition";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if (!$user) {
        header('Location: index.php');
        exit();
    }
}

$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

function validateUKPhoneNumber($phone) {
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!str_starts_with($phone, '+44')) return false;
    $number = substr($phone, 3);
    return strlen($number) === 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'id' => cleanInput($_POST['id']),
        'name' => cleanInput($_POST['name']),
        'email' => cleanInput($_POST['email']),
        'phone' => cleanInput($_POST['phone']),
        'user_type' => cleanInput($_POST['user_type']),
        'company_id' => isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id']
    ];

    if (empty($formData['phone'])) {
        $error = 'Phone number is required';
    } else {
        if (!str_starts_with($formData['phone'], '+44')) {
            $formData['phone'] = '+44' . ltrim($formData['phone'], '0');
        }
        if (!validateUKPhoneNumber($formData['phone'])) {
            $error = 'Please enter a valid UK phone number';
        }
    }

    if (empty($formData['name']) || empty($formData['email'])) {
        $error = 'Please fill in all required fields';
    } elseif (!empty($_POST['password']) && $_POST['password'] !== $_POST['confirm_password']) {
        $error = 'Passwords do not match';
    } else {
        $check_query = "SELECT id FROM Users WHERE email = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "si", $formData['email'], $formData['id']);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $error = 'Email address already exists for another user';
        } else {
            if ($formData['user_type'] === 'Admin' && !empty($_POST['admin_pin'])) {
                $admin_pin = cleanInput($_POST['admin_pin']);
                if (!preg_match('/^[0-9]{6}$/', $admin_pin)) {
                    $error = 'Admin PIN must be exactly 6 digits';
                } else {
                    $hashed_pin = password_hash($admin_pin, PASSWORD_DEFAULT);
                }
            }

            if (!$error) {
                $updates = [];
                $params = [];
                $types = "";

                $updates[] = "name = ?";
                $updates[] = "email = ?";
                $updates[] = "phone = ?";
                $updates[] = "user_type = ?";
                $updates[] = "company_id = ?";
                $params[] = $formData['name'];
                $params[] = $formData['email'];
                $params[] = $formData['phone'];
                $params[] = $formData['user_type'];
                $params[] = $formData['company_id'];
                $types .= "ssssi";

                if (!empty($_POST['password'])) {
                    $updates[] = "password = ?";
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $types .= "s";
                }

                if ($formData['user_type'] === 'Admin' && !empty($_POST['admin_pin'])) {
                    $updates[] = "admin_pin = ?";
                    $params[] = $hashed_pin;
                    $types .= "s";
                }

                $params[] = $formData['id'];
                $types .= "i";

                $query = "UPDATE Users SET " . implode(", ", $updates) . " WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, $types, ...$params);

                if (mysqli_stmt_execute($stmt)) {
                    $success = 'User updated successfully';

                    //  Log the admin update to admin.log
                  
                    $editor = $_SESSION['user_name'];
                    $editedUserId = $formData['id'];
                    $editedUserType = $formData['user_type'];
                    $logMsg = "$editor updated $editedUserType (ID: $editedUserId)";
                    writeLog('admin.log', $logMsg);

                    $query = "SELECT * FROM Users WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "i", $formData['id']);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $user = mysqli_fetch_assoc($result);
                } else {
                    $error = 'Error updating user: ' . mysqli_error($conn);
                }

                mysqli_stmt_close($stmt);
            }
        }
    }
} else {
    $formData = $user;
}
?>



<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Edit User</h1>
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
                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" name="name" id="name" required
                                    value="<?php echo htmlspecialchars($user['name']); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                                <input type="email" name="email" id="email" required
                                    value="<?php echo htmlspecialchars($user['email']); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <div class="relative mt-1">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 pointer-events-none">+44</span>
                                    <input type="tel" name="phone" id="phone" required
                                        placeholder="Enter number without leading 0"
                                        value="<?php echo !empty($user['phone']) ? substr(htmlspecialchars($user['phone']), 3) : ''; ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 pl-12 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-white">
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Enter the number without the country code (e.g., 7911123456)</p>
                            </div>

                            <div>
                                <label for="user_type" class="block text-sm font-medium text-gray-700">User Type *</label>
                                <select name="user_type" id="user_type" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    <?php if (isSuperAdmin()): ?>
                                        <option value="Super Admin" <?php echo $user['user_type'] === 'Super Admin' ? 'selected' : ''; ?>>Super Admin</option>
                                    <?php endif; ?>
                                    <option value="Admin" <?php echo $user['user_type'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="Rider" <?php echo $user['user_type'] === 'Rider' ? 'selected' : ''; ?>>Rider</option>
                                </select>
                            </div>

                            <?php if (isSuperAdmin()): ?>
                                <div>
                                    <label for="company_id" class="block text-sm font-medium text-gray-700">Company</label>
                                    <select name="company_id" id="company_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">None</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>"
                                                <?php echo $company['id'] == $user['company_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($company['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($user['user_type'] === 'Admin'): ?>
                                <div class="col-span-2 bg-yellow-50 p-6 rounded-lg border-2 border-yellow-200 mb-4">
                                    <h3 class="text-lg font-medium leading-6 text-yellow-900 mb-4">Admin PIN Management</h3>
                                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                        <div>
                                            <label for="admin_pin" class="block text-sm font-medium text-yellow-900">
                                                Update Admin PIN (6 digits)
                                                <?php if (empty($user['admin_pin'])): ?>
                                                    <span class="text-red-600">*Required</span>
                                                <?php endif; ?>
                                            </label>
                                            <input type="password" 
                                                   name="admin_pin" 
                                                   id="admin_pin" 
                                                   maxlength="6" 
                                                   pattern="[0-9]{6}"
                                                   placeholder="<?php echo empty($user['admin_pin']) ? 'Set 6-digit PIN' : 'Enter new PIN to change'; ?>"
                                                   <?php echo empty($user['admin_pin']) ? 'required' : ''; ?>
                                                   class="mt-1 block w-full rounded-md border-yellow-300 shadow-sm focus:border-yellow-500 focus:ring-yellow-500 sm:text-sm">
                                            <p class="mt-1 text-sm text-yellow-700">
                                                <?php if (empty($user['admin_pin'])): ?>
                                                    PIN is not set. Please set a 6-digit PIN.
                                                <?php else: ?>
                                                    Leave empty to keep current PIN. Enter new 6-digit PIN to change.
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center">
                                            <div class="text-sm text-yellow-800">
                                                <p class="font-medium">PIN Status:</p>
                                                <?php if (empty($user['admin_pin'])): ?>
                                                    <p class="text-red-600">Not Set</p>
                                                <?php else: ?>
                                                    <p class="text-green-600">✓ PIN is set</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="col-span-2">
                                <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Change Password</h3>
                                <p class="text-sm text-gray-500 mb-4">Leave password fields empty to keep current password</p>

                                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <div>
                                        <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                                        <input type="password" name="password" id="password"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    </div>

                                    <div>
                                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                        <input type="password" name="confirm_password" id="confirm_password"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const userTypeSelect = document.getElementById('user_type');
                const companyIdDiv = document.getElementById('company_id')?.closest('div');
                const adminPinSection = document.querySelector('.admin-pin-section');
                
                function toggleFields() {
                    if (userTypeSelect.value === 'Admin') {
                        if (adminPinSection) adminPinSection.style.display = 'block';
                        if (companyIdDiv) companyIdDiv.style.display = 'block';
                    } else {
                        if (adminPinSection) adminPinSection.style.display = 'none';
                        if (companyIdDiv) {
                            companyIdDiv.style.display = userTypeSelect.value === 'Super Admin' ? 'none' : 'block';
                        }
                    }
                }

                if (userTypeSelect) {
                    userTypeSelect.addEventListener('change', toggleFields);
                    toggleFields(); // Run on initial load
                }
            });
        </script>
</body>

</html>