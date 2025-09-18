<?php
require_once '../../includes/config.php';
requireLogin();

header('Content-Type: application/json');

$query = "
    SELECT DISTINCT rl.*, u.name as rider_name
    FROM RidersLocations rl
    INNER JOIN (
        SELECT rider_id, MAX(created_at) as latest_location
        FROM RidersLocations
        GROUP BY rider_id
    ) latest ON rl.rider_id = latest.rider_id AND rl.created_at = latest.latest_location
    INNER JOIN Users u ON rl.rider_id = u.id
    LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id
    WHERE u.user_type = 'Rider'";

if (!isSuperAdmin() && $_SESSION['company_id']) {
    $query .= " AND (u.company_id = " . $_SESSION['company_id'] . 
              " OR rc.company_id = " . $_SESSION['company_id'] . ")";
}

$result = mysqli_query($conn, $query);
$locations = [];

while ($row = mysqli_fetch_assoc($result)) {
    $locations[] = $row;
}

echo json_encode($locations);


