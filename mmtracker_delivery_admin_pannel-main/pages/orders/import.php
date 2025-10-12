<?php
ob_start();
require_once '../../includes/config.php';
requireLogin();

// Fetch WooCommerce API Keys from DB
$wooKeys = [
    "WooCommerce Store URL"       => null,
    "WooCommerce Consumer Key"    => null,
    "WooCommerce Consumer Secret" => null
];

foreach ($wooKeys as $desc => $val) {
    $descEsc = mysqli_real_escape_string($conn, $desc);
    $query   = "SELECT api_key FROM ApiKeys WHERE description = '$descEsc' LIMIT 1";
    $result  = mysqli_query($conn, $query);

    if ($row = mysqli_fetch_assoc($result)) {
        $wooKeys[$desc] = $row['api_key'];
    }
}

// Validate all keys exist
$missingKeys = [];
foreach ($wooKeys as $desc => $val) {
    if (empty($val)) {
        $missingKeys[] = $desc;
    }
}

if (!empty($missingKeys)) {
    die("Error: Missing WooCommerce API keys in database. Please ensure the following keys exist: " . implode(", ", $missingKeys));
}

// Define constants using DB values
define('WOOCOMMERCE_STORE_URL', $wooKeys["WooCommerce Store URL"]);
define('WOOCOMMERCE_CONSUMER_KEY', $wooKeys["WooCommerce Consumer Key"]);
define('WOOCOMMERCE_CONSUMER_SECRET', $wooKeys["WooCommerce Consumer Secret"]);

// --- Handle Cancel Request ---
if (isset($_GET['cancel']) && $_GET['cancel'] == '1') {
    if (isset($_SESSION['import_file_path']) && file_exists($_SESSION['import_file_path'])) {
        unlink($_SESSION['import_file_path']);
    }
    unset($_SESSION['import_file_path']);
    unset($_SESSION['import_company_id']);
    unset($_SESSION['import_csv_headers']);
    unset($_SESSION['woocommerce_orders']);
    
    header("Location: import.php");
    exit();
}

/**
 * Generate a unique order number.
 */
function generateOrderNumber($conn) {
    do {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check_query = "SELECT id FROM Orders WHERE order_number = ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "s", $order_number);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } while (mysqli_num_rows($result) > 0);
    return $order_number;
}

/**
 * Check if a WooCommerce order already exists in the database
 */
function orderExistsInDatabase($conn, $wc_order_number) {
    $check_order_num = 'WC-' . $wc_order_number;
    $check_existing = "SELECT id FROM Orders WHERE order_number = ?";
    $stmt = mysqli_prepare($conn, $check_existing);
    mysqli_stmt_bind_param($stmt, "s", $check_order_num);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = mysqli_fetch_assoc($result) ? true : false;
    mysqli_stmt_close($stmt);
    return $exists;
}

/**
 * Validate UK Postcode Format
 */
function isValidUKPostcode($postcode) {
    $postcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    $uk_postcode_pattern = '/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/';
    return preg_match($uk_postcode_pattern, $postcode);
}

/**
 * Validate UK Address using Mapbox API
 */
function validateUKAddressWithMapbox($city, $postal_code) {
    if (!isValidUKPostcode($postal_code)) {
        error_log("Invalid UK postcode format: $postal_code");
        return false;
    }
    
    $mapbox_token = defined('MAPBOX_TOKEN') ? MAPBOX_TOKEN : null;
    if (empty($mapbox_token)) {
        error_log("Mapbox token missing. Skipping API check.");
        return true;
    }

    $postal_code_cleaned = strtoupper(preg_replace('/\s+/', '', trim($postal_code)));
    $postal_code_formatted = $postal_code_cleaned; 
    if (preg_match('/^([A-Z]{1,2}[0-9][A-Z0-9]?)([0-9][A-Z]{2})$/', $postal_code_cleaned, $matches)) {
        $postal_code_formatted = $matches[1] . ' ' . $matches[2];
    }
    
    $postcode_query = urlencode($postal_code_formatted);
    $postcode_url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$postcode_query}.json?country=GB&types=postcode&limit=1&access_token={$mapbox_token}";
    
    $ch_postcode = curl_init();
    curl_setopt($ch_postcode, CURLOPT_URL, $postcode_url);
    curl_setopt($ch_postcode, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_postcode, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch_postcode, CURLOPT_SSL_VERIFYPEER, true);
    $postcode_response = curl_exec($ch_postcode);
    $postcode_status_code = curl_getinfo($ch_postcode, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch_postcode);
    curl_close($ch_postcode);

    if ($curl_error) {
        error_log("Mapbox cURL Error: $curl_error");
        return true;
    }

    if ($postcode_status_code === 200) {
        $postcode_result = json_decode($postcode_response, true);
        if (!empty($postcode_result['features'])) {
            return true; // Valid postcode found
        } else {
            error_log("Postcode $postal_code not found by Mapbox.");
            return false;
        }
    }
    
    return true;
}

/**
 * Validate and format UK phone number
 */
function validateAndFormatUKPhoneNumber($phone) {
    $cleaned_phone = preg_replace('/[^0-9+]/', '', $phone);

    if (preg_match('/^\+44[1-9][0-9]{9}$/', $cleaned_phone)) {
        return $cleaned_phone;
    }

    if (preg_match('/^0[1-9][0-9]{9}$/', $cleaned_phone)) {
        return '+44' . substr($cleaned_phone, 1);
    }
    
    if (preg_match('/^[1-9][0-9]{9}$/', $cleaned_phone)) {
        return '+44' . $cleaned_phone;
    }
        
    return false;
}

/**
 * Test WooCommerce connection and get diagnostics
 */
function testWooCommerceConnection() {
    $base_url = WOOCOMMERCE_STORE_URL;
    $consumer_key = WOOCOMMERCE_CONSUMER_KEY;
    $consumer_secret = WOOCOMMERCE_CONSUMER_SECRET;
    
    $diagnostics = [
        'connection_status' => 'failed',
        'total_orders' => 0,
        'order_statuses' => [],
        'recent_orders' => [],
        'api_version' => null,
        'error_message' => null
    ];
    
    try {
        // Test basic connection first
        $api_url = $base_url . '/wp-json/wc/v3/orders';
        $params = ['per_page' => 5, 'orderby' => 'date', 'order' => 'desc'];
        $query_string = http_build_query($params);
        $full_url = $api_url . '?' . $query_string;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $consumer_key . ':' . $consumer_secret);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TrackPod Diagnostic Tool');
        curl_setopt($ch, CURLOPT_HEADER, true); // Get headers too
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            $diagnostics['error_message'] = "cURL Error: $curl_error";
            return $diagnostics;
        }
        
        if ($http_code !== 200) {
            $error_detail = json_decode($body, true);
            $diagnostics['error_message'] = "HTTP $http_code - " . ($error_detail['message'] ?? $body);
            return $diagnostics;
        }
        
        $orders = json_decode($body, true);
        
        if (!is_array($orders)) {
            $diagnostics['error_message'] = "Invalid API response format";
            return $diagnostics;
        }
        
        $diagnostics['connection_status'] = 'success';
        $diagnostics['total_orders'] = count($orders);
        
        // Extract order statuses and recent order info
        $statuses = [];
        $recent_orders = [];
        
        foreach ($orders as $order) {
            $status = $order['status'] ?? 'unknown';
            $statuses[] = $status;
            
            $recent_orders[] = [
                'id' => $order['id'] ?? 'N/A',
                'number' => $order['number'] ?? $order['id'] ?? 'N/A',
                'status' => $status,
                'date_created' => $order['date_created'] ?? 'N/A',
                'total' => $order['total'] ?? '0.00',
                'customer_name' => trim(($order['billing']['first_name'] ?? '') . ' (' . ($order['billing']['last_name'] ?? ''))
            ];
        }
        
        $diagnostics['order_statuses'] = array_unique($statuses);
        $diagnostics['recent_orders'] = array_slice($recent_orders, 0, 3); // Show only 3 most recent
        
        // Try to get API version from headers
        if (preg_match('/X-WC-Version: ([^\r\n]+)/', $headers, $matches)) {
            $diagnostics['api_version'] = trim($matches[1]);
        }
        
    } catch (Exception $e) {
        $diagnostics['error_message'] = $e->getMessage();
    }
    
    return $diagnostics;
}

