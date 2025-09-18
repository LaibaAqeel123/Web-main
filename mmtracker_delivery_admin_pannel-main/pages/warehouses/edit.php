<?php
require_once '../../includes/config.php';
requireLogin();

// Only super admin and admin can access this page
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';
$warehouse = null;

// Function to check if coordinates are within UK bounds
function isWithinUK($lat, $long) {
    // UK bounding box coordinates
    $uk_bounds = [
        'min_lat' => 49.674, // Southernmost point
        'max_lat' => 61.061, // Northernmost point
        'min_long' => -8.647, // Westernmost point
        'max_long' => 1.768   // Easternmost point
    ];

    return $lat >= $uk_bounds['min_lat'] && 
           $lat <= $uk_bounds['max_lat'] && 
           $long >= $uk_bounds['min_long'] && 
           $long <= $uk_bounds['max_long'];
}

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND w.company_id = " . $_SESSION['company_id'] : "";
    
    // Fetch warehouse details
    $query = "SELECT w.*, c.name as company_name 
              FROM Warehouses w 
              LEFT JOIN Companies c ON w.company_id = c.id 
              WHERE w.id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $warehouse = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$warehouse) {
        header('Location: index.php');
        exit();
    }

    // Initialize form data with warehouse data
    $formData = $warehouse;

    // Fetch companies for super admin
    $companies = [];
    if (isSuperAdmin()) {
        $companies_query = "SELECT id, name FROM Companies ORDER BY name";
        $companies_result = mysqli_query($conn, $companies_query);
        while ($row = mysqli_fetch_assoc($companies_result)) {
            $companies[] = $row;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update formData with POST data
        $formData = array_merge($warehouse, [
            'name' => cleanInput($_POST['name']),
            'address' => cleanInput($_POST['address']),
            'city' => cleanInput($_POST['city']),
            'state' => cleanInput($_POST['state']),
            'postal_code' => cleanInput($_POST['postal_code']),
            'country' => cleanInput($_POST['country']),
            'latitude' => cleanInput($_POST['latitude']),
            'longitude' => cleanInput($_POST['longitude']),
            'status' => cleanInput($_POST['status']),
            'company_id' => isSuperAdmin() ? cleanInput($_POST['company_id']) : $_SESSION['company_id']
        ]);

        if (empty($formData['name']) || empty($formData['address']) || empty($formData['city'])) {
            $error = 'Name, address, and city are required';
        } elseif (!empty($formData['latitude']) && !empty($formData['longitude'])) {
            if (!isWithinUK($formData['latitude'], $formData['longitude'])) {
                $error = 'Warehouse location must be within the United Kingdom';
            }
        }

        if (!$error) {
            $query = "UPDATE Warehouses SET 
                        name = ?, address = ?, city = ?, state = ?, 
                        postal_code = ?, country = ?, latitude = ?, 
                        longitude = ?, company_id = ?, status = ?
                     WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssssddssi", 
                $formData['name'], $formData['address'], $formData['city'], 
                $formData['state'], $formData['postal_code'], $formData['country'], 
                $formData['latitude'], $formData['longitude'], 
                $formData['company_id'], $formData['status'], $id
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $success = 'Warehouse updated successfully';
                // Update warehouse data with new values
                $warehouse = $formData;
            } else {
                $error = 'Error updating warehouse: ' . mysqli_error($conn);
            }
            
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Warehouse - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="flex-1 flex flex-col">
        <div class="bg-gray-800 p-4 flex justify-between items-center">
            <div class="text-white text-lg">Edit Warehouse</div>
            <div class="flex items-center">
                <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                <a href="<?php echo SITE_URL; ?>logout.php" class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Logout</a>
            </div>
        </div>

        <div class="p-6">
            <div class="max-w-7xl mx-auto">
                <div class="mb-6 flex justify-between items-center">
                    <h1 class="text-2xl font-bold text-gray-900">Edit Warehouse: <?php echo htmlspecialchars($warehouse['name']); ?></h1>
                    <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Back to List</a>
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
                            <!-- Basic Information -->
                            <div class="space-y-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700">Warehouse Name *</label>
                                    <input type="text" name="name" id="name" required
                                           value="<?php echo htmlspecialchars($formData['name']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <?php if (isSuperAdmin()): ?>
                                <div>
                                    <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                                    <select name="company_id" id="company_id" required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>" 
                                                <?php echo $company['id'] == $warehouse['company_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($company['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                    <select name="status" id="status" required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="active" <?php echo $warehouse['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $warehouse['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="address" class="block text-sm font-medium text-gray-700">Address *</label>
                                    <input type="text" name="address" id="address" required
                                           value="<?php echo htmlspecialchars($formData['address']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <div>
                                    <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                                    <input type="text" name="city" id="city" required
                                           value="<?php echo htmlspecialchars($formData['city']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <div>
                                    <label for="state" class="block text-sm font-medium text-gray-700">State/Province</label>
                                    <input type="text" name="state" id="state"
                                           value="<?php echo htmlspecialchars($warehouse['state']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <div>
                                    <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code</label>
                                    <input type="text" name="postal_code" id="postal_code"
                                           value="<?php echo htmlspecialchars($warehouse['postal_code']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <div>
                                    <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                                    <input type="text" name="country" id="country"
                                           value="<?php echo htmlspecialchars($warehouse['country']); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            <!-- Map and Coordinates -->
                            <div class="space-y-6">
                                <div id="map" class="h-96 rounded-lg mb-4"></div>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="latitude" class="block text-sm font-medium text-gray-700">Latitude</label>
                                        <input type="number" step="any" name="latitude" id="latitude"
                                               value="<?php echo htmlspecialchars($warehouse['latitude']); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>
                                    <div>
                                        <label for="longitude" class="block text-sm font-medium text-gray-700">Longitude</label>
                                        <input type="number" step="any" name="longitude" id="longitude"
                                               value="<?php echo htmlspecialchars($warehouse['longitude']); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Update Warehouse</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize map
        const map = L.map('map').setView([
            <?php echo $warehouse['latitude'] ?: 0; ?>, 
            <?php echo $warehouse['longitude'] ?: 0; ?>
        ], <?php echo $warehouse['latitude'] ? '13' : '2'; ?>);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        let marker;
        
        // Add initial marker if coordinates exist
        if (<?php echo $warehouse['latitude'] && $warehouse['longitude'] ? 'true' : 'false'; ?>) {
            marker = L.marker([
                <?php echo $warehouse['latitude']; ?>, 
                <?php echo $warehouse['longitude']; ?>
            ]).addTo(map);
        }

        // Handle map click
        map.on('click', function(e) {
            const lat = e.latlng.lat;
            const lng = e.latlng.lng;
            
            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = lng;

            if (marker) {
                marker.setLatLng(e.latlng);
            } else {
                marker = L.marker(e.latlng).addTo(map);
            }
        });

        // Update marker when coordinates are manually entered
        function updateMarker() {
            const lat = parseFloat(document.getElementById('latitude').value);
            const lng = parseFloat(document.getElementById('longitude').value);

            if (!isNaN(lat) && !isNaN(lng)) {
                const latlng = L.latLng(lat, lng);
                if (marker) {
                    marker.setLatLng(latlng);
                } else {
                    marker = L.marker(latlng).addTo(map);
                }
                map.setView(latlng, 13);
            }
        }

        document.getElementById('latitude').addEventListener('change', updateMarker);
        document.getElementById('longitude').addEventListener('change', updateMarker);
    </script>
</body>
</html> 