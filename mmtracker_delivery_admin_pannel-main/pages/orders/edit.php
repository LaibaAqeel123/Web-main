<?php
require_once '../../includes/config.php';
include_once('../../server/log_helper.php');
function logException($exceptionMessage) {
    $logFile = __DIR__ . '/order_exception_log.log';  // Log file stored in same folder
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Exception: $exceptionMessage" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
requireLogin();

$error = '';
$success = '';
$order = null;
$is_delivered = false;

// Fetch order details
if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);

    // Check access rights
    $company_condition = !isSuperAdmin() ? "AND o.company_id = " . $_SESSION['company_id'] : "";

    // Modified query to get order with product, customer, and address details
    $query = "SELECT o.*, 
                     cust.name as customer_name, cust.email, cust.phone, 
                     addr.address_line1, addr.address_line2, addr.city, addr.state, addr.postal_code, addr.country,
                     GROUP_CONCAT(po.id) as product_order_ids, 
                     GROUP_CONCAT(po.product_id) as product_ids,
                     GROUP_CONCAT(po.quantity) as quantities,
                     GROUP_CONCAT(po.price) as prices
              FROM Orders o 
              LEFT JOIN Customers cust ON o.customer_id = cust.id
              LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
              LEFT JOIN ProductOrders po ON o.id = po.order_id
              WHERE o.id = ? $company_condition
              GROUP BY o.id";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);

    if (!$order) {
        header('Location: index.php');
        exit();
    }

    // Check if order is delivered
    $is_delivered = $order['status'] === 'delivered';

    // Process the concatenated product data
    $order['product_order_ids'] = $order['product_order_ids'] ? explode(',', $order['product_order_ids']) : [];
    $order['product_ids'] = $order['product_ids'] ? explode(',', $order['product_ids']) : [];
    $order['quantities'] = $order['quantities'] ? explode(',', $order['quantities']) : [];
    $order['prices'] = $order['prices'] ? explode(',', $order['prices']) : [];

    // Initialize requires_proof fields if they are NULL in the database
    // (for orders created before the columns were added)
    if ($order['requires_image_proof'] === null) {
        $order['requires_image_proof'] = 1; // Default to true
    }
    if ($order['requires_signature_proof'] === null) {
        $order['requires_signature_proof'] = 1; // Default to true
    }
}

// Only fetch additional data if order is not delivered
if (!$is_delivered) {
    // Fetch companies for super admin
    $companies = [];
    if (isSuperAdmin()) {
        $companies_query = "SELECT id, name FROM Companies ORDER BY name";
        $companies_result = mysqli_query($conn, $companies_query);
        while ($row = mysqli_fetch_assoc($companies_result)) {
            $companies[] = $row;
        }
    }

    // Fetch products for the current company
    $products = [];
    $company_id = isSuperAdmin() ? $order['company_id'] : $_SESSION['company_id'];
    if ($company_id) {
        $products_query = "SELECT id, name, description FROM Products WHERE company_id = ? ORDER BY name";
        $stmt = mysqli_prepare($conn, $products_query);
        mysqli_stmt_bind_param($stmt, "i", $company_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $products[] = $row;
        }
    }

    // Get organizations for the selected company
    $organizations = [];
    if ($company_id) {
        $org_query = "SELECT id, name FROM Organizations WHERE company_id = ? AND is_active = 1 ORDER BY name";
        $org_stmt = mysqli_prepare($conn, $org_query);
        mysqli_stmt_bind_param($org_stmt, "i", $company_id);
        mysqli_stmt_execute($org_stmt);
        $org_result = mysqli_stmt_get_result($org_stmt);
        while ($row = mysqli_fetch_assoc($org_result)) {
            $organizations[] = $row;
        }
    }
}

// Fetch addresses for the current customer if the order is pending and customer exists
$current_customer_addresses = [];
if ($order && !$is_delivered && $order['status'] === 'pending' && !empty($order['customer_id'])) {
    $addr_query = "SELECT id, address_line1, address_line2, city, state, postal_code, country, is_default 
                   FROM Addresses 
                   WHERE customer_id = ? 
                   ORDER BY is_default DESC, id ASC";
    $addr_stmt = mysqli_prepare($conn, $addr_query);
    mysqli_stmt_bind_param($addr_stmt, "i", $order['customer_id']);
    mysqli_stmt_execute($addr_stmt);
    $addr_result = mysqli_stmt_get_result($addr_stmt);
    while ($addr_row = mysqli_fetch_assoc($addr_result)) {
        $current_customer_addresses[] = $addr_row;
    }
    mysqli_stmt_close($addr_stmt);
}

// --- Add safety check before outputting HTML --- 
if (!is_array($order)) {
    // If order fetching failed or didn't happen, redirect or display error
    // This prevents trying to access $order variables in the HTML/JS below
    // You might want a more user-friendly error page
    error_log("Error: \$order variable is not an array before rendering HTML in edit.php for ID: " . ($id ?? 'UNKNOWN'));
    header('Location: index.php?error=' . urlencode('Failed to load order data.'));
    exit();
}
// Ensure addresses is always an array for json_encode
if (!is_array($current_customer_addresses)) {
    $current_customer_addresses = [];
}
// --- End safety check ---

// Add the Mapbox validation function after existing functions
function validateUKAddressWithMapbox($address_line1, $city, $postal_code, $country, $address_line2 = '') {
    // Check if country is UK (basic check)
    $uk_countries = ['uk', 'united kingdom', 'great britain', 'gb'];
    if (!in_array(strtolower(trim($country)), $uk_countries)) {
        error_log("Mapbox Validation: Non-UK country provided: $country");
        return false; // Only validate UK addresses
    }
    
    // Ensure essential fields aren't empty
    if (empty(trim($address_line1)) || empty(trim($city)) || empty(trim($postal_code))) {
        error_log("Mapbox Validation: Missing required fields (Address1, City, Postcode)");
        return false;
    }
    
    $mapbox_token = MAPBOX_TOKEN; // Use defined constant
    if (empty($mapbox_token)) {
        error_log("Mapbox Validation Error: Access token is missing.");
        // Fallback to basic postcode check if token is missing?
        return isValidUKPostcode($postal_code);
    }
    
    // Format the postal code properly (remove spaces and then potentially add one back)
    $postal_code_cleaned = strtoupper(preg_replace('/\s+/', '', trim($postal_code)));
    $postal_code_formatted = $postal_code_cleaned; // Default to cleaned
    
    // Add space in the correct position for UK postcodes if not present and regex matches format
    if (preg_match('/^([A-Z]{1,2}[0-9][A-Z0-9]?)([0-9][A-Z]{2})$/', $postal_code_cleaned, $matches)) {
        $postal_code_formatted = $matches[1] . ' ' . $matches[2];
    }
    
    // First, validate the postcode format strictly
    if (!isValidUKPostcode($postal_code_cleaned)) {
        error_log("Mapbox Validation: Invalid UK postcode format: $postal_code");
        return false;
    }
    
    // --- Optional: Postcode/City Check (First Mapbox Call) ---
    $postcode_query = urlencode($postal_code_formatted);
    $postcode_url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$postcode_query}.json?country=GB&types=postcode&limit=1&access_token={$mapbox_token}";
    
    $ch_postcode = curl_init();
    curl_setopt($ch_postcode, CURLOPT_URL, $postcode_url);
    curl_setopt($ch_postcode, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_postcode, CURLOPT_TIMEOUT, 5);
    $postcode_response = curl_exec($ch_postcode);
    $postcode_status_code = curl_getinfo($ch_postcode, CURLINFO_HTTP_CODE);
    curl_close($ch_postcode);
    
    if ($postcode_status_code === 200) {
        $postcode_result = json_decode($postcode_response, true);
        if (!empty($postcode_result['features'])) {
            $feature = $postcode_result['features'][0];
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
    
            // Compare input city with city/region from Mapbox result for the postcode
    if ($postcode_city) {
        $input_city = strtolower(trim($city));
                $city_aliases = [ // Example aliases
            'london' => ['greater london', 'city of london'],
                    'manchester' => ['greater manchester']
                    // Add more aliases as needed in config or here
                ];
                
        $city_match = false;
                if ($input_city === $postcode_city) $city_match = true;
                elseif (isset($city_aliases[$postcode_city]) && in_array($input_city, $city_aliases[$postcode_city])) $city_match = true;
                elseif (isset($city_aliases[$input_city]) && in_array($postcode_city, $city_aliases[$input_city])) $city_match = true;
                elseif ($postcode_region && $input_city === $postcode_region) $city_match = true;
                
                if (!$city_match) {
                    error_log("Mapbox Validation: City mismatch for postcode $postal_code. Input: '$input_city', Mapbox Postcode Context: City='$postcode_city', Region='$postcode_region'");
                    return false; // City doesn't match the postcode's location
                }
            }
        } else {
             error_log("Mapbox Validation: Postcode $postal_code not found by Mapbox.");
             // Proceed to full address check, but log it.
        }
    } else {
         error_log("Mapbox Validation: Postcode API call failed (HTTP $postcode_status_code) for URL: $postcode_url");
         // Proceed to full address check anyway, but log the error
    }
    // --- End Optional Postcode/City Check ---
    
    // --- Full Address Check (Second Mapbox Call) ---
    $full_address_parts = array_filter([trim($address_line1), trim($address_line2)]);
    $full_address_query = implode(", ", $full_address_parts);
    
    // Construct address string prioritizing specific details
    $address_query_string = urlencode("$full_address_query, $postal_code_formatted, $city, UK");
    
    $address_url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$address_query_string}.json?country=GB&types=address&limit=1&access_token={$mapbox_token}";
    
    $ch_address = curl_init();
    curl_setopt($ch_address, CURLOPT_URL, $address_url);
    curl_setopt($ch_address, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_address, CURLOPT_TIMEOUT, 10); 
    $address_response = curl_exec($ch_address);
    $address_status_code = curl_getinfo($ch_address, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch_address);
    curl_close($ch_address);
    
    error_log("Mapbox Validation: Full Address Request URL: $address_url");
    error_log("Mapbox Validation: Full Address Response Status: $address_status_code");
    if ($curl_error) {
         error_log("Mapbox Validation: Full Address cURL Error: $curl_error");
    }
    
    // If API call fails, consider it invalid (as we couldn't verify the specific address)
    if ($address_status_code !== 200) {
        error_log("Mapbox Validation: Full Address API call failed.");
        return false; // Strict: Fail if API call fails
    }
    
    $address_result = json_decode($address_response, true);
    error_log("Mapbox Validation: Full Address Response Body: " . json_encode($address_result));
    
    // Check if we got any results
    if (empty($address_result['features'])) {
        error_log("Mapbox Validation: No features found for the full address.");
        return false; // No match found for the specific address
    }
    
    // Get the first result
    $feature = $address_result['features'][0];
    
    // **Crucial Check:** Ensure the result type is specifically 'address'
    if (!isset($feature['place_type']) || !in_array('address', $feature['place_type'])) {
         error_log("Mapbox Validation: Result found, but place_type is not 'address'. Found: " . implode(', ', $feature['place_type'] ?? []));
         return false; // It found something (like the postcode area), but not the specific address
    }
    
    error_log("Mapbox Validation: Address successfully validated as type 'address'. Feature ID: " . ($feature['id'] ?? 'N/A'));
    return true; // Address seems valid and specific
}

