<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = intval($_SESSION['user_id']);

// Fetch all dashboard preferences for this user
$sql = "SELECT panel_id, width, height, grid_columns 
        FROM user_dashboard_preferences 
        WHERE user_id = $user_id";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
    exit;
}

$preferences = [];
while ($row = mysqli_fetch_assoc($result)) {
    $preferences[$row['panel_id']] = [
        'width' => $row['width'],
        'height' => $row['height'],
        'grid_columns' => $row['grid_columns']
    ];
}

echo json_encode([
    'success' => true,
    'preferences' => $preferences
]);

mysqli_close($conn);
?>