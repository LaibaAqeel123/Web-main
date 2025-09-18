<?php
require_once '../../includes/config.php';
require_once '../../includes/generate_pdf.php';
requireLogin();

$order_id = null;
if (isset($_GET['id'])) {
    $order_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
}

if (!$order_id) {
    http_response_code(400);
    die('Invalid Order ID provided.');
}

// Check access rights first by fetching the order's company ID
$company_id_query = "SELECT company_id FROM Orders WHERE id = ?";
$stmt_check = mysqli_prepare($conn, $company_id_query);
if (!$stmt_check) {
     http_response_code(500);
     die('Database error preparing check.');
}
mysqli_stmt_bind_param($stmt_check, "i", $order_id);
mysqli_stmt_execute($stmt_check);
$result_check = mysqli_stmt_get_result($stmt_check);
$order_company_info = mysqli_fetch_assoc($result_check);
mysqli_stmt_close($stmt_check);

if (!$order_company_info) {
    http_response_code(404);
    die('Order not found.');
}

// Verify permissions
if (!isSuperAdmin() && $_SESSION['company_id'] != $order_company_info['company_id']) {
    http_response_code(403);
    die('Access Denied. You do not have permission to view this order PDF.');
}


try {
    $pdf_filepath = generateOrderPDF($order_id, $conn);
    
    // Debug: Log the returned filepath
    error_log("PDF filepath returned: " . ($pdf_filepath ? $pdf_filepath : 'NULL'));
    
    if (!$pdf_filepath) {
        throw new Exception("PDF generation returned null/false");
    }
    
    if (!file_exists($pdf_filepath)) {
        throw new Exception("PDF file does not exist at: $pdf_filepath");
    }
    
    $file_size = filesize($pdf_filepath);
    if ($file_size === false || $file_size == 0) {
        throw new Exception("PDF file is empty or unreadable: $pdf_filepath");
    }
    
    // Verify it's actually a PDF file
    $file_content = file_get_contents($pdf_filepath, false, null, 0, 4);
    if ($file_content !== '%PDF') {
        throw new Exception("Generated file is not a valid PDF");
    }
    
    // Clear any previous output/errors
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Set headers for inline PDF display
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($pdf_filepath) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0, max-age=1');
    header('Pragma: public');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    
    // Output the file content
    readfile($pdf_filepath);
    
    // Delete the temporary file
    unlink($pdf_filepath);
    
    exit; // Stop script execution after sending file
    
} catch (Exception $e) {
    // Log the specific error
    error_log("PDF Generation Error for Order ID $order_id: " . $e->getMessage());
    
    // Clean up any partial file
    if (isset($pdf_filepath) && file_exists($pdf_filepath)) {
        unlink($pdf_filepath);
    }
    
    http_response_code(500);
    die('Error generating PDF: ' . $e->getMessage());
}
?>