/**
 * Fetch orders from WooCommerce API - ENHANCED DEBUG VERSION
 */
function fetchWooCommerceOrders($status = 'processing', $limit = 50, $date_from = null, $date_to = null) {
    $base_url = WOOCOMMERCE_STORE_URL;
    $consumer_key = WOOCOMMERCE_CONSUMER_KEY;
    $consumer_secret = WOOCOMMERCE_CONSUMER_SECRET;
    
    $api_url = $base_url . '/wp-json/wc/v3/orders';
    
    // Build query parameters - FIXED: Handle 'any' status correctly
    $params = [
        'per_page' => $limit,
        'orderby' => 'date',
        'order' => 'desc'
    ];
    
    // Only add status parameter if not 'any'
    if ($status !== 'any' && !empty($status)) {
        $params['status'] = $status;
    }
    
    if ($date_from) $params['after'] = $date_from . 'T00:00:00';
    if ($date_to) $params['before'] = $date_to . 'T23:59:59';
    
    $query_string = http_build_query($params);
    $full_url = $api_url . '?' . $query_string;
    
    // Add debug logging
    error_log("WooCommerce API URL: " . $full_url);
    error_log("WooCommerce API Params: " . print_r($params, true));
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $consumer_key . ':' . $consumer_secret);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TrackPod Import Tool');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Add debug logging
    error_log("WooCommerce API Response Code: " . $http_code);
    error_log("WooCommerce API Response: " . $response); // Log full response for debugging
    
    if ($curl_error) {
        throw new Exception("WooCommerce API cURL Error: $curl_error");
    }
    
    if ($http_code !== 200) {
        $error_detail = $response ? json_decode($response, true) : [];
        $error_message = isset($error_detail['message']) ? $error_detail['message'] : $response;
        throw new Exception("WooCommerce API Error: HTTP $http_code - $error_message");
    }
    
    $orders = json_decode($response, true);
    
    if (!is_array($orders)) {
        throw new Exception("Invalid response from WooCommerce API. Expected array, got: " . gettype($orders));
    }
    
    // Enhanced debugging for empty results
    if (empty($orders)) {
        // Try a test call without any status filter to see if ANY orders exist
        $test_params = [
            'per_page' => 10,
            'orderby' => 'date',
            'order' => 'desc'
        ];
        
        $test_query = http_build_query($test_params);
        $test_url = $api_url . '?' . $test_query;
        
        $test_ch = curl_init();
        curl_setopt($test_ch, CURLOPT_URL, $test_url);
        curl_setopt($test_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($test_ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($test_ch, CURLOPT_USERPWD, $consumer_key . ':' . $consumer_secret);
        curl_setopt($test_ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($test_ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($test_ch, CURLOPT_USERAGENT, 'TrackPod Import Tool');
        
        $test_response = curl_exec($test_ch);
        $test_http_code = curl_getinfo($test_ch, CURLINFO_HTTP_CODE);
        curl_close($test_ch);
        
        if ($test_http_code === 200) {
            $test_orders = json_decode($test_response, true);
            error_log("Test API call (no status filter) returned: " . count($test_orders) . " orders");
            
            if (!empty($test_orders)) {
                // Log the statuses of existing orders for debugging
                $existing_statuses = array_unique(array_column($test_orders, 'status'));
                error_log("Existing order statuses in store: " . implode(', ', $existing_statuses));
                
                // Also check if we have any orders matching our criteria but with different status
                if ($status !== 'any') {
                    throw new Exception("No orders found with status '$status'. Available order statuses in your store: " . implode(', ', $existing_statuses) . ". Try using 'Any Status' to see all orders.");
                }
            } else {
                throw new Exception("Your WooCommerce store appears to have no orders at all. Please create some test orders first.");
            }
        }
    }
    
    return $orders;
}

/**
 * Process WooCommerce orders into database - ENHANCED WITH SKIP DUPLICATES STRATEGY
 */
function processWooCommerceOrders($orders, $company_id, $conn) {
    $import_results = [
        'success' => 0, 
        'failed' => 0, 
        'skipped' => 0,
        'errors' => [],
        'skipped_orders' => []
    ];
    $customer_cache = [];
    $address_cache = [];
    
    foreach ($orders as $wc_order) {
        $wc_order_id = $wc_order['id'];
        $wc_order_number = $wc_order['number'] ?? $wc_order_id;
        
        // STEP 1: Check if order already exists - SKIP SILENTLY
        if (orderExistsInDatabase($conn, $wc_order_number)) {
            $import_results['skipped']++;
            $import_results['skipped_orders'][] = 'WC-' . $wc_order_number;
            continue; // Skip this order and move to the next one
        }
        
        // STEP 2: Process new orders only
        mysqli_begin_transaction($conn);
        $order_success = true;
        $current_order_errors = [];
        $order_id = null;
        
        try {
            // Extract customer info
            $billing = $wc_order['billing'] ?? [];
            $shipping = $wc_order['shipping'] ?? [];
            
            $customer_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
            $customer_email = $billing['email'] ?? null;
            $customer_phone_raw = $billing['phone'] ?? null;
            $customer_phone = null;
            $customer_id = null;
            
            // Validation
            if (empty($customer_name)) throw new Exception("Missing Customer Name");
            if (empty($customer_email) && empty($customer_phone_raw)) throw new Exception("Missing Customer Email or Phone");
            
            if (!empty($customer_phone_raw)) {
                $customer_phone = validateAndFormatUKPhoneNumber($customer_phone_raw);
                if ($customer_phone === false) throw new Exception("Invalid UK Phone Number: {$customer_phone_raw}");
            }
            if (!empty($customer_email) && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid Email: {$customer_email}");
            }
            
            // Handle customer
            $cache_key = !empty($customer_email) ? $customer_email : $customer_phone;
            if (isset($customer_cache[$cache_key])) {
                $customer_id = $customer_cache[$cache_key];
            } else {
                $check_cust_sql = "";
                $check_param = "";
                if (!empty($customer_email)) {
                    $check_cust_sql = "SELECT id FROM Customers WHERE email = ?";
                    $check_param = $customer_email;
                } elseif (!empty($customer_phone)) {
                    $check_cust_sql = "SELECT id FROM Customers WHERE phone = ?";
                    $check_param = $customer_phone;
                }
                
                if ($check_cust_sql) {
                    $stmt = mysqli_prepare($conn, $check_cust_sql);
                    mysqli_stmt_bind_param($stmt, "s", $check_param);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) $customer_id = $row['id'];
                    mysqli_stmt_close($stmt);
                }
                
                if (!$customer_id) {
                    $sql = "INSERT INTO Customers (company_id, name, email, phone) VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "isss", $company_id, $customer_name, $customer_email, $customer_phone);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create customer: " . mysqli_stmt_error($stmt));
                    $customer_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                }
                $customer_cache[$cache_key] = $customer_id;
            }
            
            // Handle address - use shipping if available, otherwise billing
            $addr_data = !empty($shipping['address_1']) ? $shipping : $billing;
            
            $addr_line1 = $addr_data['address_1'] ?? null;
            $addr_line2 = $addr_data['address_2'] ?? null;
            $addr_city = $addr_data['city'] ?? null;
            $addr_state = $addr_data['state'] ?? null;
            $addr_postcode = $addr_data['postcode'] ?? null;
            $addr_country = "United Kingdom";
            $address_id = null;
            
            if (empty($addr_line1) || empty($addr_city) || empty($addr_postcode)) {
                throw new Exception("Missing required address fields");
            }
            
            // Skip strict UK validation for WooCommerce imports to handle international orders
            // Only do basic postcode format check
            if (!empty($addr_postcode) && strlen(trim($addr_postcode)) < 3) {
                throw new Exception("Invalid postcode format");
            }
            
            $address_hash = md5(strtolower(trim($addr_line1).trim($addr_line2).trim($addr_city).trim($addr_postcode)));
            
            if (isset($address_cache[$customer_id][$address_hash])) {
                $address_id = $address_cache[$customer_id][$address_hash];
            } else {
                $check_addr_sql = "SELECT id FROM Addresses WHERE customer_id = ? AND LOWER(REPLACE(address_line1, ' ', '')) = ? AND LOWER(REPLACE(IFNULL(address_line2, ''), ' ', '')) = ? AND LOWER(REPLACE(city, ' ', '')) = ? AND UPPER(REPLACE(postal_code, ' ', '')) = ?";
                $stmt = mysqli_prepare($conn, $check_addr_sql);
                $check_line1 = strtolower(str_replace(' ', '', trim($addr_line1)));
                $check_line2 = strtolower(str_replace(' ', '', trim($addr_line2)));
                $check_city = strtolower(str_replace(' ', '', trim($addr_city)));
                $check_postcode = strtoupper(str_replace(' ', '', trim($addr_postcode)));
                mysqli_stmt_bind_param($stmt, "issss", $customer_id, $check_line1, $check_line2, $check_city, $check_postcode);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($res)) {
                    $address_id = $row['id'];
                }
                mysqli_stmt_close($stmt);
                
                if (!$address_id) {
                    $sql = "INSERT INTO Addresses (customer_id, address_line1, address_line2, city, state, postal_code, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $sql);
                    $is_default = 0;
                    mysqli_stmt_bind_param($stmt, "issssssi", $customer_id, $addr_line1, $addr_line2, $addr_city, $addr_state, $addr_postcode, $addr_country, $is_default);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create address: " . mysqli_stmt_error($stmt));
                    $address_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                }
                
                if (!isset($address_cache[$customer_id])) $address_cache[$customer_id] = [];
                $address_cache[$customer_id][$address_hash] = $address_id;
            }
            
            // Create order
            $order_number = 'WC-' . $wc_order_number;
            $order_notes = $wc_order['customer_note'] ?? null;
            $order_total = floatval($wc_order['total'] ?? 0);
            $req_img = 1; // Default requirements
            $req_sig = 1;
            $org_id = null;
            
            // Map WooCommerce status to your system
            $wc_status = $wc_order['status'] ?? 'processing';
            $order_status = 'pending'; // Default mapping
            switch ($wc_status) {
                case 'processing':
                case 'on-hold':
                    $order_status = 'pending';
                    break;
                case 'completed':
                    $order_status = 'delivered';
                    break;
                case 'cancelled':
                case 'refunded':
                case 'failed':
                    $order_status = 'failed';
                    break;
            }
            
            $sql = "INSERT INTO Orders (order_number, company_id, customer_id, delivery_address_id, status, notes, total_amount, requires_image_proof, requires_signature_proof, organization_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            // FIXED: Corrected parameter binding to match exactly 10 parameters
            mysqli_stmt_bind_param($stmt, "siissdiiii", $order_number, $company_id, $customer_id, $address_id, $order_status, $order_notes, $order_total, $req_img, $req_sig, $org_id);
            if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create order: " . mysqli_stmt_error($stmt));
            $order_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            
            // Add status log
            $log_sql = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_sql);
            mysqli_stmt_bind_param($log_stmt, "isi", $order_id, $order_status, $_SESSION['user_id']);
            if (!mysqli_stmt_execute($log_stmt)) throw new Exception("DB Error adding status log: " . mysqli_stmt_error($log_stmt));
            mysqli_stmt_close($log_stmt);
            
            mysqli_commit($conn);
            $import_results['success']++;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $order_success = false;
            $import_results['failed']++;
            $current_order_errors[] = $e->getMessage();
        }
        
        if (!empty($current_order_errors)) {
            $import_results['errors'][$wc_order_id] = array_unique($current_order_errors);
        }
    }
    
    return $import_results;
}

