<?php
require_once '../../includes/config.php';
requireLogin();

// Only admins can access organization management
if (!isSuperAdmin() && !isAdmin()) {
    header('Location: ../dashboard.php');
    exit();
}

$error = '';
$success = '';

// Get companies for dropdown
$companies = [];
if (isSuperAdmin()) {
    $company_query = "SELECT id, name FROM Companies ORDER BY name";
    $company_result = mysqli_query($conn, $company_query);
    while ($row = mysqli_fetch_assoc($company_result)) {
        $companies[] = $row;
    }
}

// Add validation functions (Copied from pages/orders/create.php)
function validateUKAddressWithMapbox($address_line1, $city, $postal_code, $country, $address_line2 = '') {
    $mapbox_token = 'pk.eyJ1IjoibW5hMjU4NjciLCJhIjoiY2tldTZiNzlxMXJ6YzJ6cndqY2RocXkydiJ9.Tee5ksW6tXsXXc4HOPJAwg';
    
    // Format the postal code properly (remove spaces and then add a space in the right position)
    $postal_code = trim($postal_code);
    $postal_code = strtoupper(preg_replace('/\\s+/', '', $postal_code));
    
    // Add space in the correct position for UK postcodes if not present
    if (preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $postal_code)) {
        $postal_code_length = strlen($postal_code);
        $postal_code = substr($postal_code, 0, $postal_code_length - 3) . ' ' . substr($postal_code, $postal_code_length - 3);
    }
    
    // First, validate the postcode
    if (!isValidUKPostcode($postal_code)) {
        return false;
    }
    
    // Construct the address query - use only the postal code for initial validation
    $postal_code_query = urlencode($postal_code);
    
    // Call Mapbox Geocoding API with specific UK focus - first just check the postal code
    $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/$postal_code_query.json?country=GB&types=postcode&limit=1&access_token=$mapbox_token";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set timeout to 10 seconds
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log the request for debugging
    error_log("Mapbox postcode validation request: $url");
    error_log("Mapbox response status: $status_code");
    
    // If API call fails, fall back to basic validation
    if ($status_code !== 200) {
        error_log("Mapbox API Error: Status code $status_code");
        return isValidUKPostcode($postal_code);
    }
    
    $result = json_decode($response, true);
    error_log("Mapbox postcode response: " . json_encode($result));
    
    // Check if we got any results
    if (empty($result['features'])) {
        // Fall back to basic postcode validation
        return isValidUKPostcode($postal_code);
    }
    
    // Get the first result
    $feature = $result['features'][0];
    
    // Extract location information from the postcode lookup
    $postcode_city = null;
    $postcode_region = null;
    
    if (isset($feature['context']) && is_array($feature['context'])) {
        foreach ($feature['context'] as $ctx) {
            if (isset($ctx['id']) && strpos($ctx['id'], 'place') === 0 && isset($ctx['text'])) {
                $postcode_city = strtolower($ctx['text']);
            }
            if (isset($ctx['id']) && strpos($ctx['id'], 'region') === 0 && isset($ctx['text'])) {
                $postcode_region = strtolower($ctx['text']);
            }
        }
    }
    
    // If we found a city from the postcode, check if it matches the provided city
    if ($postcode_city) {
        $input_city = strtolower(trim($city));
        
        // Define city aliases (common alternative names)
        $city_aliases = [
            'london' => ['greater london', 'city of london'],
            'manchester' => ['greater manchester'],
            'birmingham' => ['west midlands'],
            'sheffield' => ['south yorkshire'],
            'leeds' => ['west yorkshire'],
            'newcastle' => ['newcastle upon tyne', 'tyne and wear'],
            'edinburgh' => ['city of edinburgh'],
            // Add more aliases as needed
        ];
        
        // Check if the input city matches the postcode city or any of its aliases
        $city_match = false;
        
        // Direct match
        if ($input_city === $postcode_city) {
            $city_match = true;
        }
        
        // Check aliases for postcode city
        if (isset($city_aliases[$postcode_city])) {
            if (in_array($input_city, $city_aliases[$postcode_city])) {
                $city_match = true;
            }
        }
        
        // Check aliases for input city
        if (isset($city_aliases[$input_city])) {
            if (in_array($postcode_city, $city_aliases[$input_city])) {
                $city_match = true;
            }
        }
        
        // If cities don't match, check if the input city matches the region
        if (!$city_match && $postcode_region && $input_city === $postcode_region) {
            $city_match = true;
        }
        
        // If no match found, return false
        if (!$city_match) {
            error_log("City mismatch: Input city '$input_city' does not match postcode city '$postcode_city' or region '$postcode_region'");
            return false;
        }
    }
    
    // Now validate the full address, including address_line2 if available
    $full_address = $address_line1;
    if (!empty($address_line2)) {
        $full_address .= ", $address_line2";
    }
    
    $address_query = urlencode("$postal_code, $full_address, $city, $country"); // Renamed variable to avoid conflict
    $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/$address_query.json?country=GB&types=address&limit=1&access_token=$mapbox_token";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Mapbox full address validation request: $url");
    
    // If API call fails, we've already validated the postcode and city match
    if ($status_code !== 200) {
        return true;
    }
    
    $result = json_decode($response, true);
    
    // If no results, we've already validated the postcode and city match
    if (empty($result['features'])) {
        return true;
    }
    
    // Get the first result
    $feature = $result['features'][0];
    
    // If we have a result with decent relevance, consider it valid
    return $feature['relevance'] > 0.5;
}

