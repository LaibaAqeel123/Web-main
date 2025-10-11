<?php
require_once __DIR__ . '/tcpdf/tcpdf.php'; // Adjust path if TCPDF is placed elsewhere
require_once __DIR__ . '/config.php'; // Assuming config.php is one level up

// --- Extend TCPDF to create custom Header --- 
class POD_PDF extends TCPDF {
    public $orderNumber = '';
    public $companyName = '';

    //Page header
    public function Header() {
        // Set font
        $this->SetFont('helvetica', 'B', 14);
        // Company Name
        $this->Cell(0, 10, $this->companyName, 0, false, 'L', 0, '', 0, false, 'M', 'M');
        $this->Ln(5);
        // Title
        $this->SetFont('helvetica', 'B', 16);
        $this->Cell(0, 15, 'Proof of Delivery', 0, true, 'C', 0, '', 0, false, 'M', 'M');
         // Order Number
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 8, 'Order #' . $this->orderNumber, 0, false, 'R', 0, '', 0, false, 'M', 'M');
        // Line break
        $this->Ln(5);
        // Header line
        $this->Line(PDF_MARGIN_LEFT, $this->GetY(), $this->getPageWidth() - PDF_MARGIN_RIGHT, $this->GetY());
        $this->Ln(5);
    }

    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}
// --- End Custom PDF Class --- 

