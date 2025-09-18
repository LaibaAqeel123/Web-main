<?php 
require_once '../../includes/config.php';
include('../../server/log_helper.php');
function logException($exceptionMessage) {
    $logFile = __DIR__ . '/rider_exception_log.log';  //  Save in the same folder
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Exception: $exceptionMessage" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

requireLogin();

if (!isSuperAdmin() && !isAdmin()) {
    writeLog("rider.log", "Unauthorized access attempt by user ID: {$_SESSION['user_id']}");
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$existing_user = null;

function validateUKPhoneNumber($phone) {
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!str_starts_with($phone, '+44')) {
        return false;
    }
    $number = substr($phone, 3);
    return strlen($number) === 10;
}

$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

if (isset($_POST['search_rider'])) {
    $search_term = cleanInput($_POST['search_term']);
    writeLog("rider.log", "Search initiated for rider: $search_term");
    $search_query = "SELECT * FROM Users WHERE user_type = 'Rider' AND (username = ? OR email = ?)";
    $stmt = mysqli_prepare($conn, $search_query);
    mysqli_stmt_bind_param($stmt, "ss", $search_term, $search_term);
    mysqli_stmt_execute($stmt);
    $existing_user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($existing_user) {
        writeLog("rider.log", "Rider found: ID {$existing_user['id']} | Username: {$existing_user['username']}");
    } else {
        writeLog("rider.log", "No rider found for search: $search_term");
    }
}

$formData = [
    'name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'company_id' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['search_rider'])) {
    $formData = [
        'name' => cleanInput($_POST['name'] ?? ''),
        'username' => cleanInput($_POST['username'] ?? ''),
        'email' => cleanInput($_POST['email'] ?? ''),
        'phone' => cleanInput($_POST['phone'] ?? ''),
        'company_id' => isSuperAdmin() ? cleanInput($_POST['company_id'] ?? '') : $_SESSION['company_id']
    ];

    if (empty($formData['phone'])) {
        $error = 'Phone number is required';
        writeLog("rider.log", "Phone number missing for new rider form submission.");
    } else {
        if (!str_starts_with($formData['phone'], '+44')) {
            $formData['phone'] = '+44' . ltrim($formData['phone'], '0');
        }
        if (!validateUKPhoneNumber($formData['phone'])) {
            $error = 'Please enter a valid UK phone number';
            writeLog("rider.log", "Invalid UK phone number provided: {$formData['phone']}");
        }
    }

    if (!$error) {
        $check_limit_query = "SELECT c.total_riders_allowed,
            (SELECT COUNT(*) FROM RiderCompanies WHERE company_id = c.id) as current_riders
            FROM Companies c WHERE c.id = ?";
        $stmt = mysqli_prepare($conn, $check_limit_query);
        mysqli_stmt_bind_param($stmt, "i", $formData['company_id']);
        mysqli_stmt_execute($stmt);
        $limit_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($limit_result['current_riders'] >= $limit_result['total_riders_allowed']) {
            $error = 'Company has reached maximum rider limit (' . $limit_result['current_riders'] . '/' . $limit_result['total_riders_allowed'] . ')';
            writeLog("rider.log", "Rider limit exceeded for company ID: {$formData['company_id']}");
        } else {
            if (isset($_POST['existing_rider_id'])) {
                $rider_id = cleanInput($_POST['existing_rider_id']);
                $check_query = "SELECT id FROM RiderCompanies WHERE rider_id = ? AND company_id = ?";
                $stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($stmt, "ii", $rider_id, $formData['company_id']);
                mysqli_stmt_execute($stmt);
                $check_result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($check_result) > 0) {
                    $error = 'Rider is already assigned to this company';
                    writeLog("rider.log", "Attempted re-assignment of rider ID $rider_id to company ID {$formData['company_id']}");
                } else {
                    $query = "INSERT INTO RiderCompanies (rider_id, company_id, is_active) VALUES (?, ?, 1)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $rider_id, $formData['company_id']);

                    if (mysqli_stmt_execute($stmt)) {
                        $success = 'Rider assigned to company successfully';
                        writeLog("rider.log", "Successfully assigned rider ID $rider_id to company ID {$formData['company_id']}");
                        header("refresh:1;url=index.php");
                    } else {
                        $error = 'Error assigning rider to company';
                        writeLog("rider.log", "Failed to assign rider ID $rider_id to company: " . mysqli_error($conn));
                    }
                }
            } else {
                $name = $formData['name'];
                $username = $formData['username'];
                $email = $formData['email'];
                $phone = $formData['phone'];
                $password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];

                if (empty($name) || empty($username) || empty($email) || empty($password)) {
                    $error = 'Please fill in all required fields';
                    writeLog("rider.log", "Form incomplete: missing fields.");
                } else if ($password !== $confirm_password) {
                    $error = 'Passwords do not match';
                    writeLog("rider.log", "Password mismatch for new rider: $username");
                } else {
                    $check_query = "SELECT id FROM Users WHERE username = ? OR email = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ss", $username, $email);
                    mysqli_stmt_execute($stmt);
                    $check_result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($check_result) > 0) {
                        $error = 'Username or email already exists';
                        writeLog("rider.log", "Duplicate username/email attempt: $username / $email");
                    } else {
                        mysqli_begin_transaction($conn);
                        try {

                            $query = "INSERT INTO Users (name, username, email, phone, password, user_type, is_active) VALUES (?, ?, ?, ?, ?, 'Rider', 1)";
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "sssss", $name, $username, $email, $phone, $hashed_password);

                            if (mysqli_stmt_execute($stmt)) {
                                $rider_id = mysqli_insert_id($conn);
                                writeLog("rider.log", "New rider created: ID $rider_id | Username: $username");

                                $rider_company_query = "INSERT INTO RiderCompanies (rider_id, company_id, is_active) VALUES (?, ?, 1)";
                                $stmt = mysqli_prepare($conn, $rider_company_query);
                                mysqli_stmt_bind_param($stmt, "ii", $rider_id, $formData['company_id']);

                                if (!mysqli_stmt_execute($stmt)) {
                                    throw new Exception('Error assigning rider to company: ' . mysqli_error($conn));
                                }

                                mysqli_commit($conn);
                                $success = 'Rider created successfully';
                                writeLog("rider.log", "Rider assigned to company successfully: Rider ID $rider_id, Company ID {$formData['company_id']}");
                                header("refresh:1;url=index.php");
                            } else {
                                throw new Exception('Error creating rider: ' . mysqli_error($conn));
                            }
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                            $error = $e->getMessage();
                            writeLog("rider.log", "Transaction failed: $error");
                             logException($e->getMessage());
                        }
                    }
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
    <title>Add Rider - <?php echo SITE_NAME; ?></title>
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
            <h1 class="text-2xl font-bold text-gray-900">Add New Rider</h1>
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

        <!-- Search Existing Rider -->
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6 mb-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Search Existing Rider</h2>
            <form action="" method="POST" class="space-y-4">
                <div class="flex gap-4">
                    <input type="text" name="search_term" placeholder="Enter username or email" 
                           class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="submit" name="search_rider" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">
                        Search
                    </button>
                </div>
            </form>

            <?php if (isset($_POST['search_rider'])): ?>
                <?php if ($existing_user): ?>
                    <div class="mt-4 p-4 border rounded-md">
                        <h3 class="font-medium text-gray-900">Rider Found:</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Name: <?php echo htmlspecialchars($existing_user['name']); ?><br>
                            Username: <?php echo htmlspecialchars($existing_user['username']); ?>
                        </p>
                        <form action="" method="POST" class="mt-4">
                            <input type="hidden" name="existing_rider_id" value="<?php echo $existing_user['id']; ?>">
                            <?php if (isSuperAdmin()): ?>
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700">Select Company</label>
                                    <select name="company_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">Select Company</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                                Add to Company
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="mt-4 text-sm text-gray-600">No rider found with the provided username or email.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Create New Rider Form -->
        <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Create New Rider</h2>
            <form action="" method="POST" class="space-y-6">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Personal Information -->
                    <div class="space-y-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                            <input type="text" name="name" id="name" required
                                   value="<?php echo htmlspecialchars($formData['name']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
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
                    </div>

                    <!-- Account Information -->
                    <div class="space-y-6">
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700">Username *</label>
                            <input type="text" name="username" id="username" required
                                   value="<?php echo htmlspecialchars($formData['username']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700">Email Address *</label>
                            <input type="email" name="email" id="email" required
                                   value="<?php echo htmlspecialchars($formData['email']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700">Password *</label>
                            <input type="password" name="password" id="password" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password *</label>
                            <input type="password" name="confirm_password" id="confirm_password" required
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Rider</button>
                </div>
            </form>
        </div>
    </div>
    </div>
</body>
</html>