function isValidUKPostcode($postcode) {
    // Clean the postcode
    $postcode = strtoupper(preg_replace('/\\s+/', '', $postcode));
    
    // UK Postcode regex pattern - more comprehensive
    $uk_postcode_pattern = '/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/';
    
    // List of known valid postcodes that might fail regex validation
    $known_valid_postcodes = [
        'S102TH', 'SW117US', 'UB55JB', 'E16QL'
    ];
    
    // Check if it's in our list of known valid postcodes
    if (in_array($postcode, $known_valid_postcodes)) {
        return true;
    }
    
    // Validate postcode format
    return preg_match($uk_postcode_pattern, $postcode);
}

function validateUKPhoneNumber($phone) {
    // Remove any non-digit characters except plus sign
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check if the number starts with +44 and follows UK number format
    // UK numbers are typically 11 digits (including the leading 0)
    // When used with +44, the leading 0 is removed, so we expect 10 digits after +44
    if (preg_match('/^\\+44[1-9]\\d{9}$/', $phone)) {
        return true;
    }
    
    return false;
}


// Initialize form data
$formData = [
    'name' => '',
    'company_id' => isSuperAdmin() ? '' : $_SESSION['company_id'],
    'address_line1' => '', // New address fields
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => 'United Kingdom', // Default to UK
    'phone' => '',
    'email' => '',
    'is_active' => 1
];

