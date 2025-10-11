<?php
session_start();
require_once '../config.php';
header('Content-Type: application/json');

$sql = "SELECT u.id, u.name, rl.lat, rl.lng 
        FROM Users u 
        LEFT JOIN RidersLocations rl ON rl.rider_id = u.id 
        WHERE u.user_type = 'Rider' AND u.is_active = 1";
$res = $conn->query($sql);

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
