<?php
require_once '../../includes/config.php';
include('../../server/log_helper.php');

function logException($exceptionMessage) {
    $logFile = __DIR__ . '/order_exception_log.log';  // Log stored in current folder
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] Exception: $exceptionMessage" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}
requireLogin();

$error = '';
$success = '';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Generate unique order number
function generateOrderNumber($conn)
{
    do {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check_query = "SELECT id FROM Orders WHERE order_number = '$order_number'";
        $result = mysqli_query($conn, $check_query);
    } while (mysqli_num_rows($result) > 0);

    return $order_number;
}

// Fetch companies for super admin
$companies = [];
if (isSuperAdmin()) {
    $companies_query = "SELECT id, name FROM Companies ORDER BY name";
    $companies_result = mysqli_query($conn, $companies_query);
    while ($row = mysqli_fetch_assoc($companies_result)) {
        $companies[] = $row;
    }
}

// Get companies for dropdown
$companies = [];
if (isSuperAdmin()) {
    $company_query = "SELECT id, name FROM Companies ORDER BY name";
    $company_result = mysqli_query($conn, $company_query);
    while ($row = mysqli_fetch_assoc($company_result)) {
        $companies[] = $row;
    }
}

// Get organizations for the selected company
$organizations = [];
$company_id = isSuperAdmin() ? (isset($_POST['company_id']) ? (int)$_POST['company_id'] : (isset($formData['company_id']) ? (int)$formData['company_id'] : 0)) : $_SESSION['company_id']; // Also check formData on initial load

if ($company_id) {
    $org_query = "SELECT id, name FROM Organizations WHERE company_id = ? AND is_active = 1 ORDER BY name";
    $org_stmt = mysqli_prepare($conn, $org_query);
    mysqli_stmt_bind_param($org_stmt, "i", $company_id);
    mysqli_stmt_execute($org_stmt);
    $org_result = mysqli_stmt_get_result($org_stmt);
    while ($row = mysqli_fetch_assoc($org_result)) {
        $organizations[] = $row;
    }
    mysqli_stmt_close($org_stmt); // Close statement
}

// Get company ID for product fetching
$current_company_id = isSuperAdmin() ?
    (isset($_POST['company_id']) ? cleanInput($_POST['company_id']) : (isset($formData['company_id']) ? cleanInput($formData['company_id']) : 0)) :
    $_SESSION['company_id'];

