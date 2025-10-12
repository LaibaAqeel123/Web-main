<?php
// generate_view_pdf.php - Make sure no session_start() here
// Complete output buffer cleanup
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Start fresh output buffer
ob_start();

// Set custom error handler to prevent any output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PDF View Error: $errstr in $errfile on line $errline");
    return true;
});

// Include files with correct paths for your structure
require_once '../../includes/config.php';
require_once '../../includes/generate_pdf.php';
requireLogin(); 

// Clean any potential output
if (ob_get_length() > 0) {
    ob_clean();
}

$order_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;

if (!$order_id) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid Order ID provided.');
}

// Check access rights
$company_id_query = "SELECT company_id FROM Orders WHERE id = ?";
$stmt_check = mysqli_prepare($conn, $company_id_query);
if (!$stmt_check) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain');
    die('Database error preparing check.');
}

mysqli_stmt_bind_param($stmt_check, "i", $order_id);
mysqli_stmt_execute($stmt_check);
$result_check = mysqli_stmt_get_result($stmt_check);
$order_company_info = mysqli_fetch_assoc($result_check);
mysqli_stmt_close($stmt_check);

if (!$order_company_info) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(404);
    header('Content-Type: text/plain');
    die('Order not found.');
}

// Verify permissions
if (!isSuperAdmin() && $_SESSION['company_id'] != $order_company_info['company_id']) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access Denied. You do not have permission to view this order PDF.');
}

// Final buffer cleanup before PDF generation
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    // Generate PDF
    $pdf_filepath = generateOrderPDF($order_id, $conn);
    
    if (!$pdf_filepath) {
        throw new Exception("PDF generation failed - no file path returned");
    }
    
    if (!file_exists($pdf_filepath)) {
        throw new Exception("PDF file does not exist at: $pdf_filepath");
    }
    
    $file_size = filesize($pdf_filepath);
    if ($file_size === false || $file_size == 0) {
        throw new Exception("PDF file is empty or unreadable");
    }
    
    // Verify it's actually a PDF file
    $file_content = file_get_contents($pdf_filepath, false, null, 0, 4);
    if ($file_content !== '%PDF') {
        throw new Exception("Generated file is not a valid PDF");
    }
    
    // Final buffer cleanup before headers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Set headers for PDF response
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Proof_of_Delivery_' . $order_id . '.pdf"');
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output the file content
    readfile($pdf_filepath);
    
    // Delete the temporary file
    if (file_exists($pdf_filepath)) {
        unlink($pdf_filepath);
    }
    
    exit;
    
} catch (Exception $e) {
    // Clean up any partial file
    if (isset($pdf_filepath) && file_exists($pdf_filepath)) {
        unlink($pdf_filepath);
    }
    
    // Clean buffers before error response
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Simple error page
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>PDF Generation Error</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .error { background: #ffecec; border: 1px solid #f5aca6; padding: 20px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="error">
            <h3>PDF Generation Error</h3>
            <p>Sorry, there was an error generating the PDF document.</p>
            <p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>
            <p><a href="javascript:history.back()">Go Back</a></p>
        </div>
    </body>
    </html>';
}

// Restore original error handler
restore_error_handler();
?>