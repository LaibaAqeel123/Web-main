<?php

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

// Check if this is a route assignment or order assignment
$route_id = intval($input['route_id'] ?? 0);
$order_id = intval($input['order_id'] ?? 0);
$rider_id = intval($input['rider_id'] ?? 0);

if (!$rider_id) {
    echo json_encode(['success' => false, 'message' => 'Rider ID is required']);
    exit;
}

if (!$route_id && !$order_id) {
    echo json_encode(['success' => false, 'message' => 'Either route_id or order_id is required']);
    exit;
}

// Company condition for non-super admins
$company_condition = "";
if (!isSuperAdmin() && !empty($_SESSION['company_id'])) {
    $company_condition = "AND company_id = " . intval($_SESSION['company_id']);
}

try {
    mysqli_begin_transaction($conn);
    
    // Check if rider exists and is available
    $rider_check = mysqli_query($conn, "SELECT id, name FROM Users WHERE id = $rider_id AND user_type = 'Rider'");
    if (!$rider_check || mysqli_num_rows($rider_check) === 0) {
        throw new Exception('Rider not found');
    }
    $rider = mysqli_fetch_assoc($rider_check);
    
    if ($route_id) {
        // === ROUTE ASSIGNMENT MODE ===
        
        // 1. Check if route/manifest exists and belongs to user's company
        $route_check = mysqli_query($conn, "SELECT id, company_id, rider_id FROM Manifests WHERE id = $route_id $company_condition");
        if (!$route_check || mysqli_num_rows($route_check) === 0) {
            throw new Exception('Route not found or access denied');
        }
        $route = mysqli_fetch_assoc($route_check);
        
        // 2. Check if route already assigned to this rider
    // Check if route is already assigned to ANY rider
if ($route['rider_id'] && $route['rider_id'] != $rider_id) {
    // Route is already assigned to a DIFFERENT rider
    $current_rider = mysqli_query($conn, "SELECT name FROM Users WHERE id = {$route['rider_id']}");
    $current_rider_name = mysqli_fetch_assoc($current_rider)['name'] ?? 'Unknown';
    
    throw new Exception("Route already assigned to {$current_rider_name}. Please unassign first or choose a different route.");
}

// If route is already assigned to THIS rider, do nothing
if ($route['rider_id'] == $rider_id) {
    echo json_encode([
        'success' => true,
        'message' => "Route already assigned to {$rider['name']}",
        'rider_name' => $rider['name']
    ]);
    exit;
}
        // 3. Assign the entire route to the new rider
        $assign_route = mysqli_query($conn, "UPDATE Manifests SET rider_id = $rider_id, status = 'assigned' WHERE id = $route_id");
        
        if (!$assign_route) {
            throw new Exception('Failed to assign route to rider: ' . mysqli_error($conn));
        }
        
        // 4. Update all orders in this route to 'assigned' status
        mysqli_query($conn, "
            UPDATE Orders o 
            JOIN ManifestOrders mo ON o.id = mo.order_id 
            SET o.status = 'assigned' 
            WHERE mo.manifest_id = $route_id
        ");
        
        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true,
            'message' => "Route #$route_id assigned to {$rider['name']}",
            'rider_name' => $rider['name'],
            'route_id' => $route_id
        ]);
        
    } else {
        // === INDIVIDUAL ORDER ASSIGNMENT MODE  ===
        
        // 1. Check if order exists and belongs to user's company
        $order_check = mysqli_query($conn, "SELECT id, company_id FROM Orders WHERE id = $order_id $company_condition");
        if (!$order_check || mysqli_num_rows($order_check) === 0) {
            throw new Exception('Order not found or access denied');
        }
        $order = mysqli_fetch_assoc($order_check);
        
        // 2. Check if order is already assigned to a manifest
        $existing_assignment = mysqli_query($conn, "
            SELECT m.id as manifest_id, m.rider_id, u.name as rider_name 
            FROM ManifestOrders mo 
            JOIN Manifests m ON mo.manifest_id = m.id 
            LEFT JOIN Users u ON m.rider_id = u.id 
            WHERE mo.order_id = $order_id
        ");
        
        if ($existing_assignment && mysqli_num_rows($existing_assignment) > 0) {
            $existing = mysqli_fetch_assoc($existing_assignment);
            
            // If already assigned to the same rider, no need to change
            if ($existing['rider_id'] == $rider_id) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Order already assigned to this rider',
                    'manifest_id' => $existing['manifest_id'],
                    'rider_name' => $rider['name']
                ]);
                exit;
            }
            
            // Remove from existing manifest
            mysqli_query($conn, "DELETE FROM ManifestOrders WHERE order_id = $order_id");
            
            // Update old manifest's order count
            mysqli_query($conn, "
                UPDATE Manifests 
                SET total_orders_assigned = (
                    SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = {$existing['manifest_id']}
                ) 
                WHERE id = {$existing['manifest_id']}
            ");
        }
        
        // 3. Look for an existing active manifest for this rider
        $existing_manifest = mysqli_query($conn, "
            SELECT id FROM Manifests 
            WHERE rider_id = $rider_id 
            AND status IN ('pending', 'assigned') 
            AND company_id = {$order['company_id']}
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        
        $manifest_id = null;
        
        if ($existing_manifest && mysqli_num_rows($existing_manifest) > 0) {
            // Use existing manifest
            $manifest = mysqli_fetch_assoc($existing_manifest);
            $manifest_id = $manifest['id'];
        } else {
            // Create new manifest for this rider
            $create_manifest = mysqli_query($conn, "
                INSERT INTO Manifests (rider_id, company_id, status, total_orders_assigned) 
                VALUES ($rider_id, {$order['company_id']}, 'assigned', 0)
            ");
            
            if (!$create_manifest) {
                throw new Exception('Failed to create manifest: ' . mysqli_error($conn));
            }
            
            $manifest_id = mysqli_insert_id($conn);
        }
        
        // 4. Add order to manifest
        $assign_order = mysqli_query($conn, "
            INSERT INTO ManifestOrders (manifest_id, order_id) 
            VALUES ($manifest_id, $order_id)
        ");
        
        if (!$assign_order) {
            throw new Exception('Failed to assign order to manifest: ' . mysqli_error($conn));
        }
        
        // 5. Update order status
        mysqli_query($conn, "UPDATE Orders SET status = 'assigned' WHERE id = $order_id");
        
        // 6. Update manifest's total order count
        mysqli_query($conn, "
            UPDATE Manifests 
            SET total_orders_assigned = (
                SELECT COUNT(*) FROM ManifestOrders WHERE manifest_id = $manifest_id
            ) 
            WHERE id = $manifest_id
        ");
        
        // 7. Update manifest status if it was pending
        mysqli_query($conn, "UPDATE Manifests SET status = 'assigned' WHERE id = $manifest_id AND status = 'pending'");
        
        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true,
            'message' => "Order assigned to {$rider['name']}",
            'manifest_id' => $manifest_id,
            'rider_name' => $rider['name']
        ]);
    }
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>