$page_title = "Import Orders";
$error = '';
$success = '';
$upload_error = '';
$import_results = null;
$uploaded_file_path = null;
$csv_headers = [];
$company_id = null;
$woocommerce_orders = [];

// Check for existing import state
$show_mapping_interface = false;
$show_woocommerce_preview = false;

// FIX: Check for session state at the very beginning before any processing
if (isset($_SESSION['import_file_path']) && isset($_SESSION['import_csv_headers']) && isset($_SESSION['import_company_id'])) {
    $show_mapping_interface = true;
    $uploaded_file_path = $_SESSION['import_file_path'];
    $csv_headers = $_SESSION['import_csv_headers'];
    $company_id = $_SESSION['import_company_id'];
}

if (isset($_SESSION['woocommerce_orders']) && isset($_SESSION['import_company_id'])) {
    $show_woocommerce_preview = true;
    $woocommerce_orders = $_SESSION['woocommerce_orders'];
    $company_id = $_SESSION['import_company_id'];
}

// Fetch companies for SuperAdmin
$companies = [];
if (isSuperAdmin()) {
    $comp_query = "SELECT id, name FROM Companies ORDER BY name";
    $comp_result = mysqli_query($conn, $comp_query);
    while ($row = mysqli_fetch_assoc($comp_result)) {
        $companies[] = $row;
    }
} else {
    if (!$show_mapping_interface && !$show_woocommerce_preview) {
        $company_id = $_SESSION['company_id'];
    }
}

// Handle WooCommerce diagnostics request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_woocommerce']) && !$show_mapping_interface && !$show_woocommerce_preview) {
    try {
        $diagnostics = testWooCommerceConnection();
        
        if ($diagnostics['connection_status'] === 'success') {
            $success = "WooCommerce API connection successful! Found {$diagnostics['total_orders']} recent orders.";
            if (!empty($diagnostics['order_statuses'])) {
                $success .= " Available statuses: " . implode(', ', $diagnostics['order_statuses']);
            }
            if (!empty($diagnostics['api_version'])) {
                $success .= " API Version: {$diagnostics['api_version']}";
            }
        } else {
            $error = "WooCommerce API connection failed: " . $diagnostics['error_message'];
        }
    } catch (Exception $e) {
        $error = "Diagnostics error: " . $e->getMessage();
    }
}

// Handle WooCommerce fetch request - IMPROVED ERROR HANDLING
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_woocommerce']) && !$show_mapping_interface && !$show_woocommerce_preview) {
    if (isSuperAdmin()) {
        if (empty($_POST['company_id'])) {
            $error = 'Please select a company for the WooCommerce import.';
        } else {
            $company_id = cleanInput($_POST['company_id']);
        }
    } else {
        $company_id = $_SESSION['company_id'];
    }
    
    if (!$error && $company_id) {
        try {
            $status = $_POST['wc_status'] ?? 'processing';
            $limit = min(100, max(10, intval($_POST['wc_limit'] ?? 50)));
            $date_from = !empty($_POST['wc_date_from']) ? cleanInput($_POST['wc_date_from']) : null;
            $date_to = !empty($_POST['wc_date_to']) ? cleanInput($_POST['wc_date_to']) : null;
            
            // Add debugging
            error_log("Fetching WooCommerce orders with status: $status, limit: $limit");
            
            $fetched_orders = fetchWooCommerceOrders($status, $limit, $date_from, $date_to);
            
            if (empty($fetched_orders)) {
                $error = "No orders found with the specified criteria. Check if your WooCommerce store has orders with status '$status' in the selected date range.";
                // Add additional debugging info
                if ($date_from || $date_to) {
                    $error .= " Date range: " . ($date_from ?? 'any') . " to " . ($date_to ?? 'any');
                }
            } else {
                $_SESSION['woocommerce_orders'] = $fetched_orders;
                $_SESSION['import_company_id'] = $company_id;
                
                // Add success message for debugging
                $success = "Successfully fetched " . count($fetched_orders) . " orders from WooCommerce.";
                
                // FIX: Use JavaScript redirect instead of header redirect for better reliability
                echo '<script>window.location.href = "import.php";</script>';
                exit();
            }
            
        } catch (Exception $e) {
            $error = "WooCommerce fetch error: " . $e->getMessage();
            // Log the full error for debugging
            error_log("WooCommerce API Error: " . $e->getMessage());
        }
    }
}