// Form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $formData['name'] = trim($_POST['name']);
    $formData['company_id'] = isSuperAdmin() ? (int)$_POST['company_id'] : $_SESSION['company_id'];
    $formData['address_line1'] = trim($_POST['address_line1'] ?? ''); // New address fields
    $formData['address_line2'] = trim($_POST['address_line2'] ?? '');
    $formData['city'] = trim($_POST['city'] ?? '');
    $formData['state'] = trim($_POST['state'] ?? '');
    $formData['postal_code'] = trim($_POST['postal_code'] ?? '');
    $formData['country'] = trim($_POST['country'] ?? 'United Kingdom'); // Should always be UK
    $formData['phone'] = '+44' . preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? '')); // Add +44 prefix
    $formData['email'] = trim($_POST['email'] ?? '');
    $formData['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    // Validate required fields
    if (empty($formData['name'])) {
        $error = 'Organization name is required';
    } elseif (isSuperAdmin() && empty($formData['company_id'])) {
        $error = 'Company is required';
    } elseif (empty($formData['address_line1'])) { // Validate new required fields
        $error = 'Address Line 1 is required';
    } elseif (empty($formData['city'])) {
        $error = 'City is required';
    } elseif (empty($formData['postal_code'])) {
        $error = 'Postal Code is required';
    } elseif (!empty($formData['phone']) && !validateUKPhoneNumber($formData['phone'])) { // Validate phone
        $error = 'Please enter a valid UK phone number';
    } elseif (!isValidUKPostcode($formData['postal_code'])) { // Validate postcode format
        $error = 'Please enter a valid UK postcode';
    } elseif (!validateUKAddressWithMapbox( // Validate full address via Mapbox
        $formData['address_line1'], 
        $formData['city'], 
        $formData['postal_code'], 
        $formData['country'], 
        $formData['address_line2']
    )) {
        $error = 'Please enter a valid UK address. The provided address could not be verified.';
    } else {
        // Check if organization with same name already exists for this company
        $check_query = "SELECT id FROM Organizations WHERE name = ? AND company_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "si", $formData['name'], $formData['company_id']);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = 'An organization with this name already exists for the selected company';
        } else {
            // Process logo upload if provided
            $logo_url = null;
            /* Remove logo upload logic
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
                $file = $_FILES['logo'];
                
                if (!in_array($file['type'], $allowed_types)) {
                    $error = 'Invalid file type. Only JPG, JPEG and PNG are allowed';
                } else {
                    // Create upload directory if it doesn't exist
                    $upload_dir = '../../uploads/logos';
                    if (!file_exists($upload_dir)) {
                        if (!mkdir($upload_dir, 0777, true)) {
                            $error = 'Failed to create upload directory';
                        }
                    }
                    
                    if (empty($error)) {
                        // Generate unique filename
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $filename = 'org_' . time() . '_' . uniqid() . '.' . $extension;
                        $upload_path = $upload_dir . '/' . $filename;
                        
                        // Move uploaded file
                        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                            $logo_url = 'uploads/logos/' . $filename;
                        } else {
                            $error = 'Failed to upload logo';
                        }
                    }
                }
            }
            */
            
            // Insert new organization
            if (empty($error)) {
                $insert_query = "INSERT INTO Organizations (name, company_id, address_line1, address_line2, city, state, postal_code, country, phone, email, logo_url, is_active) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // Updated query
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param(
                    $insert_stmt, 
                    "sisssssssssi", // Updated types (12 params: s, i, s, s, s, s, s, s, s, s, s, i) - logo_url is 's'
                    $formData['name'],
                    $formData['company_id'],
                    $formData['address_line1'], // New fields
                    $formData['address_line2'],
                    $formData['city'],
                    $formData['state'],
                    $formData['postal_code'],
                    $formData['country'],
                    $formData['phone'],
                    $formData['email'],
                    $logo_url, // This will be null now
                    $formData['is_active']
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $success = 'Organization created successfully';
                    // Reset form data
                    $formData = [
                        'name' => '',
                        'company_id' => isSuperAdmin() ? '' : $_SESSION['company_id'],
                        'address_line1' => '', // Reset new fields
                        'address_line2' => '',
                        'city' => '',
                        'state' => '',
                        'postal_code' => '',
                        'country' => 'United Kingdom',
                        'phone' => '',
                        'email' => '',
                        'is_active' => 1
                    ];
                } else {
                    $error = 'Failed to create organization: ' . mysqli_stmt_error($insert_stmt);
                    
                    // If there was an error and we uploaded a file, delete it
                    /* Remove logo deletion on error
                    if ($logo_url && file_exists('../../' . $logo_url)) {
                        unlink('../../' . $logo_url);
                    }
                    */
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
    <title>Create Organization - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Create Organization</h1>
            <a href="index.php" class="text-indigo-600 hover:text-indigo-900">
                &larr; Back to Organizations
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow-md rounded-lg p-6">
            <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Organization Name *</label>
                        <input type="text" name="name" id="name" required
                               value="<?php echo htmlspecialchars($formData['name']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
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

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" id="email"
                               value="<?php echo htmlspecialchars($formData['email']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                         <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <span class="text-gray-500 sm:text-sm">+44</span>
                            </div>
                            <input type="tel" name="phone" id="phone"
                                   value="<?php echo htmlspecialchars(preg_replace('/^\\+44/', '', $formData['phone'])); ?>"
                                   class="pl-12 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                   placeholder="7911123456">
                        </div>
                        <p class="mt-1 text-sm text-gray-500">Enter number without leading 0 (e.g., 7911123456)</p>
                    </div>
                </div>
                
                <!-- Structured Address Fields -->
                <div class="space-y-4 mt-6 border-t pt-6">
                     <h3 class="text-lg font-medium text-gray-900">Address Details</h3>
                     <div>
                        <label for="address_line1" class="block text-sm font-medium text-gray-700">Address Line 1 *</label>
                        <input type="text" name="address_line1" id="address_line1" required
                               value="<?php echo htmlspecialchars($formData['address_line1']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="address_line2" class="block text-sm font-medium text-gray-700">Address Line 2</label>
                        <input type="text" name="address_line2" id="address_line2"
                               value="<?php echo htmlspecialchars($formData['address_line2']); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                            <input type="text" name="city" id="city" required
                                   value="<?php echo htmlspecialchars($formData['city']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="state" class="block text-sm font-medium text-gray-700">State/County</label>
                            <input type="text" name="state" id="state"
                                   value="<?php echo htmlspecialchars($formData['state']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code *</label>
                            <input type="text" name="postal_code" id="postal_code" required
                                   value="<?php echo htmlspecialchars($formData['postal_code']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                            <input type="text" name="country" id="country" required readonly
                                   value="United Kingdom"
                                   class="mt-1 block w-full rounded-md bg-gray-100 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <div>
                    <div class="flex items-center mt-6">
                        <input type="checkbox" name="is_active" id="is_active" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                               <?php echo $formData['is_active'] ? 'checked' : ''; ?>>
                        <label for="is_active" class="ml-2 block text-sm text-gray-900">
                            Active
                        </label>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white py-2 px-4 rounded">
                        Cancel
                    </a>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white py-2 px-4 rounded">
                        Create Organization
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html> 