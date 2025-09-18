<?php
require_once '../config.php';

// Verify API key and get company_id
function verifyApiKey() {
    global $conn;
    
    // Get API key from header
    $headers = getallheaders();
    $api_key = isset($headers['X-API-Key']) ? $headers['X-API-Key'] : null;
    
    if (!$api_key) {
        throw new Exception('API key is required');
    }
    
    // Verify API key and get company_id
    $query = "SELECT company_id FROM ApiKeys 
              WHERE api_key = ? AND is_active = 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $api_key);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $key_data = mysqli_fetch_assoc($result);
    
    if (!$key_data) {
        throw new Exception('Invalid API key');
    }
    
    // Update last used timestamp
    $update = "UPDATE ApiKeys SET last_used_at = NOW() WHERE api_key = ?";
    $stmt = mysqli_prepare($conn, $update);
    mysqli_stmt_bind_param($stmt, "s", $api_key);
    mysqli_stmt_execute($stmt);
    
    return $key_data['company_id'];
}

// Generate unique numeric barcode for company (12 digits)
function generateUniqueQRCode($conn, $company_id) {
    do {
        // Generate a 12-digit number
        // First 2 digits: company_id (padded with 0)
        // Next 6 digits: current date YYMMDD
        // Last 4 digits: random number
        
        $company_prefix = str_pad($company_id, 2, '0', STR_PAD_LEFT);
        $date_part = date('ymd');
        $random_part = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        $barcode = $company_prefix . $date_part . $random_part;
        
        // Check if this barcode already exists for this company
        $check = mysqli_query($conn, "SELECT id FROM Products 
                                    WHERE qrcode_number = '$barcode' 
                                    AND company_id = $company_id");
    } while (mysqli_num_rows($check) > 0);
    
    return $barcode;
}

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON data');
    }
    
    // Verify API key and get company_id
    $company_id = verifyApiKey();
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    // Validate required order fields
    $required_fields = ['customer_name', 'phone', 'address_line1', 'city', 'products'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Generate unique order number
    do {
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check = mysqli_query($conn, "SELECT id FROM Orders WHERE order_number = '$order_number'");
    } while (mysqli_num_rows($check) > 0);
    
    // Calculate total amount from products
    $total_amount = 0;
    foreach ($input['products'] as $product) {
        if (empty($product['quantity']) || empty($product['price'])) {
            throw new Exception('Product quantity and price are required');
        }
        $total_amount += ($product['quantity'] * $product['price']);
    }
    
    // Insert order
    $order_query = "INSERT INTO Orders (
        order_number, customer_name, phone, email,
        address_line1, address_line2, city, state,
        postal_code, country, total_amount, company_id,
        status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    
    // Create variables for bind_param
    $stmt = mysqli_prepare($conn, $order_query);
    $status = 'pending';
    $email = $input['email'] ?? '';
    $address_line2 = $input['address_line2'] ?? '';
    $state = $input['state'] ?? '';
    $postal_code = $input['postal_code'] ?? '';
    $country = $input['country'] ?? '';
    
    mysqli_stmt_bind_param($stmt, "ssssssssssdi",
        $order_number,
        $input['customer_name'],
        $input['phone'],
        $email,
        $input['address_line1'],
        $address_line2,
        $input['city'],
        $state,
        $postal_code,
        $country,
        $total_amount,
        $company_id
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to create order: ' . mysqli_error($conn));
    }
    
    $order_id = mysqli_insert_id($conn);
    
    // Process products
    foreach ($input['products'] as $product) {
        // Validate required product fields
        if (empty($product['id']) || empty($product['name']) || 
            empty($product['description']) || empty($product['quantity']) || 
            empty($product['price'])) {
            throw new Exception('All product fields (id, name, description, quantity, price) are required');
        }

        // Check if product exists by external ID
        $check_product = "SELECT id FROM Products 
                         WHERE external_id = ? AND company_id = ?";
        $stmt = mysqli_prepare($conn, $check_product);
        mysqli_stmt_bind_param($stmt, "si", $product['id'], $company_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $existing_product = mysqli_fetch_assoc($result);
        
        if ($existing_product) {
            $product_id = $existing_product['id'];
        } else {
            // Create new product
            $qrcode = generateUniqueQRCode($conn, $company_id);
            $product_query = "INSERT INTO Products (
                external_id, name, description, qrcode_number, company_id
            ) VALUES (?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $product_query);
            mysqli_stmt_bind_param($stmt, "ssssi",
                $product['id'],
                $product['name'],
                $product['description'],
                $qrcode,
                $company_id
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Failed to create product: ' . mysqli_error($conn));
            }
            
            $product_id = mysqli_insert_id($conn);
        }
        
        // Add product to order
        $order_product_query = "INSERT INTO ProductOrders (
            order_id, product_id, quantity, price
        ) VALUES (?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $order_product_query);
        mysqli_stmt_bind_param($stmt, "iidd",
            $order_id,
            $product_id,
            $product['quantity'],
            $product['price']
        );
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Failed to add product to order: ' . mysqli_error($conn));
        }
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully',
        'data' => [
            'order_id' => $order_id,
            'order_number' => $order_number
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        mysqli_rollback($conn);
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} 