// Handle WooCommerce import confirmation - ENHANCED WITH SKIP DUPLICATES FEEDBACK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_woocommerce_import'])) {
    // Check if we have the session data needed for import
    if (isset($_SESSION['woocommerce_orders']) && isset($_SESSION['import_company_id'])) {
        $woocommerce_orders = $_SESSION['woocommerce_orders'];
        $company_id = $_SESSION['import_company_id'];
        
        try {
            set_time_limit(300);
            $import_results = processWooCommerceOrders($woocommerce_orders, $company_id, $conn);
            
            // Enhanced success message with detailed feedback
            $success_parts = [];
            if ($import_results['success'] > 0) {
                $success_parts[] = "{$import_results['success']} new orders imported";
            }
            if ($import_results['skipped'] > 0) {
                $success_parts[] = "{$import_results['skipped']} orders skipped (already exists)";
            }
            if ($import_results['failed'] > 0) {
                $success_parts[] = "{$import_results['failed']} orders failed";
            }
            
            $success = "WooCommerce import completed. " . implode(', ', $success_parts) . ".";
            
            if ($import_results['failed'] > 0) {
                $error = "Some orders failed to import due to validation errors. See details below.";
            }
            
            // Clear session immediately after processing
            unset($_SESSION['woocommerce_orders']);
            unset($_SESSION['import_company_id']);
            $show_woocommerce_preview = false;
            
        } catch (Exception $e) {
            $error = "Import processing error: " . $e->getMessage();
            // Clear session on error too
            unset($_SESSION['woocommerce_orders']);
            unset($_SESSION['import_company_id']);
            $show_woocommerce_preview = false;
        }
    } else {
        $error = "WooCommerce import session expired. Please fetch orders again.";
        $show_woocommerce_preview = false;
    }
}

// --- Step 1: Handle File Upload POST ---
// Only process upload if NOT already in mapping state from session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['order_csv']) && !$show_mapping_interface) {
    // Determine Company ID
    if (isSuperAdmin()) {
        if (empty($_POST['company_id'])) {
            $upload_error = 'Please select a company for the import.';
        } else {
            $company_id = cleanInput($_POST['company_id']);
        }
    } else {
        $company_id = $_SESSION['company_id']; // Re-confirm for non-superadmin
    }

    if (!$upload_error && $company_id) {
        if ($_FILES['order_csv']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_path = $_FILES['order_csv']['tmp_name'];
            $file_name = $_FILES['order_csv']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = 'csv';
                
            if ($file_ext === $allowed_ext) {
                $upload_dir = realpath(__DIR__ . '/../../uploads') . '/csv_imports/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0777, true)) {
                        $upload_error = "Error creating upload directory: $upload_dir. Please check permissions.";
                    }
                }
                if (!$upload_error && !is_writable(dirname($upload_dir))) {
                    $upload_error = "Upload directory's parent is not writable: " . dirname($upload_dir) . ". Please check permissions.";
                } elseif (!$upload_error && !is_writable($upload_dir)) {
                    $upload_error = "Upload directory is not writable: $upload_dir. Please check permissions.";
                }

                if (!$upload_error) {
                    $new_filename = 'import_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $dest_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($file_tmp_path, $dest_path)) {
                        $uploaded_file_path = $dest_path;
                        if (($handle = fopen($uploaded_file_path, "r")) !== FALSE) {
                            $csv_headers = fgetcsv($handle);
                            fclose($handle);
                            if ($csv_headers) {
                                $_SESSION['import_file_path'] = $uploaded_file_path;
                                $_SESSION['import_company_id'] = $company_id;
                                $_SESSION['import_csv_headers'] = $csv_headers;
                                
                                // FIX: Use JavaScript redirect instead of header redirect
                                echo '<script>window.location.href = "import.php";</script>';
                                exit();
                                
                            } else {
                                 $upload_error = 'Could not read headers from the uploaded CSV file.';
                                 unlink($dest_path); // Clean up
                            }
                        } else {
                            $upload_error = 'Error opening uploaded CSV file for reading headers.';
                            unlink($dest_path); // Clean up
                        }
                    } else {
                        $upload_error = 'Error moving uploaded file. Check server logs and permissions.';
                    }
                }
            } else {
                $upload_error = 'Invalid file type. Please upload a CSV file.';
            }
        } elseif ($_FILES['order_csv']['error'] == UPLOAD_ERR_NO_FILE) {
            $upload_error = 'Please select a CSV file to upload.';
        } else {
            $upload_error = 'File upload error code: ' . $_FILES['order_csv']['error'];
        }
    }
    // If upload error occurred, set general error for display on upload form
    $error = $upload_error;
    $show_mapping_interface = false; // Ensure mapping doesn't show if upload failed
}

