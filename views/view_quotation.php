<?php
// view_quotation.php – View a single quotation (read‑only) with Convert to Invoice button
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quotation_id <= 0) {
    header('Location: quotations.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch quotation header with all necessary fields
    $stmt = $conn->prepare("
        SELECT 
            q.*,
            c.full_name as customer_name,
            c.telephone,
            c.email,
            c.address,
            c.customer_type,
            c.company_name,
            c.tin,
            q.total_amount as total
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quote) {
        header('Location: quotations.php');
        exit();
    }

    // Ensure all required keys exist with default values
    $quote['subtotal'] = $quote['subtotal'] ?? 0;
    $quote['tax'] = $quote['tax'] ?? 0;
    $quote['total_amount'] = $quote['total_amount'] ?? 0;
    $quote['total'] = $quote['total'] ?? $quote['total_amount'];
    $quote['vehicle_reg'] = $quote['vehicle_reg'] ?? 'N/A';
    $quote['vehicle_model'] = $quote['vehicle_model'] ?? 'N/A';
    $quote['chassis_no'] = $quote['chassis_no'] ?? 'N/A';
    $quote['odo_reading'] = $quote['odo_reading'] ?? 'N/A';
    $quote['customer_type'] = $quote['customer_type'] ?? 'individual';
    $quote['company_name'] = $quote['company_name'] ?? '';
    $quote['tin'] = $quote['tin'] ?? '';

    // Fetch items - handle missing item_number column
    $stmt = $conn->prepare("
        SELECT * FROM quotation_items 
        WHERE quotation_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Quotation | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Blue Theme – consistent with dashboard */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e6f0fa 0%, #d4e4f5 100%);
            padding: 2rem;
            position: relative;
        }
        .watermark {
            position: fixed;
            bottom: 20px;
            right: 20px;
            opacity: 0.15;
            pointer-events: none;
            z-index: 1000;
            font-size: 48px;
            font-weight: 800;
            color: #2563eb;
            transform: rotate(-15deg);
            white-space: nowrap;
        }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .watermark { opacity: 0.1; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .toolbar { display: none; }
            .container { box-shadow: none; border-radius: 0; }
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        .toolbar {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            padding: 1rem 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar button, .toolbar a {
            background: #2c3e50;
            border: none;
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar button:hover, .toolbar a:hover {
            background: #1e2b38;
            transform: translateY(-1px);
        }
        .toolbar .print-btn {
            background: #FFD700;
            color: #1e293b;
        }
        .convert-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .convert-btn:hover {
            background: #059669;
        }
        .edit-btn {
            background: #f59e0b;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .edit-btn:hover {
            background: #d97706;
        }
        .quote-content {
            padding: 2rem;
        }
        .header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #2563eb;
        }
        .logo {
            flex-shrink: 0;
            width: 100px;
        }
        .logo img {
            max-width: 80px;
            height: auto;
        }
        .company {
            flex-grow: 1;
            text-align: center;
        }
        .company h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1e293b;
        }
        .company p {
            color: #475569;
            font-size: 12px;
        }
        .right-text {
            width: 100px;
            text-align: right;
            font-size: 18px;
            font-weight: 800;
            color: #2563eb;
        }
        .info-grid {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            margin-bottom: 30px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        .info-box {
            flex: 1;
        }
        .info-box h4 {
            color: #64748b;
            font-size: 12px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-box p {
            font-weight: 600;
            color: #0f172a;
            margin: 4px 0;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        .items-table th {
            background: #2563eb;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .text-right {
            text-align: right;
        }
        .totals {
            width: 320px;
            margin-left: auto;
            margin-top: 20px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }
        .grand-total {
            font-weight: 800;
            font-size: 18px;
            border-top: 2px solid #cbd5e1;
            margin-top: 8px;
            padding-top: 12px;
            color: #2563eb;
        }
        .payment-note {
            background: #fef9c3;
            border-left: 4px solid #eab308;
            padding: 15px;
            margin: 30px 0;
            border-radius: 8px;
        }
        .bank-details {
            text-align: center;
            font-size: 12px;
            color: #64748b;
            margin-top: 30px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 20px;
        }
        footer {
            text-align: center;
            padding: 1rem;
            font-size: 12px;
            color: #64748b;
            background: #f1f5f9;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-approved {
            background: #dcfce7;
            color: #166534;
        }
        .status-draft {
            background: #e2e8f0;
            color: #475569;
        }
        .status-sent {
            background: #dbeafe;
            color: #1e40af;
        }
        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-invoiced {
            background: #e0e7ff;
            color: #4338ca;
        }
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .info-grid { flex-direction: column; gap: 15px; }
            .totals { width: 100%; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar div { display: flex; flex-wrap: wrap; gap: 8px; }
            .header-wrapper { flex-direction: column; text-align: center; gap: 15px; }
            .right-text { width: auto; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="watermark">SAVANT MOTORS</div>

    <div class="container">
        <div class="toolbar">
            <div>
                <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <a href="quotations.php"><i class="fas fa-arrow-left"></i> Back to List</a>
                <?php if ($quote['status'] === 'approved'): ?>
                    <a href="invoices.php?convert_quotation=<?php echo $quote['id']; ?>" class="convert-btn">
                        <i class="fas fa-exchange-alt"></i> Convert to Invoice
                    </a>
                <?php endif; ?>
            </div>
            <div>
                <a href="edit_quotation.php?id=<?php echo $quote['id']; ?>" class="edit-btn">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>

        <div class="quote-content">
            <div class="header-wrapper">
                <div class="logo">
                    <img src="../images/logo.jpeg" alt="Savant Motors Logo" onerror="this.style.display='none'">
                </div>
                <div class="company">
                    <h1>SAVANT MOTORS UGANDA</h1>
                    <p>Bugolobi, Bunyonyi Drive, Kampala, Uganda</p>
                    <p>Tel: +256 774 537 017 / +256 704 496 974</p>
                    <p>Email: rogersm2008@gmail.com</p>
                </div>
                <div class="right-text">
                    QUOTATION<br>
                    <span style="font-size: 12px; color: #64748b;">#<?php echo htmlspecialchars($quote['quotation_number']); ?></span>
                </div>
            </div>

            <div class="info-grid">
                <div class="info-box">
                    <h4><i class="fas fa-user"></i> Bill To</h4>
                    <p><strong><?php echo htmlspecialchars($quote['customer_name']); ?></strong></p>
                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($quote['telephone'] ?? 'N/A'); ?></p>
                    <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($quote['email'] ?? 'N/A'); ?></p>
                    <p><i class="fas fa-map-marker-alt"></i> <?php echo nl2br(htmlspecialchars($quote['address'] ?? 'N/A')); ?></p>
                    <?php if ($quote['customer_type'] === 'business'): ?>
                        <p><strong>Company:</strong> <?php echo htmlspecialchars($quote['company_name']); ?></p>
                        <p><strong>TIN:</strong> <?php echo htmlspecialchars($quote['tin']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="info-box">
                    <h4><i class="fas fa-car"></i> Vehicle Details</h4>
                    <p><strong>Reg:</strong> <?php echo htmlspecialchars($quote['vehicle_reg']); ?></p>
                    <p><strong>Model:</strong> <?php echo htmlspecialchars($quote['vehicle_model']); ?></p>
                    <p><strong>Chassis:</strong> <?php echo htmlspecialchars($quote['chassis_no']); ?></p>
                    <p><strong>ODO:</strong> <?php echo htmlspecialchars($quote['odo_reading']); ?> km</p>
                </div>
                <div class="info-box">
                    <h4><i class="fas fa-file-invoice"></i> Quote Details</h4>
                    <p><strong>Quote #:</strong> <?php echo htmlspecialchars($quote['quotation_number']); ?></p>
                    <p><strong>Date:</strong> <?php echo date('d/m/Y', strtotime($quote['quotation_date'])); ?></p>
                    <p><strong>Valid Until:</strong> <?php echo isset($quote['valid_until']) ? date('d/m/Y', strtotime($quote['valid_until'])) : 'N/A'; ?></p>
                    <p><strong>Status:</strong> 
                        <span class="status-badge status-<?php echo $quote['status']; ?>">
                            <?php echo strtoupper($quote['status']); ?>
                        </span>
                    </p>
                </div>
            </div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Description</th>
                        <th style="width: 80px;">Qty</th>
                        <th style="width: 120px;">Unit Price (UGX)</th>
                        <th style="width: 120px;">Total (UGX)</th>
                    </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    $display_subtotal = 0;
                    if (empty($items)): 
                    ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px;">
                            <i class="fas fa-box-open"></i> No items found for this quotation
                        </td>
                    </tr>
                    <?php else: 
                        foreach ($items as $item): 
                            $item_total = ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
                            $display_subtotal += $item_total;
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo htmlspecialchars($item['description'] ?? 'N/A'); ?></td>
                        <td class="text-right"><?php echo number_format($item['quantity'] ?? 0); ?></td>
                        <td class="text-right"><?php echo number_format($item['unit_price'] ?? 0, 0); ?></td>
                        <td class="text-right"><?php echo number_format($item_total, 0); ?></td>
                    </tr>
                    <?php 
                        endforeach; 
                    endif; 
                    ?>
                </tbody>
            </table>

            <?php
            // Safely calculate totals with fallbacks
            $subtotal = $quote['subtotal'] ?? $display_subtotal ?? 0;
            $tax = $quote['tax'] ?? 0;
            $discount = $quote['discount'] ?? 0;
            $total = $quote['total_amount'] ?? $quote['total'] ?? ($subtotal + $tax - $discount);
            ?>

            <div class="totals">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>UGX <?php echo number_format($subtotal, 0); ?></span>
                </div>
                <?php if ($discount > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <span>- UGX <?php echo number_format($discount, 0); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($tax > 0): ?>
                <div class="total-row">
                    <span>Tax (VAT 18%):</span>
                    <span>UGX <?php echo number_format($tax, 0); ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row grand-total">
                    <span>Grand Total:</span>
                    <span>UGX <?php echo number_format($total, 0); ?></span>
                </div>
            </div>

            <?php if (!empty($quote['notes'])): ?>
            <div style="margin: 20px 0; padding: 15px; background: #f1f5f9; border-radius: 12px;">
                <strong><i class="fas fa-sticky-note"></i> Notes:</strong>
                <p style="margin-top: 8px;"><?php echo nl2br(htmlspecialchars($quote['notes'])); ?></p>
            </div>
            <?php endif; ?>

            <div class="payment-note">
                <i class="fas fa-info-circle"></i> <strong>Payment Terms:</strong> 70% payment is needed before commencement of any work and 30% on completion.
            </div>

            <div class="bank-details">
                <p><strong>Payments Can be Made to SAVANT MOTORS</strong></p>
                <p>ABSA A/C NO: 6007717553 | Mobile Money: 915573</p>
                <p style="margin-top: 10px;"><i class="fas fa-certificate"></i> "Testify" – Generated by Savant Motors ERP</p>
            </div>
        </div>
        <footer>
            <i class="fas fa-charging-station"></i> Savant Motors - Quality Service You Can Trust | Since 2018
        </footer>
    </div>

    <script>
        // Print functionality
        document.querySelector('.print-btn')?.addEventListener('click', function() {
            window.print();
        });
    </script>
</body>
</html>