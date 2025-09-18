<?php
// api/get_orders.php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json');

$company_condition = !isSuperAdmin() && !empty($_SESSION['company_id']) ? " AND company_id = " . (int)$_SESSION['company_id'] : "";

$sql = "SELECT id, order_number, status, latitude, longitude FROM Orders WHERE (latitude IS NOT NULL AND longitude IS NOT NULL) $company_condition ORDER BY created_at DESC LIMIT 500";
$res = mysqli_query($conn, $sql);
$orders = [];
while ($r = mysqli_fetch_assoc($res)) $orders[] = $r;

echo json_encode(['status'=>'success','orders'=>$orders]);
