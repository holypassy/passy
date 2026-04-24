<?php
// view_sale.php – View full sale details with print option
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid sale ID.');
}

$sale_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch sale details
    $stmt = $conn->prepare("
        SELECT s.*, c.full_name as cust_fullname, c.telephone as cust_phone, c.email as cust_email
        FROM sales_ledger s
        LEFT JOIN customers c ON s.customer_id = c.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        die('Sale record not found.');
    }

    // Fetch sale items
    $itemStmt = $conn->prepare("
        SELECT sli.*
        FROM sales_ledger_items sli
        WHERE sli.sale_id = ?
        ORDER BY sli.id
    ");
    $itemStmt->execute([$sale_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

$customer = $sale['cust_fullname'] ?: $sale['customer_name'] ?: 'Walk-in Customer';
$payMethod = ucwords(str_replace('_', ' ', $sale['payment_method']));

$statusColors = ['paid' => '#059669', 'partial' => '#d97706', 'credit' => '#dc2626'];
$statusColor = $statusColors[$sale['payment_status']] ?? '#64748b';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Sale – <?=htmlspecialchars($sale['sale_number'])?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f1f5f9;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f2b45 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .card-header h2 {
            font-size: 20px;
            font-weight: 600;
        }
        .btn-group {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 18px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-secondary:hover {
            background: #475569;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .btn-warning {
            background: #d97706;
            color: white;
        }
        .btn-warning:hover {
            background: #b45309;
        }
        .card-body {
            padding: 24px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .info-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .info-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-value {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        th {
            background: #f1f5f9;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        td:last-child, th:last-child {
            text-align: right;
        }
        .totals {
            margin-top: 20px;
            text-align: right;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
        }
        .totals p {
            margin: 6px 0;
        }
        .grand-total {
            font-size: 20px;
            font-weight: 800;
            color: #2563eb;
        }
        .notes-section {
            margin-top: 24px;
            padding: 16px;
            background: #fffbeb;
            border-radius: 12px;
            border-left: 4px solid #f59e0b;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        @media (max-width: 640px) {
            .card-header {
                flex-direction: column;
                text-align: center;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header">
            <h2>📄 Sale Details – <?=htmlspecialchars($sale['sale_number'])?></h2>
            <div class="btn-group">
                <a href="print_sale.php?id=<?=$sale_id?>" target="_blank" class="btn btn-primary">
                    🖨️ Print Receipt
                </a>
                <a href="sales_ledger.php" class="btn btn-secondary">
                    ← Back to Sales
                </a>
            </div>
        </div>
        <div class="card-body">
            <!-- Customer Information -->
            <div class="info-grid">
                <div class="info-row">
                    <span class="info-label">Customer Name</span>
                    <span class="info-value"><?=htmlspecialchars($customer)?></span>
                </div>
                <?php if (!empty($sale['cust_phone'])): ?>
                <div class="info-row">
                    <span class="info-label">Phone Number</span>
                    <span class="info-value"><?=htmlspecialchars($sale['cust_phone'])?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($sale['cust_email'])): ?>
                <div class="info-row">
                    <span class="info-label">Email Address</span>
                    <span class="info-value"><?=htmlspecialchars($sale['cust_email'])?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Sale Date</span>
                    <span class="info-value"><?=date('d F Y', strtotime($sale['sale_date']))?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?=htmlspecialchars($payMethod)?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Status</span>
                    <span class="info-value">
                        <span class="status-badge" style="background: <?=$statusColor?>20; color: <?=$statusColor?>; border:1px solid <?=$statusColor?>">
                            <?=strtoupper($sale['payment_status'])?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Recorded By</span>
                    <span class="info-value"><?=htmlspecialchars($_SESSION['full_name'] ?? 'System')?></span>
                </div>
            </div>

            <!-- Items Table -->
            <h3 style="margin-bottom: 16px; font-size: 16px;">🛒 Items Sold</h3>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th style="text-align:center;">Quantity</th>
                        <th style="text-align:right;">Unit Price (UGX)</th>
                        <th style="text-align:right;">Total (UGX)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; color:#94a3b8;">No items found</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td><?=$i+1?></td>
                            <td><?=htmlspecialchars($item['description'])?></td>
                            <td style="text-align:center;"><?=number_format($item['quantity'], 2)?></td>
                            <td style="text-align:right;"><?=number_format($item['unit_price'])?></td>
                            <td style="text-align:right;"><?=number_format($item['total_price'])?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="totals">
                <p>Subtotal: <strong>UGX <?=number_format($sale['subtotal'])?></strong></p>
                <?php if ($sale['discount'] > 0): ?>
                <p style="color:#dc2626;">Discount: <strong>- UGX <?=number_format($sale['discount'])?></strong></p>
                <?php endif; ?>
                <p class="grand-total">Total Amount: UGX <?=number_format($sale['total_amount'])?></p>
                <p style="color:#059669;">Amount Paid: <strong>UGX <?=number_format($sale['amount_paid'])?></strong></p>
                <?php if ($sale['balance_due'] > 0): ?>
                <p style="color:#dc2626;">Balance Due: <strong>UGX <?=number_format($sale['balance_due'])?></strong></p>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <?php if (!empty($sale['notes'])): ?>
            <div class="notes-section">
                <strong>📝 Notes:</strong><br>
                <?=nl2br(htmlspecialchars($sale['notes']))?>
            </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <?php if ($sale['payment_status'] !== 'paid' && $sale['balance_due'] > 0): ?>
                <a href="record_payment.php?id=<?=$sale_id?>" class="btn btn-warning">
                    💰 Record Payment
                </a>
                <?php endif; ?>
                <a href="edit_sale.php?id=<?=$sale_id?>" class="btn btn-primary">
                    ✏️ Edit Sale
                </a>
                <a href="print_sale.php?id=<?=$sale_id?>" target="_blank" class="btn btn-primary">
                    🖨️ Print
                </a>
                <button onclick="if(confirm('Are you sure? This will delete this sale record.')) window.location.href='delete_sale.php?id=<?=$sale_id?>'" class="btn btn-danger">
                    🗑️ Delete
                </button>
            </div>
        </div>
    </div>
</div>
</body>
</html>