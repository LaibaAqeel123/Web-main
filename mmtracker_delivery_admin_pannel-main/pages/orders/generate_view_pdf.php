<?php
// ULTIMATE OUTPUT CLEANING - NO WHITESPACE BEFORE THIS LINE
while (ob_get_level()) ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once '../../includes/config.php';
require_once '../../includes/generate_pdf.php';
requireLogin();

$order_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if (!$order_id) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain');
    http_response_code(400);
    die('Invalid Order ID provided.');
}

// Quick permission check
$stmt = mysqli_prepare($conn, "SELECT company_id FROM Orders WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $order_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$order = mysqli_fetch_assoc($result);

if (!$order) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain');
    http_response_code(404);
    die('Order not found.');
}

if (!isSuperAdmin() && $_SESSION['company_id'] != $order['company_id']) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain');
    http_response_code(403);
    die('Access Denied. You do not have permission to view this order PDF.');
}

try {
    $pdf_filepath = generateOrderPDF($order_id, $conn);
    
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
    
    // CLEAN ALL BUFFERS BEFORE HEADERS
    while (ob_get_level()) ob_end_clean();
    
    // Set headers for PDF response
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="proof_of_delivery.pdf"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Transfer-Encoding: binary');
    
    // Output the file content
    readfile($pdf_filepath);
    
    // Delete the temporary file
    unlink($pdf_filepath);
    
    exit;
    
} catch (Exception $e) {
    // Clean any remaining output
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: text/plain');
    error_log("PDF Generation Error for Order ID $order_id: " . $e->getMessage());
    
    // Clean up any partial file
    if (isset($pdf_filepath) && file_exists($pdf_filepath)) {
        unlink($pdf_filepath);
    }
    
    http_response_code(500);
    die('Error generating PDF: ' . $e->getMessage());
}
?>