// Add a function to validate UK postcodes
function isValidUKPostcode($postcode) {
    // Clean the postcode
    $postcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    
    // UK Postcode regex pattern - more comprehensive
    $uk_postcode_pattern = '/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/';
    
    // List of known valid postcodes that might fail regex validation (Example)
    $known_valid_postcodes = [
        // 'GIFA1AA' // Example if needed
    ];
    
    // Check if it's in our list of known valid postcodes
    if (in_array($postcode, $known_valid_postcodes)) {
        return true;
    }
    
    // Validate postcode format
    return preg_match($uk_postcode_pattern, $postcode);
}

// Add UK phone number validation function after other functions
function validateUKPhoneNumber($phone) {
    // Remove any non-digit characters except plus sign
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Check if the number starts with +44 and follows UK number format
    // UK numbers are typically 11 digits (including the leading 0)
    // When used with +44, the leading 0 is removed, so we expect 10 digits after +44
    if (preg_match('/^\+44[1-9]\d{9}$/', $phone)) {
        return true;
    }
    
    return false;
}

// Initialize form data with order values if exists
$formData = [
    'customer_name' => $order ? ($order['customer_name'] ?? '') : '',
    'email' => $order ? ($order['email'] ?? '') : '',
    'phone' => $order ? ($order['phone'] ?? '') : '',
    'address_line1' => $order ? ($order['address_line1'] ?? '') : '',
    'address_line2' => $order ? ($order['address_line2'] ?? '') : '',
    'city' => $order ? ($order['city'] ?? '') : '',
    'state' => $order ? ($order['state'] ?? '') : '',
    'postal_code' => $order ? ($order['postal_code'] ?? '') : '',
    'country' => $order ? ($order['country'] ?? 'United Kingdom') : 'United Kingdom',
    'notes' => $order ? ($order['notes'] ?? '') : '',
    'company_id' => $order ? ($order['company_id'] ?? (isSuperAdmin() ? '' : $_SESSION['company_id'])) : (isSuperAdmin() ? '' : $_SESSION['company_id']),
    'delivery_date' => $order ? ($order['delivery_date'] ?? '') : '',
    'status' => $order ? ($order['status'] ?? 'pending') : 'pending',
    'product_ids' => $order ? ($order['product_ids'] ?? []) : [],
    'quantities' => $order ? ($order['quantities'] ?? []) : [],
    'requires_image_proof' => $order ? ($order['requires_image_proof'] ?? 1) : 1,
    'requires_signature_proof' => $order ? ($order['requires_signature_proof'] ?? 1) : 1,
    'organization_id' => $order ? ($order['organization_id'] ?? null) : null
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['just_company_change']) && $_POST['just_company_change'] === '1') {
        // Get organizations for the selected company
        $company_id = isSuperAdmin() ? (int)$_POST['company_id'] : $_SESSION['company_id'];
        $order['company_id'] = $company_id;
        
        // Reset organization_id when company changes
        $order['organization_id'] = null;
        
        // Get organizations for the selected company
        $organizations = [];
        if ($company_id) {
            $org_query = "SELECT id, name FROM Organizations WHERE company_id = ? AND is_active = 1 ORDER BY name";
            $org_stmt = mysqli_prepare($conn, $org_query);
            mysqli_stmt_bind_param($org_stmt, "i", $company_id);
            mysqli_stmt_execute($org_stmt);
            $org_result = mysqli_stmt_get_result($org_stmt);
            while ($row = mysqli_fetch_assoc($org_result)) {
                $organizations[] = $row;
            }
        }
    } else {
        // --- START: Form Processing Logic --- 
        // Store form data
        $formData = $_POST; // Keep all submitted data
        // Ensure array fields exist even if empty
        $formData['product_ids'] = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
        $formData['quantities'] = isset($_POST['quantities']) ? $_POST['quantities'] : [];
        $formData['requires_image_proof'] = isset($_POST['requires_image_proof']) ? 1 : 0;
        $formData['requires_signature_proof'] = isset($_POST['requires_signature_proof']) ? 1 : 0;

        // Check if order is delivered before processing any updates
        $id = cleanInput($_POST['id']);
        $check_query = "SELECT status, customer_id, delivery_address_id FROM Orders WHERE id = ?"; // Fetch original IDs too
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "i", $id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        $original_order_data = mysqli_fetch_assoc($check_result);
        mysqli_stmt_close($check_stmt);

        if (!$original_order_data) {
            $error = 'Order not found.';
            // Exit or handle error appropriately
        } elseif ($original_order_data['status'] === 'delivered') {
            $error = 'Cannot modify a delivered order';
        } else {
            $original_status = $original_order_data['status'];
            $original_customer_id = $original_order_data['customer_id'];
            $original_address_id = $original_order_data['delivery_address_id'];

            // Submitted data (Cleaned for processing)
            $customer_name = cleanInput($formData['customer_name'] ?? '');
            $email = cleanInput($formData['email'] ?? '');
            $phone_raw = preg_replace('/[^0-9]/', '', cleanInput($formData['phone'] ?? ''));
            $phone = !empty($phone_raw) ? '+44' . $phone_raw : ''; // Add prefix only if number exists
            $status = $formData['status'];
            $address_line1 = cleanInput($formData['address_line1'] ?? '');
            $address_line2 = cleanInput($formData['address_line2'] ?? '');
            $city = cleanInput($formData['city'] ?? '');
            $state = cleanInput($formData['state'] ?? '');
            $postal_code = cleanInput($formData['postal_code'] ?? '');
            $country = cleanInput($formData['country'] ?? ''); // Should default to UK if empty?
            $notes = $formData['notes'];
            $total_amount = 0; // Recalculate if needed
            $company_id = $formData['company_id'];
            // Correctly handle empty date: Set to NULL if empty
            $delivery_date = !empty($formData['delivery_date']) ? cleanInput($formData['delivery_date']) : null;
            $organization_id = isset($formData['organization_id']) && !empty($formData['organization_id']) ? (int)$formData['organization_id'] : null;
            $product_ids = $formData['product_ids'];
            $product_quantities = $formData['quantities'];
            $product_prices = array_fill(0, count($product_ids), 0);
            $requires_image_proof = isset($formData['requires_image_proof']) ? 1 : 0;
            $requires_signature_proof = isset($formData['requires_signature_proof']) ? 1 : 0;
            $force_skip_address_validation = isset($_POST['force_address_validation_skip']) && $_POST['force_address_validation_skip'] === '1';

            // Get submitted customer/address IDs from hidden fields
            $submitted_customer_id = isset($formData['customer_id']) ? (int)$formData['customer_id'] : null; // From hidden input #customer_id
            $submitted_address_id_from_selector = $formData['selected_address_id_selector'] ?? null; // From dropdown
            $submitted_address_id_hidden = isset($formData['selected_address_id']) ? (int)$formData['selected_address_id'] : null; // From hidden input #selected_address_id
            
            // Use the selected address ID from either source
            $submitted_address_id = is_numeric($submitted_address_id_from_selector) ? (int)$submitted_address_id_from_selector : $submitted_address_id_hidden;

            // Determine if customer/address was changed via search (only possible if status was pending)
            $can_change_customer_address = ($original_status === 'pending');
            $customer_changed_via_search = ($can_change_customer_address && $submitted_customer_id !== $original_customer_id && $submitted_customer_id !== null);
            $new_address_selected = ($can_change_customer_address && ($submitted_address_id_from_selector === '_new' || empty($submitted_address_id_from_selector) && $customer_changed_via_search));
            $existing_address_selected = ($can_change_customer_address && is_numeric($submitted_address_id_from_selector) && $submitted_address_id_from_selector != $original_address_id);
            $address_changed_via_search = ($can_change_customer_address && !$new_address_selected && $existing_address_selected);

            // Check if text fields changed (only relevant if customer/address wasn't changed via search)
            $customer_fields_changed = (!$customer_changed_via_search && $can_change_customer_address &&
                                       ($customer_name !== ($order['customer_name'] ?? '') ||
                                        $email !== ($order['email'] ?? '') ||
                                        $phone !== ($order['phone'] ?? ''))); // Compare against DB value for original customer

            $address_fields_changed = (!$new_address_selected && !$existing_address_selected && $can_change_customer_address &&
                                       ($address_line1 !== ($order['address_line1'] ?? '') ||
                                        $address_line2 !== ($order['address_line2'] ?? '') ||
                                        $city !== ($order['city'] ?? '') ||
                                        $state !== ($order['state'] ?? '') ||
                                        $postal_code !== ($order['postal_code'] ?? '') ||
                                        $country !== ($order['country'] ?? ''))); // Compare against DB value for original address

            // --- Start Validation --- (Refined based on changes)
            // --- Address Validation (Always run unless forced) ---
            if (!$force_skip_address_validation) {
                error_log("[DEBUG] Running Mapbox validation for Order ID: {$id}. Force skip: " . var_export($force_skip_address_validation, true));
                $is_address_valid = validateUKAddressWithMapbox($address_line1, $city, $postal_code, $country, $address_line2);
                error_log("[DEBUG] Mapbox validation result: " . var_export($is_address_valid, true));
                if (!$is_address_valid) {
                    $error = 'Address could not be verified by Mapbox. Use "Force Update" to bypass.';
                }
            } else {
                error_log("[DEBUG] Skipping Mapbox validation due to force flag for Order ID: {$id}.");
            }
            
            error_log("[DEBUG] Error status after address validation: '{$error}'");

            // --- Basic Field Validations (Run regardless of address validation outcome if no error yet) ---
            if (!$error) { // Only check these if no address validation error yet
                if ($new_address_selected) { // If creating new address 
                     if (empty($address_line1)) $error = 'Address Line 1 is required for new address.';
                     elseif (empty($city)) $error = 'City is required for new address.';
                     elseif (empty($postal_code)) $error = 'Postal Code is required for new address.';
                     elseif (!isValidUKPostcode($postal_code)) $error = 'Invalid UK postcode format for new address.';
                } elseif ($customer_fields_changed) { // Only validate customer text fields if they were manually changed
                    if (empty($customer_name)) $error = 'Customer Name cannot be empty.';
                    elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid email address for the customer.';
                    elseif (!empty($phone_raw) && !validateUKPhoneNumber($phone)) $error = 'Please enter a valid UK phone number (starting +44).';
                } elseif ($address_fields_changed) { // Only validate address text fields if they were manually changed
                    if (empty($address_line1)) $error = 'Address Line 1 cannot be empty.';
                    elseif (empty($city)) $error = 'City cannot be empty.';
                    elseif (empty($postal_code)) $error = 'Postal Code cannot be empty.';
                    elseif (!isValidUKPostcode($postal_code)) $error = 'Invalid UK postcode format.';
                }
            }
            
            // Product validation (remains the same, run if no prior errors)
            if (!$error) {
                if (empty($product_ids)) {
                    $error = 'Please select at least one product.';
                } else {
                    foreach ($product_quantities as $qty) {
                        if (!is_numeric($qty) || $qty <= 0) {
                            $error = 'Product quantities must be valid positive numbers.';
                            break;
                        }
                    }
                }
            }

            // --- End Validation ---

            error_log("[DEBUG] Final error status before transaction check: '{$error}'");

            if ($error) {
                 error_log("[DEBUG] Error found ('{$error}'), skipping DB transaction for Order ID: {$id}.");
                 // Keep form data populated from POST
                 // Ensure phone is formatted correctly for display if validation fails
                 $formData['phone'] = $phone_raw; // Display without +44 on error
            } else {
                // --- Start Transaction --- 
                mysqli_begin_transaction($conn);
                try {
                    

                    // Verify organization (if set)
                    if ($organization_id) {
                        $check_org_query = "SELECT id FROM Organizations WHERE id = ? AND company_id = ?";
                        $check_org_stmt = mysqli_prepare($conn, $check_org_query);
                        mysqli_stmt_bind_param($check_org_stmt, "ii", $organization_id, $company_id);
                        mysqli_stmt_execute($check_org_stmt);
                        if (mysqli_stmt_get_result($check_org_stmt)->num_rows === 0) {
                            $organization_id = null; 
                        }
                        mysqli_stmt_close($check_org_stmt);
                    }

                    $customer_id_to_update = $original_customer_id;
                    $address_id_to_update = $original_address_id;

                    // === Handle Customer/Address Changes ===
                    if ($can_change_customer_address) {
                        if ($customer_changed_via_search) {
                            // Verify new customer ID exists
                            $check_cust_sql = "SELECT id FROM Customers WHERE id = ?";
                            $check_cust_stmt = mysqli_prepare($conn, $check_cust_sql);
                            mysqli_stmt_bind_param($check_cust_stmt, "i", $submitted_customer_id); // Use the ID from hidden input
                            mysqli_stmt_execute($check_cust_stmt);
                            if(mysqli_stmt_get_result($check_cust_stmt)->num_rows === 0) {
                                throw new Exception("Invalid customer selected via search.");
                            }
                            mysqli_stmt_close($check_cust_stmt);
                            $customer_id_to_update = $submitted_customer_id;

                            // Address handling when customer changes via search:
                            if ($new_address_selected) {
                                // Create new address for the *new* customer
                                $insert_addr_sql = "INSERT INTO Addresses (customer_id, address_line1, address_line2, city, state, postal_code, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                $insert_addr_stmt = mysqli_prepare($conn, $insert_addr_sql);
                                $is_default_new = true; 
                                mysqli_stmt_bind_param($insert_addr_stmt, "issssssi", $customer_id_to_update, $address_line1, $address_line2, $city, $state, $postal_code, $country, $is_default_new);
                                if (!mysqli_stmt_execute($insert_addr_stmt)) throw new Exception("Failed to create new address for selected customer: " . mysqli_stmt_error($insert_addr_stmt));
                                $address_id_to_update = mysqli_insert_id($conn);
                                mysqli_stmt_close($insert_addr_stmt);
                            } elseif ($existing_address_selected) {
                                // Verify selected existing address (from dropdown) belongs to the *new* customer
                                $verify_addr_sql = "SELECT id FROM Addresses WHERE id = ? AND customer_id = ?";
                                $verify_addr_stmt = mysqli_prepare($conn, $verify_addr_sql);
                                mysqli_stmt_bind_param($verify_addr_stmt, "ii", $submitted_address_id, $customer_id_to_update);
                                mysqli_stmt_execute($verify_addr_stmt);
                                if(mysqli_stmt_get_result($verify_addr_stmt)->num_rows === 0) {
                                    throw new Exception("Selected address does not belong to the newly selected customer.");
                                }
                                mysqli_stmt_close($verify_addr_stmt);
                                $address_id_to_update = $submitted_address_id;
                            } else {
                                 // Customer changed, but address didn't (or wasn't selected) - this shouldn't happen with current JS logic?
                                 // Maybe default to null or throw error? For now, keep original address ID linkage.
                                 error_log("Warning: Customer changed via search but address ID did not update as expected. Order ID: $id");
                                 // $address_id_to_update = null; // Or keep original?
                            }
                        } elseif ($new_address_selected) { 
                             // Customer didn't change, but creating new address for original customer
                             $insert_addr_sql = "INSERT INTO Addresses (customer_id, address_line1, address_line2, city, state, postal_code, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                             $insert_addr_stmt = mysqli_prepare($conn, $insert_addr_sql);
                             $is_default_new = true; 
                             mysqli_stmt_bind_param($insert_addr_stmt, "issssssi", $original_customer_id, $address_line1, $address_line2, $city, $state, $postal_code, $country, $is_default_new);
                             if (!mysqli_stmt_execute($insert_addr_stmt)) throw new Exception("Failed to create new address for original customer: " . mysqli_stmt_error($insert_addr_stmt));
                             $address_id_to_update = mysqli_insert_id($conn);
                             mysqli_stmt_close($insert_addr_stmt);
                        } elseif ($address_changed_via_search) {
                            // Customer didn't change, but selected different existing address for original customer
                            // Verify selected existing address belongs to the *original* customer
                            $verify_addr_sql = "SELECT id FROM Addresses WHERE id = ? AND customer_id = ?";
                            $verify_addr_stmt = mysqli_prepare($conn, $verify_addr_sql);
                            mysqli_stmt_bind_param($verify_addr_stmt, "ii", $submitted_address_id, $original_customer_id);
                            mysqli_stmt_execute($verify_addr_stmt);
                            if(mysqli_stmt_get_result($verify_addr_stmt)->num_rows === 0) {
                                throw new Exception("Selected address does not belong to the original customer.");
                            }
                            mysqli_stmt_close($verify_addr_stmt);
                            $address_id_to_update = $submitted_address_id;
                        } else {
                             // No change via search, potentially update fields based on text input below
                        }
                    }

                    // 1. Update Customer TEXT FIELDS if changed (and not changed via search)
                    if ($customer_fields_changed && $original_customer_id) {
                        $update_cust_sql = "UPDATE Customers SET name = ?, email = ?, phone = ? WHERE id = ?";
                        $update_cust_stmt = mysqli_prepare($conn, $update_cust_sql);
                        mysqli_stmt_bind_param($update_cust_stmt, "sssi", $customer_name, $email, $phone, $original_customer_id);
                        if (!mysqli_stmt_execute($update_cust_stmt)) {
                             throw new Exception('Error updating customer fields: ' . mysqli_stmt_error($update_cust_stmt));
                        }
                        mysqli_stmt_close($update_cust_stmt);
                    }
                    
                    // 2. Update Address TEXT FIELDS if changed (and not changed via search/new)
                    if ($address_fields_changed && $original_address_id) {
                         $update_addr_sql = "UPDATE Addresses SET address_line1 = ?, address_line2 = ?, city = ?, state = ?, postal_code = ?, country = ? WHERE id = ?";
                         $update_addr_stmt = mysqli_prepare($conn, $update_addr_sql);
                         mysqli_stmt_bind_param($update_addr_stmt, "ssssssi", $address_line1, $address_line2, $city, $state, $postal_code, $country, $original_address_id);
                         if (!mysqli_stmt_execute($update_addr_stmt)) {
                             throw new Exception('Error updating address fields: ' . mysqli_stmt_error($update_addr_stmt));
                         }
                         mysqli_stmt_close($update_addr_stmt);
                    }

                    // 3. Update Order (Includes potentially changed customer/address IDs)
                    $update_order_sql = "UPDATE Orders SET 
                              customer_id = ?,
                              delivery_address_id = ?,
                              status = ?,
                              notes = ?,
                              total_amount = ?,
                              company_id = ?,
                              organization_id = ?,
                              delivery_date = ?,
                              requires_image_proof = ?,
                              requires_signature_proof = ?
                              WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $update_order_sql);
                    
                    mysqli_stmt_bind_param(
                        $stmt,
                        "iissdiisiii", // Corrected: 11 types for 11 variables
                        $customer_id_to_update,     // 1 (i)
                        $address_id_to_update,      // 2 (i)
                        $status,                    // 3 (s)
                        $notes,                     // 4 (s)
                        $total_amount,              // 5 (d)
                        $company_id,                // 6 (i)
                        $organization_id,           // 7 (i)
                        $delivery_date,             // 8 (s)
                        $formData['requires_image_proof'], // 9 (i)
                        $formData['requires_signature_proof'], // 10 (i)
                        $id                         // 11 (i)
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception('Error updating order details: ' . mysqli_stmt_error($stmt));
                    }
                     mysqli_stmt_close($stmt);

                    // 4. Update Product Orders (Delete and Re-insert - logic unchanged)
                        $delete_query = "DELETE FROM ProductOrders WHERE order_id = ?";
                        $delete_stmt = mysqli_prepare($conn, $delete_query);
                        mysqli_stmt_bind_param($delete_stmt, "i", $id);
                        mysqli_stmt_execute($delete_stmt);
                    mysqli_stmt_close($delete_stmt);

                        $product_query = "INSERT INTO ProductOrders (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                        $product_stmt = mysqli_prepare($conn, $product_query);

                        foreach ($product_ids as $index => $product_id) {
                        if (!empty($product_id) && isset($product_quantities[$index]) && $product_quantities[$index] > 0) { // Ensure quantity is set and > 0
                            $current_quantity = (int)$product_quantities[$index];
                            $current_price = isset($product_prices[$index]) ? (float)$product_prices[$index] : 0.0;
                                mysqli_stmt_bind_param(
                                    $product_stmt,
                                    "iiid",
                                    $id,
                                    $product_id,
                                $current_quantity,
                                $current_price
                                );
                            if (!mysqli_stmt_execute($product_stmt)) {
                                throw new Exception('Error inserting product order: ' . mysqli_stmt_error($product_stmt));
                            }
                        }
                    }
                     mysqli_stmt_close($product_stmt);

                   // 5. Add status log if status changed (logic unchanged)
if ($status !== $original_status) {
    $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
    $log_stmt = mysqli_prepare($conn, $log_query);
    mysqli_stmt_bind_param($log_stmt, "isi", $id, $status, $_SESSION['user_id']);
    if (!mysqli_stmt_execute($log_stmt)) {
        throw new Exception('Error adding status log: ' . mysqli_stmt_error($log_stmt));
    }
    mysqli_stmt_close($log_stmt);

   
    $log_message = "Order #$id status changed from '$original_status' to '$status' by user #" . $_SESSION['user_id'];
    writeLog("order.log", $log_message);
}


                    // 6. Handle manifest removal if status changes to 'pending' (logic unchanged)
                    if ($status === 'pending' && ($original_status === 'assigned' || $original_status === 'delivering')) {
                            $remove_manifest_order_query = "DELETE FROM ManifestOrders WHERE order_id = ?";
                            $remove_stmt = mysqli_prepare($conn, $remove_manifest_order_query);
                            mysqli_stmt_bind_param($remove_stmt, "i", $id);
                         if (!mysqli_stmt_execute($remove_stmt)) {
                             throw new Exception('Error removing order from manifest: ' . mysqli_stmt_error($remove_stmt));
                         }
                          mysqli_stmt_close($remove_stmt);
                    }

                    // --- Commit Transaction ---
                        mysqli_commit($conn);
                        $success = 'Order updated successfully';
                        $error = ''; // Clear any previous error message

                    // Redirect to refresh data
                        header("Location: edit.php?id=" . $id . "&success=" . urlencode($success));
                        exit();

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = $e->getMessage();
                    logException($e->getMessage());//exceptional log
                    // Repopulate formData if error occurs during transaction
                     $formData = $_POST;
                     $formData['product_ids'] = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
                     $formData['quantities'] = isset($_POST['quantities']) ? $_POST['quantities'] : [];
                }
            }
             // If validation failed before transaction, ensure formData is still populated
             if ($error) {
                $formData = $_POST;
                $formData['product_ids'] = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
                $formData['quantities'] = isset($_POST['quantities']) ? $_POST['quantities'] : [];
            }
        }
    }
}

