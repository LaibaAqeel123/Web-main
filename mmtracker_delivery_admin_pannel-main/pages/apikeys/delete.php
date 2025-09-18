<?php
require_once '../../includes/config.php';
requireLogin();

if (isset($_GET['id'])) {
    $id = cleanInput($_GET['id']);
    
    // Check if user has permission to delete this key
    $company_condition = !isSuperAdmin() ? "AND company_id = " . $_SESSION['company_id'] : "";
    
    $query = "UPDATE ApiKeys SET is_active = 0 
              WHERE id = ? $company_condition";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $id);
    
    if (mysqli_stmt_execute($stmt)) {
        header('Location: index.php?deleted=1');
    } else {
        header('Location: index.php?error=1');
    }
    exit();
}

header('Location: index.php');
exit(); 