// --- Step 3: Handle Mapping Submission & Processing ---
// Only process mapping if mapping interface should be shown AND form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mapping']) && $show_mapping_interface) {
     if (isset($_SESSION['import_file_path']) && isset($_SESSION['import_csv_headers']) && isset($_SESSION['import_company_id'])) {
        $uploaded_file_path = $_SESSION['import_file_path'];
        $csv_headers = $_SESSION['import_csv_headers'];
        $company_id = $_SESSION['import_company_id'];
        $mapping = $_POST['map'] ?? []; // Get the mapping array [csv_index => db_field_key]

        // ** 1. Validate Mapping **
        $required_mappings = [
            'order_group_id', 'customer_name', 'delivery_address_line1', 
            'delivery_city', 'delivery_postal_code', 'delivery_state',
            'product_quantity' 
            // Add 'product_price' if needed for total calculation 
        ];
        $contact_mapped = false;
        $product_id_mapped = false;
        $mapped_fields = array_values($mapping); // Get the chosen db fields

        foreach ($required_mappings as $req) {
            if (!in_array($req, $mapped_fields)) {
                $error = "Mapping validation failed. Please map the required field: '$req'.";
                break;
            }
        }
        if (!$error) {
             if (!in_array('customer_email', $mapped_fields) && !in_array('customer_phone', $mapped_fields)) {
                  $error = "Mapping validation failed. Please map either 'Customer Email' or 'Customer Phone'.";
             } elseif (!in_array('product_qrcode', $mapped_fields)) {
                 $error = "Mapping validation failed. Please map 'Product QR Code / SKU'.";
             }
        }

        if (!$error) {
            // ** 2. Process the CSV File **
            $file_handle = fopen($uploaded_file_path, 'r');
            if ($file_handle === false) {
                $error = "Failed to open uploaded file for processing.";
            } else {
                set_time_limit(300); // Increase max execution time for potentially large files
                
                $all_rows = [];
                $row_num = 0;
                fgetcsv($file_handle); // Skip header row

                while (($row_data = fgetcsv($file_handle)) !== false) {
                    $row_num++;
                    // Ensure row has the same number of columns as header
                    if (count($row_data) !== count($csv_headers)) {
                         error_log("Import Warning: Row $row_num has incorrect column count. Skipping.");
                         continue; 
                    }
                    // Combine row data with original headers for easier access via mapping
                    $row_assoc = [];
                    $order_group_id_value = null;
                    foreach ($csv_headers as $index => $header) {
                        $db_field_key = $mapping[$index] ?? null; // Get mapped DB field
                        if ($db_field_key) { // Only store data for mapped columns
                            $cell_value = trim($row_data[$index] ?? '');
                            $row_assoc[$db_field_key] = $cell_value;
                            if ($db_field_key === 'order_group_id') {
                                $order_group_id_value = $cell_value;
                            }
                        }
                    }
                    // Add row if it has an order group ID
                    if ($order_group_id_value !== null && $order_group_id_value !== '') {
                         $all_rows[$order_group_id_value][] = $row_assoc; // Group by Order Group ID
                    } else {
                         error_log("Import Warning: Row $row_num skipped because Order Group ID is missing or not mapped.");
                    }
                }
                fclose($file_handle);

                // ** 3. Process Grouped Orders **
                $import_results = ['success' => 0, 'failed' => 0, 'errors' => []];
                $customer_cache = []; // Cache created/found customers within this import run [email/phone => customer_id]
                $address_cache = []; // Cache created/found addresses [customer_id][address_hash => address_id]
                $product_cache = []; // Cache found product IDs [company_id][sku/name => product_id]

                foreach ($all_rows as $order_group_id => $order_rows) {
                    mysqli_begin_transaction($conn);
                    $order_success = true;
                    $current_order_errors = [];
                    $order_id = null; // Track the created order ID
                    
                    try {
                        $first_row = $order_rows[0]; // Use first row for customer/address info

                        // --- Customer Handling ---
                        $customer_email = $first_row['customer_email'] ?? null;
                        $customer_phone_raw = $first_row['customer_phone'] ?? null;
                        $customer_phone = null;
                        $customer_name = $first_row['customer_name'] ?? null;
                        $customer_id = null;

                        // Basic validation for required customer info
                        if (empty($customer_name)) throw new Exception("Missing Customer Name");
                        if (empty($customer_email) && empty($customer_phone_raw)) throw new Exception("Missing Customer Email or Phone");
                        if (!empty($customer_phone_raw)) {
                            $customer_phone = validateAndFormatUKPhoneNumber($customer_phone_raw);
                            if ($customer_phone === false) throw new Exception("Invalid UK Phone Number format: {$customer_phone_raw}");
                        }
                        if (!empty($customer_email) && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid Email format: {$customer_email}");
                         
                        // Check cache or DB
                        $cache_key = !empty($customer_email) ? $customer_email : $customer_phone; // Prioritize email
                        if (isset($customer_cache[$cache_key])) {
                             $customer_id = $customer_cache[$cache_key];
                        } else {
                            // Check DB by email first, then phone
                            $check_cust_sql = ""; $check_param = ""; $param_type = "s";
                            if (!empty($customer_email)) { $check_cust_sql = "SELECT id FROM Customers WHERE email = ?"; $check_param = $customer_email; }
                            elseif (!empty($customer_phone)) { $check_cust_sql = "SELECT id FROM Customers WHERE phone = ?"; $check_param = $customer_phone; }
                            
                            if ($check_cust_sql) {
                                $stmt = mysqli_prepare($conn, $check_cust_sql);
                                mysqli_stmt_bind_param($stmt, $param_type, $check_param);
                                mysqli_stmt_execute($stmt);
                                $res = mysqli_stmt_get_result($stmt);
                                if ($row = mysqli_fetch_assoc($res)) $customer_id = $row['id'];
                                mysqli_stmt_close($stmt);
                            }
                            
                            if (!$customer_id) {
                                // Insert new customer
                                $sql = "INSERT INTO Customers (company_id, name, email, phone) VALUES (?, ?, ?, ?)";
                                $stmt = mysqli_prepare($conn, $sql);
                                mysqli_stmt_bind_param($stmt, "isss", $company_id, $customer_name, $customer_email, $customer_phone);
                                if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create customer: " . mysqli_stmt_error($stmt));
                                $customer_id = mysqli_insert_id($conn);
                                mysqli_stmt_close($stmt);
                            }
                            $customer_cache[$cache_key] = $customer_id; // Add to cache
                        }

                        // --- Address Handling ---
                        $addr_line1 = $first_row['delivery_address_line1'] ?? null;
                        $addr_line2 = $first_row['delivery_address_line2'] ?? null;
                        $addr_city = $first_row['delivery_city'] ?? null;
                        $addr_state = $first_row['delivery_state'] ?? null;
                        $addr_postcode = $first_row['delivery_postal_code'] ?? null;
                        $addr_country = "United Kingdom"; // Fixed country
                        $address_id = null;

                        if (empty($addr_line1) || empty($addr_city) || empty($addr_postcode) || empty($addr_state)) {
                             throw new Exception("Missing required address fields (Line 1, City, State, Postcode)");
                        }
                        if (!validateUKAddressWithMapbox($addr_city, $addr_postcode)) throw new Exception("Address validation failed");
                        
                        $address_hash = md5(strtolower(trim($addr_line1).trim($addr_line2).trim($addr_city).trim($addr_postcode)));
                        
                        if (isset($address_cache[$customer_id][$address_hash])) {
                            $address_id = $address_cache[$customer_id][$address_hash];
                        } else {
                            // Check DB for identical address for this customer
                            $check_addr_sql = "SELECT id FROM Addresses WHERE customer_id = ? AND LOWER(REPLACE(address_line1, ' ', '')) = ? AND LOWER(REPLACE(IFNULL(address_line2, ''), ' ', '')) = ? AND LOWER(REPLACE(city, ' ', '')) = ? AND UPPER(REPLACE(postal_code, ' ', '')) = ? AND country = ?";
                            $stmt = mysqli_prepare($conn, $check_addr_sql);
                            $check_line1 = strtolower(str_replace(' ', '', trim($addr_line1))); $check_line2 = strtolower(str_replace(' ', '', trim($addr_line2)));
                            $check_city = strtolower(str_replace(' ', '', trim($addr_city))); $check_postcode = strtoupper(str_replace(' ', '', trim($addr_postcode)));
                            mysqli_stmt_bind_param($stmt, "isssss", $customer_id, $check_line1, $check_line2, $check_city, $check_postcode, $addr_country);
                            mysqli_stmt_execute($stmt);
                            $res = mysqli_stmt_get_result($stmt);
                            if ($row = mysqli_fetch_assoc($res)) {
                                $address_id = $row['id'];
                            }
                            mysqli_stmt_close($stmt);

                            if (!$address_id) {
                                // Insert new address
                                $sql = "INSERT INTO Addresses (customer_id, address_line1, address_line2, city, state, postal_code, country, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                $stmt = mysqli_prepare($conn, $sql);
                                $is_default = 0; // Don't set as default during import
                                mysqli_stmt_bind_param($stmt, "issssssi", $customer_id, $addr_line1, $addr_line2, $addr_city, $addr_state, $addr_postcode, $addr_country, $is_default);
                                if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create address: " . mysqli_stmt_error($stmt));
                                $address_id = mysqli_insert_id($conn);
                                mysqli_stmt_close($stmt);
                            }
                            // Add to cache
                             if (!isset($address_cache[$customer_id])) $address_cache[$customer_id] = [];
                             $address_cache[$customer_id][$address_hash] = $address_id;
                        }
                        
                        // --- Order Creation ---
                        $order_number = generateOrderNumber($conn);
                        $order_notes = $first_row['order_notes'] ?? null;
                        $req_img = filter_var($first_row['requires_image_proof'] ?? 1, FILTER_VALIDATE_BOOLEAN);
                        $req_sig = filter_var($first_row['requires_signature_proof'] ?? 1, FILTER_VALIDATE_BOOLEAN);
                        $order_total = 0; $org_id = null; // Init
                        
                        $sql = "INSERT INTO Orders (order_number, company_id, customer_id, delivery_address_id, status, notes, total_amount, requires_image_proof, requires_signature_proof, organization_id) VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "siisdiiii", $order_number, $company_id, $customer_id, $address_id, $order_notes, $order_total, $req_img, $req_sig, $org_id);
                        if (!mysqli_stmt_execute($stmt)) throw new Exception("DB Error create order: " . mysqli_stmt_error($stmt));
                        $order_id = mysqli_insert_id($conn);
                        mysqli_stmt_close($stmt);

                        // --- Product Handling ---
                        $product_lines_added = 0;
                        $product_insert_sql = "INSERT INTO ProductOrders (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";
                        $prod_stmt = mysqli_prepare($conn, $product_insert_sql);
                        
                        foreach ($order_rows as $product_row_index => $product_row) {
                             $qrcode = $product_row['product_qrcode'] ?? null;
                             $name = $product_row['product_name'] ?? null;
                             $qty = isset($product_row['product_quantity']) ? (int)$product_row['product_quantity'] : 0;
                             $price = isset($product_row['product_price']) ? (float)$product_row['product_price'] : 0.0;
                             $product_id = null;
                             $product_lookup_key = null;
                             $lookup_type = null;

                             if ($qty <= 0) {
                                 $current_order_errors[] = "Row #" . ($product_row_index+1) . ": Skipped product row: Invalid quantity ('$qty') for QR '$qrcode'.";
                                 continue;
                            }

                             // Determine lookup key (QR Code ONLY)
                             if (!empty($qrcode)) { $product_lookup_key = $qrcode; $lookup_type = 'qrcode'; }
                             else { $current_order_errors[] = "Row #" . ($product_row_index+1) . ": Skipped product row: Missing Product QR Code / SKU."; continue; }

                             // Check product cache
                             $cache_lookup_key = $lookup_type . '_' . $product_lookup_key; // Cache key is now always qrcode_...
                             if (isset($product_cache[$company_id][$cache_lookup_key])) {
                                 $product_id = $product_cache[$company_id][$cache_lookup_key];
                             } else {
                                 // Find Product ID in DB using QR Code ONLY
                                 $find_prod_sql = "SELECT id FROM Products WHERE company_id = ? AND qrcode_number = ?"; // Only QR lookup
                                 
                                 $find_stmt = mysqli_prepare($conn, $find_prod_sql);
                                 mysqli_stmt_bind_param($find_stmt, "is", $company_id, $product_lookup_key);
                                 mysqli_stmt_execute($find_stmt);
                                 $find_res = mysqli_stmt_get_result($find_stmt);
                                 if ($p_row = mysqli_fetch_assoc($find_res)) {
                                     $product_id = $p_row['id'];
                                     // Cache it
                                      if (!isset($product_cache[$company_id])) $product_cache[$company_id] = [];
                                      $product_cache[$company_id][$cache_lookup_key] = $product_id;
                                 } else {
                                     // --- Product Not Found By QR Code - Attempt Creation ---
                                     if (!empty($name)) { // Can only create if name is provided
                                         error_log("Attempting to auto-create product: Name='$name', QR='$qrcode' for Company ID: $company_id");
                                         $create_prod_sql = "INSERT INTO Products (company_id, name, qrcode_number, description) VALUES (?, ?, ?, ?)";
                                         $create_stmt = mysqli_prepare($conn, $create_prod_sql);
                                         $description = $name; // Use name as description for auto-created products
                                         mysqli_stmt_bind_param($create_stmt, "isss", $company_id, $name, $qrcode, $description);
                                         
                                         if (mysqli_stmt_execute($create_stmt)) {
                                             $product_id = mysqli_insert_id($conn); // Get the new ID
                                             error_log("Auto-created product ID: $product_id");
                                             // Cache the newly created product ID by both potential keys
                                             if (!isset($product_cache[$company_id])) $product_cache[$company_id] = [];
                                             $product_cache[$company_id]['name_'.$name] = $product_id; // Cache by name
                                             if (!empty($qrcode)) {
                                                 $product_cache[$company_id]['qrcode_'.$qrcode] = $product_id; // Cache by QR if exists
                                            }
                                        } else {
                                              // Failed to create product - Critical error for this line
                                              $error_msg = "Row #" . ($product_row_index+1) . ": Failed to auto-create product '$name': " . mysqli_stmt_error($create_stmt);
                                              $current_order_errors[] = $error_msg;
                                              mysqli_stmt_close($create_stmt);
                                              continue; // Skip this product line
                                         }
                                         mysqli_stmt_close($create_stmt);
                                     } else {
                                         // Product not found AND no name provided - Cannot create
                                         $error_msg = "Row #" . ($product_row_index+1) . ": Skipped: Product not found for QR Code '$product_lookup_key' and cannot auto-create without Product Name.";
                                         $current_order_errors[] = $error_msg;
                                         mysqli_stmt_close($find_stmt); // Close the find statement before continuing
                                         continue; // Skip this product line
                                     }
                                     // --- End Product Creation Attempt ---
                                 }
                                 mysqli_stmt_close($find_stmt);
                             }
                             
                             // Insert ProductOrder (Now product_id should be valid if creation worked)
                             if ($product_id) { // Ensure we have a valid ID before inserting
                                 mysqli_stmt_bind_param($prod_stmt, "iiid", $order_id, $product_id, $qty, $price);
                                 if (!mysqli_stmt_execute($prod_stmt)) throw new Exception("DB Error adding product order for product ID $product_id: " . mysqli_stmt_error($prod_stmt));
                                 $order_total += ($qty * $price);
                                 $product_lines_added++;
                             } // else error was already logged if product couldn't be found/created
                         }
                         mysqli_stmt_close($prod_stmt);
                         
                         if ($product_lines_added === 0) {
                              throw new Exception("No valid product lines could be added for this order. Check product errors above.");
                         }

                        // --- Update Order Total ---
                        $update_total_sql = "UPDATE Orders SET total_amount = ? WHERE id = ?";
                        $update_stmt = mysqli_prepare($conn, $update_total_sql);
                        mysqli_stmt_bind_param($update_stmt, "di", $order_total, $order_id);
                         if (!mysqli_stmt_execute($update_stmt)) throw new Exception("DB Error updating order total: " . mysqli_stmt_error($update_stmt));
                        mysqli_stmt_close($update_stmt);
                        
                        // --- Add Status Log ---
                        $log_sql = "INSERT INTO OrderStatusLogs (order_id, status, changed_by) VALUES (?, 'pending', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_sql);
                        mysqli_stmt_bind_param($log_stmt, "ii", $order_id, $_SESSION['user_id']);
                        if (!mysqli_stmt_execute($log_stmt)) throw new Exception("DB Error adding status log: " . mysqli_stmt_error($log_stmt));
                        mysqli_stmt_close($log_stmt);
                        
                        // Everything OK for this order
                        mysqli_commit($conn);
                        $import_results['success']++;

                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $order_success = false;
                        $import_results['failed']++;
                        $current_order_errors[] = $e->getMessage(); // Add the main exception
                    }
                    
                    if (!$order_success && $order_id) {
                         // Attempt to clean up partially created order if creation failed mid-way
                         error_log("Attempting cleanup for failed order import (Order Group ID: $order_group_id)");
                         // mysqli_query($conn, "DELETE FROM ProductOrders WHERE order_id = $order_id");
                         // mysqli_query($conn, "DELETE FROM OrderStatusLogs WHERE order_id = $order_id");
                         // mysqli_query($conn, "DELETE FROM Orders WHERE id = $order_id");
                         // Consider if customer/address should be deleted if newly created - complex!
                    }
                    
                    if (!empty($current_order_errors)) {
                        $import_results['errors'][$order_group_id] = array_unique($current_order_errors);
                    }
                } // End foreach order group

                $success = "Import process finished. Success: {$import_results['success']}, Failed: {$import_results['failed']}.";
                 if ($import_results['failed'] > 0) {
                     $error = "Some orders failed to import. See details below."; // Use general error for summary
                 }

            } // End file handle check
        } // End mapping validation check

        // Clear session variables after processing attempt
        if (isset($_SESSION['import_file_path']) && file_exists($_SESSION['import_file_path'])) {
             try { unlink($_SESSION['import_file_path']); } catch (Exception $_) {} // Clean up uploaded file, ignore errors
        }
        unset($_SESSION['import_file_path']);
        unset($_SESSION['import_csv_headers']);
        unset($_SESSION['import_company_id']);
        $show_mapping_interface = false; // Hide mapping after submission

    } else {
        $error = "Import session expired or invalid. Please upload the file again.";
        $show_mapping_interface = false;
    }
}

