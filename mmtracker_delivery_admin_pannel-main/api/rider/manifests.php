<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    header('Content-Length: 0');
    header('Content-Type: text/plain');
    exit();
}

require_once '../config.php';

// Get and validate token
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : (isset(getallheaders()['Authorization']) ? getallheaders()['Authorization'] : '');
if (empty($auth_header)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header missing']);
    exit();
}

$token = str_replace('Bearer ', '', $auth_header);

// Check token in database
$check_query = "SELECT ut.user_id 
                FROM UserTokens ut
                JOIN Users u ON ut.user_id = u.id
                WHERE ut.token = ? 
                AND ut.is_active = 1 
                AND u.user_type = 'Rider'
                AND u.is_active = 1";
                
$check_stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($check_stmt, "s", $token);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);

if (mysqli_num_rows($check_result) !== 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$token_data = mysqli_fetch_assoc($check_result);
$rider_id = $token_data['user_id'];

// Function to get coordinates from Mapbox
function getCoordinates($address, $city, $state, $postal_code, $country, $address_line2 = '') {
    // Use the Mapbox token
    $mapbox_token = 'pk.eyJ1IjoibW5hMjU4NjciLCJhIjoiY2tldTZiNzlxMXJ6YzJ6cndqY2RocXkydiJ9.Tee5ksW6tXsXXc4HOPJAwg';
    
    // Clean and format the address
    $address_parts = array_filter([
        trim($address),
        trim($address_line2),
        trim($city),
        trim($state),
        trim($postal_code),
        trim($country)
    ]);
    
    $full_address = implode(', ', $address_parts);
    $encoded_address = urlencode($full_address);
    
    $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$encoded_address}.json?access_token={$mapbox_token}&limit=1";
    
    error_log("Geocoding address: " . $full_address);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'DeliveryApp/1.0'
    ]);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($err || $http_code !== 200) {
        error_log("Mapbox API Error: " . $err . " HTTP Code: " . $http_code);
        return ['latitude' => null, 'longitude' => null];
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['features']) && !empty($data['features'])) {
        $coordinates = $data['features'][0]['center'];
        error_log("Found coordinates for $full_address: " . json_encode($coordinates));
        return [
            'longitude' => $coordinates[0],
            'latitude' => $coordinates[1]
        ];
    }
    
    error_log("No coordinates found for address: " . $full_address);
    return ['latitude' => null, 'longitude' => null];
}

// New function to optimize route using Mapbox Directions API
function optimizeRouteWithMapbox($orders, $warehouse) {
    if (empty($orders)) return [];
    
    // Filter out orders that don't have coordinates
    $valid_orders = array_filter($orders, function($order) {
        return !empty($order['latitude']) && !empty($order['longitude']);
    });
    
    if (empty($valid_orders)) return $orders;
    
    // Mapbox token
    $mapbox_token = 'pk.eyJ1IjoibW5hMjU4NjciLCJhIjoiY2tldTZiNzlxMXJ6YzJ6cndqY2RocXkydiJ9.Tee5ksW6tXsXXc4HOPJAwg';
    
    // Build coordinates string for Mapbox API
    $coordinates = [];
    
    // Start with warehouse
    $coordinates[] = $warehouse['longitude'] . ',' . $warehouse['latitude'];
    
    // Add all order coordinates
    foreach ($valid_orders as $order) {
        $coordinates[] = $order['longitude'] . ',' . $order['latitude'];
    }
    
    // End at warehouse (complete the loop)
    $coordinates[] = $warehouse['longitude'] . ',' . $warehouse['latitude'];
    
    // Build the Mapbox Directions API URL
    // We use the 'driving' profile and request the 'waypoints' in the response
    $url = "https://api.mapbox.com/optimized-trips/v1/mapbox/driving/" . 
           implode(';', $coordinates) . 
           "?overview=full&steps=false&source=first&destination=last&roundtrip=true&access_token=" . $mapbox_token;
    
    error_log("Calling Mapbox Optimized Trips API: " . $url);
    
    // Make the API request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'DeliveryApp/1.0'
    ]);
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($err || $http_code !== 200) {
        error_log("Mapbox Optimization API Error: " . $err . " HTTP Code: " . $http_code . " Response: " . $response);
        // Fall back to the original nearest neighbor algorithm if Mapbox API fails
        return optimizeRouteWithNearestNeighbor($orders, $warehouse);
    }
    
    $data = json_decode($response, true);
    
    // Check if we got a valid response with waypoints
    if (!isset($data['waypoints']) || empty($data['waypoints'])) {
        error_log("No waypoints in Mapbox response: " . json_encode($data));
        return optimizeRouteWithNearestNeighbor($orders, $warehouse);
    }
    
    // Reorder the orders based on the waypoint indices from Mapbox
    // Note: Mapbox returns waypoints that include the start/end warehouse,
    // so we need to exclude waypoint index 0 and the last index
    $optimized_orders = [];
    $waypoints = $data['waypoints'];
    
    // Get the original valid orders into an indexed array
    $indexed_valid_orders = array_values($valid_orders);
    
    // Process waypoints, skipping first and last (warehouse)
    for ($i = 1; $i < count($waypoints) - 1; $i++) {
        $waypoint = $waypoints[$i];
        $order_index = $waypoint['waypoint_index'] - 1; // Adjust index to match our valid_orders array
        
        if (isset($indexed_valid_orders[$order_index])) {
            // Add the estimated arrival time from the API if available
            if (isset($data['trips'][0]['legs'][$i-1]['duration'])) {
                $indexed_valid_orders[$order_index]['estimated_travel_time'] = 
                    round($data['trips'][0]['legs'][$i-1]['duration'] / 60); // Convert seconds to minutes
            }
            
            $optimized_orders[] = $indexed_valid_orders[$order_index];
        }
    }
    
    // Add any orders that didn't have coordinates at the end
    $invalid_orders = array_filter($orders, function($order) {
        return empty($order['latitude']) || empty($order['longitude']);
    });
    
    return array_merge($optimized_orders, $invalid_orders);
}