// Fetch products for the selected company
$products = [];
if ($current_company_id) {
    $products_query = "SELECT id, name, description FROM Products WHERE company_id = ? ORDER BY name"; // Removed price
    $stmt = mysqli_prepare($conn, $products_query);
    mysqli_stmt_bind_param($stmt, "i", $current_company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $products[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Function to validate UK postcodes (Keep this helper)
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

// Updated Mapbox validation function
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
             // If postcode not found by Mapbox, maybe reject? Or rely on the full address check?
             // For now, let's allow it to proceed to full address check but log it.
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
    
    // Optional stricter check: Relevance score (e.g., > 0.7)
    /*
    if (!isset($feature['relevance']) || $feature['relevance'] <= 0.7) {
         error_log("Mapbox Validation: Result found, but relevance score is too low: " . ($feature['relevance'] ?? 'N/A'));
         return false;
    }
    */

    error_log("Mapbox Validation: Address successfully validated as type 'address'. Feature ID: " . ($feature['id'] ?? 'N/A'));
    return true; // Address seems valid and specific
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

// Initialize form data
$formData = [
    'customer_name' => '',
    'email' => '',
    'phone' => '',
    'address_line1' => '',
    'address_line2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => 'United Kingdom', // Default country
    'notes' => '',
    'company_id' => (isSuperAdmin() ? '' : $_SESSION['company_id']),
    'delivery_date' => '',
    'product_ids' => [],
    'quantities' => [],
    'organization_id' => null,
    'requires_image_proof' => 0, // Default to unchecked
    'requires_signature_proof' => 0, // Default to unchecked
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['just_company_change']) && $_POST['just_company_change'] === '1') {
        // Reload organizations based on company change (Existing logic)
        $current_company_id = isSuperAdmin() ? (int)$_POST['company_id'] : $_SESSION['company_id'];
        $organizations = [];
        if ($current_company_id) {
            $org_query = "SELECT id, name FROM Organizations WHERE company_id = ? AND is_active = 1 ORDER BY name";
            $org_stmt = mysqli_prepare($conn, $org_query);
            mysqli_stmt_bind_param($org_stmt, "i", $current_company_id);
            mysqli_stmt_execute($org_stmt);
            $org_result = mysqli_stmt_get_result($org_stmt);
            while ($row = mysqli_fetch_assoc($org_result)) {
                $organizations[] = $row;
            }
        }
         $formData = $_POST; // Repopulate form slightly differently on company change
         $formData['requires_image_proof'] = isset($_POST['requires_image_proof']) ? 1 : 0;
         $formData['requires_signature_proof'] = isset($_POST['requires_signature_proof']) ? 1 : 0;
    } else {
        // --- Form Submission Logic --- 
        $is_new_customer = !isset($_POST['customer_id']) || empty($_POST['customer_id']);
        $customer_id = !$is_new_customer ? (int)$_POST['customer_id'] : null;
        
        // Correctly check if a new address is intended via the dropdown value
        $selected_address_option = $_POST['selected_address_id'] ?? null; 
        $is_new_address = ($selected_address_option === '_new' || empty($selected_address_option));
        $address_id = (!$is_new_address && !empty($selected_address_option)) ? (int)$selected_address_option : null;

        // Basic data cleaning (as before)
        $customer_name = cleanInput($_POST['customer_name'] ?? '');
        $email = cleanInput($_POST['email'] ?? '');
        $phone_raw = preg_replace('/[^0-9]/', '', cleanInput($_POST['phone'] ?? ''));
        $phone = '+44' . $phone_raw;
        $address_line1 = cleanInput($_POST['address_line1'] ?? '');
        $address_line2 = cleanInput($_POST['address_line2'] ?? '');
        $city = cleanInput($_POST['city'] ?? '');
        $state = cleanInput($_POST['state'] ?? '');
        $postal_code = cleanInput($_POST['postal_code'] ?? '');
        $country = cleanInput($_POST['country'] ?? '');
        $notes = cleanInput($_POST['notes'] ?? '');
        $company_id = isSuperAdmin() ? cleanInput($_POST['company_id'] ?? '') : $_SESSION['company_id'];
        $delivery_date = !empty($_POST['delivery_date']) ? cleanInput($_POST['delivery_date']) : null;
        $status = 'pending'; // Default status for new orders
        $product_ids = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
        $product_quantities = isset($_POST['quantities']) ? $_POST['quantities'] : [];
        $organization_id = isset($_POST['organization_id']) && !empty($_POST['organization_id']) ? (int)$_POST['organization_id'] : null;
        $requires_image_proof = isset($_POST['requires_image_proof']) ? 1 : 0;
        $requires_signature_proof = isset($_POST['requires_signature_proof']) ? 1 : 0;
        $force_skip_address_validation = isset($_POST['force_address_validation_skip']) && $_POST['force_address_validation_skip'] === '1';

        // --- Validation --- (Similar to edit.php, adapted for create)
        if (empty($customer_name) && $is_new_customer) $error = 'Customer Name is required for new customers.';
        elseif (empty($product_ids)) $error = 'Please select at least one product.';
        elseif (empty($email) && $is_new_customer) $error = 'Email is required for new customers.'; // Made required for new
        elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Invalid email format.';
        elseif (!empty($phone_raw) && !validateUKPhoneNumber($phone)) $error = 'Invalid UK phone number format.';
        elseif (empty($address_line1)) $error = 'Address Line 1 is required.';
        elseif (empty($city)) $error = 'City is required.';
        elseif (empty($postal_code)) $error = 'Postal Code is required.';
        // --- Skip Address Validation if Force Flag is Set ---
        elseif (!$force_skip_address_validation && !validateUKAddressWithMapbox($address_line1, $city, $postal_code, $country, $address_line2)) {
            $error = 'Address could not be verified or is invalid. Please check postcode, city and address line 1. You can use "Force Create" to bypass this check.';
        } else {
            // --- Database Operations ---
                mysqli_begin_transaction($conn);
            try {
                // 1. Handle Customer
              
                if ($is_new_customer) {
                    // Check if customer exists by email or phone (if provided)
                    $existing_customer_id = null;
                    if (!empty($email)) {
                        $check_cust_sql = "SELECT id FROM Customers WHERE email = ?";
                        $check_cust_stmt = mysqli_prepare($conn, $check_cust_sql);
                        mysqli_stmt_bind_param($check_cust_stmt, "s", $email);
                        mysqli_stmt_execute($check_cust_stmt);
                        $check_cust_res = mysqli_stmt_get_result($check_cust_stmt);
                        if ($row = mysqli_fetch_assoc($check_cust_res)) $existing_customer_id = $row['id'];
                        mysqli_stmt_close($check_cust_stmt);
                    }
                    if (!$existing_customer_id && !empty($phone)) {
                         $check_cust_sql = "SELECT id FROM Customers WHERE phone = ?";
                        $check_cust_stmt = mysqli_prepare($conn, $check_cust_sql);
                        mysqli_stmt_bind_param($check_cust_stmt, "s", $phone);
                        mysqli_stmt_execute($check_cust_stmt);
                        $check_cust_res = mysqli_stmt_get_result($check_cust_stmt);
                        if ($row = mysqli_fetch_assoc($check_cust_res)) $existing_customer_id = $row['id'];
                        mysqli_stmt_close($check_cust_stmt);
                    }

                    if ($existing_customer_id) {
                        // Use existing customer ID
                        $customer_id = $existing_customer_id;
                        error_log("Using existing customer ID based on email/phone: $customer_id");
                        // Maybe update name/phone/email if provided?
                    } else {
                        // Insert new customer
                        $insert_cust_sql = "INSERT INTO Customers (company_id, name, email, phone) VALUES (?, ?, ?, ?)";
                        $insert_cust_stmt = mysqli_prepare($conn, $insert_cust_sql);
                        mysqli_stmt_bind_param($insert_cust_stmt, "isss", $company_id, $customer_name, $email, $phone);
                        if (!mysqli_stmt_execute($insert_cust_stmt)) throw new Exception("Failed to create customer: " . mysqli_stmt_error($insert_cust_stmt));
                        $customer_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($insert_cust_stmt);
                        error_log("Created new customer ID: $customer_id");
                    }
                }
                
                // 2. Handle Address
                 if ($is_new_address) {
                    // Assume new address needs creating if not selected from search results
                    // Optionally: Add logic here to check if an IDENTICAL address already exists for this customer before inserting
                    $insert_addr_sql = "INSERT INTO Addresses (customer_id, address_line1, address_line2, city, state, postal_code, country, is_default) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $insert_addr_stmt = mysqli_prepare($conn, $insert_addr_sql);
                    $is_default_new = true; // Make the first manually entered address the default?
                    mysqli_stmt_bind_param($insert_addr_stmt, "issssssi", $customer_id, $address_line1, $address_line2, $city, $state, $postal_code, $country, $is_default_new);
                    if (!mysqli_stmt_execute($insert_addr_stmt)) throw new Exception("Failed to create address: " . mysqli_stmt_error($insert_addr_stmt));
                    $address_id = mysqli_insert_id($conn);
                     mysqli_stmt_close($insert_addr_stmt);
                    error_log("Created new address ID: $address_id");
                } else {
                    // Verify the selected address_id belongs to the customer_id
                    $verify_addr_sql = "SELECT id FROM Addresses WHERE id = ? AND customer_id = ?";
                    $verify_addr_stmt = mysqli_prepare($conn, $verify_addr_sql);
                    mysqli_stmt_bind_param($verify_addr_stmt, "ii", $address_id, $customer_id);
                    mysqli_stmt_execute($verify_addr_stmt);
                    if(mysqli_stmt_get_result($verify_addr_stmt)->num_rows === 0) {
                         throw new Exception("Selected address does not belong to the selected customer.");
                    }
                     mysqli_stmt_close($verify_addr_stmt);
                     error_log("Using selected address ID: $address_id for customer ID: $customer_id");
                }
                
                // 3. Create Order
                $order_number = generateOrderNumber($conn);
                $total_amount = 0; // Calculate based on products later if needed

                $insert_order_sql = "INSERT INTO Orders (order_number, company_id, customer_id, delivery_address_id, status, notes, total_amount, delivery_date, organization_id, requires_image_proof, requires_signature_proof) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $order_stmt = mysqli_prepare($conn, $insert_order_sql);
                mysqli_stmt_bind_param($order_stmt,  "siiissdssii",
                                     $order_number, $company_id, $customer_id, $address_id, $status, 
                                     $notes, $total_amount, $delivery_date, $organization_id,
                                     $requires_image_proof, $requires_signature_proof);

                if (!mysqli_stmt_execute($order_stmt)) {
                    throw new Exception("Failed to create order: " . mysqli_stmt_error($order_stmt));
                }
                $new_order_id = mysqli_insert_id($conn);

$user_id = $_SESSION['user_id'] ?? 'unknown';

writeLog('order.log', "Order #$new_order_id created by user #$user_id");

mysqli_stmt_close($order_stmt);

error_log("Created new order ID: $new_order_id");


                // 4. Insert Product Orders
                $product_query = "INSERT INTO ProductOrders (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                $product_stmt = mysqli_prepare($conn, $product_query);
                $product_prices = array_fill(0, count($product_ids), 0); // Assuming price isn't set here

                foreach ($product_ids as $index => $product_id) {
                    if (!empty($product_id)) {
                         $quantity = isset($product_quantities[$index]) ? (int)$product_quantities[$index] : 1;
                         $price = isset($product_prices[$index]) ? (float)$product_prices[$index] : 0.0;
                         if ($quantity > 0) { // Only insert if quantity is valid
                             mysqli_stmt_bind_param($product_stmt, "iiid", $new_order_id, $product_id, $quantity, $price);
                             if (!mysqli_stmt_execute($product_stmt)) throw new Exception("Failed to add product to order: " . mysqli_stmt_error($product_stmt));
                         }
                    }
                }
                mysqli_stmt_close($product_stmt);

                // 5. Add initial status log
                $log_query = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, "isi", $new_order_id, $status, $_SESSION['user_id']); // Use session user ID
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);

                mysqli_commit($conn);
                $success = 'Order created successfully (#' . $order_number . ')';
                // Clear form data after success?
                header("Location: index.php?success=" . urlencode($success)); // Redirect to index on success
                exit();

            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
                logException($error);

                // Repopulate formData from POST to preserve user input on error
                $formData = $_POST;
                 // Ensure array fields are arrays even if not submitted
                $formData['product_ids'] = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
                $formData['quantities'] = isset($_POST['quantities']) ? $_POST['quantities'] : [];
                $formData['requires_image_proof'] = isset($_POST['requires_image_proof']) ? 1 : 0;
                $formData['requires_signature_proof'] = isset($_POST['requires_signature_proof']) ? 1 : 0;

                // --- Robust Repopulation for Customer Fields ---
                if (!empty($_POST['customer_id']) && (empty($formData['customer_name']) || empty($formData['email']))) {
                    $re_customer_id = (int)$_POST['customer_id'];
                    $re_cust_sql = "SELECT name, email, phone FROM Customers WHERE id = ?";
                    $re_cust_stmt = mysqli_prepare($conn, $re_cust_sql);
                    if ($re_cust_stmt) {
                        mysqli_stmt_bind_param($re_cust_stmt, "i", $re_customer_id);
                        mysqli_stmt_execute($re_cust_stmt);
                        $re_cust_res = mysqli_stmt_get_result($re_cust_stmt);
                        if ($re_cust_data = mysqli_fetch_assoc($re_cust_res)) {
                            $formData['customer_name'] = $formData['customer_name'] ?? $re_cust_data['name'];
                            $formData['email'] = $formData['email'] ?? $re_cust_data['email'];
                            // Use the phone number from the DB if it wasn't submitted
                            if (empty($_POST['phone'])) { 
                                $formData['phone'] = $re_cust_data['phone']; // Keep the +44 format
                            } else {
                                // If submitted, keep the submitted (already formatted) version
                                $formData['phone'] = '+44' . preg_replace('/[^0-9]/', '', cleanInput($_POST['phone'] ?? '')); 
                            }
                        }
                        mysqli_stmt_close($re_cust_stmt);
                    } else {
                         error_log("Failed to prepare statement to re-fetch customer details on error.");
                    }
                }
                 // Reformat phone for display (remove +44) if it exists
                $formData['phone'] = isset($formData['phone']) ? preg_replace('/^\+44/', '', $formData['phone']) : '';
            }
        }
         // If validation failed before transaction, repopulate formData similarly
        if ($error) {
            $formData = $_POST;
            $formData['product_ids'] = isset($_POST['product_ids']) ? $_POST['product_ids'] : [];
            $formData['quantities'] = isset($_POST['quantities']) ? $_POST['quantities'] : [];
            $formData['requires_image_proof'] = isset($_POST['requires_image_proof']) ? 1 : 0;
            $formData['requires_signature_proof'] = isset($_POST['requires_signature_proof']) ? 1 : 0;
            
             // --- Robust Repopulation for Customer Fields ---
             if (!empty($_POST['customer_id']) && (empty($formData['customer_name']) || empty($formData['email']))) {
                $re_customer_id = (int)$_POST['customer_id'];
                $re_cust_sql = "SELECT name, email, phone FROM Customers WHERE id = ?";
                $re_cust_stmt = mysqli_prepare($conn, $re_cust_sql);
                if ($re_cust_stmt) {
                    mysqli_stmt_bind_param($re_cust_stmt, "i", $re_customer_id);
                    mysqli_stmt_execute($re_cust_stmt);
                    $re_cust_res = mysqli_stmt_get_result($re_cust_stmt);
                    if ($re_cust_data = mysqli_fetch_assoc($re_cust_res)) {
                        $formData['customer_name'] = $formData['customer_name'] ?? $re_cust_data['name'];
                        $formData['email'] = $formData['email'] ?? $re_cust_data['email'];
                        if (empty($_POST['phone'])) { 
                            $formData['phone'] = $re_cust_data['phone']; 
                        } else {
                             $formData['phone'] = '+44' . preg_replace('/[^0-9]/', '', cleanInput($_POST['phone'] ?? ''));
                        }
                    }
                    mysqli_stmt_close($re_cust_stmt);
                } else {
                     error_log("Failed to prepare statement to re-fetch customer details on validation error.");
                }
            }
             // Reformat phone for display (remove +44) if it exists
             $formData['phone'] = isset($formData['phone']) ? preg_replace('/^\+44/', '', $formData['phone']) : '';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Order - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-timepicker-addon/1.6.3/jquery-ui-timepicker-addon.min.css" />
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-ui-timepicker-addon/1.6.3/jquery-ui-timepicker-addon.min.js"></script>

    <!-- Add some basic styling for suggestions -->
    <style>
        #customer-suggestions {
            position: absolute;
            border: 1px solid #ccc;
            background-color: white;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000; /* Ensure it appears above other elements */
            width: calc(100% - 2rem); /* Match input width roughly */
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: none; /* Hidden by default */
            border-radius: 0 0 0.375rem 0.375rem; /* Match input rounding */
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
                    <h1 class="text-2xl font-bold text-gray-900">Create New Order</h1>
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
                    <form action="" method="POST" class="space-y-6" id="orderForm">
                        
                        <?php if (isSuperAdmin()): ?>
                            <div>
                                <label for="company_id" class="block text-sm font-medium text-gray-700">Company *</label>
                                <select name="company_id" id="company_id" required 
                                        onchange="this.form.just_company_change.value='1'; this.form.submit();" 
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select Company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>" 
                                                <?php echo (isset($formData['company_id']) && $company['id'] == $formData['company_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="just_company_change" value="0">
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="company_id" value="<?php echo $_SESSION['company_id']; ?>">
                        <?php endif; ?>

                        <!-- Customer Selection -->
                        <div class="relative">
                            <label for="customer_search" class="block text-sm font-medium text-gray-700">Search Existing Customer</label>
                            <input type="text" id="customer_search" 
                                   placeholder="Start typing name, email, or phone..." 
                                   autocomplete="off"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <div id="customer-suggestions" class="absolute mt-1 w-full rounded-md bg-white shadow-lg" style="display: none;"></div> 
                            <p class="mt-1 text-sm text-gray-500">Select a customer to auto-fill details or enter details below for a new customer.</p>
                             <input type="hidden" name="customer_id" id="customer_id">
                             <p id="selected-customer-info" class="mt-2 text-sm font-medium text-indigo-600"></p>
                        </div>

                         <!-- Customer Details (Potentially auto-filled or for new customer) -->
                         <div class="grid grid-cols-1 gap-6 md:grid-cols-2" id="customer-details-section">
                            <div>
                                <label for="customer_name" class="block text-sm font-medium text-gray-700">Customer Name *</label>
                                <input type="text" name="customer_name" id="customer_name" required
                                       value="<?php echo htmlspecialchars($formData['customer_name']); ?>"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                                <input type="email" name="email" id="email" required
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
                                           value="<?php echo htmlspecialchars(preg_replace('/^\+44/', '', $formData['phone'])); ?>"
                                           class="pl-12 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                           placeholder="7911123456">
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Enter number without leading 0 (e.g., 7911123456)</p>
                            </div>
                        </div>

                        <!-- Address Section -->
                        <div class="address-section space-y-4" id="address-section">
                             <h3 class="text-lg font-medium text-gray-900">Delivery Address</h3>
                             <!-- Address Selection (Populated by JS) -->
                             <div id="address-selector-container" style="display: none;">
                                 <label for="address_selector" class="block text-sm font-medium text-gray-700">Select Address</label>
                                 <select id="address_selector" name="selected_address_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                     <option value="">-- Select Saved Address --</option>
                                     <option value="_new">-- Enter New Address Below --</option>
                                 </select>
                             </div>
                            <!-- Address Fields -->
                            <div id="address-fields">
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
                        </div>

                        <!-- Add Organization selection -->
                        <div>
                             <label for="organization_id" class="block text-sm font-medium text-gray-700">Organization</label>
                             <select name="organization_id" id="organization_id"
                                     class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                 <option value="">Select Organization (Optional)</option>
                                 <?php foreach ($organizations as $org): ?>
                                     <option value="<?php echo $org['id']; ?>" 
                                             <?php echo (isset($formData['organization_id']) && $org['id'] == $formData['organization_id']) ? 'selected' : ''; ?>>
                                         <?php echo htmlspecialchars($org['name']); ?>
                                     </option>
                                 <?php endforeach; ?>
                             </select>
                             <p class="mt-1 text-sm text-gray-500">Select the organization this order belongs to</p>
                         </div>

                        <div>
                            <label for="delivery_date" class="block text-sm font-medium text-gray-700">Delivery Date</label>
                            <input type="date" name="delivery_date" id="delivery_date"
                                   value="<?php echo htmlspecialchars($formData['delivery_date']); ?>"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <!-- Products Section -->
                        <div class="space-y-4 pt-6 border-t">
                            <h3 class="text-lg font-medium text-gray-900">Products</h3>
                            <div id="products-container">
                                <?php if (!empty($formData['product_ids'])): ?>
                                    <?php foreach ($formData['product_ids'] as $index => $product_id): ?>
                                        <div class="product-row grid grid-cols-3 gap-4 mb-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Product *</label>
                                                <select name="product_ids[]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm product-select">
                                                    <option value="">Select Product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" <?php echo ($product_id == $product['id']) ? 'selected' : ''; ?> data-price="<?php echo $product['price'] ?? 0; ?>">
                                                            <?php echo htmlspecialchars($product['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                                <input type="number" name="quantities[]" required min="1" value="<?php echo htmlspecialchars($formData['quantities'][$index] ?? 1); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm quantity-input">
                                            </div>
                                            <div class="flex items-end">
                                                <button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">Remove</button>
                                            </div>
                                            <input type="hidden" name="prices[]" value="<?php echo htmlspecialchars($formData['prices'][$index] ?? 0); ?>" class="price-input">
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Default first product row -->
                                    <div class="product-row grid grid-cols-3 gap-4 mb-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Product *</label>
                                            <select name="product_ids[]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm product-select">
                                                <option value="">Select Product</option>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['price'] ?? 0; ?>">
                                                        <?php echo htmlspecialchars($product['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                                            <input type="number" name="quantities[]" required min="1" value="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm quantity-input">
                                        </div>
                                        <div class="flex items-end">
                                            <button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">Remove</button>
                                        </div>
                                        <input type="hidden" name="prices[]" value="0" class="price-input">
                                    </div>
                                <?php endif; ?>
                            </div>
                             <button type="button" id="add-product-btn" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 <?php echo empty($products) ? 'opacity-50 cursor-not-allowed' : ''; ?>" <?php echo empty($products) ? 'disabled' : ''; ?>>
                                Add Another Product
                            </button>
                             <p id="no-products-message" class="text-sm text-red-600" style="<?php echo empty($products) ? 'display: block;' : 'display: none;'; ?>">
                                Please select a company first to see available products.
                            </p>
                        </div>

                         <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea name="notes" id="notes" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                        </div>
                        
                        <!-- Proof Requirements Section -->
                        <div class="space-y-4 pt-6 mt-6 border-t">
                             <h3 class="text-lg font-medium text-gray-900">Proof Requirements</h3>
                             <p class="text-sm text-gray-600">Configure proof requirements for this order (Defaults can be set per company).</p>
                             <div class="space-y-4">
                                 <div class="flex items-start">
                                     <div class="flex items-center h-5">
                                         <input id="requires_image_proof" name="requires_image_proof" type="checkbox" value="1"
                                                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded proof-checkbox"
                                                <?php echo !empty($formData['requires_image_proof']) ? 'checked' : ''; ?>>
                                     </div>
                                     <div class="ml-3 text-sm">
                                         <label for="requires_image_proof" class="font-medium text-gray-700">Require Image Proof</label>
                                         <p class="text-gray-500">Rider must upload an image as proof of delivery.</p>
                                     </div>
                                 </div>
                                 <div class="flex items-start">
                                     <div class="flex items-center h-5">
                                         <input id="requires_signature_proof" name="requires_signature_proof" type="checkbox" value="1"
                                                class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded proof-checkbox"
                                                <?php echo !empty($formData['requires_signature_proof']) ? 'checked' : ''; ?>>
                                     </div>
                                     <div class="ml-3 text-sm">
                                         <label for="requires_signature_proof" class="font-medium text-gray-700">Require Signature Proof</label>
                                         <p class="text-gray-500">Rider must capture a signature as proof of delivery.</p>
                                     </div>
                                 </div>
                             </div>
                         </div>

                        <input type="hidden" name="force_address_validation_skip" id="force_address_validation_skip" value="0">

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                            <button type="button" id="forceCreateBtn" class="bg-orange-500 text-white px-4 py-2 rounded-md hover:bg-orange-600">Force Create Order</button>
                            <button type="submit" id="createBtn" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700">Create Order</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        // Debounce function
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

        // Global variable to store fetched customer data by ID for address handling
        let customerCache = {};

        // Function to fill address fields
        function fillAddressFields(address) {
            $('#address_line1').val(address.address_line1 || '');
            $('#address_line2').val(address.address_line2 || '');
            $('#city').val(address.city || '');
            $('#state').val(address.state || '');
            $('#postal_code').val(address.postal_code || '');
            $('#country').val(address.country || 'United Kingdom');
            $('#selected_address_id').val(address.id);
            // Enable fields if they were disabled
             $('#address-fields input, #address-fields select').prop('disabled', false);
        }
        
        // Function to clear customer and address fields
        function clearCustomerAndAddress() {
             $('#customer_id').val('');
             $('#selected_address_id').val('');
             $('#selected-customer-info').text('').hide();
             $('#customer_name').val('').prop('disabled', false);
             $('#email').val('').prop('disabled', false);
             $('#phone').val('').prop('disabled', false);
             $('#address_selector').empty().append('<option value="">-- Select Saved Address --</option>').append('<option value="_new">-- Enter New Address Below --</option>');
             $('#address-selector-container').hide();
             // Clear address fields and enable them
             $('#address_line1').val('');
             $('#address_line2').val('');
             $('#city').val('');
             $('#state').val('');
             $('#postal_code').val('');
             $('#country').val('United Kingdom');
             $('#address-fields input, #address-fields select').prop('disabled', false);
             console.log("Cleared customer and address info.");
        }

        $(document).ready(function () {
            const customerSearchInput = $('#customer_search');
            const suggestionsContainer = $('#customer-suggestions');
            const customerIdInput = $('#customer_id');
            const selectedAddressIdInput = $('#selected_address_id');
            const addressSelectorContainer = $('#address-selector-container');
            const addressSelector = $('#address_selector');
            const addressFieldsDiv = $('#address-fields');
            const selectedCustomerInfo = $('#selected-customer-info');
            
            // --- Customer Search --- 
            const handleSearch = debounce(function() {
                const query = customerSearchInput.val().trim();
                suggestionsContainer.hide().empty(); // Hide and clear previous suggestions
                customerCache = {}; // Clear cache on new search

                if (query.length < 2) { // Minimum query length
                    if (customerIdInput.val()) { // If a customer was selected but search is cleared
                        clearCustomerAndAddress();
                    }
                    return;
                }
                
                 // AJAX call to search API
                $.ajax({
                    url: '../../api/admin/search_customers.php',
                    method: 'GET',
                    data: { query: query },
                    dataType: 'json',
                    // Include Auth Token if your API requires it
                    // headers: { 'Authorization': 'Bearer YOUR_TOKEN_HERE' }, 
                    beforeSend: function() {
                        // Optional: add loading indicator
                    },
                    success: function(data) {
                        if (data && data.length > 0) {
                            data.forEach(function(customer) {
                                customerCache[customer.id] = customer; // Cache customer data
                                let displayText = customer.name;
                                if (customer.email) displayText += ` (${customer.email})`;
                                else if (customer.phone) displayText += ` (${customer.phone})`;
                                
                                suggestionsContainer.append(
                                    $('<div data-id="' + customer.id + '"></div>').text(displayText)
                                );
                            });
                            suggestionsContainer.show();
                        } else {
                            suggestionsContainer.append('<div>No customers found. Enter details below to create new.</div>');
                            suggestionsContainer.show();
                            // Clear previous selection if no results found
                            if (customerIdInput.val()) {
                                clearCustomerAndAddress();
                            }
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error("Customer search error:", textStatus, errorThrown);
                        suggestionsContainer.append('<div>Error searching customers.</div>');
                        suggestionsContainer.show();
                         if (customerIdInput.val()) {
                                clearCustomerAndAddress();
                         }
                    }
                });
            }, 300); // 300ms debounce

            customerSearchInput.on('keyup', handleSearch);
            
             // Clear everything if search input is manually cleared
            customerSearchInput.on('input', function() {
                if ($(this).val().trim() === '' && customerIdInput.val()) {
                    clearCustomerAndAddress();
                    suggestionsContainer.hide().empty();
                }
            });

            // --- Suggestion Selection --- 
            suggestionsContainer.on('click', 'div[data-id]', function() {
                const selectedId = $(this).data('id');
                const selectedCustomer = customerCache[selectedId]; // Get from cache

                if (selectedCustomer) {
                    customerSearchInput.val(selectedCustomer.name); // Put name in search box
                    customerIdInput.val(selectedCustomer.id); // Set hidden customer ID
                    suggestionsContainer.hide().empty(); // Hide suggestions
                    
                    // Display selected customer info
                    selectedCustomerInfo.text(`Selected: ${selectedCustomer.name} (${selectedCustomer.email || selectedCustomer.phone || 'No contact'})`).show();

                    // --- Handle Addresses --- 
                    addressSelector.empty().append('<option value="">-- Select Saved Address --</option>').append('<option value="_new">-- Enter New Address Below --</option>'); // Reset dropdown
                    selectedAddressIdInput.val(''); // Clear selected address ID

                    if (selectedCustomer.addresses && selectedCustomer.addresses.length > 0) {
                        let defaultAddress = null;
                        selectedCustomer.addresses.forEach(function(addr) {
                            const addressText = `${addr.address_line1}, ${addr.city}, ${addr.postal_code}`;
                             addressSelector.append($('<option></option>').attr('value', addr.id).text(addressText));
                            if (addr.is_default) {
                                defaultAddress = addr;
                            }
                        });
                        
                        addressSelectorContainer.show(); // Show the dropdown
                        
                        // If a default exists, select it and fill fields
                        if (defaultAddress) {
                            addressSelector.val(defaultAddress.id);
                            fillAddressFields(defaultAddress);
                            addressFieldsDiv.show(); // Ensure fields are visible
                        } else {
                            // No default, select the "Enter New" option
                             addressSelector.val('_new');
                            // Clear and enable address fields for manual entry
                            $('#address_line1').val('');
                            $('#address_line2').val('');
                            $('#city').val('');
                            $('#state').val('');
                            $('#postal_code').val('');
                            $('#country').val('United Kingdom');
                            addressFieldsDiv.show();
                            $('#address-fields input, #address-fields select').prop('disabled', false);
                        }
                    } else {
                        // No saved addresses for this customer
                         addressSelectorContainer.hide(); // Hide dropdown if no addresses
                        // Clear and enable address fields for manual entry
                        $('#address_line1').val('');
                        $('#address_line2').val('');
                        $('#city').val('');
                        $('#state').val('');
                        $('#postal_code').val('');
                        $('#country').val('United Kingdom');
                        addressFieldsDiv.show();
                        $('#address-fields input, #address-fields select').prop('disabled', false);
                    }
                    
                    // Optionally disable direct editing of customer name/email/phone
                     $('#customer_name').val(selectedCustomer.name).prop('disabled', true);
                     $('#email').val(selectedCustomer.email || '').prop('disabled', true);
                     $('#phone').val(selectedCustomer.phone ? selectedCustomer.phone.replace('/^\+44/','') : '').prop('disabled', true);

                } else {
                     console.error("Selected customer data not found in cache for ID:", selectedId);
                     clearCustomerAndAddress(); // Clear if data is missing
                }
            });

             // --- Address Selection Change --- 
             addressSelector.on('change', function() {
                const selectedAddrId = $(this).val();
                const currentCustomerId = customerIdInput.val();
                const currentCustomer = customerCache[currentCustomerId];

                if (selectedAddrId === '_new') {
                     // Clear and enable address fields for manual entry
                    $('#address_line1').val('');
                    $('#address_line2').val('');
                    $('#city').val('');
                    $('#state').val('');
                    $('#postal_code').val('');
                    $('#country').val('United Kingdom');
                    selectedAddressIdInput.val(''); // Clear hidden ID for new address
                    addressFieldsDiv.show();
                    $('#address-fields input, #address-fields select').prop('disabled', false);
                } else if (selectedAddrId && currentCustomer && currentCustomer.addresses) {
                     // Find the selected address in the customer's addresses
                    const selectedAddress = currentCustomer.addresses.find(addr => addr.id == selectedAddrId);
                    if (selectedAddress) {
                        fillAddressFields(selectedAddress);
                         addressFieldsDiv.show(); // Ensure visible
                         // Optionally disable address fields when selected
                         // $('#address-fields input, #address-fields select').prop('disabled', true);
                    } else {
                         console.error("Selected address not found in customer cache");
                        // Fallback to clearing fields?
                    }
                } else {
                    // Handle cases where selector is set to placeholder or customer data is missing
                     if (selectedAddrId === '' && currentCustomerId) {
                          // If placeholder is selected, clear fields but keep customer info
                          $('#address_line1').val('');
                          $('#address_line2').val('');
                          $('#city').val('');
                          $('#state').val('');
                          $('#postal_code').val('');
                          $('#country').val('United Kingdom');
                           selectedAddressIdInput.val('');
                           $('#address-fields input, #address-fields select').prop('disabled', false); // Enable for potential manual entry
                           addressFieldsDiv.show();
                     } else {
                           // If customer data gone, clear everything
                           clearCustomerAndAddress();
                     }
                }
            });

            // Hide suggestions if clicked outside
            $(document).on('click', function(event) {
                if (!$(event.target).closest('#customer_search, #customer-suggestions').length) {
                    suggestionsContainer.hide();
                }
            });
            
            // AJAX call to get default proof requirements based on company
            function fetchCompanyDefaults(companyId) {
                if (!companyId) {
                    // Set to default checked if no company selected (or handle as needed)
                    $('#requires_image_proof').prop('checked', false);
                    $('#requires_signature_proof').prop('checked', false);
                    return;
                }
                $.ajax({
                    url: '../api/get_company_defaults.php', 
                    method: 'GET',
                    data: { company_id: companyId },
                    dataType: 'json',
                    success: function(data) {
                        if (data && data.success) {
                            $('#requires_image_proof').prop('checked', data.defaults.default_requires_image_proof);
                            $('#requires_signature_proof').prop('checked', data.defaults.default_requires_signature_proof);
                        }
                    },
                    error: function() {
                        console.error('Failed to fetch company default proof requirements.');
                        // Default to unchecked on error?
                        $('#requires_image_proof').prop('checked', false);
                        $('#requires_signature_proof').prop('checked', false);
                    }
                });
            }

            // Fetch defaults on page load if company is pre-selected (e.g., non-SuperAdmin)
            const initialCompanyId = $('#company_id').val() || $('input[name="company_id"]').val();
            fetchCompanyDefaults(initialCompanyId);
            
             // Re-fetch defaults if company changes (SuperAdmin only)
             $('#company_id').on('change', function() {
                  if (<?php echo json_encode(isSuperAdmin()); ?>) { // Only run if super admin
                     // This part is tricky because the form submits on change.
                     // The fetch needs to happen *after* the page reloads with the new company ID.
                     // The existing PHP handles reloading organizations, which is good.
                     // The fetch on page load should handle getting the defaults for the *newly* selected company.
                  }
             });

             // Add Product Row Logic (updated for products/prices)
            const productsData = <?php echo json_encode($products); ?>; // Pass PHP products to JS
            const productOptions = productsData.map(p => `<option value="${p.id}" data-price="${p.price || 0}">${p.name}</option>`).join('');

             // Initial check for enabling Add Product button
             if (productsData.length === 0) {
                 $('#add-product-btn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
                 $('#no-products-message').show();
             } else {
                  $('#add-product-btn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
                  $('#no-products-message').hide();
             }


            $('#add-product-btn').click(function() {
                const container = $('#products-container');
                const newRow = $('<div class="product-row grid grid-cols-3 gap-4 mb-4"></div>');
                newRow.html(`
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Product *</label>
                        <select name="product_ids[]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm product-select">
                            <option value="">Select Product</option>
                            ${productOptions}
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Quantity *</label>
                        <input type="number" name="quantities[]" required min="1" value="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm quantity-input">
                    </div>
                    <div class="flex items-end">
                        <button type="button" onclick="removeProductRow(this)" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 mb-0">Remove</button>
                    </div>
                     <input type="hidden" name="prices[]" value="0" class="price-input">
                `);
                container.append(newRow);
            });

            // --- Force Create Button Handler ---
            $('#forceCreateBtn').on('click', function() {
                // Set the hidden field to indicate force
                $('#force_address_validation_skip').val('1');
                // Submit the form
                $('#orderForm').submit();
            });
            // --- End Force Create Button Handler ---
        });
        
        // Keep removeProductRow function accessible globally or move inside ready()
            function removeProductRow(button) {
                const productRows = document.querySelectorAll('.product-row');
                if (productRows.length > 1) {
                    button.closest('.product-row').remove();
                } else {
                    alert('At least one product is required.');
                }
            }


        </script>
</body>
</html>