// Define potential database fields the CSV columns can map to
$db_fields = [
    '' => '-- Ignore --',
    'order_group_id' => 'Order Group ID (Common ID for products of same order)', // REQUIRED
    'customer_name' => 'Customer Name', // REQUIRED
    'customer_email' => 'Customer Email', // Required if phone missing
    'customer_phone' => 'Customer Phone', // Required if email missing
    'delivery_address_line1' => 'Delivery Address Line 1', // REQUIRED
    'delivery_address_line2' => 'Delivery Address Line 2',
    'delivery_city' => 'Delivery City', // REQUIRED
    'delivery_state' => 'Delivery State', // REQUIRED
    'delivery_postal_code' => 'Delivery Postal Code', // REQUIRED
    // 'delivery_country' => 'Delivery Country', // Removed - Fixed to UK
    'product_qrcode' => 'Product QR Code / SKU', // REQUIRED for lookup
    'product_name' => 'Product Name', // Required for auto-creation if QR not found
    'product_quantity' => 'Product Quantity', // REQUIRED
    'product_price' => 'Product Price (per item)', // Optional, used if present
    'order_notes' => 'Order Notes',
    'requires_image_proof' => 'Requires Image Proof (1 or true)',
    'requires_signature_proof' => 'Requires Signature Proof (1 or true)',
    // 'organization_id' => 'Organization ID', // Add if needed
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .tooltip { position: relative; display: inline-block; border-bottom: 1px dotted black; }
        .tooltip .tooltiptext { visibility: hidden; width: 220px; background-color: #555; color: #fff; text-align: center; border-radius: 6px; padding: 5px 0; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -110px; opacity: 0; transition: opacity 0.3s; }
        .tooltip:hover .tooltiptext { visibility: visible; opacity: 1; }
        .error-details { max-height: 200px; overflow-y: auto; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 4px; margin-top: 10px; font-size: 0.875rem; }
        .error-details strong { color: #721c24; }
        .error-details ul { list-style-type: disc; margin-left: 20px; }
        .import-card { transition: all 0.3s ease; }
        .import-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .skip-details { max-height: 150px; overflow-y: auto; background-color: #e1f5fe; border: 1px solid #b3e5fc; padding: 10px; border-radius: 4px; margin-top: 10px; font-size: 0.875rem; }
        .skip-details strong { color: #01579b; }
    </style>
</head>
<body class="bg-gray-100">
    <?php include_once '../../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <!-- Top Bar -->
        <div class="bg-gray-800 p-4 flex justify-between items-center">
            <div class="text-white text-lg">Admin Panel</div>
            <div>
                <span class="text-gray-300 text-sm mr-4"><?php echo $_SESSION['user_name']; ?></span>
                <a href="<?php echo SITE_URL; ?>logout.php" class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">Logout</a>
            </div>
        </div>

        <!-- Page Content -->
        <div class="p-6">
            <div class="max-w-6xl mx-auto">
                <!-- Page Header -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b">
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo $page_title; ?></h1>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($success); ?></span>
                    
                 <?php if ($import_results && isset($import_results['skipped']) && $import_results['skipped'] > 0 && !empty($import_results['skipped_orders'])): ?>
                        <div class="skip-details">
                            <strong>Skipped Orders (Already Exist):</strong>
                            <ul style="margin-top: 5px;">
                                <?php foreach ($import_results['skipped_orders'] as $skipped_order): ?>
                                    <li><?php echo htmlspecialchars($skipped_order); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($import_results && $import_results['failed'] > 0 && !empty($import_results['errors'])): ?>
                        <div class="error-details">
                            <strong>Error Details (Order ID: Error(s)):</strong>
                            <ul>
                                <?php foreach ($import_results['errors'] as $order_id => $errors): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($order_id); ?>:</strong> 
                                        <?php echo htmlspecialchars(implode(', ', $errors)); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!$show_mapping_interface && !$show_woocommerce_preview): ?>
                <!-- Import Options Grid -->
                <div class="grid md:grid-cols-2 gap-6">
                    
                    <!-- CSV Import Card -->
                    <div class="bg-white shadow-md rounded-lg overflow-hidden import-card">
                        <div class="bg-blue-600 px-6 py-4">
                            <h2 class="text-xl font-bold text-white">CSV File Import</h2>
                            <p class="text-blue-100 mt-2">Upload CSV files with order data</p>
                        </div>
                        <div class="p-6">
                            <form action="import.php" method="POST" enctype="multipart/form-data" class="space-y-4" id="csvUploadForm">
                                <?php if (isSuperAdmin()): ?>
                                <div>
                                    <label for="csv_company_id" class="block text-sm font-medium text-gray-700 mb-1">Select Company *</label>
                                    <select name="company_id" id="csv_company_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value="">-- Select Company --</option>
                                        <?php foreach ($companies as $comp): ?>
                                            <option value="<?php echo $comp['id']; ?>"><?php echo htmlspecialchars($comp['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div>
                                    <label for="order_csv" class="block text-sm font-medium text-gray-700">CSV File *</label>
                                    <input type="file" name="order_csv" id="order_csv" required accept=".csv"
                                           class="mt-1 block w-full text-sm text-gray-500
                                                  file:mr-4 file:py-2 file:px-4
                                                  file:rounded-full file:border-0
                                                  file:text-sm file:font-semibold
                                                  file:bg-blue-50 file:text-blue-700
                                                  hover:file:bg-blue-100"/>
                                    <p class="mt-1 text-xs text-gray-500">
                                        Each row should represent one product line. Use a common ID to group products into orders.
                                    </p>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit" id="csvUploadBtn"
                                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <span id="csvUploadText">Upload CSV & Map Columns</span>
                                        <span id="csvUploadSpinner" class="hidden ml-2">
                                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- WooCommerce Import Card - NOW MATCHES CSV CARD STYLING -->
                    <div class="bg-white shadow-md rounded-lg overflow-hidden import-card">
                        <div class="bg-blue-600 px-6 py-4">
                            <h2 class="text-xl font-bold text-white">WooCommerce Import</h2>
                            <p class="text-blue-100 mt-2">Fetch orders directly from WooCommerce</p>
                        </div>
                        <div class="p-6">
                            <form action="import.php" method="POST" class="space-y-4" id="wooCommerceForm">
                                <input type="hidden" name="fetch_woocommerce" value="1">
                                
                                <?php if (isSuperAdmin()): ?>
                                <div>
                                    <label for="wc_company_id" class="block text-sm font-medium text-gray-700 mb-1">Select Company *</label>
                                    <select name="company_id" id="wc_company_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <option value="">-- Select Company --</option>
                                        <?php foreach ($companies as $comp): ?>
                                            <option value="<?php echo $comp['id']; ?>"><?php echo htmlspecialchars($comp['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="wc_status" class="block text-sm font-medium text-gray-700">Order Status</label>
                                        <select name="wc_status" id="wc_status"
                                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                            <option value="processing">Processing</option>
                                            <option value="completed">Completed</option>
                                            <option value="on-hold">On Hold</option>
                                            <option value="pending">Pending Payment</option>
                                            <option value="any">Any Status</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="wc_limit" class="block text-sm font-medium text-gray-700">Max Orders</label>
                                        <select name="wc_limit" id="wc_limit"
                                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                            <option value="10">10 Orders</option>
                                            <option value="25">25 Orders</option>
                                            <option value="50" selected>50 Orders</option>
                                            <option value="100">100 Orders</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="wc_date_from" class="block text-sm font-medium text-gray-700">From Date (Optional)</label>
                                        <input type="date" name="wc_date_from" id="wc_date_from"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label for="wc_date_to" class="block text-sm font-medium text-gray-700">To Date (Optional)</label>
                                        <input type="date" name="wc_date_to" id="wc_date_to"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    </div>
                                </div>

                                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                                    <div class="flex">
                                        <div class="ml-3">
                                            <p class="text-sm text-yellow-800">
                                                <strong>Store:</strong> intern.neptasolutions.co.uk<br>
                                                <strong>Note:</strong> Orders will be imported with WC- prefix. Duplicate orders are automatically skipped.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-between">
                                    <button type="submit" name="test_woocommerce" value="1"
                                            class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Test Connection & Diagnose
                                    </button>
                                    <button type="submit" id="wooCommerceBtn"
                                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        <span id="wooCommerceText">Fetch WooCommerce Orders</span>
                                        <span id="wooCommerceSpinner" class="hidden ml-2">
                                            <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                        </span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php elseif ($show_woocommerce_preview): ?>
                <!-- WooCommerce Preview & Confirmation -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b bg-blue-50">
                        <h2 class="text-lg font-medium text-gray-900">WooCommerce Orders Preview</h2>
                        <p class="text-sm text-gray-600 mt-1">
                            Review the orders fetched from WooCommerce before importing them into your system.
                        </p>
                    </div>

                    <div class="p-6">
                        <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-6">
                            <h3 class="text-md font-semibold text-blue-800 mb-2">Import Summary</h3>
                            <p class="text-sm text-blue-700">
                                <strong><?php echo count($woocommerce_orders); ?> orders</strong> ready to import.
                                Duplicate orders will be automatically skipped during import.
                            </p>
                        </div>

                        <div class="overflow-x-auto mb-6">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">WC Order</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach (array_slice($woocommerce_orders, 0, 10) as $order): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            WC-<?php echo htmlspecialchars($order['number'] ?? $order['id']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php 
                                            $billing = $order['billing'] ?? [];
                                            echo htmlspecialchars(trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                                <?php 
                                                $status = $order['status'] ?? '';
                                                echo $status === 'completed' ? 'bg-green-100 text-green-800' : 
                                                     ($status === 'processing' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800'); 
                                                ?>">
                                                <?php echo htmlspecialchars($status); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($order['currency'] ?? 'GBP'); ?> <?php echo htmlspecialchars($order['total'] ?? '0.00'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('Y-m-d', strtotime($order['date_created'] ?? 'now')); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (count($woocommerce_orders) > 10): ?>
                            <p class="text-sm text-gray-500 mt-2 text-center">
                                Showing first 10 orders. <?php echo count($woocommerce_orders) - 10; ?> more will be imported.
                            </p>
                            <?php endif; ?>
                        </div>

                        <form action="import.php" method="POST" class="flex justify-between">
                            <input type="hidden" name="confirm_woocommerce_import" value="1">
                            <a href="import.php?cancel=1" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">
                                Cancel Import
                            </a>
                            <button type="submit"
                                    class="inline-flex items-center px-6 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Confirm & Import All Orders
                            </button>
                        </form>
                    </div>
                </div>

                <?php else: ?>
                <!-- CSV Mapping Interface (existing functionality) -->
                <div class="bg-white shadow-md rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b">
                        <h2 class="text-lg font-medium text-gray-900">Map CSV Columns to Order Fields</h2>
                        <p class="text-sm text-gray-600 mt-1">
                            Match the columns from your uploaded CSV file to the corresponding order data fields.
                        </p>
                    </div>

                    <div class="p-6">
                        <form action="import.php" method="POST" class="space-y-6">
                            <input type="hidden" name="submit_mapping" value="1">
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CSV Column Header</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Map to Field</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($csv_headers as $index => $header): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($header); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <select name="map[<?php echo $index; ?>]" 
                                                        class="block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 sm:text-sm">
                                                    <?php foreach ($db_fields as $field_key => $field_label): ?>
                                                        <option value="<?php echo $field_key; ?>"><?php echo htmlspecialchars($field_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex justify-between pt-4">
                                <a href="import.php?cancel=1" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300">Cancel</a>
                                <button type="submit"
                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                    Start Import Process
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Set today as max date for date inputs
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.setAttribute('max', today);
            });
        });

        // Auto-suggest date range (last 30 days)
        document.getElementById('wc_date_from')?.addEventListener('focus', function() {
            if (!this.value) {
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                this.value = thirtyDaysAgo.toISOString().split('T')[0];
            }
        });

        // Auto-map CSV columns based on header names
        document.addEventListener('DOMContentLoaded', function() {
            // Mapping rules: CSV header patterns -> database field values
            const autoMappingRules = {
                // Order identifiers
                'order_group_id': 'order_group_id',
                'order_id': 'order_group_id',
                'group_id': 'order_group_id',
                
                // Customer info
                'customer_name': 'customer_name',
                'customer_email': 'customer_email', 
                'customer_phone': 'customer_phone',
                'name': 'customer_name',
                'email': 'customer_email',
                'phone': 'customer_phone',
                
                // Address fields
                'delivery_address_line1': 'delivery_address_line1',
                'address_line1': 'delivery_address_line1',
                'address': 'delivery_address_line1',
                'delivery_address_line2': 'delivery_address_line2', 
                'address_line2': 'delivery_address_line2',
                'delivery_city': 'delivery_city',
                'city': 'delivery_city',
                'delivery_state': 'delivery_state',
                'state': 'delivery_state',
                'delivery_postal_code': 'delivery_postal_code',
                'postal_code': 'delivery_postal_code',
                'postcode': 'delivery_postal_code',
                
                // Product fields
                'product_qrcode': 'product_qrcode',
                'product_sku': 'product_qrcode',
                'sku': 'product_qrcode',
                'qrcode': 'product_qrcode',
                'product_name': 'product_name',
                'product_quantity': 'product_quantity',
                'quantity': 'product_quantity',
                'qty': 'product_quantity',
                'product_price': 'product_price',
                'price': 'product_price',
                'order_notes': 'order_notes',
                'notes': 'order_notes'
            };
            
            // Auto-map function
            function autoMapColumns() {
                const selects = document.querySelectorAll('select[name^="map["]');
                
                selects.forEach(function(select) {
                    const row = select.closest('tr');
                    const headerCell = row.querySelector('td:first-child');
                    const headerText = headerCell.textContent.toLowerCase().trim();
                    
                    // Try exact match first
                    if (autoMappingRules[headerText]) {
                        select.value = autoMappingRules[headerText];
                        return;
                    }
                    
                    // Try partial matches
                    for (const [pattern, fieldValue] of Object.entries(autoMappingRules)) {
                        if (headerText.includes(pattern) || pattern.includes(headerText)) {
                            select.value = fieldValue;
                            break;
                        }
                    }
                });
            }
            
            // Add auto-map button
            const form = document.querySelector('form[action="import.php"]');
            if (form) {
                const buttonContainer = form.querySelector('.flex.justify-between');
                const autoMapButton = document.createElement('button');
                autoMapButton.type = 'button';
                autoMapButton.className = 'bg-yellow-600 text-white px-4 py-2 rounded-md hover:bg-yellow-700 mr-2';
                autoMapButton.textContent = 'Auto-Map Columns';
                autoMapButton.onclick = autoMapColumns;
                
                buttonContainer.insertBefore(autoMapButton, buttonContainer.lastElementChild);
            }
            
            // Auto-map on page load
            autoMapColumns();
        });

        // Add loading states for form submissions
        document.addEventListener('DOMContentLoaded', function() {
            const csvForm = document.getElementById('csvUploadForm');
            const wooForm = document.getElementById('wooCommerceForm');
            
            if (csvForm) {
                csvForm.addEventListener('submit', function() {
                    const btn = document.getElementById('csvUploadBtn');
                    const text = document.getElementById('csvUploadText');
                    const spinner = document.getElementById('csvUploadSpinner');
                    
                    if (btn && text && spinner) {
                        btn.disabled = true;
                        text.textContent = 'Uploading...';
                        spinner.classList.remove('hidden');
                    }
                });
            }
            
            if (wooForm) {
                wooForm.addEventListener('submit', function(e) {
                    // Only show loading for the main fetch button, not test connection
                    if (!e.submitter || !e.submitter.name || e.submitter.name !== 'test_woocommerce') {
                        const btn = document.getElementById('wooCommerceBtn');
                        const text = document.getElementById('wooCommerceText');
                        const spinner = document.getElementById('wooCommerceSpinner');
                        
                        if (btn && text && spinner) {
                            btn.disabled = true;
                            text.textContent = 'Fetching Orders...';
                            spinner.classList.remove('hidden');
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>