// Fallback function using nearest neighbor algorithm
function optimizeRouteWithNearestNeighbor($orders, $warehouse) {
    if (empty($orders)) return [];
    
    $warehouse_coords = [
        'lat' => floatval($warehouse['latitude']),
        'lng' => floatval($warehouse['longitude'])
    ];
    
    $unvisited = $orders;
    $optimized = [];
    $current_point = $warehouse_coords;
    
    while (!empty($unvisited)) {
        $nearest = null;
        $shortest_distance = PHP_FLOAT_MAX;
        $nearest_index = 0;
        
        // Find nearest unvisited point
        foreach ($unvisited as $index => $order) {
            if (!$order['latitude'] || !$order['longitude']) continue;
            
            $distance = calculateDistance(
                $current_point['lat'],
                $current_point['lng'],
                floatval($order['latitude']),
                floatval($order['longitude'])
            );
            
            if ($distance < $shortest_distance) {
                $shortest_distance = $distance;
                $nearest = $order;
                $nearest_index = $index;
            }
        }
        
        if ($nearest) {
            // Add estimated travel time
            $nearest['estimated_travel_time'] = estimateTravelTime(
                $current_point['lat'],
                $current_point['lng'],
                floatval($nearest['latitude']),
                floatval($nearest['longitude'])
            );
            
            $optimized[] = $nearest;
            unset($unvisited[$nearest_index]);
            $current_point = [
                'lat' => floatval($nearest['latitude']),
                'lng' => floatval($nearest['longitude'])
            ];
        } else {
            // Handle any remaining orders without coordinates
            $optimized = array_merge($optimized, array_values($unvisited));
            break;
        }
    }
    
    return $optimized;
}

// Helper function to calculate distance between two points
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $r = 6371; // Earth's radius in km
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    
    $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlon/2) * sin($dlon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $r * $c;
}

// Helper function to calculate estimated travel time between two points (fallback method)
function estimateTravelTime($lat1, $lon1, $lat2, $lon2) {
    // Calculate distance in kilometers
    $distance = calculateDistance($lat1, $lon1, $lat2, $lon2);
    
    // Assume average driving speed of 30 km/h in urban areas
    $average_speed = 30;
    
    // Return estimated time in minutes
    return round(($distance / $average_speed) * 60);
}

