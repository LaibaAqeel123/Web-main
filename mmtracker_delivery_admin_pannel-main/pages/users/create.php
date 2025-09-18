<?php
require_once '../../includes/config.php';
include('../../server/log_helper.php');

requireLogin();

// Only super admin and admin can create users
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';

// Fetch companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

// Initialize form data
$formData = [
    'name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'user_type' => '',
    'company_id' => ''
];

function validateUKPhoneNumber($phone) {
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!str_starts_with($phone, '+44')) {
        return false;
    }
    $number = substr($phone, 3);
    return strlen($number) === 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'name' => cleanInput($_POST['name']),
        'username' => cleanInput($_POST['username']),
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

    if ($formData['user_type'] === 'Super Admin') {
        $formData['company_id'] = null;
    }

    if (!$error) {
        if (empty($formData['name']) || empty($formData['username']) || empty($formData['email']) || empty($_POST['password'])) {
            $error = 'Please fill in all required fields';
        } elseif ($_POST['password'] !== $_POST['confirm_password']) {
            $error = 'Passwords do not match';
        } else {
            $check_query = "SELECT id, username, email FROM Users WHERE username = ? OR email = ?";
            $stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($stmt, "ss", $formData['username'], $formData['email']);
            mysqli_stmt_execute($stmt);
            $check_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($check_result) > 0) {
                $existing_user = mysqli_fetch_assoc($check_result);
                if ($existing_user['username'] === $formData['username']) {
                    $error = 'Username already exists';
                } else {
                    $error = 'Email address already exists';
                }
            } else {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);

                $query = "INSERT INTO Users (name, username, email, phone, password, user_type, company_id, is_active) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param(
                    $stmt,
                    "ssssssi",
                    $formData['name'],
                    $formData['username'],
                    $formData['email'],
                    $formData['phone'],
                    $hashed_password,
                    $formData['user_type'],
                    $formData['company_id']
                );

                if (mysqli_stmt_execute($stmt)) {
                    $success = 'User created successfully';

                    //  Log admin creation properly to admin.log
                    if ($formData['user_type'] === 'Admin') {
                        $logMessage = "Admin created: {$formData['name']} (username: {$formData['username']}, email: {$formData['email']})";
                        writeLog('admin.log', $logMessage); // Fixed argument order
                    }

                    header("refresh:1;url=index.php");
                } else {
                    $error = 'Error creating user: ' . mysqli_error($conn);
                }

                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Create New <?php echo isAdmin() ? "Admin" : "User"; ?></h1>
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
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" name="name" id="name" required
                                    value="<?php echo htmlspecialchars($formData['name']); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="username" class="block text-sm font-medium text-gray-700">Username *</label>
                                <input type="text" name="username" id="username" required
                                    value="<?php echo htmlspecialchars($formData['username']); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                                <input type="email" name="email" id="email" required
                                    value="<?php echo htmlspecialchars($formData['email']); ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <div class="relative mt-1">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 pointer-events-none">+44</span>
                                    <input type="tel" name="phone" id="phone" required
                                        placeholder="Enter number without leading 0"
                                        value="<?php echo !empty($formData['phone']) ? substr(htmlspecialchars($formData['phone']), 3) : ''; ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 pl-12 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm bg-white">
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Enter the number without the country code (e.g., 7911123456)</p>
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">Password *</label>
                                <input type="password" name="password" id="password" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password *</label>
                                <input type="password" name="confirm_password" id="confirm_password" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            </div>

                            <div>
                                <label for="user_type" class="block text-sm font-medium text-gray-700">User Type *</label>
                                <select name="user_type" id="user_type" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                    <?php if (isSuperAdmin()): ?>
                                        <option value="Super Admin" <?php echo $formData['user_type'] === 'Super Admin' ? 'selected' : ''; ?>>Super Admin</option>
                                        <option value="Admin" <?php echo $formData['user_type'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                        <option value="Rider" <?php echo $formData['user_type'] === 'Rider' ? 'selected' : ''; ?>>Rider</option>
                                    <?php endif; ?>

                                    <?php if (isAdmin()): ?>
                                        <option value="Admin" <?php echo $formData['user_type'] === 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <?php if (isSuperAdmin()): ?>
                                <div>
                                    <label for="company_id" class="block text-sm font-medium text-gray-700">Company (Not required for Super Admin)</label>
                                    <select name="company_id" id="company_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">None</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>" 
                                                <?php echo $company['id'] == $formData['company_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($company['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="mt-1 text-sm text-gray-500">Company is required for Admin and Rider users only</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create <?php echo isAdmin() ? "Admin" : "User"; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const userTypeSelect = document.getElementById('user_type');
            const companyIdDiv = document.getElementById('company_id').closest('div');

            function toggleCompanyField() {
                if (userTypeSelect.value === 'Super Admin') {
                    companyIdDiv.style.display = 'none';
                    document.getElementById('company_id').value = '';
                } else {
                    companyIdDiv.style.display = 'block';
                }
            }

            userTypeSelect.addEventListener('change', toggleCompanyField);
            toggleCompanyField(); // Run on initial load
        });
    </script>
</body>

</html>