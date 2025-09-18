<?php
require_once '../includes/config.php';
$error = '';
$success = '';
$logo_path = SITE_URL . "assets/images/logo_black.png";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['email'])) {
        // Request reset code
        $email = cleanInput($_POST['email']);
        
        // Check if email exists and user is admin/super admin
        $query = "SELECT id, name, user_type FROM Users 
                 WHERE email = ? 
                 AND user_type IN ('Admin', 'Super Admin')
                 AND is_active = 1";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);
            
            // Generate reset code
            $reset_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $hashed_token = password_hash($reset_code, PASSWORD_DEFAULT);
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            mysqli_begin_transaction($conn);

            // Deactivate old tokens
            $deactivate = "UPDATE PasswordResetTokens 
                          SET is_used = 1 
                          WHERE user_id = ? AND is_used = 0";
            $deactivate_stmt = mysqli_prepare($conn, $deactivate);
            mysqli_stmt_bind_param($deactivate_stmt, "i", $user['id']);
            mysqli_stmt_execute($deactivate_stmt);

            // Store new token
            $store = "INSERT INTO PasswordResetTokens (user_id, token, expires_at) 
                     VALUES (?, ?, ?)";
            $store_stmt = mysqli_prepare($conn, $store);
            mysqli_stmt_bind_param($store_stmt, "iss", $user['id'], $hashed_token, $expires_at);
            mysqli_stmt_execute($store_stmt);

            // Send email
            $to = $email;
            $subject = "Password Reset Code - " . SITE_NAME;
            $message = "Hello " . $user['name'] . ",\n\n"
                    . "Your password reset code is: " . $reset_code . "\n"
                    . "This code will expire in 15 minutes.\n\n"
                    . "If you didn't request this, please ignore this email.";
            $headers = "From: noreply@" . $_SERVER['HTTP_HOST'];

            mail($to, $subject, $message, $headers);
            mysqli_commit($conn);

            $_SESSION['reset_email'] = $email;
            header('Location: reset_password.php');
            exit();
        } else {
            $error = 'No admin account found with this email';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-lg shadow-lg">
            <div class="flex flex-col items-center">
                <img class="h-8 w-auto" src="<?php echo $logo_path; ?>" alt="Logo">
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Forgot Password
                </h2>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?php echo $success; ?></span>
            </div>
            <?php endif; ?>

            <form class="mt-8 space-y-6" method="POST">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">
                        Email Address
                    </label>
                    <input type="email" name="email" id="email" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                           placeholder="Enter your email">
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Send Reset Code
                    </button>
                </div>

                <div class="text-sm text-center">
                    <a href="../index.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Login
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html> 