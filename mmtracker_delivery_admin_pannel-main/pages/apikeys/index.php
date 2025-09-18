<?php
require_once '../../includes/config.php';
requireLogin();

// Get API keys for the company
$company_condition = !isSuperAdmin() ? "AND ak.company_id = " . $_SESSION['company_id'] : "";

$query = "SELECT ak.*, c.name as company_name, u.name as created_by_name 
          FROM ApiKeys ak
          LEFT JOIN Companies c ON ak.company_id = c.id
          LEFT JOIN Users u ON ak.created_by = u.id
          WHERE ak.is_active = 1 $company_condition
          ORDER BY ak.created_at DESC";

$result = mysqli_query($conn, $query);

// Get companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">API Keys</h1>
            <a href="create.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                Create New API Key
            </a>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">API Key</th>
                        <?php if (isSuperAdmin()): ?>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                        <?php endif; ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Used</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php while ($key = mysqli_fetch_assoc($result)): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <span class="font-mono text-sm"><?php echo substr($key['api_key'], 0, 16); ?>...</span>
                                    <button onclick="copyApiKey('<?php echo $key['api_key']; ?>')" 
                                            class="ml-2 text-gray-400 hover:text-gray-600">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                                  d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                            <?php if (isSuperAdmin()): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($key['company_name']); ?>
                                </td>
                            <?php endif; ?>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <?php echo htmlspecialchars($key['description']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($key['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $key['last_used_at'] ? date('M d, Y H:i', strtotime($key['last_used_at'])) : 'Never'; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button onclick="deleteApiKey(<?php echo $key['id']; ?>)" 
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function copyApiKey(key) {
            navigator.clipboard.writeText(key).then(() => {
                alert('API Key copied to clipboard!');
            });
        }

        function deleteApiKey(id) {
            if (confirm('Are you sure you want to delete this API key? This action cannot be undone.')) {
                window.location.href = 'delete.php?id=' + id;
            }
        }
    </script>
</body>
</html> 