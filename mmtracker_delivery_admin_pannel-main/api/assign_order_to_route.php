<?php
// api/assign_order_to_route.php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$order_id = intval($input['order_id'] ?? 0);
$route_id = intval($input['route_id'] ?? 0); // This is manifest_id

if (!$order_id || !$route_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID or route ID']);
    exit;
}

// FIXED: Get company_id for proper filtering
$company_id = null;
if (!isSuperAdmin() && !empty($_SESSION['company_id'])) {
    $company_id = intval($_SESSION['company_id']);
}

try {
    mysqli_begin_transaction($conn);
    
    // 1. Check if order exists and belongs to user's company - FIXED
    $order_query = "SELECT id, company_id FROM Orders WHERE id = $order_id";
    if ($company_id) {
        $order_query .= " AND company_id = $company_id";
    }
    
    $order_check = mysqli_query($conn, $order_query);
    if (!$order_check || mysqli_num_rows($order_check) === 0) {
        throw new Exception('Order not found or access denied');
    }
    $order = mysqli_fetch_assoc($order_check);
    
    // 2. Check if route/manifest exists - FIXED WITH PROPER TABLE ALIASES
    $route_query = "
        SELECT m.id, m.rider_id, m.status, u.name as rider_name 
        FROM Manifests m 
        LEFT JOIN Users u ON m.rider_id = u.id 
        WHERE m.id = $route_id";
    
    if ($company_id) {
        $route_query .= " AND m.company_id = $company_id";
    }
    
    $route_check = mysqli_query($conn, $route_query);
    
    if (!$route_check || mysqli_num_rows($route_check) === 0) {
        throw new Exception('Route not found or access denied');
    }
    $route = mysqli_fetch_assoc($route_check);
    
    // 3. Check if order is already assigned to this manifest
    $existing_assignment = mysqli_query($conn, "SELECT id FROM ManifestOrders WHERE order_id = $order_id AND manifest_id = $route_id");
    if ($existing_assignment && mysqli_num_rows($existing_assignment) > 0) {
        echo json_encode([
            'success' => true, 
            'message' => 'Order already assigned to this route'
        ]);
        exit;
    }
    
    // 4. Remove order from any existing manifest
    $old_manifest = mysqli_query($conn, "SELECT manifest_id FROM ManifestOrders WHERE order_id = $order_id");
    if ($old_manifest && mysqli_num_rows($old_manifest) > 0) {
        $old = mysqli_fetch_assoc($old_manifest);
        
        // Remove from old manifest
        mysqli_query($conn, "DELETE FROM ManifestOrders WHERE order_id = $order_id");
        
        // Update old manifest's order count
        mysqli_query($conn, "
            UPDATE Manifests 
            SET total_orders_assigned = (
                SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = {$old['manifest_id']}
            ) 
            WHERE id = {$old['manifest_id']}
        ");
    }
    
    // 5. Add order to the new manifest/route
    $assign_order = mysqli_query($conn, "
        INSERT INTO ManifestOrders (manifest_id, order_id) 
        VALUES ($route_id, $order_id)
    ");
    
    if (!$assign_order) {
        throw new Exception('Failed to assign order to route: ' . mysqli_error($conn));
    }
    
    // 6. Update order status
    mysqli_query($conn, "UPDATE Orders SET status = 'assigned' WHERE id = $order_id");
    
    // 7. Update manifest's total order count
    mysqli_query($conn, "
        UPDATE Manifests 
        SET total_orders_assigned = (
            SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = $route_id
        ) 
        WHERE id = $route_id
    ");
    
    // 8. Update manifest status if it was pending
    mysqli_query($conn, "UPDATE Manifests SET status = 'assigned' WHERE id = $route_id AND status = 'pending'");
    
    mysqli_commit($conn);
    
    $route_name = "Route #" . $route_id;
    if ($route['rider_name']) {
        $route_name .= " (" . $route['rider_name'] . ")";
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Order assigned to $route_name successfully!",
        'route_id' => $route_id,
        'rider_name' => $route['rider_name']
    ]);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>