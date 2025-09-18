<?php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if user is logged in (basic security)
if (!isLoggedIn()) {
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;

// If logged-in user is not Super Admin, force their company ID
if (!isSuperAdmin()) {
    $company_id = $_SESSION['company_id'];
}

$defaults = [
    'requires_image_proof' => true, // Default if company not found or not provided
    'requires_signature_proof' => true
];

if ($company_id > 0) {
    $query = "SELECT default_requires_image_proof, default_requires_signature_proof 
              FROM Companies 
              WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $company_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $company_data = mysqli_fetch_assoc($result);

    if ($company_data) {
        $defaults['requires_image_proof'] = (bool)$company_data['default_requires_image_proof'];
        $defaults['requires_signature_proof'] = (bool)$company_data['default_requires_signature_proof'];
    }
}

echo json_encode($defaults);
exit();
?> 