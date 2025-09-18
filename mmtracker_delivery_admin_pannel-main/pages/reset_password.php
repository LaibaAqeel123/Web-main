<?php
require_once '../includes/config.php';

if (!isset($_SESSION['reset_email'])) {
    header('Location: ../index.php');
    exit();
}

$error = '';
$success = '';
$logo_path = SITE_URL . "assets/images/logo_black.png";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['code']) && isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
        $code = $_POST['code'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        $email = $_SESSION['reset_email'];

        if ($new_password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            try {
                mysqli_begin_transaction($conn);

                // Get user and token
                $check_query = "SELECT u.id, prt.token
                              FROM Users u
                              JOIN PasswordResetTokens prt ON u.id = prt.user_id
                              WHERE u.email = ? 
                              AND u.user_type IN ('Admin', 'Super Admin')
                              AND u.is_active = 1
                              AND prt.is_used = 0
                              AND prt.expires_at > NOW()
                              ORDER BY prt.created_at DESC
                              LIMIT 1";

                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "s", $email);
                mysqli_stmt_execute($check_stmt);
                $result = mysqli_stmt_get_result($check_stmt);

                if (mysqli_num_rows($result) === 0) {
                    throw new Exception('Invalid or expired reset code');
                }

                $reset_data = mysqli_fetch_assoc($result);

                if (!password_verify($code, $reset_data['token'])) {
                    throw new Exception('Invalid reset code');
                }

                // Update password
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password = "UPDATE Users SET password = ? WHERE id = ?";
                $password_stmt = mysqli_prepare($conn, $update_password);
                mysqli_stmt_bind_param($password_stmt, "si", $new_password_hash, $reset_data['id']);
                mysqli_stmt_execute($password_stmt);

                // Mark token as used
                $update_token = "UPDATE PasswordResetTokens SET is_used = 1 WHERE user_id = ?";
                $token_stmt = mysqli_prepare($conn, $update_token);
                mysqli_stmt_bind_param($token_stmt, "i", $reset_data['id']);
                mysqli_stmt_execute($token_stmt);

                mysqli_commit($conn);
                unset($_SESSION['reset_email']);

                $success = 'Password reset successful. You can now login with your new password.';
                header("refresh:2;url=../index.php");
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
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
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-lg shadow-lg">
            <div class="flex flex-col items-center">
                <img class="h-8 w-auto" src="<?php echo $logo_path; ?>" alt="Logo">
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    Reset Password
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
            <?php else: ?>
            <form class="mt-8 space-y-6" method="POST">
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700">
                        Reset Code
                    </label>
                    <input type="text" name="code" id="code" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                           placeholder="Enter reset code">
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700">
                        New Password
                    </label>
                    <input type="password" name="new_password" id="new_password" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                           placeholder="Enter new password">
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">
                        Confirm Password
                    </label>
                    <input type="password" name="confirm_password" id="confirm_password" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                           placeholder="Confirm new password">
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Reset Password
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <div class="text-sm text-center">
                <a href="../index.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                    Back to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html> 