// Function to generate PDF for a given order
// Returns the path to the generated PDF file or false on failure
function generateOrderPDF($order_id, $conn) {
    
    // 1. Fetch Order Details (including Customer, Address, Company, Org, Lat/Lng)
    $query = "SELECT 
                o.id, o.order_number, o.status, o.total_amount, o.created_at, o.notes, 
                o.proof_photo_url, o.proof_signature_path, o.requires_image_proof, o.requires_signature_proof,
                o.latitude, o.longitude,
                cust.name as customer_name, cust.phone as customer_phone, cust.email as customer_email,
                addr.address_line1, addr.address_line2, addr.city, addr.state, addr.postal_code, addr.country,
                m.id as manifest_id,
                u.name as rider_name,
                c.name as company_name,
                org.name as organization_name,
                GROUP_CONCAT(p.name SEPARATOR '\n') as product_names,
                GROUP_CONCAT(po.quantity SEPARATOR '\n') as quantities,
                GROUP_CONCAT(po.price SEPARATOR '\n') as prices
              FROM Orders o
              LEFT JOIN Customers cust ON o.customer_id = cust.id
              LEFT JOIN Addresses addr ON o.delivery_address_id = addr.id
              LEFT JOIN ManifestOrders mo ON o.id = mo.order_id
              LEFT JOIN Manifests m ON mo.manifest_id = m.id
              LEFT JOIN Users u ON m.rider_id = u.id
              LEFT JOIN Companies c ON o.company_id = c.id
              LEFT JOIN Organizations org ON o.organization_id = org.id
              LEFT JOIN ProductOrders po ON o.id = po.order_id
              LEFT JOIN Products p ON po.product_id = p.id
              WHERE o.id = ?
              GROUP BY o.id";
              
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("PDF Gen Error: Failed to prepare order fetch statement: " . mysqli_error($conn));
        return false;
    }
    mysqli_stmt_bind_param($stmt, "i", $order_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $order = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$order) {
        error_log("PDF Gen Error: Order not found for ID: $order_id");
        return false;
    }

    // 2. Fetch Product Details
    $products = [];
    $product_query = "SELECT p.name, po.quantity 
                      FROM ProductOrders po 
                      JOIN Products p ON po.product_id = p.id 
                      WHERE po.order_id = ?";
    $product_stmt = mysqli_prepare($conn, $product_query);
     if (!$product_stmt) {
        error_log("PDF Gen Error: Failed to prepare product fetch statement: " . mysqli_error($conn));
        return false;
    }
    mysqli_stmt_bind_param($product_stmt, "i", $order_id);
    mysqli_stmt_execute($product_stmt);
    $product_result = mysqli_stmt_get_result($product_stmt);
    while ($row = mysqli_fetch_assoc($product_result)) {
        $products[] = $row;
    }
    mysqli_stmt_close($product_stmt);

    // Fetch delivery confirmation details (timestamp, delivered_to) from OrderStatusLogs
    $log_query = "SELECT changed_at, delivered_to, reason 
                  FROM OrderStatusLogs 
                  WHERE order_id = ? AND (status = 'delivered' OR status = 'failed')
                  ORDER BY changed_at DESC LIMIT 1";
    $log_stmt = mysqli_prepare($conn, $log_query);
    $lat = null;
    $lng = null;
    $delivery_timestamp = 'N/A';
    $delivered_to = $order['status'] === 'delivered' ? 'N/A' : null; // Default for delivered
    $failure_reason = $order['status'] === 'failed' ? 'N/A' : null; // Default for failed

    if ($log_stmt) {
        mysqli_stmt_bind_param($log_stmt, "i", $order_id);
        mysqli_stmt_execute($log_stmt);
        $log_result = mysqli_stmt_get_result($log_stmt);
        if ($log_row = mysqli_fetch_assoc($log_result)) {
            $delivery_timestamp = date('Y-m-d H:i:s', strtotime($log_row['changed_at']));
            if ($order['status'] === 'delivered') {
                $delivered_to = $log_row['delivered_to'] ?: 'N/A';
            } else if ($order['status'] === 'failed') {
                $failure_reason = $log_row['reason'] ?: 'Unknown';
            }
        } else {
            error_log("No delivered/failed status log found for order ID: $order_id");
        }
    } else {
        error_log("Failed to prepare statement for status log: " . mysqli_error($conn));
    }

    // Use latitude and longitude directly from the Orders table
    $lat = $order['latitude'];
    $lng = $order['longitude'];

    // --- ADD LOGGING FOR LAT/LNG --- 
    error_log("PDF Gen Lat/Lng Check - Order ID: $order_id, Fetched Lat from Orders: " . var_export($lat, true) . ", Fetched Lng from Orders: " . var_export($lng, true));
    // --- END LOGGING --- 

    // 4. Initialize Custom TCPDF Class
    try {
        // Use the extended class
        $pdf = new POD_PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Pass data to custom header
        $pdf->companyName = $order['company_name'] ?? 'Delivery System';
        $pdf->orderNumber = $order['order_number'] ?? 'N/A';

        // Set document information
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor($pdf->companyName);
        $pdf->SetTitle('Proof of Delivery - Order #' . $pdf->orderNumber);
        $pdf->SetSubject('Proof of Delivery');

        $pdf->setPrintHeader(true); // Enable custom header
        $pdf->setPrintFooter(true); // Enable custom footer

        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // Set margins (Top margin adjusted for header)
        $pdf->SetMargins(PDF_MARGIN_LEFT, 35, PDF_MARGIN_RIGHT); // Left, Top, Right
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // Set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Add a page
        $pdf->AddPage();

        // --- Start PDF Content (Redesigned) ---
        $col_width = ($pdf->getPageWidth() - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT) / 2 - 5; // Width for two columns with gap

        // --- Left Column: Order & Customer Info --- 
        $pdf->SetY($pdf->GetY()); // Ensure Y position is correct after header
        $current_y = $pdf->GetY();
        
        $left_col_html = '';
        // Order Details
        $left_col_html .= '<div style="font-size: 11pt; font-weight: bold; margin-bottom: 5px;">Order Details</div>';
        $left_col_html .= '<table border="0" cellspacing="1" cellpadding="2" style="font-size: 9pt;">';
        $left_col_html .= '<tr><td width="35%"><b>Order Date:</b></td><td width="65%">' . date('M d, Y H:i', strtotime($order['created_at'])) . '</td></tr>';
        // Display Organization Name if it exists
        if (!empty($order['organization_name'])) {
             $left_col_html .= '<tr><td><b>Organization:</b></td><td>' . htmlspecialchars($order['organization_name']) . '</td></tr>';
        }
         $left_col_html .= '<tr><td><b>Status:</b></td><td>' . htmlspecialchars($order['status']) . '</td></tr>';
         $left_col_html .= '</table><br />'; // Added line break

        // Customer Details
        $left_col_html .= '<div style="font-size: 11pt; font-weight: bold; margin-bottom: 5px;">Customer Information</div>';
        $left_col_html .= '<div style="font-size: 9pt; line-height: 1.4;">';
        $left_col_html .= '<b>Name:</b> ' . htmlspecialchars($order['customer_name']) . '<br />';
        $left_col_html .= '<b>Email:</b> ' . htmlspecialchars($order['customer_email']) . '<br />';
        if (!empty($order['customer_phone'])) $left_col_html .= '<b>Phone:</b> ' . htmlspecialchars($order['customer_phone']);
        $left_col_html .= '</div>';

        $pdf->writeHTMLCell($col_width, 0, '', $current_y, $left_col_html, 0, 0, false, true, 'L', true);
        // --- End Left Column --- 

        // --- Right Column: Delivery Address & Confirmation --- 
        $right_col_html = '';
        // Delivery Address
        $right_col_html .= '<div style="font-size: 11pt; font-weight: bold; margin-bottom: 5px;">Delivery Address</div>';
        $right_col_html .= '<div style="font-size: 9pt; line-height: 1.4;">';
        $right_col_html .= htmlspecialchars($order['address_line1']) . '<br />';
        if (!empty($order['address_line2'])) $right_col_html .= htmlspecialchars($order['address_line2']) . '<br />';
        $right_col_html .= htmlspecialchars($order['city']) . ', ';
        if (!empty($order['state'])) $right_col_html .= htmlspecialchars($order['state']) . ', ';
        $right_col_html .= htmlspecialchars($order['postal_code']) . '<br />';
        $right_col_html .= htmlspecialchars($order['country']);
        $right_col_html .= '</div><br />'; // Added line break
        
        // Delivery Confirmation
        $right_col_html .= '<div style="font-size: 11pt; font-weight: bold; margin-bottom: 5px;">Delivery Confirmation</div>';
        $right_col_html .= '<div style="font-size: 9pt; line-height: 1.4;">';
        $right_col_html .= '<b>Delivery Timestamp:</b><br />' . $delivery_timestamp . '<br />';
        $right_col_html .= '<b>Delivered To:</b><br />' . $delivered_to . '<br />';
        if ($order['status'] === 'failed') {
            $right_col_html .= '<b>Failure Reason:</b><br />' . $failure_reason . '<br />';
        }
        $right_col_html .= '<b>Latitude:</b><br />' . ($lat !== null ? htmlspecialchars(number_format($lat, 6)) : 'N/A') . '<br />';
        $right_col_html .= '<b>Longitude:</b><br />' . ($lng !== null ? htmlspecialchars(number_format($lng, 6)) : 'N/A') . '<br />';
        $right_col_html .= '</div>';

        $pdf->writeHTMLCell($col_width, 0, PDF_MARGIN_LEFT + $col_width + 5, $current_y, $right_col_html, 0, 1, false, true, 'L', true);
        // --- End Right Column --- 
        
        $pdf->Ln(8); // Add space after columns

        // --- Products Table --- 
        if (!empty($products)) {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'Delivered Products', 0, 1, 'L');
            $pdf->Ln(1);
            
            // Define Colors and Font
            $header_bg_color = array(224, 224, 224); // Light Grey
            $header_text_color = array(0, 0, 0); // Black
            $row_colors = array(array(255, 255, 255), array(245, 245, 245)); // White, Light Grey
            $border_color = array(150, 150, 150);
            $pdf->SetFillColorArray($header_bg_color);
            $pdf->SetTextColorArray($header_text_color);
            $pdf->SetDrawColorArray($border_color);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetLineWidth(0.2);

            // Header
            $w = array(($pdf->getPageWidth() - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT) * 0.8, ($pdf->getPageWidth() - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT) * 0.2);
            $pdf->Cell($w[0], 7, 'Product Name', 1, 0, 'L', 1); // Fill=1 for background
            $pdf->Cell($w[1], 7, 'Quantity', 1, 1, 'R', 1);

            // Data
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetFillColorArray($row_colors[0]); // Reset fill color
            $pdf->SetTextColorArray(array(0,0,0)); // Reset text color
            $fill = false;
            foreach ($products as $product) {
                $pdf->SetFillColorArray($row_colors[$fill ? 1 : 0]);
                $pdf->MultiCell($w[0], 6, htmlspecialchars($product['name']), 1, 'L', 1, 0, '', '', true, 0, false, true, 0, 'M');
                $pdf->MultiCell($w[1], 6, htmlspecialchars($product['quantity']), 1, 'R', 1, 1, '', '', true, 0, false, true, 0, 'M');
                $fill = !$fill;
            }
            $pdf->Ln(8);
        }
        // --- End Products Table --- 
        
        // --- Notes --- 
        if (!empty($order['notes'])) {
             $pdf->SetFont('helvetica', 'B', 11);
             $pdf->Cell(0, 8, 'Delivery Notes', 0, 1, 'L');
             $pdf->SetFont('helvetica', '', 9);
             $pdf->SetFillColor(245, 245, 245);
             $pdf->MultiCell(0, 5, htmlspecialchars($order['notes']), 'LTRB', 'L', true, 1);
             $pdf->Ln(8);
        }
        // --- End Notes --- 

        // --- Delivery Proofs --- 
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Delivery Proofs', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', '', 9);
        
        // Embed Images
        $proof_photo_path_on_server = null;
        $proof_sig_path_on_server = null;

        if (!empty($order['proof_photo_url'])) {
            // Corrected base path: Go up one level from includes, then into api/
            $base_path = realpath(__DIR__ . '/../api'); 
            $image_relative_path = $order['proof_photo_url'];
            if ($base_path && $image_relative_path) {
                 $proof_photo_path_on_server = $base_path . '/' . $image_relative_path;
            } else {
                 $proof_photo_path_on_server = null; // Base path resolution failed
            }
           
             error_log("PDF Gen: Attempting to access photo path: " . $proof_photo_path_on_server);
             if (!$proof_photo_path_on_server || !file_exists($proof_photo_path_on_server)) {
                 error_log("PDF Gen Error: Photo proof file not found or path incorrect: " . ($proof_photo_path_on_server ?: $order['proof_photo_url']));
                 $proof_photo_path_on_server = null; // Prevent trying to embed non-existent file
             }
        }
         if (!empty($order['proof_signature_path'])) {
             // Corrected base path: Go up one level from includes, then into api/
            $base_path = realpath(__DIR__ . '/../api'); 
            $sig_relative_path = $order['proof_signature_path'];
             if ($base_path && $sig_relative_path) {
                 $proof_sig_path_on_server = $base_path . '/' . $sig_relative_path;
            } else {
                 $proof_sig_path_on_server = null; // Base path resolution failed
            }

            error_log("PDF Gen: Attempting to access signature path: " . $proof_sig_path_on_server);
             if (!$proof_sig_path_on_server || !file_exists($proof_sig_path_on_server)) {
                 error_log("PDF Gen Error: Signature proof file not found or path incorrect: " . ($proof_sig_path_on_server ?: $order['proof_signature_path']));
                 $proof_sig_path_on_server = null; // Prevent trying to embed non-existent file
             }
        }
        

        // Place images side-by-side if possible
        $image_y_start = $pdf->GetY();
        $image_max_height = 50; // Max height for proof images
        $photo_x = PDF_MARGIN_LEFT;
        $photo_w = $col_width;
        $sig_x = PDF_MARGIN_LEFT + $col_width + 5;
        $sig_w = $col_width;

        // Photo Proof
        $pdf->SetY($image_y_start);
        $pdf->SetX($photo_x);
        $pdf->SetFont('', 'B');
        $pdf->Cell($photo_w, 6, 'Photo Proof', 0, 1, 'L');
        $pdf->SetY($pdf->GetY()); // Get Y after title
        $pdf->SetX($photo_x);
        if ($proof_photo_path_on_server) {
            $pdf->Image($proof_photo_path_on_server, $photo_x, $pdf->GetY(), $photo_w, $image_max_height, '', '', 'T', true, 300, '', false, false, 1, false, false, false);
        } else {
            $pdf->SetFont('', 'I');
            $pdf->Cell($photo_w, $image_max_height, 'Photo Proof Not Available', 1, 0, 'C', 0, '', 0, false, 'T', 'M');
        }

        // Signature Proof
        $pdf->SetY($image_y_start); // Reset Y to align titles
        $pdf->SetX($sig_x);
        $pdf->SetFont('', 'B');
        $pdf->Cell($sig_w, 6, 'Signature Proof', 0, 1, 'L');
        $pdf->SetY($pdf->GetY()); // Get Y after title
        $pdf->SetX($sig_x);
        if ($proof_sig_path_on_server) {
             // Add a white background rectangle first for signatures
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($sig_x, $pdf->GetY(), $sig_w, $image_max_height, 'DF', array('all' => array('width' => 0.2, 'color' => $border_color)));
            $pdf->Image($proof_sig_path_on_server, $sig_x+1, $pdf->GetY()+1, $sig_w-2, $image_max_height-2, 'PNG', '', 'T', true, 300, '', false, false, 0, false, false, false);
         } else {
             $pdf->SetFont('', 'I');
             $pdf->Cell($sig_w, $image_max_height, 'Signature Proof Not Available', 1, 1, 'C', 0, '', 0, false, 'T', 'M');
         }

        // --- End Delivery Proofs --- 

        // --- End PDF Content ---

        // 5. Save PDF to a temporary file
        $pdf_dir = '/tmp/delivery_pdfs'; // Or choose a writable directory accessible by web server
        if (!file_exists($pdf_dir)) {
            if (!mkdir($pdf_dir, 0777, true)) {
                 error_log("PDF Gen Error: Failed to create PDF directory: $pdf_dir");
                 return false;
            }
        }
        $filename = 'POD_Order_' . $order['order_number'] . '_' . time() . '.pdf';
        $filepath = $pdf_dir . '/' . $filename;
        
        $pdf->Output($filepath, 'F'); // 'F' saves to file

        error_log("PDF Generated: $filepath");
        return $filepath;

    } catch (Exception $e) {
        error_log("PDF Generation Exception: " . $e->getMessage());
        return false;
    }
}

?> 
