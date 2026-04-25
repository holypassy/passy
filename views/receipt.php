<?php
// receipt.php - Generate Payment Receipt from Invoice
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoice_id <= 0) {
    $_SESSION['error'] = "Invalid invoice ID";
    header('Location: invoices.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch invoice details with customer information
    $stmt = $conn->prepare("
        SELECT 
            i.*,
            c.full_name as customer_name,
            c.telephone,
            c.email,
            c.address,
            c.customer_tier
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        $_SESSION['error'] = "Invoice not found";
        header('Location: invoices.php');
        exit();
    }
    
    // Fetch invoice items
    $stmt = $conn->prepare("
        SELECT * FROM invoice_items 
        WHERE invoice_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate amounts
    $total_amount = floatval($invoice['total_amount'] ?? 0);
    $amount_paid = floatval($invoice['amount_paid'] ?? 0);
    $balance_due = $total_amount - $amount_paid;
    $payment_status = $invoice['payment_status'] ?? 'unpaid';
    $payment_method = $invoice['payment_method'] ?? 'Cash';
    
    // Receipt number
    $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($invoice_id, 4, '0', STR_PAD_LEFT);
    
    // Set page title and subtitle for header
    $page_title = 'PAYMENT RECEIPT';
    $page_subtitle = 'Receipt #' . $receipt_number . ' | ' . date('d M Y');
    
    include 'header.php';
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<style>
    /* Override header background for receipt */
    .unified-header {
        background: linear-gradient(135deg, #f8fafc, #e2e8f0) !important;
        color: #1e293b !important;
    }
    .unified-header .company-details h2,
    .unified-header .company-details p {
        color: #1e293b !important;
    }
    .unified-header .header-right h3 {
        background: rgba(0,0,0,0.05) !important;
        color: #2563eb !important;
    }
    .unified-header .header-right .subtitle {
        color: #334155 !important;
    }

    /* Receipt styles */
    .receipt-container {
        max-width: 1100px;
        margin: 20px auto;
        background: white;
        border-radius: 24px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        overflow: hidden;
    }
    
    .receipt-header {
        background: linear-gradient(135deg, #1e40af, #2563eb);
        color: white;
        padding: 25px 30px;
        text-align: center;
    }
    
    .receipt-title {
        font-size: 28px;
        font-weight: 800;
        margin-bottom: 5px;
    }
    
    .receipt-subtitle {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .receipt-number {
        background: rgba(255,255,255,0.2);
        display: inline-block;
        padding: 5px 15px;
        border-radius: 30px;
        font-size: 12px;
        margin-top: 10px;
    }
    
    .receipt-body {
        padding: 30px;
    }
    
    /* Horizontal Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        margin-bottom: 25px;
    }
    
    .info-card {
        background: #f8fafc;
        border-radius: 16px;
        padding: 15px;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    
    .info-card-title {
        font-size: 12px;
        font-weight: 700;
        color: #2563eb;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #2563eb;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .info-card-title i {
        font-size: 14px;
    }
    
    .info-row {
        margin-bottom: 8px;
        font-size: 12px;
        display: flex;
        flex-wrap: wrap;
    }
    
    .info-label {
        width: 100px;
        font-weight: 600;
        color: #64748b;
    }
    
    .info-value {
        flex: 1;
        color: #0f172a;
        font-weight: 500;
        word-break: break-word;
    }
    
    .payment-details {
        background: #dcfce7;
        padding: 20px;
        border-radius: 16px;
        margin-bottom: 25px;
        border-left: 4px solid #10b981;
    }
    
    .payment-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 15px;
    }
    
    .payment-item {
        text-align: center;
    }
    
    .payment-label {
        font-size: 11px;
        font-weight: 600;
        color: #166534;
        text-transform: uppercase;
        margin-bottom: 5px;
    }
    
    .payment-value {
        font-size: 16px;
        font-weight: 800;
        color: #166534;
    }
    
    .payment-value.balance {
        color: #dc2626;
    }
    
    .payment-value.paid {
        color: #10b981;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 13px;
    }
    
    .items-table th,
    .items-table td {
        border: 1px solid #e2e8f0;
        padding: 10px;
        text-align: left;
    }
    
    .items-table th {
        background: #f1f5f9;
        font-weight: 700;
        font-size: 12px;
    }
    
    .text-right {
        text-align: right;
    }
    
    .totals {
        margin-top: 20px;
        text-align: right;
    }
    
    .totals p {
        margin-bottom: 8px;
        font-size: 14px;
    }
    
    .grand-total {
        font-size: 18px;
        font-weight: 800;
        color: #2563eb;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 2px solid #e2e8f0;
    }
    
    .footer {
        text-align: center;
        padding: 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        font-size: 11px;
        color: #64748b;
    }
    
    .print-btn-container {
        text-align: right;
        max-width: 1100px;
        margin: 0 auto 15px auto;
    }
    
    .btn-print {
        background: #4b5563;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .btn-print:hover {
        background: #1f2937;
        transform: translateY(-2px);
    }
    
    .btn-back {
        background: #64748b;
        margin-left: 10px;
    }
    
    .btn-back:hover {
        background: #475569;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 11px;
        font-weight: 700;
    }
    
    .status-paid {
        background: #dcfce7;
        color: #166534;
    }
    
    .status-partial {
        background: #fed7aa;
        color: #9a3412;
    }
    
    .thank-you {
        text-align: center;
        margin: 20px 0;
        padding: 15px;
        background: #fef9c3;
        border-radius: 12px;
    }
    
    /* PRINT STYLES - Keep horizontal layout */
    @media print {
        @page {
            size: A4;
            margin: 0.5cm;
        }
        
        body {
            background: white;
            padding: 0;
            margin: 0;
        }
        
        .unified-header {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        
        .print-btn-container {
            display: none;
        }
        
        .receipt-container {
            box-shadow: none;
            margin: 0;
            border-radius: 0;
        }
        
        /* Keep horizontal grid in print */
        .info-grid {
            display: grid !important;
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 15px !important;
            break-inside: avoid;
        }
        
        .info-card {
            break-inside: avoid;
            border: 1px solid #ccc;
        }
        
        .payment-grid {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            gap: 15px !important;
            break-inside: avoid;
        }
        
        .payment-details {
            break-inside: avoid;
        }
        
        .items-table {
            break-inside: avoid;
        }
        
        .info-row {
            break-inside: avoid;
        }
        
        .info-card-title {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        
        .status-badge {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }
    
    @media (max-width: 768px) {
        body {
            padding: 15px;
        }
        .receipt-body {
            padding: 20px;
        }
        .info-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }
        .payment-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        .info-row {
            flex-direction: column;
        }
        .info-label {
            width: 100%;
            margin-bottom: 4px;
        }
        .items-table {
            font-size: 11px;
        }
        .items-table th,
        .items-table td {
            padding: 6px;
        }
        .print-btn-container {
            text-align: center;
        }
        .btn-print {
            margin-bottom: 10px;
        }
    }
</style>

<div class="print-btn-container no-print">
    <button class="btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Receipt
    </button>
    <a href="invoices.php" class="btn-print btn-back">
        <i class="fas fa-arrow-left"></i> Back to Invoices
    </a>
</div>

<div class="receipt-container">

    <div class="receipt-body">
        <!-- Horizontal Info Grid -->
        <div class="info-grid">
            <!-- Customer Information Card -->
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-user"></i> CUSTOMER INFORMATION
                </div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value"><strong><?php echo htmlspecialchars($invoice['customer_name'] ?? 'N/A'); ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['telephone'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['email'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value"><?php echo nl2br(htmlspecialchars($invoice['address'] ?? 'N/A')); ?></span>
                </div>
                <?php if (!empty($invoice['customer_tier']) && $invoice['customer_tier'] != 'bronze'): ?>
                <div class="info-row">
                    <span class="info-label">Tier:</span>
                    <span class="info-value">
                        <span class="status-badge" style="background:#fed7aa; color:#9a3412;">
                            <i class="fas fa-crown"></i> <?php echo strtoupper($invoice['customer_tier']); ?>
                        </span>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Vehicle Information Card -->
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-car"></i> VEHICLE INFORMATION
                </div>
                <?php if (!empty($invoice['vehicle_reg']) || !empty($invoice['vehicle_model'])): ?>
                    <div class="info-row">
                        <span class="info-label">Registration:</span>
                        <span class="info-value"><strong><?php echo htmlspecialchars($invoice['vehicle_reg'] ?? 'N/A'); ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Model:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['vehicle_model'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Odometer:</span>
                        <span class="info-value"><?php echo htmlspecialchars($invoice['odometer_reading'] ?? 'N/A'); ?> km</span>
                    </div>
                <?php else: ?>
                    <div class="info-row">
                        <span class="info-value" style="color: #94a3b8;">No vehicle information available</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Invoice Information Card -->
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-file-invoice"></i> INVOICE INFORMATION
                </div>
                <div class="info-row">
                    <span class="info-label">Invoice #:</span>
                    <span class="info-value"><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Invoice Date:</span>
                    <span class="info-value"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Receipt Date:</span>
                    <span class="info-value"><?php echo date('d M Y h:i A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <span class="status-badge <?php echo $payment_status == 'paid' ? 'status-paid' : 'status-partial'; ?>">
                            <i class="fas fa-<?php echo $payment_status == 'paid' ? 'check-circle' : 'clock'; ?>"></i>
                            <?php echo strtoupper($payment_status); ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Payment Details Summary -->
        <div class="payment-details">
            <div class="payment-grid">
                <div class="payment-item">
                    <div class="payment-label">Total Amount</div>
                    <div class="payment-value">UGX <?php echo number_format($total_amount, 0); ?></div>
                </div>
                <div class="payment-item">
                    <div class="payment-label">Amount Paid</div>
                    <div class="payment-value paid">UGX <?php echo number_format($amount_paid, 0); ?></div>
                </div>
                <?php if ($balance_due > 0): ?>
                <div class="payment-item">
                    <div class="payment-label">Balance Due</div>
                    <div class="payment-value balance">UGX <?php echo number_format($balance_due, 0); ?></div>
                </div>
                <?php else: ?>
                <div class="payment-item">
                    <div class="payment-label">Payment Status</div>
                    <div class="payment-value paid"><i class="fas fa-check-circle"></i> PAID IN FULL</div>
                </div>
                <?php endif; ?>
                <div class="payment-item">
                    <div class="payment-label">Payment Method</div>
                    <div class="payment-value"><?php echo htmlspecialchars($payment_method); ?></div>
                </div>
            </div>
        </div>

        <!-- Items Breakdown -->
        <?php if (!empty($items)): ?>
        <div class="info-section">
            <div class="info-card-title" style="margin-bottom: 15px;">
                <i class="fas fa-list"></i> SERVICES / ITEMS BREAKDOWN
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Description</th>
                        <th style="width: 80px;">Qty</th>
                        <th style="width: 130px;">Unit Price (UGX)</th>
                        <th style="width: 130px;">Total (UGX)</th>
                    </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    $display_subtotal = 0;
                    foreach ($items as $item): 
                        $item_total = ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
                        $display_subtotal += $item_total;
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($item['description'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo number_format($item['quantity'] ?? 0); ?></td>
                        <td class="text-right">UGX <?php echo number_format($item['unit_price'] ?? 0, 0); ?></td>
                        <td class="text-right">UGX <?php echo number_format($item_total, 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Financial Summary -->
        <div class="totals">
            <p>Subtotal: UGX <?php echo number_format($invoice['subtotal'] ?? $display_subtotal, 0); ?></p>
            <?php if (($invoice['discount'] ?? 0) > 0): ?>
            <p>Discount: - UGX <?php echo number_format($invoice['discount'] ?? 0, 0); ?></p>
            <?php endif; ?>
            <?php if (($invoice['tax'] ?? 0) > 0): ?>
            <p>Tax (VAT 18%): UGX <?php echo number_format($invoice['tax'] ?? 0, 0); ?></p>
            <?php endif; ?>
            <p class="grand-total">GRAND TOTAL: UGX <?php echo number_format($total_amount, 0); ?></p>
        </div>

        <?php if (!empty($invoice['notes'])): ?>
        <div style="margin: 15px 0; padding: 12px; background: #f1f5f9; border-radius: 12px;">
            <strong><i class="fas fa-sticky-note"></i> Notes:</strong>
            <p style="margin-top: 5px; font-size: 12px;"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Thank You Note -->
        <div class="thank-you">
            <i class="fas fa-smile-wink"></i> 
            <strong>Thank you for choosing Savant Motors!</strong>
            <p style="font-size: 12px; margin-top: 5px;">We appreciate doing business with you, looking forward to serve you again.</p>
        </div>
    </div>

    <div class="footer">
        <p>Generated on: <?php echo date('d F Y h:i A'); ?></p>
    </div>
</div>

<?php
// No closing PHP tag needed
?>