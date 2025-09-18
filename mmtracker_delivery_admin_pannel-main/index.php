<?php
require_once 'includes/config.php';
include __DIR__ . '/server/log_helper.php';



// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: pages/dashboard.php');
    exit();
}

$error = '';
$logo_path = SITE_URL . "assets/images/logo_black.png";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = cleanInput($_POST['username']); // Can be either username or email
    $password = $_POST['password'];

    $ip = $_SERVER['REMOTE_ADDR'];
    $query = "SELECT id, name, username, password, user_type, company_id FROM Users 
              WHERE (username = ? OR email = ?) AND is_active = 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ss", $login, $login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Check if user is a rider
        if ($user['user_type'] === 'Rider') {
            $error = 'Not found';
             writeLog('auth.log', "Blocked login attempt for Rider '{$login}' from IP $ip.");
        } 
        // Verify password for non-rider users
        else if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['company_id'] = $user['company_id'];
            writeLog('auth.log', "User #{$user['id']} ({$user['username']}) logged in successfully from IP $ip.");
            header('Location: pages/dashboard.php');
            exit();
        } else {
            $error = 'Invalid password';
            writeLog('auth.log', "Failed login for '{$login}' - wrong password from IP $ip.");
        }
    } else {
        $error = 'Invalid username/email or account is inactive';
        writeLog('auth.log', "Failed login attempt for '{$login}' - not found or inactive. IP: $ip");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-lg shadow-lg">
            <div class="flex flex-col items-center">
                <img class="h-8 w-auto" src="<?php echo $logo_path; ?>" alt="<?php echo SITE_NAME; ?> Logo">
                <p class="mt-4 text-center text-sm text-gray-600">
                    Sign in to your account
                </p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" method="POST">
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="username" class="sr-only">Username or Email</label>
                        <input id="username" name="username" type="text" required
                               class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-t-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                               placeholder="Username or Email">
                    </div>
                    <div>
                        <label for="password" class="sr-only">Password</label>
                        <input id="password" name="password" type="password" required
                               class="appearance-none rounded-none relative block w-full px-3 py-2 border border-gray-300 placeholder-gray-500 text-gray-900 rounded-b-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 focus:z-10 sm:text-sm"
                               placeholder="Password">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-sm">
                        <a href="pages/forgot_password.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                            Forgot your password?
                        </a>
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Sign in
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script src="./assets/_litespa/main.js" type="module" main="main"></script>
        
    </script>
</body>
</html>
