<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$id = $_GET['id'] ?? 0;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get purchase details
    $stmt = $conn->prepare("
        SELECT p.*, s.supplier_name, s.telephone, s.email, s.address, s.tax_id, s.contact_person,
               u1.full_name as created_by_name,
               u2.full_name as received_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN users u1 ON p.created_by = u1.id
        LEFT JOIN users u2 ON p.received_by = u2.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$purchase) {
        header('Location: index.php');
        exit();
    }
    
    // Get purchase items
    $stmt = $conn->prepare("
        SELECT pi.*, i.item_code, i.product_name, i.unit_of_measure, i.category
        FROM purchase_items pi
        LEFT JOIN inventory i ON pi.product_id = i.id
        WHERE pi.purchase_id = ?
    ");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get company info (you can store this in settings table)
    $company = [
        'name' => 'SAVANT MOTORS UGANDA',
        'address' => 'Plot 123, Kampala Road, Kampala, Uganda',
        'phone' => '+256 414 123456',
        'email' => 'info@savantmotors.com',
        'tax_id' => 'UG-123456789',
        'logo' => '../images/logo.jpeg'
    ];
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Calculate totals for display
$subtotal = $purchase['subtotal'];
$discount_total = $purchase['discount_total'];
$tax_total = $purchase['tax_total'];
$shipping_cost = $purchase['shipping_cost'];
$grand_total = $purchase['total_amount'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order - <?php echo htmlspecialchars($purchase['po_number']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Calibri', 'Segoe UI', 'Tahoma', 'Geneva', 'Verdana', sans-serif;
            background: #fff;
            color: #333;
            padding: 20px;
        }
        
        /* Print Container */
        .print-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        /* Header Section */
        .header {
            padding: 30px;
            border-bottom: 3px solid #1e40af;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        }
        
        .company-info {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .company-name {
            font-size: 32px;
            font-weight: 800;
            color: #1e40af;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        
        .company-tagline {
            font-size: 12px;
            color: #64748b;
            letter-spacing: 2px;
        }
        
        .company-details {
            font-size: 12px;
            color: #475569;
            margin-top: 10px;
        }
        
        .document-title {
            text-align: center;
            margin-top: 20px;
        }
        
        .document-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 3px;
        }
        
        .document-subtitle {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        /* Info Sections */
        .info-section {
            display: flex;
            justify-content: space-between;
            padding: 30px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-box {
            flex: 1;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            margin: 0 10px;
        }
        
        .info-box:first-child {
            margin-left: 0;
        }
        
        .info-box:last-child {
            margin-right: 0;
        }
        
        .info-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #1e40af;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .info-label {
            width: 120px;
            font-weight: 600;
            color: #475569;
        }
        
        .info-value {
            flex: 1;
            color: #1e293b;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .status-ordered {
            background: #fff3e0;
            color: #f59e0b;
        }
        
        .status-received {
            background: #dcfce7;
            color: #10b981;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #ef4444;
        }
        
        /* Items Table */
        .items-section {
            padding: 30px;
        }
        
        .items-title {
            font-size: 16px;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #1e40af;
            text-transform: uppercase;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .items-table th {
            background: #1e40af;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
        }
        
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .items-table tr:hover {
            background: #f8fafc;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        /* Totals Section */
        .totals-section {
            padding: 20px 30px;
            background: #f8fafc;
            margin: 0 30px 30px 30px;
            border-radius: 8px;
        }
        
        .totals-table {
            width: 100%;
            max-width: 400px;
            margin-left: auto;
        }
        
        .totals-table td {
            padding: 8px;
            font-size: 13px;
        }
        
        .totals-table .label {
            font-weight: 600;
            color: #475569;
        }
        
        .totals-table .value {
            text-align: right;
            font-weight: 500;
        }
        
        .grand-total {
            border-top: 2px solid #1e40af;
            margin-top: 5px;
            padding-top: 10px;
        }
        
        .grand-total .label {
            font-size: 16px;
            font-weight: 800;
            color: #1e40af;
        }
        
        .grand-total .value {
            font-size: 18px;
            font-weight: 800;
            color: #1e40af;
        }
        
        /* Notes Section */
        .notes-section {
            padding: 0 30px 30px 30px;
        }
        
        .notes-box {
            background: #fefce8;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 8px;
        }
        
        .notes-title {
            font-size: 13px;
            font-weight: 700;
            color: #f59e0b;
            margin-bottom: 8px;
        }
        
        .notes-text {
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        
        /* Footer */
        .footer {
            padding: 20px 30px;
            background: #f8fafc;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 11px;
            color: #94a3b8;
        }
        
        .footer p {
            margin: 5px 0;
        }
        
        /* Print Button */
        .print-actions {
            text-align: center;
            padding: 20px;
            background: white;
            margin-bottom: 20px;
        }
        
        .btn-print {
            background: #1e40af;
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            margin: 0 10px;
            transition: all 0.3s;
            font-family: 'Calibri', sans-serif;
        }
        
        .btn-print:hover {
            background: #1e3a8a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30,64,175,0.3);
        }
        
        .btn-back {
            background: #64748b;
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            margin: 0 10px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-back:hover {
            background: #475569;
            transform: translateY(-2px);
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .print-actions {
                display: none;
            }
            
            .print-container {
                box-shadow: none;
                padding: 0;
            }
            
            .header {
                padding: 20px;
            }
            
            .info-section {
                padding: 20px;
            }
            
            .items-section {
                padding: 20px;
            }
            
            .totals-section {
                padding: 15px 20px;
                margin: 0 20px 20px 20px;
            }
            
            .notes-section {
                padding: 0 20px 20px 20px;
            }
            
            .footer {
                padding: 15px 20px;
                position: fixed;
                bottom: 0;
                width: 100%;
            }
            
            .status-badge {
                border: 1px solid #ccc;
            }
            
            a {
                text-decoration: none;
                color: black;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .info-section {
                flex-direction: column;
            }
            
            .info-box {
                margin: 10px 0;
            }
            
            .info-box:first-child {
                margin-top: 0;
            }
            
            .info-box:last-child {
                margin-bottom: 0;
            }
            
            .items-table {
                font-size: 11px;
            }
            
            .items-table th,
            .items-table td {
                padding: 8px;
            }
        }
        
        /* Watermark for received orders */
        .watermark {
            position: relative;
        }
        
        .watermark::after {
            content: "RECEIVED";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 60px;
            font-weight: 800;
            color: rgba(16, 185, 129, 0.1);
            pointer-events: none;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print"></i> Print Purchase Order
        </button>
        <a href="view.php?id=<?php echo $id; ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to View
        </a>
    </div>
    
    <div class="print-container <?php echo $purchase['status'] == 'received' ? 'watermark' : ''; ?>">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <div class="company-name"><?php echo $company['name']; ?></div>
                <div class="company-tagline">AUTOMOTIVE SOLUTIONS</div>
                <div class="company-details">
                    <?php echo $company['address']; ?> | Tel: <?php echo $company['phone']; ?> | Email: <?php echo $company['email']; ?>
                </div>
            </div>
            
            <div class="document-title">
                <h1>PURCHASE ORDER</h1>
                <div class="document-subtitle">Official Purchase Document</div>
            </div>
        </div>
        
        <!-- Information Sections -->
        <div class="info-section">
            <div class="info-box">
                <div class="info-title">PO DETAILS</div>
                <div class="info-row">
                    <div class="info-label">PO Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['po_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">PO Date:</div>
                    <div class="info-value"><?php echo date('d F Y', strtotime($purchase['purchase_date'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $purchase['status']; ?>">
                            <?php echo strtoupper($purchase['status']); ?>
                        </span>
                    </div>
                </div>
                <?php if ($purchase['expected_delivery']): ?>
                <div class="info-row">
                    <div class="info-label">Expected Delivery:</div>
                    <div class="info-value"><?php echo date('d F Y', strtotime($purchase['expected_delivery'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($purchase['received_date']): ?>
                <div class="info-row">
                    <div class="info-label">Received Date:</div>
                    <div class="info-value"><?php echo date('d F Y', strtotime($purchase['received_date'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <div class="info-title">SUPPLIER INFORMATION</div>
                <div class="info-row">
                    <div class="info-label">Company:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($purchase['supplier_name']); ?></strong></div>
                </div>
                <?php if ($purchase['contact_person']): ?>
                <div class="info-row">
                    <div class="info-label">Contact Person:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['contact_person']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">Phone:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['telephone']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['email']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($purchase['address'])); ?></div>
                </div>
                <?php if ($purchase['tax_id']): ?>
                <div class="info-row">
                    <div class="info-label">Tax ID:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['tax_id']); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box">
                <div class="info-title">PAYMENT & DELIVERY</div>
                <div class="info-row">
                    <div class="info-label">Payment Terms:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['payment_terms'] ?? 'Standard'); ?></div>
                </div>
                <?php if ($purchase['supplier_invoice']): ?>
                <div class="info-row">
                    <div class="info-label">Supplier Invoice:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['supplier_invoice']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <div class="info-label">Created By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['created_by_name'] ?? 'System'); ?></div>
                </div>
                <?php if ($purchase['received_by_name']): ?>
                <div class="info-row">
                    <div class="info-label">Received By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($purchase['received_by_name']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Items Table -->
        <div class="items-section">
            <div class="items-title">ORDER ITEMS</div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="15%">Item Code</th>
                        <th width="35%">Product Description</th>
                        <th width="10%" class="text-center">Quantity</th>
                        <th width="15%" class="text-right">Unit Price (UGX)</th>
                        <th width="10%" class="text-right">Discount (UGX)</th>
                        <th width="10%" class="text-right">Total (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($item['product_name']); ?>
                            <?php if ($item['category']): ?>
                            <br><small style="color: #64748b;"><?php echo htmlspecialchars($item['category']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit_of_measure'] ?? 'pcs'); ?>
                        </td>
                        <td class="text-right"><?php echo number_format($item['unit_price']); ?></td>
                        <td class="text-right"><?php echo number_format($item['discount']); ?></td>
                        <td class="text-right"><strong><?php echo number_format($item['total']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Totals Section -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label">Subtotal:</td>
                    <td class="value">UGX <?php echo number_format($subtotal); ?></td>
                </tr>
                <?php if ($discount_total > 0): ?>
                <tr>
                    <td class="label">Discount:</td>
                    <td class="value" style="color: #ef4444;">- UGX <?php echo number_format($discount_total); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">Tax (18%):</td>
                    <td class="value">UGX <?php echo number_format($tax_total); ?></td>
                </tr>
                <?php if ($shipping_cost > 0): ?>
                <tr>
                    <td class="label">Shipping:</td>
                    <td class="value">UGX <?php echo number_format($shipping_cost); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total">
                    <td class="label">GRAND TOTAL:</td>
                    <td class="value">UGX <?php echo number_format($grand_total); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Notes Section -->
        <?php if ($purchase['notes']): ?>
        <div class="notes-section">
            <div class="notes-box">
                <div class="notes-title">
                    <i class="fas fa-sticky-note"></i> ADDITIONAL NOTES
                </div>
                <div class="notes-text">
                    <?php echo nl2br(htmlspecialchars($purchase['notes'])); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Terms and Conditions -->
        <div class="notes-section">
            <div class="notes-box" style="background: #f0fdf4; border-left-color: #10b981;">
                <div class="notes-title" style="color: #10b981;">TERMS & CONDITIONS</div>
                <div class="notes-text">
                    <ul style="margin-left: 20px; line-height: 1.6;">
                        <li>Goods remain the property of SAVANT MOTORS until paid in full</li>
                        <li>Please inspect goods upon delivery for any damages or discrepancies</li>
                        <li>Claims must be made within 7 days of delivery</li>
                        <li>Payment is due according to the agreed payment terms</li>
                        <li>This is a computer-generated document - no signature required</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Thank you for your business!</p>
            <p>This is a computer-generated purchase order. Please verify all details before processing.</p>
            <p>Generated on: <?php echo date('d F Y H:i:s'); ?> | Document ID: <?php echo htmlspecialchars($purchase['po_number']); ?></p>
            <p>© <?php echo date('Y'); ?> SAVANT MOTORS UGANDA - All Rights Reserved</p>
        </div>
    </div>
    
    <script>
        // Auto-print dialog when page loads (optional - uncomment if needed)
        // window.onload = function() {
        //     window.print();
        // }
        
        // Add Font Awesome for icons (if not already loaded)
        function loadFontAwesome() {
            if (!document.querySelector('link[href*="font-awesome"]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
                document.head.appendChild(link);
            }
        }
        loadFontAwesome();
    </script>
</body>
</html>