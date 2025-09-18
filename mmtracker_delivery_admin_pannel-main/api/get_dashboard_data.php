<?php
// api/get_dashboard_data.php
require_once '../includes/config.php';
requireLogin();
header('Content-Type: application/json');

$company_id = $_SESSION['company_id'] ?? null;
$company_where = (!isSuperAdmin() && $company_id) ? "AND o.company_id = " . intval($company_id) : "";

// Orders (include lat/long, rider_id, manifest_id)
$orders_sql = "
  SELECT o.id, o.order_number, o.status, o.latitude, o.longitude, o.rider_id, o.manifest_id, c.name AS customer_name
  FROM Orders o
  LEFT JOIN Customers c ON o.customer_id = c.id
  WHERE 1 = 1 $company_where
  ORDER BY o.created_at DESC
  LIMIT 1000
";
$orders_res = mysqli_query($conn, $orders_sql);
$orders = [];
while ($r = mysqli_fetch_assoc($orders_res)) {
    $orders[] = $r;
}

// Riders: latest RidersLocations per rider plus user name
$riders_sql = "
  SELECT u.id AS rider_id, u.name AS rider_name, rl.lat, rl.lng, rl.created_at
  FROM Users u
  LEFT JOIN (
    SELECT rl1.* FROM RidersLocations rl1
    INNER JOIN (
      SELECT rider_id, MAX(created_at) AS latest_at FROM RidersLocations GROUP BY rider_id
    ) latest ON rl1.rider_id = latest.rider_id AND rl1.created_at = latest.latest_at
  ) rl ON u.id = rl.rider_id
  WHERE u.user_type = 'Rider' AND u.is_active = 1
";
$riders_res = mysqli_query($conn, $riders_sql);
$riders = [];
while ($r = mysqli_fetch_assoc($riders_res)) {
    $riders[] = $r;
}

// Routes/Manifests
$routes_sql = "
  SELECT m.id, m.name, m.status,
    (SELECT COUNT(*) FROM Orders o WHERE o.manifest_id = m.id) AS orders_count
  FROM Manifests m
  WHERE 1 = 1 " . ((!isSuperAdmin() && $company_id) ? " AND m.company_id = " . intval($company_id) : "") . "
  ORDER BY m.id DESC
";
$routes_res = mysqli_query($conn, $routes_sql);
$routes = [];
while ($r = mysqli_fetch_assoc($routes_res)) {
    $routes[] = $r;
}

echo json_encode([
    'success' => true,
    'orders' => $orders,
    'riders' => $riders,
    'routes' => $routes
], JSON_UNESCAPED_UNICODE);
exit;
?>
