<?php
// api/get_route_orders.php
require_once '../includes/config.php';
header('Content-Type: application/json');

// Always use manifest_id (DB naming convention)
$manifestId = isset($_GET['manifest_id']) ? intval($_GET['manifest_id']) : null;

if (!$manifestId) {
    echo json_encode(['error' => 'Manifest ID not provided.']);
    exit;
}

$sql = "SELECT 
            o.id, 
            o.order_number, 
            o.status, 
            c.name AS customer_name
        FROM Orders o
        INNER JOIN ManifestOrders mo ON o.id = mo.order_id
        LEFT JOIN Customers c ON o.customer_id = c.id
        WHERE mo.manifest_id = ?
        ORDER BY o.id ASC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $manifestId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$orders = [];
while ($row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}

mysqli_stmt_close($stmt);

// Return the data as JSON
echo json_encode($orders);
?>