try {
    // Get manifests assigned to the rider
    $query = "SELECT m.*, c.name as company_name,
              w.address as warehouse_address, w.city as warehouse_city, 
              w.state as warehouse_state, w.postal_code as warehouse_postal_code,
              w.country as warehouse_country, w.latitude as warehouse_latitude,
              w.longitude as warehouse_longitude
              FROM Manifests m
              LEFT JOIN Companies c ON m.company_id = c.id
              LEFT JOIN Warehouses w ON m.warehouse_id = w.id
              WHERE m.rider_id = ? AND m.status != 'delivered'
              ORDER BY m.created_at DESC";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $rider_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $manifests = [];
    while ($manifest = mysqli_fetch_assoc($result)) {
        // Format warehouse data
        $warehouse = null;
        if ($manifest['warehouse_address']) {
            $warehouse = [
                'address' => $manifest['warehouse_address'],
                'city' => $manifest['warehouse_city'],
                'state' => $manifest['warehouse_state'],
                'postal_code' => $manifest['warehouse_postal_code'],
                'country' => $manifest['warehouse_country'],
                'latitude' => $manifest['warehouse_latitude'],
                'longitude' => $manifest['warehouse_longitude']
            ];
        }

        // Get orders for this manifest
        $orders_query = "SELECT o.*, 
                        cust.name as customer_name, cust.email as email, cust.phone as phone,
                        addr.address_line1, addr.city, addr.state, addr.postal_code, addr.country, addr.address_line2,
                        GROUP_CONCAT(p.id ORDER BY po.id) as product_ids, 
                        GROUP_CONCAT(p.name ORDER BY po.id) as product_names,
                        GROUP_CONCAT(po.quantity ORDER BY po.id) as quantities,
                        GROUP_CONCAT(po.price ORDER BY po.id) as prices,
                        GROUP_CONCAT(p.qrcode_number ORDER BY po.id) as qrcode_numbers,
                        GROUP_CONCAT(IFNULL(po.picked_quantity, 0) ORDER BY po.id) as picked_quantities,
                        GROUP_CONCAT(IFNULL(po.missing_quantity, 0) ORDER BY po.id) as missing_quantities
                        FROM Orders o
                        JOIN ManifestOrders mo ON o.id = mo.order_id
                        LEFT JOIN Customers cust ON o.customer_id = cust.id
                        LEFT JOIN ProductOrders po ON o.id = po.order_id
                        LEFT JOIN Products p ON po.product_id = p.id
                        LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
                        WHERE mo.manifest_id = ?
                        GROUP BY o.id";
        
        $orders_stmt = mysqli_prepare($conn, $orders_query);
        mysqli_stmt_bind_param($orders_stmt, "i", $manifest['id']);
        mysqli_stmt_execute($orders_stmt);
        $orders_result = mysqli_stmt_get_result($orders_stmt);
        
        $orders = [];
        while ($order = mysqli_fetch_assoc($orders_result)) {
            // Get coordinates for the order's address
            $coordinates = getCoordinates(
                $order['address_line1'] ?? '', 
                $order['city'] ?? '', 
                $order['state'] ?? '',
                $order['postal_code'] ?? '',
                $order['country'] ?? '',
                $order['address_line2'] ?? ''
            );
            
            // Format products data - Add null checks
            $product_ids = !empty($order['product_ids']) ? explode(',', $order['product_ids']) : [];
            $product_names = !empty($order['product_names']) ? explode(',', $order['product_names']) : [];
            $quantities = !empty($order['quantities']) ? explode(',', $order['quantities']) : [];
            $prices = !empty($order['prices']) ? explode(',', $order['prices']) : [];
            $qrcode_numbers = !empty($order['qrcode_numbers']) ? explode(',', $order['qrcode_numbers']) : [];
            $picked_quantities = !empty($order['picked_quantities']) ? explode(',', $order['picked_quantities']) : [];
            $missing_quantities = !empty($order['missing_quantities']) ? explode(',', $order['missing_quantities']) : [];
            
            $products = [];
            if (!empty($product_ids)) {
                for ($i = 0; $i < count($product_ids); $i++) {
                    if (!empty($product_ids[$i])) {  // Only add product if ID exists
                        $total_quantity = (int)($quantities[$i] ?? 0);
                        $picked = (int)($picked_quantities[$i] ?? 0);
                        $missing = (int)($missing_quantities[$i] ?? 0);
                        
                        // Ensure picked and missing quantities are properly calculated
                        $products[] = [
                            'id' => $product_ids[$i] ?? '',
                            'name' => $product_names[$i] ?? '',
                            'quantity' => (string)$total_quantity,
                            'price' => $prices[$i] ?? '0',
                            'qrcode_number' => $qrcode_numbers[$i] ?? '',
                            'picked_quantity' => $picked,
                            'missing_quantity' => $missing,
                            'remaining_quantity' => $total_quantity - ($picked + $missing)
                        ];
                    }
                }
            }
            
            // Add coordinates to order data
            $order['latitude'] = $coordinates['latitude'];
            $order['longitude'] = $coordinates['longitude'];
            $order['products'] = $products;
            unset($order['product_ids'], $order['product_names'], $order['quantities'], 
                  $order['prices'], $order['qrcode_numbers'], $order['picked_quantities'], 
                  $order['missing_quantities']);
            
            // Ensure boolean values for proof requirements
            $order['requires_image_proof'] = (bool)$order['requires_image_proof'];
            $order['requires_signature_proof'] = (bool)$order['requires_signature_proof'];
            
            $orders[] = $order;
        }
        
        // Initialize route summary
        $route_summary = [
            'total_stops' => count($orders),
            'estimated_duration_minutes' => 0,
            'estimated_distance_km' => 0
        ];
        
        // Optimize route if warehouse coordinates exist using Mapbox
        if ($warehouse && $warehouse['latitude'] && $warehouse['longitude']) {
            $orders = optimizeRouteWithMapbox($orders, $warehouse);
            
            // Calculate total estimated time and distance
            $total_duration = 0;
            foreach ($orders as $order) {
                if (isset($order['estimated_travel_time'])) {
                    $total_duration += $order['estimated_travel_time'];
                }
            }
            
            $route_summary['estimated_duration_minutes'] = $total_duration;
        }
        
        // Build manifest data with optimized orders
        $manifest_data = [
            'id' => $manifest['id'],
            'status' => $manifest['status'],
            'total_orders_assigned' => $manifest['total_orders_assigned'],
            'created_at' => $manifest['created_at'],
            'company_id' => $manifest['company_id'],
            'company_name' => $manifest['company_name'],
            'warehouse' => $warehouse,
            'route_summary' => $route_summary,
            'orders' => $orders
        ];
        
        $manifests[] = $manifest_data;
    }
    
    echo json_encode([
        'success' => true,
        'manifests' => $manifests
    ]);

} catch (Exception $e) {
    error_log("Error in manifests.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
