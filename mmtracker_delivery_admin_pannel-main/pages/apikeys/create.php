<?php
require_once '../../includes/config.php';
requireLogin();

$error = '';
$success = '';

// Get companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = cleanInput($_POST['description']);
    $company_id = isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id'];

    // Generate a secure random API key
    $api_key = bin2hex(random_bytes(32)); // 64 characters long

    $query = "INSERT INTO ApiKeys (company_id, api_key, description, created_by) 
              VALUES (?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "issi", $company_id, $api_key, $description, $_SESSION['user_id']);
    
    if (mysqli_stmt_execute($stmt)) {
        $success = 'API Key created successfully';
        $_SESSION['new_api_key'] = $api_key; // Store the new key to display it
        header("Location: index.php?created=1");
        exit();
    } else {
        $error = 'Error creating API key: ' . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create API Key - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-2xl font-bold mb-6">Create New API Key</h1>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6">
                <form method="POST">
                    <?php if (isSuperAdmin()): ?>
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="company_id">
                                Company
                            </label>
                            <select name="company_id" id="company_id" required
                                    class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="">Select Company</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>">
                                        <?php echo htmlspecialchars($company['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">
                            Description
                        </label>
                        <textarea name="description" id="description" rows="3" required
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
                                  placeholder="Enter a description for this API key"></textarea>
                    </div>

                    <div class="flex justify-end">
                        <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded mr-2 hover:bg-gray-600">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            Generate API Key
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html> 