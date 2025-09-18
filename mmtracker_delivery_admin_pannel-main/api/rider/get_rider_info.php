<?php
require_once '../includes/config.php';
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['rider_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Rider ID required']);
    exit;
}

$rider_id = (int)$_GET['rider_id'];
$current_company_id = $_SESSION['company_id'] ?? null;

// Different queries for Super Admin and Company Admin
if (isSuperAdmin()) {
    // Super Admin can see all rider info
    $query = "SELECT 
        u.name,
        u.company_id as primary_company_id,
        GROUP_CONCAT(rc.company_id) as assigned_companies
    FROM Users u
    LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id AND rc.is_active = 1
    WHERE u.id = ? AND u.user_type = 'Rider'
    GROUP BY u.id";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $rider_id);
} else {
    // Company Admin can only see riders assigned to their company
    $query = "SELECT 
        u.name,
        u.company_id as primary_company_id,
        GROUP_CONCAT(rc.company_id) as assigned_companies
    FROM Users u
    LEFT JOIN RiderCompanies rc ON u.id = rc.rider_id AND rc.is_active = 1
    WHERE u.id = ? 
    AND u.user_type = 'Rider'
    AND (u.company_id = ? OR rc.company_id = ?)
    GROUP BY u.id";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "iii", $rider_id, $current_company_id, $current_company_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    // Convert assigned_companies string to array
    $assigned_companies = $row['assigned_companies'] ? 
        array_map('intval', explode(',', $row['assigned_companies'])) : 
        [];

    // Add primary company to the list if not null
    if ($row['primary_company_id']) {
        $assigned_companies[] = (int)$row['primary_company_id'];
    }

    // Remove duplicates
    $assigned_companies = array_unique($assigned_companies);

    echo json_encode([
        'name' => $row['name'],
        'company_id' => $current_company_id ?? $row['primary_company_id'],
        'all_company_ids' => $assigned_companies
    ]);
} else {
    http_response_code(404);
    echo json_encode([
        'error' => 'Rider not found or not assigned to your company',
        'rider_id' => $rider_id,
        'company_id' => $current_company_id
    ]);
}

mysqli_stmt_close($stmt); 