// Get success message from URL if it exists
if (isset($_GET['success'])) {
    $success = $_GET['success'];
    $error = ''; // Clear any error if success is present
}

// Get error message from URL if it exists and no success message
if (isset($_GET['error']) && empty($success)) {
    $error = $_GET['error'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Add styles for suggestions -->
    <style>
        #customer-suggestions {
            position: absolute;
            border: 1px solid #ccc;
            background-color: white;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            width: calc(100% - 2rem); /* Adjust as needed */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: none;
            border-radius: 0 0 0.375rem 0.375rem;
        }
        #customer-suggestions div {
            padding: 8px 12px;
            cursor: pointer;
        }
        #customer-suggestions div:hover {
            background-color: #f0f0f0;
        }
        .address-section {
            border: 1px dashed #ccc;
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 0.375rem;
        }
         .disabled-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(243, 244, 246, 0.5); /* bg-gray-100 with opacity */
            cursor: not-allowed;
            z-index: 10;
        }
    </style>
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
                    <h1 class="text-2xl font-bold text-gray-900">Edit Order #<?php echo htmlspecialchars($order['order_number']); ?></h1>
                     <p class="text-sm text-gray-500">Current Status: <span class="font-semibold <?php echo ($order['status'] === 'delivered' ? 'text-red-600' : ($order['status'] === 'pending' ? 'text-blue-600' : 'text-gray-700')); ?>"><?php echo ucfirst($order['status']); ?></span></p>
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

                <?php if ($is_delivered): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline">This order is delivered and cannot be modified.</span>
                    </div>
                <?php endif; ?>

                <div class="bg-white shadow-md rounded-lg overflow-hidden p-6">
                    <form action="" method="POST" class="space-y-6" id="orderForm">
                        <input type="hidden" name="id" value="<?php echo $order['id']; ?>">
                         <input type="hidden" id="original_customer_id" value="<?php echo htmlspecialchars($order['customer_id'] ?? ''); ?>">
                         <input type="hidden" id="original_address_id" value="<?php echo htmlspecialchars($order['delivery_address_id'] ?? ''); ?>">
                         <input type="hidden" name="customer_id" id="customer_id" value="<?php echo htmlspecialchars($order['customer_id'] ?? ''); ?>"> <!-- Final customer ID -->
                         <input type="hidden" name="selected_address_id" id="selected_address_id" value="<?php echo htmlspecialchars($order['delivery_address_id'] ?? ''); ?>"> <!-- Final address ID -->
                         <input type="hidden" id="order_status" value="<?php echo htmlspecialchars($order['status'] ?? 'pending'); ?>">


                        <?php if (isSuperAdmin()): ?>
                            <div>
                                <label for="company_id" class="block text-sm font-medium text-gray-700">Company</label>
                                <select name="company_id" id="company_id" onchange="this.form.just_company_change.value='1'; this.form.submit();"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>" 
                                        <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>" 
                                                <?php echo $company['id'] == $order['company_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="just_company_change" value="0">
                                <?php if ($order['status'] !== 'pending'): ?>
                                     <p class="mt-1 text-sm text-gray-500">Company cannot be changed unless the order is 'pending'.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                             <input type="hidden" name="company_id" value="<?php echo $_SESSION['company_id']; ?>">
                        <?php endif; ?>

                        <!-- Add Organization selection -->
                        <div>
                            <label for="organization_id" class="block text-sm font-medium text-gray-700">Organization</label>
                            <select name="organization_id" id="organization_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                    <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                <option value="">Select Organization (Optional)</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>" 
                                            <?php echo $org['id'] == $order['organization_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($org['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                             <?php if ($order['status'] !== 'pending'): ?>
                                <p class="mt-1 text-sm text-gray-500">Organization cannot be changed unless the order is 'pending'.</p>
                            <?php else: ?>
                            <p class="mt-1 text-sm text-gray-500">Select the organization this order belongs to</p>
                            <?php endif; ?>
                        </div>

                         <!-- Customer Selection Section (Moved Up) -->
                         <div class="relative pt-6 mt-6 border-t">
                             <h3 class="text-lg font-medium text-gray-900 mb-2">Customer</h3>
                             <?php if ($order['status'] === 'pending'): ?>
                                 <div class="relative">
                                     <label for="customer_search" class="block text-sm font-medium text-gray-700">Search/Change Customer</label>
                                     <input type="text" id="customer_search"
                                            placeholder="Start typing name, email, or phone to change customer..."
                                            autocomplete="off"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                     <div id="customer-suggestions" class="absolute mt-1 w-full rounded-md bg-white shadow-lg z-10" style="display: none;"></div> <!-- Added z-10 -->
                                     <p class="mt-1 text-sm text-gray-500">Selecting a customer will populate their details and address options below.</p>
                                     <p id="selected-customer-info" class="mt-2 text-sm font-medium text-indigo-600" style="display: none;"></p>
                                 </div>
                              <?php else: ?>
                                  <p class="text-sm text-gray-500 bg-gray-100 p-2 rounded">Customer and address cannot be changed for orders not in 'pending' status.</p>
                              <?php endif; ?>
                          </div>
                        
                        <!-- Customer Details Section -->
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2" id="customer-details-section">
                            <div>
                                <label for="customer_name" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                                <input type="text" name="customer_name" id="customer_name" required
                                       value="<?php echo htmlspecialchars($formData['customer_name'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                       <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                                <input type="email" name="email" id="email" required
                                       value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                       <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3">
                                        <span class="text-gray-500 sm:text-sm">+44</span>
                                    </div>
                                    <input type="tel" name="phone" id="phone"
                                           value="<?php echo htmlspecialchars(preg_replace('/^\+44/', '', $formData['phone'] ?? '')); ?>"
                                           class="pl-12 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                           placeholder="7911123456"
                                           <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Enter number without leading 0 (e.g., 7911123456)</p>
                            </div>
                        </div>

                        <!-- Address Section -->
                         <div class="address-section space-y-4" id="address-section">
                              <h3 class="text-lg font-medium text-gray-900">Delivery Address</h3>
                               <?php if ($order['status'] === 'pending'): ?>
                                 <!-- Address Selection (Populated by JS if customer selected) -->
                                 <div id="address-selector-container" style="display: none;">
                                     <label for="address_selector" class="block text-sm font-medium text-gray-700">Select Saved Address or Enter New</label>
                                     <select id="address_selector" name="selected_address_id_selector" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                         <!-- Options populated by JS -->
                                         <option value="">-- Select Address --</option> 
                                         <option value="_new">-- Enter New Address Below --</option>
                                     </select>
                                     <p class="mt-1 text-sm text-gray-500">Selecting an address will populate the fields below. Choose 'Enter New' to add one.</p>
                                 </div>
                              <?php endif; ?>
                              <!-- Address Fields -->
                              <div id="address-fields" class="space-y-4"> 
                            <div>
                                <label for="address_line1" class="block text-sm font-medium text-gray-700">Address Line 1 *</label>
                                <input type="text" name="address_line1" id="address_line1" required
                                       value="<?php echo htmlspecialchars($formData['address_line1'] ?? ''); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                           <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                            </div>

                            <div>
                                <label for="address_line2" class="block text-sm font-medium text-gray-700">Address Line 2</label>
                                <input type="text" name="address_line2" id="address_line2"
                                       value="<?php echo htmlspecialchars($formData['address_line2'] ?? ''); ?>"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                           <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label for="city" class="block text-sm font-medium text-gray-700">City *</label>
                                    <input type="text" name="city" id="city" required
                                           value="<?php echo htmlspecialchars($formData['city'] ?? ''); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                               <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                </div>

                                <div>
                                    <label for="state" class="block text-sm font-medium text-gray-700">State/County</label>
                                    <input type="text" name="state" id="state"
                                           value="<?php echo htmlspecialchars($formData['state'] ?? ''); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                               <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                </div>

                                <div>
                                    <label for="postal_code" class="block text-sm font-medium text-gray-700">Postal Code *</label>
                                    <input type="text" name="postal_code" id="postal_code" required
                                           value="<?php echo htmlspecialchars($formData['postal_code'] ?? ''); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>"
                                               <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                </div>

                                <div>
                                    <label for="country" class="block text-sm font-medium text-gray-700">Country</label>
                                    <input type="text" name="country" id="country" required readonly
                                               value="<?php echo htmlspecialchars($formData['country'] ?? 'United Kingdom'); ?>" 
                                           class="mt-1 block w-full rounded-md bg-gray-100 border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    </div>
                                </div>
                            </div>
                         </div>

                        <div>
                            <label for="delivery_date" class="block text-sm font-medium text-gray-700">Delivery Date</label>
                            <input type="date" name="delivery_date" id="delivery_date"
                                   value="<?php echo htmlspecialchars($formData['delivery_date'] ?? ''); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>"
                                   <?php echo $is_delivered ? 'disabled' : ''; ?>>
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea name="notes" id="notes" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>"
                                      <?php echo $is_delivered ? 'disabled' : ''; ?>><?php echo htmlspecialchars($formData['notes'] ?? ''); ?></textarea>
                        </div>

                        <!-- Products Section -->
                         <div class="space-y-4 pt-6 border-t">
                            <h3 class="text-lg font-medium text-gray-900">Products</h3>
                             <?php if ($order['status'] !== 'pending'): ?>
                                <p class="text-sm text-gray-500 bg-gray-100 p-2 rounded">Products cannot be changed unless the order is 'pending'.</p>
                            <?php endif; ?>
                            <div id="products-container">
                                <?php if (!empty($formData['product_ids'])): ?>
                                    <?php foreach ($formData['product_ids'] as $index => $product_id): ?>
                                        <div class="product-row grid grid-cols-3 gap-4 mb-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Product *</label>
                                                <select name="product_ids[]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm product-select <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>" <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" 
                                                                <?php echo $product['id'] == $product_id ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($product['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                                <input type="number" name="quantities[]" required min="1" 
                                                       value="<?php echo htmlspecialchars($formData['quantities'][$index] ?? '1'); ?>"
                                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm quantity-input <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>" <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                            </div>
                                            <div class="flex items-end">
                                                 <?php if ($order['status'] === 'pending'): ?>
                                                <button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">
                                                    Remove
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="prices[]" value="0" class="price-input">
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Default row if order had no products (should not happen ideally) -->
                                    <div class="product-row grid grid-cols-3 gap-4 mb-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Product *</label>
                                            <select name="product_ids[]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm product-select <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>" <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                                <option value="">Select Product</option>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo $product['id']; ?>">
                                                        <?php echo htmlspecialchars($product['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                            <input type="number" name="quantities[]" required min="1" value="1"
                                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm quantity-input <?php echo $order['status'] !== 'pending' ? 'bg-gray-100' : ''; ?>" <?php echo $order['status'] !== 'pending' ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="flex items-end">
                                             <?php if ($order['status'] === 'pending'): ?>
                                            <button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">
                                                Remove
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="prices[]" value="0" class="price-input">
                                    </div>
                                <?php endif; ?>
                            </div>
                             <?php if ($order['status'] === 'pending'): ?>
                                <button type="button" id="add-product-btn" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                                Add Another Product
                            </button>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status *</label>
                            <select name="status" id="status" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 <?php echo $is_delivered ? 'bg-gray-100' : ''; ?>"
                                    <?php echo $is_delivered ? 'disabled' : ''; ?>>
                                <option value="pending" <?php echo $formData['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="assigned" <?php echo $formData['status'] == 'assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="delivering" <?php echo $formData['status'] == 'delivering' ? 'selected' : ''; ?>>Delivering</option>
                                <option value="delivered" <?php echo $formData['status'] == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                <option value="failed" <?php echo $formData['status'] == 'failed' ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>

                        <!-- Proof Requirements Section -->
                        <div class="space-y-4 pt-6 mt-6 border-t">
                             <h3 class="text-lg font-medium text-gray-900">Proof Requirements</h3>
                              <?php if ($order['status'] !== 'pending'): ?>
                                 <p class="text-sm text-gray-500 bg-gray-100 p-2 rounded">Proof requirements cannot be changed unless the order is 'pending'.</p>
                             <?php endif; ?>
                             <div class="space-y-4">
                                 <div class="flex items-start">
                                     <div class="flex items-center h-5">
                                         <input id="requires_image_proof" name="requires_image_proof" type="checkbox" value="1"
                                                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded <?php echo $order['status'] !== 'pending' ? 'bg-gray-200' : ''; ?>"
                                                <?php echo !empty($formData['requires_image_proof']) ? 'checked' : ''; ?>
                                                <?php echo ($order['status'] !== 'pending') ? 'disabled' : ''; ?>>
                                     </div>
                                     <div class="ml-3 text-sm">
                                         <label for="requires_image_proof" class="font-medium <?php echo ($order['status'] !== 'pending') ? 'text-gray-500' : 'text-gray-700'; ?>">Require Image Proof</label>
                                         <p class="text-gray-500">Rider must upload an image as proof of delivery.</p>
                                     </div>
                                 </div>
                                 <div class="flex items-start">
                                     <div class="flex items-center h-5">
                                         <input id="requires_signature_proof" name="requires_signature_proof" type="checkbox" value="1"
                                                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded <?php echo $order['status'] !== 'pending' ? 'bg-gray-200' : ''; ?>"
                                                <?php echo !empty($formData['requires_signature_proof']) ? 'checked' : ''; ?>
                                                <?php echo ($order['status'] !== 'pending') ? 'disabled' : ''; ?>>
                                     </div>
                                     <div class="ml-3 text-sm">
                                         <label for="requires_signature_proof" class="font-medium <?php echo ($order['status'] !== 'pending') ? 'text-gray-500' : 'text-gray-700'; ?>">Require Signature Proof</label>
                                         <p class="text-gray-500">Rider must capture a signature as proof of delivery.</p>
                                     </div>
                                 </div>
                             </div>
                        </div>

                        <input type="hidden" name="total_amount" id="total_amount" value="0">
                        <input type="hidden" name="force_address_validation_skip" id="force_address_validation_skip" value="0">

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <?php if (!$is_delivered): ?>
                                <button type="button" id="forceUpdateBtn" class="bg-orange-500 text-white px-4 py-2 rounded-md hover:bg-orange-600">Force Update Order</button>
                            <?php endif; ?>
                            <button type="submit" 
                                    class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 <?php echo $is_delivered ? 'opacity-50 cursor-not-allowed' : ''; ?>" 
                                    <?php echo $is_delivered ? 'disabled' : ''; ?>>Update Order</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
             // Debounce function (keep as is)
            function debounce(func, wait) {
                let timeout;
                return function executedFunction(...args) {
                    const later = () => {
                        clearTimeout(timeout);
                        func(...args);
                    };
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                };
            }
    
            // Global variable to store fetched customer data by ID
            let customerCache = {};
            
            // Store original details fetched from PHP
             const originalData = {
                customerId: $('#original_customer_id').val(),
                customerName: <?php echo json_encode(htmlspecialchars($order['customer_name'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                email: <?php echo json_encode(htmlspecialchars($order['email'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                phone: <?php echo json_encode(htmlspecialchars(preg_replace('/^\+44/', '', $order['phone'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>,
                addressId: $('#original_address_id').val(),
                address_line1: <?php echo json_encode(htmlspecialchars($order['address_line1'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                address_line2: <?php echo json_encode(htmlspecialchars($order['address_line2'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                city: <?php echo json_encode(htmlspecialchars($order['city'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                state: <?php echo json_encode(htmlspecialchars($order['state'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                postal_code: <?php echo json_encode(htmlspecialchars($order['postal_code'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                country: <?php echo json_encode(htmlspecialchars($order['country'] ?? 'United Kingdom', ENT_QUOTES, 'UTF-8')); ?>
            };
    
            // Function to enable/disable only the address fields
             function setAddressFieldsState(disabled) {
                 const fields = ['#address_line1', '#address_line2', '#city', '#state', '#postal_code'];
                 fields.forEach(selector => {
                     $(selector).prop('disabled', disabled)
                                .toggleClass('bg-gray-100', disabled);
                 });
             }
    
            // Function to fill address fields (revised to not disable fields here)
            function fillAddressFields(address) {
                $('#address_line1').val(address.address_line1 || '');
                $('#address_line2').val(address.address_line2 || '');
                $('#city').val(address.city || '');
                $('#state').val(address.state || '');
                $('#postal_code').val(address.postal_code || '');
                $('#country').val(address.country || 'United Kingdom');
                // Set the *actual* hidden input used for submission
                $('#selected_address_id').val(address.id || ''); // Use empty string if no ID (new address)
            }
            
            // Simplified function to reset form to original order values
            function resetToOriginalValues() {
                 $('#customer_id').val(originalData.customerId);
                 $('#selected_address_id').val(originalData.addressId);
                 $('#selected-customer-info').text('').hide();
                 $('#customer_search').val(''); // Clear search box
                 
                 $('#customer_name').val(originalData.customerName).prop('disabled', false).removeClass('bg-gray-100'); // Ensure enabled
                 $('#email').val(originalData.email).prop('disabled', false).removeClass('bg-gray-100');
                 $('#phone').val(originalData.phone).prop('disabled', false).removeClass('bg-gray-100');
                 
                 $('#address_selector').empty()
                     .append('<option value="">-- Select Saved Address --</option>') // Changed placeholder
                     .append('<option value="_new">-- Enter New Address Below --</option>')
                     .val(''); // Reset selection
                 $('#address-selector-container').hide(); // Hide selector initially
                 
                 // Restore original address fields
                 fillAddressFields(originalData); // fillAddressFields now sets hidden #selected_address_id
                 setAddressFieldsState(false); // Ensure address fields are enabled after reset
                 
                 // Re-apply disabled state if order is NOT pending
                 if ($('#order_status').val() !== 'pending') {
                    $('#customer_name, #email, #phone').prop('disabled', true).addClass('bg-gray-100');
                    setAddressFieldsState(true);
                 }
                 
                 console.log("Reset to original order values.");
            }
            
            $(document).ready(function () {
                // --- Cache DOM Elements ---
                const customerSearchInput = $('#customer_search');
                const suggestionsContainer = $('#customer-suggestions');
                const customerIdInput = $('#customer_id'); // Hidden input for final customer ID
                const selectedAddressIdInput = $('#selected_address_id'); // Hidden input for final address ID
                const addressSelectorContainer = $('#address-selector-container');
                const addressSelector = $('#address_selector'); // The dropdown itself
                const addressFieldsDiv = $('#address-fields');
                const selectedCustomerInfo = $('#selected-customer-info');
                const orderStatus = $('#order_status').val();
                const isOrderPending = orderStatus === 'pending';

                // Global variable to store fetched customer data by ID
                let customerCache = {};

                // Store original details fetched from PHP
                const originalData = {
                    customerId: $('#original_customer_id').val(),
                    customerName: <?php echo json_encode(htmlspecialchars($order['customer_name'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                    email: <?php echo json_encode(htmlspecialchars($order['email'] ?? '', ENT_QUOTES, 'UTF-8')); ?>,
                    phone: <?php echo json_encode(htmlspecialchars(preg_replace('/^\+44/', '', $order['phone'] ?? ''), ENT_QUOTES, 'UTF-8')); ?>,
                    addressId: $('#original_address_id').val(),
                    address_line1: <?php echo json_encode(htmlspecialchars($order['address_line1'] ?? '', ENT_QUOTES, 'UTF-8')); ?> ,
                    address_line2: <?php echo json_encode(htmlspecialchars($order['address_line2'] ?? '', ENT_QUOTES, 'UTF-8')); ?> ,
                    city: <?php echo json_encode(htmlspecialchars($order['city'] ?? '', ENT_QUOTES, 'UTF-8')); ?> ,
                    state: <?php echo json_encode(htmlspecialchars($order['state'] ?? '', ENT_QUOTES, 'UTF-8')); ?> ,
                    postal_code: <?php echo json_encode(htmlspecialchars($order['postal_code'] ?? '', ENT_QUOTES, 'UTF-8')); ?> ,
                    country: <?php echo json_encode(htmlspecialchars($order['country'] ?? 'United Kingdom', ENT_QUOTES, 'UTF-8')); ?>
                };

                // Initial addresses from PHP for the original customer
                const initialAddresses = <?php echo json_encode($current_customer_addresses); ?> ;

                // --- Helper Functions ---

                function setCustomerFieldsState(disabled) {
                    // console.log('Setting customer fields state:', disabled);
                    const fields = ['#customer_name', '#email', '#phone'];
                    fields.forEach(selector => {
                        $(selector).prop('disabled', disabled)
                                   .toggleClass('bg-gray-100', disabled);
                    });
                }

                function setAddressFieldsState(disabled) {
                    // console.log('Setting address fields state:', disabled);
                    const fields = ['#address_line1', '#address_line2', '#city', '#state', '#postal_code'];
                    fields.forEach(selector => {
                        $(selector).prop('disabled', disabled)
                                   .toggleClass('bg-gray-100', disabled);
                    });
                }

                function fillAddressFields(address) {
                    // console.log('Filling address fields with:', address);
                    $('#address_line1').val(address.address_line1 || '');
                    $('#address_line2').val(address.address_line2 || '');
                    $('#city').val(address.city || '');
                    $('#state').val(address.state || '');
                    $('#postal_code').val(address.postal_code || '');
                    $('#country').val(address.country || 'United Kingdom');
                    selectedAddressIdInput.val(address.id || ''); // Set hidden input
                }

                function populateAddressDropdown(customerId, addresses, selectedAddressId) {
                    // console.log(`Populating address dropdown for customer ${customerId}, selected: ${selectedAddressId}`);
                    addressSelector.empty()
                        .append('<option value="">-- Select Saved Address --</option>')
                        .append('<option value="_new">-- Enter New Address Below --</option>');
                    let addressFoundInList = false;
                    if (addresses && addresses.length > 0) {
                        addresses.forEach(function(addr) {
                            const addressText = `${addr.address_line1}, ${addr.city}, ${addr.postal_code}`;
                            const option = $('<option></option>').attr('value', addr.id).text(addressText);
                            if (addr.id && addr.id == selectedAddressId) { // Ensure comparison is valid
                                option.prop('selected', true);
                                addressFoundInList = true;
                            }
                            addressSelector.append(option);
                        });
                        addressSelectorContainer.show();
                    } else {
                        addressSelectorContainer.hide();
                    }
                    return addressFoundInList;
                }

                function populateInitialUIState() {
                    // console.log('Populating initial UI state. Order pending:', isOrderPending);
                    if (!isOrderPending) {
                        // If not pending, ensure search and selector are hidden (PHP should handle disabling fields)
                        customerSearchInput.hide();
                        addressSelectorContainer.hide();
                        return; // No need for further JS setup for addresses
                    }

                    // If pending, populate address dropdown for the initial customer
                    if (originalData.customerId && initialAddresses.length > 0) {
                        const initialAddressWasSelected = populateAddressDropdown(originalData.customerId, initialAddresses, originalData.addressId);
                        if (initialAddressWasSelected) {
                            setAddressFieldsState(true); // Disable fields if matching initial selection
                        } else {
                            // Original address ID exists but not in the list? Or no original ID?
                            if (originalData.addressId && originalData.addressId !== '') {
                                // Select "_new" if an invalid/old ID was present
                                addressSelector.val('_new');
                            }
                            setAddressFieldsState(false); // Enable fields otherwise
                        }
                    } else {
                        // No initial customer or no addresses for them
                        addressSelectorContainer.hide();
                        setAddressFieldsState(false); // Enable fields for manual entry
                    }
                     // Set initial state for customer fields (should be enabled if pending)
                     setCustomerFieldsState(false);
                }

                 function resetToOriginalValues() {
                    console.log("Resetting to original order values.");
                    customerIdInput.val(originalData.customerId);
                    selectedAddressIdInput.val(originalData.addressId);
                    selectedCustomerInfo.text('').hide();
                    customerSearchInput.val('');
                    suggestionsContainer.hide().empty();
                    customerCache = {}; // Clear cache

                    $('#customer_name').val(originalData.customerName);
                    $('#email').val(originalData.email);
                    $('#phone').val(originalData.phone);
                    setCustomerFieldsState(false); // Ensure enabled if pending

                    fillAddressFields(originalData); // Restore original address fields and hidden ID
                    populateInitialUIState(); // Re-run initial setup for addresses
                }

                // --- Initial Setup ---
                populateInitialUIState();

                // --- Event Handlers (Only if order is pending) ---
                if (isOrderPending) {

                    // --- Customer Search --- 
                    const handleSearch = debounce(function() {
                        const query = customerSearchInput.val().trim();
                        suggestionsContainer.hide().empty(); // Hide and clear previous suggestions
                        customerCache = {}; // Clear cache on new search

                        if (query.length < 2) { // Minimum query length
                            if (customerIdInput.val() !== originalData.customerId) {
                                resetToOriginalValues();
                            }
                            return;
                        }

                        // console.log('Searching customers for:', query);
                        $.ajax({
                            url: '../../api/admin/search_customers.php',
                            method: 'GET',
                            data: { query: query },
                            dataType: 'json',
                            success: function(data) {
                                // console.log('Customer search results:', data);
                                if (data && data.length > 0) {
                                    data.forEach(function(customer) {
                                        // IMPORTANT: Ensure API returns addresses array
                                        customerCache[customer.id] = customer;
                                        let displayText = customer.name;
                                        if (customer.email) displayText += ` (${customer.email})`;
                                        else if (customer.phone) displayText += ` (${customer.phone})`;

                                        suggestionsContainer.append(
                                            $('<div data-id="' + customer.id + '" class="p-2 hover:bg-gray-100 cursor-pointer"></div>').text(displayText)
                                        );
                                    });
                                    suggestionsContainer.show();
                                } else {
                                    suggestionsContainer.append('<div class="p-2 text-gray-500">No customers found. You can modify details below.</div>');
                                    suggestionsContainer.show();
                                    if (customerIdInput.val() !== originalData.customerId) {
                                        resetToOriginalValues();
                                    }
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                console.error("Customer search error:", textStatus, errorThrown, jqXHR.responseText);
                                suggestionsContainer.append('<div class="p-2 text-red-500">Error searching customers. Check API.</div>');
                                suggestionsContainer.show();
                                 if (customerIdInput.val() !== originalData.customerId) {
                                    resetToOriginalValues();
                                 }
                            }
                        });
                    }, 300);

                    customerSearchInput.on('keyup', handleSearch);

                     // Reset if search input is manually cleared
                    customerSearchInput.on('input', function() {
                        if ($(this).val().trim() === '' && customerIdInput.val() !== originalData.customerId) {
                            resetToOriginalValues();
                        }
                    });

                    // Suggestion Selection
                    suggestionsContainer.on('click', 'div[data-id]', function() {
                        const selectedId = $(this).data('id');
                        const selectedCustomer = customerCache[selectedId];
                        console.log('Suggestion clicked. Customer:', selectedCustomer);

                        if (selectedCustomer) {
                            customerSearchInput.val('');
                            customerIdInput.val(selectedCustomer.id);
                            suggestionsContainer.hide().empty();

                            selectedCustomerInfo.text(`Selected: ${selectedCustomer.name} (${selectedCustomer.email || selectedCustomer.phone || 'No contact'})`).show();

                            // Fill customer text fields and disable them
                            $('#customer_name').val(selectedCustomer.name);
                            $('#email').val(selectedCustomer.email || '');
                            $('#phone').val(selectedCustomer.phone ? selectedCustomer.phone.replace(/^\+44/,'') : '');
                            setCustomerFieldsState(true); // Disable direct editing of customer fields

                            // Make sure to clear selected address ID if selecting a new customer
                            if (selectedCustomer.id != originalData.customerId) {
                                selectedAddressIdInput.val('');
                            }

                            // Handle Addresses
                            let defaultAddress = null;
                            let defaultAddressId = '';
                            
                            // Check if customer has addresses
                            if (selectedCustomer.addresses && Array.isArray(selectedCustomer.addresses) && selectedCustomer.addresses.length > 0) {
                                // Find default address
                                defaultAddress = selectedCustomer.addresses.find(addr => addr.is_default === true || addr.is_default === 1);
                                defaultAddressId = defaultAddress ? defaultAddress.id : '';
                                
                                const addressSelected = populateAddressDropdown(selectedCustomer.id, selectedCustomer.addresses, defaultAddressId);
                                
                                if (addressSelected && defaultAddress) {
                                    fillAddressFields(defaultAddress); // Fill with default
                                    selectedAddressIdInput.val(defaultAddress.id); // Ensure the hidden field is updated
                                    setAddressFieldsState(true); // Disable address fields
                                } else {
                                    // No default found - select "_new" for new address
                                    addressSelector.val('_new');
                                    fillAddressFields({}); // Clear fields 
                                    selectedAddressIdInput.val(''); // Clear hidden address ID
                                    setAddressFieldsState(false); // Enable address fields
                                }
                                
                                // Always show the address selector when addresses exist
                                addressSelectorContainer.show();
                            } else {
                                // No addresses for this customer
                                console.log('No addresses found for this customer');
                                addressSelector.empty()
                                    .append('<option value="">-- Select Saved Address --</option>')
                                    .append('<option value="_new" selected>-- Enter New Address Below --</option>');
                                    
                                fillAddressFields({}); // Clear fields
                                selectedAddressIdInput.val(''); // Clear hidden address ID
                                setAddressFieldsState(false); // Enable address fields
                                
                                // Hide selector when no saved addresses
                                addressSelectorContainer.hide();
                            }

                        } else {
                             console.error("Selected customer data not found in cache for ID:", selectedId);
                             resetToOriginalValues();
                        }
                    });

                     // Address Selection Change
                     addressSelector.on('change', function() {
                        const selectedAddrOption = $(this).val();
                        const currentCustomerId = customerIdInput.val();
                        console.log(`Address selector changed to: ${selectedAddrOption}, Customer ID: ${currentCustomerId}`);

                        if (selectedAddrOption === '_new') {
                             fillAddressFields({ id: '' }); // Clear fields, set hidden ID to empty
                             selectedAddressIdInput.val(''); // Ensure hidden field is explicitly cleared
                             setAddressFieldsState(false); // Enable fields
                        } else if (selectedAddrOption && selectedAddrOption !== '') {
                            // Find the selected address in the appropriate cache/data
                            let selectedAddress = null;
                            if (currentCustomerId == originalData.customerId) {
                                selectedAddress = initialAddresses.find(addr => addr.id == selectedAddrOption);
                            } else if (customerCache[currentCustomerId] && customerCache[currentCustomerId].addresses) {
                                selectedAddress = customerCache[currentCustomerId].addresses.find(addr => addr.id == selectedAddrOption);
                            }

                            if (selectedAddress) {
                                console.log('Found selected address:', selectedAddress);
                                fillAddressFields(selectedAddress); // Fills fields and sets hidden ID
                                selectedAddressIdInput.val(selectedAddrOption); // Explicitly set the hidden input
                                setAddressFieldsState(true); // Disable fields for existing selection
                            } else {
                                 console.error("Selected address data not found for ID:", selectedAddrOption);
                                fillAddressFields({ id: '' });
                                selectedAddressIdInput.val('');
                                setAddressFieldsState(false);
                                addressSelector.val('_new');
                            }
                        } else {
                            // Placeholder "-- Select Saved Address --" selected
                            fillAddressFields({ id: '' });
                            selectedAddressIdInput.val('');
                            setAddressFieldsState(false);
                        }
                    });

                    // Add Product Row button - logic unchanged
                     $('#add-product-btn')?.click(function() {
                         const container = $('#products-container');
                         const lastRow = container.find('.product-row:last');
                         if (lastRow.length === 0) {
                              console.error("Cannot add product row - no template row found.");
                              return;
                         }
                         const newRow = lastRow.clone();
                         newRow.find('select').val('').prop('disabled', false).removeClass('bg-gray-100');
                         newRow.find('input[type="number"]').val('1').prop('disabled', false).removeClass('bg-gray-100');
                         newRow.find('input[type="hidden"]').val('0');
                         const removeButton = newRow.find('button[onclick="removeProductRow(this)"]');
                         if (removeButton.length === 0) {
                             const buttonDiv = newRow.find('.flex.items-end');
                             if (buttonDiv.length > 0) {
                                  buttonDiv.html(
                                     '<button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">Remove</button>'
                                 );
                             } else {
                                console.error("Could not find button container div in cloned row");
                             }
                         } else {
                              removeButton.show();
                         }
                         container.append(newRow);
                     });

                } // End if(isOrderPending)

                // --- Global Event Handlers ---

                // Hide suggestions if clicked outside
                $(document).on('click', function(event) {
                    if (!$(event.target).closest('#customer_search, #customer-suggestions').length) {
                        suggestionsContainer.hide();
                    }
                });

                // Ensure disabled fields have their values submitted
                $('#orderForm').on('submit', function() {
                    // Check if customer fields are disabled, get their values and add as hidden fields
                    if ($('#customer_name').prop('disabled')) {
                        const customerName = $('#customer_name').val();
                        const email = $('#email').val();
                        const phone = $('#phone').val();
                        
                        // Remove any existing hidden fields
                        $('#hidden-customer-fields').remove();
                        
                        // Add hidden fields with current values
                        const hiddenFields = $('<div id="hidden-customer-fields" style="display:none;"></div>');
                        hiddenFields.append(`<input type="hidden" name="customer_name" value="${customerName}">`);
                        hiddenFields.append(`<input type="hidden" name="email" value="${email}">`);
                        hiddenFields.append(`<input type="hidden" name="phone" value="${phone}">`);
                        
                        $(this).append(hiddenFields);
                    }
                    
                    // Similarly for address fields
                    const addressFieldsDisabled = $('#address_line1').prop('disabled');
                    if (addressFieldsDisabled) {
                        const addressFields = {
                            address_line1: $('#address_line1').val(),
                            address_line2: $('#address_line2').val(),
                            city: $('#city').val(),
                            state: $('#state').val(), 
                            postal_code: $('#postal_code').val(),
                            country: $('#country').val()
                        };
                        
                        // Remove any existing hidden fields
                        $('#hidden-address-fields').remove();
                        
                        // Add hidden fields with current values
                        const hiddenAddressFields = $('<div id="hidden-address-fields" style="display:none;"></div>');
                        Object.keys(addressFields).forEach(key => {
                            hiddenAddressFields.append(`<input type="hidden" name="${key}" value="${addressFields[key]}">`);
                        });
                        
                        $(this).append(hiddenAddressFields);
                    }
                    
                    return true;
                });

                // --- Force Update Button Handler ---
                $('#forceUpdateBtn').on('click', function() {
                    // Set the hidden field to indicate force
                    $('#force_address_validation_skip').val('1');
                    // Submit the form
                    $('#orderForm').submit();
                });
                // --- End Force Update Button Handler ---

            }); // End $(document).ready()

            // Keep removeProductRow function accessible globally
            function removeProductRow(button) {
                const productRows = document.querySelectorAll('.product-row');
                if (productRows.length > 1) {
                    button.closest('.product-row').remove();
                } else {
                    alert('At least one product is required.');
                }
            }
        </script>
    </div>
</body>
</html>