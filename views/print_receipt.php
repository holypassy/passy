<?php
// print_receipt.php – Print a saved receipt
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid receipt.";
    header('Location: receipts_list.php');
    exit();
}

$receipt_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch receipt header
    $stmt = $conn->prepare("SELECT * FROM receipts WHERE id = ?");
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        die("Receipt not found.");
    }

    // Fetch items
    $stmt = $conn->prepare("SELECT * FROM receipt_items WHERE receipt_id = ?");
    $stmt->execute([$receipt_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

$page_title = 'RECEIPT';
$page_subtitle = 'Invoice Receipt';
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Calibri', sans-serif;
            background: linear-gradient(135deg, #f0f4fc 0%, #e2eaf5 100%);
            padding: 2rem;
            min-height: 100vh;
        }

        .unified-header {
            background: transparent !important;
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .unified-header .company-details h2 {
            color: #0ea5e9 !important;
        }
        .unified-header .company-details p {
            color: #dc2626 !important;
            font-size: 14px !important;
        }
        .unified-header .header-right h3 {
            background: transparent !important;
            color: #1e293b !important;
        }
        .unified-header .header-right .subtitle {
            color: #334155 !important;
        }

        .receipt-container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
            padding: 30px;
        }
        .receipt-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed #e2e8f0;
        }
        .receipt-title {
            font-size: 32px;
            font-weight: 800;
            color: #2563eb;
            margin-bottom: 10px;
        }
        .receipt-number {
            font-size: 18px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }
        .receipt-date {
            font-size: 14px;
            color: #64748b;
        }
        .info-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            padding: 4px 0;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #475569;
        }
        .info-value {
            color: #1e293b;
            font-weight: 500;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .items-table th, .items-table td {
            border: 1px solid #e2e8f0;
            padding: 12px;
            font-size: 13px;
        }
        .items-table th {
            background: #f1f5f9;
            font-weight: 700;
            color: #1e293b;
        }
        .items-table td {
            color: #334155;
        }
        .text-right {
            text-align: right;
        }
        .totals {
            text-align: right;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }
        .totals p {
            margin-bottom: 8px;
            font-size: 14px;
            color: #475569;
        }
        .grand-total {
            font-size: 22px;
            font-weight: 800;
            color: #2563eb;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #e2e8f0;
        }
        .notes-section {
            margin-top: 20px;
            padding: 16px;
            background: #fef3c7;
            border-radius: 12px;
            border-left: 4px solid #f59e0b;
        }
        .notes-section strong {
            color: #92400e;
        }
        .notes-section p {
            color: #78350f;
            margin-top: 8px;
            font-size: 13px;
        }
        .footer-note {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        .print-btn-container {
            text-align: right;
            margin: 0 auto 20px auto;
            max-width: 800px;
        }
        .print-btn {
            background: #1a56db;
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
            transition: all 0.2s;
        }
        .print-btn:hover {
            background: #0f3a8a;
            transform: translateY(-2px);
        }
        .back-btn {
            background: #64748b;
            margin-right: 10px;
        }
        .back-btn:hover {
            background: #475569;
        }
        
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
            .print-btn-container, .no-print {
                display: none !important;
            }
            .receipt-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            .info-section {
                background: #f8fafc;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .notes-section {
                background: #fef3c7;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }
            .receipt-container {
                padding: 20px;
            }
            .receipt-title {
                font-size: 24px;
            }
            .items-table th, .items-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="print-btn-container no-print">
    <button class="print-btn back-btn" onclick="window.location.href='receipts_list.php'">
        <i class="fas fa-arrow-left"></i> Back to Receipts
    </button>
    <button class="print-btn" onclick="window.print()">
        <i class="fas fa-print"></i> Print Receipt
    </button>
</div>

<div class="receipt-container">
    <div class="receipt-header">
        <div class="receipt-title">
            <i class="fas fa-receipt"></i> INVOICE RECEIPT
        </div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="info-label"><i class="fas fa-user"></i> Customer:</span>
            <span class="info-value"><?php echo htmlspecialchars($receipt['customer_name']); ?></span>
        </div>
        <?php if (!empty($receipt['customer_phone'])): ?>
        <div class="info-row">
            <span class="info-label"><i class="fas fa-phone"></i> Phone:</span>
            <span class="info-value"><?php echo htmlspecialchars($receipt['customer_phone']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($receipt['customer_email'])): ?>
        <div class="info-row">
            <span class="info-label"><i class="fas fa-envelope"></i> Email:</span>
            <span class="info-value"><?php echo htmlspecialchars($receipt['customer_email']); ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-label"><i class="fas fa-credit-card"></i> Payment Method:</span>
            <span class="info-value">
                <?php 
                $method = ucfirst(str_replace('_', ' ', $receipt['payment_method']));
                $icon = '';
                if ($receipt['payment_method'] == 'cash') $icon = '<i class="fas fa-money-bill-wave"></i> ';
                elseif ($receipt['payment_method'] == 'mobile_money') $icon = '<i class="fas fa-mobile-alt"></i> ';
                elseif ($receipt['payment_method'] == 'bank_transfer') $icon = '<i class="fas fa-university"></i> ';
                elseif ($receipt['payment_method'] == 'cheque') $icon = '<i class="fas fa-check-circle"></i> ';
                echo $icon . $method;
                ?>
            </span>
        </div>
        <?php if (!empty($receipt['transaction_id'])): ?>
        <div class="info-row">
            <span class="info-label"><i class="fas fa-hashtag"></i> Transaction ID:</span>
            <span class="info-value"><?php echo htmlspecialchars($receipt['transaction_id']); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Price (UGX)</th>
                <th class="text-right">Total (UGX)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
            <tr>
                <td colspan="4" style="text-align: center; color: #94a3b8;">No items found</td>
            </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                    <td class="text-right"><?php echo $item['quantity']; ?></td>
                    <td class="text-right"><?php echo number_format($item['unit_price'], 0); ?></td>
                    <td class="text-right"><?php echo number_format($item['total_price'], 0); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Subtotal:</strong> UGX <?php echo number_format($receipt['subtotal'], 0); ?></p>
        <?php if ($receipt['discount_amount'] > 0): ?>
        <p><strong>Discount:</strong> UGX <?php echo number_format($receipt['discount_amount'], 0); ?></p>
        <?php endif; ?>
        <?php if ($receipt['tax_amount'] > 0): ?>
        <p><strong>Tax (<?php echo $receipt['tax_rate']; ?>%):</strong> UGX <?php echo number_format($receipt['tax_amount'], 0); ?></p>
        <?php endif; ?>
        <p class="grand-total">
            <strong>GRAND TOTAL:</strong> UGX <?php echo number_format($receipt['total_amount'], 0); ?>
        </p>
        <p style="font-size: 12px; color: #10b981; margin-top: 8px;">
            <i class="fas fa-check-circle"></i> Amount Paid in Full
        </p>
    </div>

    <?php if (!empty($receipt['notes'])): ?>
    <div class="notes-section">
        <strong><i class="fas fa-sticky-note"></i> Notes:</strong>
        <p><?php echo nl2br(htmlspecialchars($receipt['notes'])); ?></p>
    </div>
    <?php endif; ?>

    <div class="footer-note">
        <i class="fas fa-charging-station"></i> Savant Motors - Quality Service You Can Trust<br>
        <small>Thank you for choosing Savant Motors. Drive safely!</small>
    </div>
</div>

<script>
    // Auto-print if parameter is set
    if (window.location.search.includes('autoprint=1')) {
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    }
</script>
</body>
</html>