<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['panel_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$panel_id = mysqli_real_escape_string($conn, $input['panel_id']);
$width = isset($input['width']) ? mysqli_real_escape_string($conn, $input['width']) : '100%';
$height = isset($input['height']) ? mysqli_real_escape_string($conn, $input['height']) : '220px';
$grid_columns = isset($input['grid_columns']) ? mysqli_real_escape_string($conn, $input['grid_columns']) : NULL;

// Use INSERT ... ON DUPLICATE KEY UPDATE for upsert behavior
$sql = "INSERT INTO user_dashboard_preferences (user_id, panel_id, width, height, grid_columns) 
        VALUES ($user_id, '$panel_id', '$width', '$height', " . ($grid_columns ? "'$grid_columns'" : "NULL") . ")
        ON DUPLICATE KEY UPDATE 
        width = '$width', 
        height = '$height',
        grid_columns = " . ($grid_columns ? "'$grid_columns'" : "NULL");

if (mysqli_query($conn, $sql)) {
    echo json_encode([
        'success' => true, 
        'message' => 'Layout saved successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>