<?php
require_once '../../includes/config.php';
include('../../server/log_helper.php');
function logException($exceptionMessage) {
    $logFile = __DIR__ . '/rider_exception_log.log';  // Logs stored in the same folder
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Exception: $exceptionMessage" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

requireLogin();

if (isSuperAdmin() || isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$rider = null;
$formData = [];

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    $company_condition = !isSuperAdmin() ? "AND rc.company_id = " . $_SESSION['company_id'] : "";

    $query = "SELECT u.*, rc.company_id 
              FROM Users u 
              LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
              WHERE u.id = ? AND u.user_type = 'Rider' $company_condition";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rider = mysqli_fetch_assoc($result);

    if (!$rider) {
        header('Location: index.php');
        exit();
    }

    $formData = $rider;
}

$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = array_merge($rider, [
        'id' => cleanInput($_POST['id']),
        'name' => cleanInput($_POST['name']),
        'email' => cleanInput($_POST['email']),
        'phone' => cleanInput($_POST['phone']),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'company_id' => isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id']
    ]);

    $id = $formData['id'];
    $name = $formData['name'];
    $email = $formData['email'];
    $phone = $formData['phone'];
    $is_active = $formData['is_active'];
    $company_id = $formData['company_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name) || empty($email)) {
        $error = 'Please fill in all required fields';
    } elseif (!empty($password) && $password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        $check_query = "SELECT id FROM Users WHERE email = ? AND id != ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "si", $email, $id);
        mysqli_stmt_execute($stmt);
        $check_result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $error = 'Email already exists for another user';
        } else {
            if (isSuperAdmin() && $company_id != $rider['company_id']) {
                $limit_query = "SELECT c.total_riders_allowed,
                              (SELECT COUNT(*) FROM RiderCompanies WHERE company_id = c.id) as current_riders
                              FROM Companies c WHERE c.id = ?";
                $stmt = mysqli_prepare($conn, $limit_query);
                mysqli_stmt_bind_param($stmt, "i", $company_id);
                mysqli_stmt_execute($stmt);
                $limit_result = mysqli_stmt_get_result($stmt);
                $company_data = mysqli_fetch_assoc($limit_result);

                if (
                    $company_data['total_riders_allowed'] > 0 &&
                    $company_data['current_riders'] >= $company_data['total_riders_allowed']
                ) {
                    $error = 'Target company has reached its maximum rider limit';
                }
            }

            if (!$error) {
                mysqli_begin_transaction($conn);
                try {
                   
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $query = "UPDATE Users SET 
                                 name = ?, 
                                 email = ?,
                                 phone = ?,
                                 password = ?,
                                 is_active = ?
                                 WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param(
                            $stmt,
                            "ssssii",
                            $name,
                            $email,
                            $phone,
                            $hashed_password,
                            $is_active,
                            $id
                        );
                    } else {
                        $query = "UPDATE Users SET 
                                 name = ?, 
                                 email = ?,
                                 phone = ?,
                                 is_active = ?
                                 WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssii",
                            $name,
                            $email,
                            $phone,
                            $is_active,
                            $id
                        );
                    }
                    mysqli_stmt_execute($stmt);

                    if (isSuperAdmin() && $company_id != $rider['company_id']) {
                        $delete_company = "DELETE FROM RiderCompanies WHERE rider_id = ?";
                        $stmt = mysqli_prepare($conn, $delete_company);
                        mysqli_stmt_bind_param($stmt, "i", $id);
                        mysqli_stmt_execute($stmt);

                        $add_company = "INSERT INTO RiderCompanies (rider_id, company_id) VALUES (?, ?)";
                        $stmt = mysqli_prepare($conn, $add_company);
                        mysqli_stmt_bind_param($stmt, "ii", $id, $company_id);
                        mysqli_stmt_execute($stmt);
                    }

                    mysqli_commit($conn);
                    $success = 'Rider updated successfully';

                    // Logging update action
                    $log_message = "Rider updated: ID={$id}, Name={$name}, Email={$email}, Phone={$phone}, Active={$is_active}, CompanyID={$company_id}";
                    writeLog('rider.log', $log_message); // ⬅️ Corrected logging call

                    $rider['name'] = $name;
                    $rider['email'] = $email;
                    $rider['phone'] = $phone;
                    $rider['is_active'] = $is_active;
                    $rider['company_id'] = $company_id;
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = 'Error updating rider: ' . $e->getMessage();
                     logException($e->getMessage()); 
                }
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
    <title>Edit Rider - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="text-2xl font-bold text-gray-900">Edit Rider</h1>
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

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Edit Form -->
                    <div class="lg:col-span-2">
                        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
                            <form action="" method="POST">
                                <input type="hidden" name="id" value="<?php echo $formData['id']; ?>">

                                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                        <input type="text" name="name" id="name" required
                                            value="<?php echo htmlspecialchars($formData['name']); ?>"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>

                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address *</label>
                                        <input type="email" name="email" id="email" required
                                            value="<?php echo htmlspecialchars($formData['email']); ?>"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>

                                    <div>
                                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                        <input type="text" name="phone" id="phone"
                                            value="<?php echo htmlspecialchars($formData['phone']); ?>"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>

                                    <?php if (isSuperAdmin()): ?>
                                        <div>
                                            <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                                            <select name="company_id" id="company_id" required
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <?php foreach ($companies as $company): ?>
                                                    <option value="<?php echo $company['id']; ?>"
                                                        <?php echo $company['id'] == $formData['company_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($company['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-span-2">
                                        <div class="flex items-center">
                                            <input type="checkbox" name="is_active" id="is_active"
                                                class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                                            <label for="is_active" class="ml-2 block text-sm text-gray-900">
                                                Active Account
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6">
                                    <h3 class="text-lg font-medium text-gray-900">Change Password</h3>
                                    <p class="mt-1 text-sm text-gray-500">Leave blank to keep current password</p>

                                    <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                                        <div>
                                            <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                                            <input type="password" name="password" id="password"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>

                                        <div>
                                            <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                            <input type="password" name="confirm_password" id="confirm_password"
                                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 flex justify-end space-x-3">
                                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Rider</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Current Status -->
                    <div class="lg:col-span-1">
                        <div class="bg-white shadow rounded-lg divide-y divide-gray-200">
                            <div class="p-6">
                                <h2 class="text-lg font-medium text-gray-900">Current Status</h2>
                                <dl class="mt-4 space-y-4">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Account Status</dt>
                                        <dd class="mt-1">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $rider['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $rider['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </dd>
                                    </div>

                                    <?php if ($current_manifest): ?>
                                        <div>
                                            <dt class="text-sm font-medium text-gray-500">Current Manifest</dt>
                                            <dd class="mt-1">
                                                <div class="text-sm text-gray-900">
                                                    <a href="../manifests/view.php?id=<?php echo $current_manifest['id']; ?>"
                                                        class="text-indigo-600 hover:text-indigo-900">
                                                        Manifest #<?php echo $current_manifest['id']; ?>
                                                    </a>
                                                </div>
                                                <div class="mt-2">
                                                    <div class="text-sm text-gray-500">
                                                        Progress: <?php echo $current_manifest['delivered_orders']; ?>/<?php echo $current_manifest['total_orders']; ?> orders
                                                    </div>
                                                    <div class="mt-1 relative pt-1">
                                                        <div class="overflow-hidden h-2 text-xs flex rounded bg-indigo-200">
                                                            <div style="width:<?php echo ($current_manifest['delivered_orders'] / $current_manifest['total_orders']) * 100; ?>%"
                                                                class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-indigo-500">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </dd>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Last Login</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            <?php echo date('M d, Y H:i', strtotime($rider['updated_at'])); ?>
                                        </dd>
                                    </div>

                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Member Since</dt>
                                        <dd class="mt-1 text-sm text-gray-900">
                                            <?php echo date('M d, Y', strtotime($rider['created_at'])); ?>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="mt-6 bg-white shadow rounded-lg divide-y divide-gray-200">
                            <div class="p-6">
                                <h2 class="text-lg font-medium text-gray-900">Performance Stats</h2>
                                <?php
                                // Get rider stats
                                $stats_query = "SELECT 
                            COUNT(DISTINCT m.id) as total_manifests,
                            COUNT(DISTINCT CASE WHEN o.status = 'delivered' THEN o.id END) as delivered_orders,
                            COUNT(DISTINCT CASE WHEN o.status = 'failed' THEN o.id END) as failed_orders
                            FROM Users u
                            LEFT JOIN Manifests m ON u.id = m.rider_id
                            LEFT JOIN ManifestOrders mo ON m.id = mo.manifest_id
                            LEFT JOIN Orders o ON mo.order_id = o.id
                            WHERE u.id = ?";
                                $stmt = mysqli_prepare($conn, $stats_query);
                                mysqli_stmt_bind_param($stmt, "i", $rider['id']);
                                mysqli_stmt_execute($stmt);
                                $stats_result = mysqli_stmt_get_result($stmt);
                                $stats = mysqli_fetch_assoc($stats_result);
                                ?>
                                <dl class="mt-4 space-y-4">
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Total Manifests</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-gray-900"><?php echo $stats['total_manifests']; ?></dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Delivered Orders</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-green-600"><?php echo $stats['delivered_orders']; ?></dd>
                                    </div>
                                    <div>
                                        <dt class="text-sm font-medium text-gray-500">Failed Orders</dt>
                                        <dd class="mt-1 text-2xl font-semibold text-red-600"><?php echo $stats['failed_orders']; ?></dd>
                                    </div>
                                    <?php if ($stats['delivered_orders'] > 0): ?>
                                        <div>
                                            <dt class="text-sm font-medium text-gray-500">Success Rate</dt>
                                            <dd class="mt-1 text-2xl font-semibold text-indigo-600">
                                                <?php
                                                $total_completed = $stats['delivered_orders'] + $stats['failed_orders'];
                                                echo $total_completed > 0
                                                    ? round(($stats['delivered_orders'] / $total_completed) * 100, 1) . '%'
                                                    : 'N/A';
                                                ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</body>

</html>