<?php
require_once '../../includes/config.php';
requireLogin(); // Ensure user is logged in

header('Content-Type: application/json');

$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$company_id = $_SESSION['company_id']; // Assuming admin searches within their own company
$is_super_admin = isSuperAdmin();

if (strlen($query) < 2) { // Minimum query length
    echo json_encode([]);
    exit();
}

$customers = [];
$search_param = "%$query%";

// Base SQL
$sql = "SELECT c.id, c.name, c.email, c.phone 
        FROM Customers c 
        WHERE (c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";

// Append company filter if not super admin
if (!$is_super_admin) {
    $sql .= " AND c.company_id = ?";
}

error_log("Search Customers SQL Prepared: " . $sql); // Log the SQL before preparing

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    if (!$is_super_admin) {
        mysqli_stmt_bind_param($stmt, "sssi", $search_param, $search_param, $search_param, $company_id);
    } else {
        mysqli_stmt_bind_param($stmt, "sss", $search_param, $search_param, $search_param);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $customer_ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[$row['id']] = $row; // Use ID as key for easy lookup
        $customers[$row['id']]['addresses'] = []; // Initialize addresses array
        $customer_ids[] = $row['id'];
    }
    mysqli_stmt_close($stmt);

    // Now fetch addresses for the found customers
    if (!empty($customer_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($customer_ids), '?'));
        $types = str_repeat('i', count($customer_ids));

        $addr_sql = "SELECT id, customer_id, address_line1, address_line2, city, state, postal_code, country, is_default 
                     FROM Addresses 
                     WHERE customer_id IN ($ids_placeholder) 
                     ORDER BY customer_id, is_default DESC, id"; // Order by default first
                     
        $addr_stmt = mysqli_prepare($conn, $addr_sql);
        if ($addr_stmt) {
            mysqli_stmt_bind_param($addr_stmt, $types, ...$customer_ids);
            mysqli_stmt_execute($addr_stmt);
            $addr_result = mysqli_stmt_get_result($addr_stmt);

            while ($addr_row = mysqli_fetch_assoc($addr_result)) {
                // Ensure is_default is boolean/int 0 or 1 for JSON consistency
                $addr_row['is_default'] = (bool)$addr_row['is_default']; 
                if (isset($customers[$addr_row['customer_id']])) {
                    $customers[$addr_row['customer_id']]['addresses'][] = $addr_row;
                }
            }
            mysqli_stmt_close($addr_stmt);
        } else {
             error_log("Address fetch prepare error in search_customers.php: " . mysqli_error($conn)); // Log address prepare error
        }
    }

    // Convert back to indexed array for JSON output
    echo json_encode(array_values($customers));

} else {
    error_log("Customer search prepare error in search_customers.php: " . mysqli_error($conn) . " | SQL: " . $sql); // Log the specific MySQL error and the SQL
    echo json_encode(['error' => 'Failed to search customers.']);
    http_response_code(500);
}

?> 