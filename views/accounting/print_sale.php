<?php
// print_sale.php – Print a single Sales Ledger entry
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

    // Fetch sale details with customer info
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

// Set variables
$customer = $sale['cust_fullname'] ?: $sale['customer_name'] ?: 'Walk-in Customer';
$payMethod = ucwords(str_replace('_', ' ', $sale['payment_method']));

// Status colors
$statusColors = ['paid' => '#059669', 'partial' => '#d97706', 'credit' => '#dc2626'];
$statusColor  = $statusColors[$sale['payment_status']] ?? '#64748b';

// Set page title for unified header
$page_title = 'Sales Ledger';

// Include unified header (this provides the sidebar and top bar)
include_once '../header.php';
?>

<!-- Print-specific styles -->
<style>
    /* ── Suppress broken watermark image loaded by header.php ── */
    img[src*="watermark"] {
        display: none !important;
    }
    /* Catch-all: hide any broken images (shows alt text otherwise) */
    img:not([src]), img[src=""], img[src*="../images/watermark.jpeg"] {
        display: none !important;
        visibility: hidden;
        width: 0; height: 0;
    }
    /* ── Fixed sale info badge — top right corner, always visible on screen ── */
    .sale-badge {
        position: fixed;
        top: 18px;
        right: 24px;
        text-align: right;
        z-index: 9999;
        background: white;
        border: 1.5px solid #2563eb;
        border-radius: 12px;
        padding: 8px 16px 8px 14px;
        box-shadow: 0 4px 14px rgba(37,99,235,.15);
        line-height: 1.4;
    }
    .sale-badge .sn  { font-size: 15px; font-weight: 800; color: #2563eb; font-family: monospace; }
    .sale-badge .sd  { font-size: 11px; color: #64748b; }
    .sale-badge .status-pill {
        display: inline-block; padding: 2px 10px; border-radius: 20px;
        font-size: 10px; font-weight: 700; margin-top: 3px;
        background: <?=htmlspecialchars($statusColor)?>22;
        color: <?=htmlspecialchars($statusColor)?>;
        border: 1.5px solid <?=htmlspecialchars($statusColor)?>;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* ── Layout ── */
    .main-content { background: #f1f5f9; }

    .print-container {
        max-width: 820px;
        margin: 24px auto 40px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 8px 30px rgba(0,0,0,.10);
        padding: 32px 38px 38px;
        position: relative;
        display: flex;
        flex-direction: column;
        min-height: 80vh; /* Ensures footer can be pushed down */
    }

    /* Document header — just a divider line, no company name (header.php has it) */
    .print-header {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 3px double #2c3e66;
        position: relative;
        z-index: 1;
    }
    /* On screen the right side is empty — badge is fixed. On print we show inline */
    .print-header-right { display: none; }

    .info-band {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 14px 30px; background: #f8faff;
        border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 14px 18px; margin-bottom: 24px;
        position: relative; z-index: 1;
    }
    .info-item { display: flex; gap: 8px; align-items: baseline; }
    .info-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; min-width: 90px; }
    .info-value { font-size: 13px; font-weight: 600; color: #1e293b; }

    .section-title {
        font-size: 13px; font-weight: 700; color: #1e466e;
        border-left: 4px solid #2563eb; padding-left: 10px;
        margin-bottom: 12px; position: relative; z-index: 1;
    }

    table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; position: relative; z-index: 1; }
    table.items thead th {
        background: #2563eb; color: white;
        padding: 9px 12px; font-size: 11px; font-weight: 700; text-align: left;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    table.items thead th:last-child { text-align: right; }
    table.items tbody td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    table.items tbody td:last-child { text-align: right; font-weight: 700; }
    table.items tfoot td { padding: 7px 12px; font-size: 13px; }
    table.items tfoot .lbl { text-align: right; color: #64748b; font-weight: 600; }
    table.items tfoot .val { text-align: right; font-weight: 700; }
    table.items tfoot tr.grand td { border-top: 2px solid #2563eb; }
    table.items tfoot tr.grand .val { font-size: 17px; color: #2563eb; }

    .notes-box {
        background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
        padding: 12px 16px; font-size: 12px; color: #78350f; margin-bottom: 20px;
        position: relative; z-index: 1;
    }

    .sig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px; position: relative; z-index: 1; }
    .sig-box { border-top: 1px solid #94a3b8; padding-top: 8px; font-size: 11px; color: #64748b; }

    /* Footer area that stays at bottom */
    .footer-area {
        margin-top: auto; /* pushes to bottom */
        position: relative;
        z-index: 1;
    }

    .testify {
        text-align: center;
        font-size: 11px;
        color: #475569;
        margin: 20px 0 12px;
        font-style: italic;
    }

    .footer-note {
        text-align: center;
        font-size: 10px;
        color: #94a3b8;
        border-top: 1px dotted #cbd5e1;
        padding-top: 12px;
    }

    .btn-bar {
        text-align: center; margin-top: 28px; padding-top: 16px;
        border-top: 1px solid #e2e8f0;
    }
    .btn-bar button {
        padding: 10px 28px; border-radius: 40px; border: none;
        font-weight: 700; font-size: 13px; cursor: pointer; margin: 0 6px;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-print-go { background: #2563eb; color: white; }
    .btn-close-pg { background: #e2e8f0; color: #475569; }

    /* ── PRINT STYLES ── */
    @media print {
        /* Hide all navigation / sidebar / buttons */
        .top-nav, .sidebar, .page-title-bar, .navbar, nav,
        header:not(.print-doc-header), .btn-bar, .action-buttons,
        .logout-btn, .nav-links, .user-menu, .no-print { display: none !important; }

        /* Hide fixed badge on print */
        .sale-badge { display: none !important; }

        body, .main-content { background: white; padding: 0; margin: 0; }
        .print-container {
            box-shadow: none; border-radius: 0; margin: 0;
            padding: 0.2in 0.35in 0.35in; max-width: 100%;
            min-height: auto;
        }

        /* Show inline header block on print – top-right corner */
        .print-header { justify-content: flex-end; margin-bottom: 20px; }
        .print-header-right { 
            display: block; 
            text-align: right; 
            font-family: monospace;
        }
        .sale-number-print { 
            font-size: 18px; 
            font-weight: 800; 
            color: #2563eb; 
        }
        .sale-date-print { font-size: 11px; color: #64748b; margin-top: 2px; }
        .status-pill-print {
            display: inline-block; 
            padding: 2px 8px; 
            border-radius: 20px;
            font-size: 9px; 
            font-weight: 700; 
            margin-top: 5px;
            background: <?=htmlspecialchars($statusColor)?>22;
            color: <?=htmlspecialchars($statusColor)?>;
            border: 1px solid <?=htmlspecialchars($statusColor)?>;
        }

        /* Ensure footer stays at bottom of printed page */
        .footer-area {
            margin-top: auto;
            position: relative;
            bottom: 0;
        }
        .testify {
            font-size: 10px;
            margin: 15px 0 8px;
        }
        .footer-note {
            font-size: 9px;
            padding-top: 8px;
        }
    }
</style>

<!-- Silently suppress broken image 404s from header.php -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('img').forEach(function (img) {
        img.onerror = function () { this.style.display = 'none'; };
        // Also hide watermark images immediately
        if (img.src && img.src.includes('watermark')) {
            img.style.display = 'none';
        }
    });
});
</script>

<!-- Fixed badge: sale number, date, status — always top-right on screen (hidden on print) -->
<div class="sale-badge no-print">
    <div class="sn"><?=htmlspecialchars($sale['sale_number'])?></div>
    <div class="sd"><?=date('d F Y', strtotime($sale['sale_date']))?></div>
    <div><span class="status-pill"><?=strtoupper($sale['payment_status'])?></span></div>
</div>

<div class="print-container">
    <!-- Header: hidden on screen, shown on print (top-right corner) -->
    <div class="print-header">
        <div class="print-header-right">
            <div class="sale-number-print"><?=htmlspecialchars($sale['sale_number'])?></div>
            <div class="sale-date-print"><?=date('d F Y', strtotime($sale['sale_date']))?></div>
            <div style="margin-top:5px;"><span class="status-pill-print"><?=strtoupper($sale['payment_status'])?></span></div>
        </div>
    </div>

    <!-- Customer Info -->
    <div class="info-band">
        <div class="info-item">
            <span class="info-label">Customer</span>
            <span class="info-value"><?=htmlspecialchars($customer)?></span>
        </div>
        <?php if (!empty($sale['cust_phone'])): ?>
        <div class="info-item">
            <span class="info-label">Phone</span>
            <span class="info-value"><?=htmlspecialchars($sale['cust_phone'])?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($sale['cust_email'])): ?>
        <div class="info-item">
            <span class="info-label">Email</span>
            <span class="info-value"><?=htmlspecialchars($sale['cust_email'])?></span>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <span class="info-label">Payment Method</span>
            <span class="info-value"><?=htmlspecialchars($payMethod)?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Recorded By</span>
            <span class="info-value"><?=htmlspecialchars($_SESSION['full_name'] ?? 'Staff')?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Printed</span>
            <span class="info-value"><?=date('d M Y, H:i')?></span>
        </div>
    </div>

    <!-- Items Table -->
    <div class="section-title">Sale Items</div>
    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th style="text-align:center;">Qty</th>
                <th style="text-align:right;">Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td style="color:#94a3b8;width:28px;"><?=$i+1?></td>
                <td><?=htmlspecialchars($it['description'])?></td>
                <td style="text-align:center;"><?=number_format($it['quantity'], 2)?></td>
                <td style="text-align:right;">UGX <?=number_format($it['unit_price'])?></td>
                <td>UGX <?=number_format($it['total_price'])?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:1rem;">No items recorded</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3"></td>
                <td class="lbl">Subtotal</td>
                <td class="val">UGX <?=number_format($sale['subtotal'])?></td>
            </tr>
            <?php if ($sale['discount'] > 0): ?>
            <tr>
                <td colspan="3"></td>
                <td class="lbl" style="color:#dc2626;">Discount</td>
                <td class="val" style="color:#dc2626;">– UGX <?=number_format($sale['discount'])?></td>
            </tr>
            <?php endif; ?>
            <tr class="grand">
                <td colspan="3"></td>
                <td class="lbl" style="font-size:14px;">TOTAL</td>
                <td class="val" style="font-size:17px;">UGX <?=number_format($sale['total_amount'])?></td>
            </tr>
            <tr>
                <td colspan="3"></td>
                <td class="lbl" style="color:#059669;">Amount Paid</td>
                <td class="val" style="color:#059669;">UGX <?=number_format($sale['amount_paid'])?></td>
            </tr>
            <tr>
                <td colspan="3"></td>
                <td class="lbl" style="color:#dc2626;">Balance Due</td>
                <td class="val" style="color:#dc2626;font-size:15px;">UGX <?=number_format($sale['balance_due'])?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Notes -->
    <?php if (!empty($sale['notes'])): ?>
    <div class="notes-box"><strong>Notes:</strong> <?=nl2br(htmlspecialchars($sale['notes']))?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="sig-row">
        <div class="sig-box">Received by (Customer)<br><br><br></div>
        <div class="sig-box">Authorised by (Savant Motors)<br><br><br></div>
    </div>

    <!-- Footer area that sticks to bottom -->
    <div class="footer-area">
        <div class="testify">
            ⭐ Testify: “Savant Motors provided excellent service – reliable, professional, and great value!” – Happy Customer
        </div>
        <div class="footer-note">
            Thank you for your business • Savant Motors • Printed <?=date('d/m/Y H:i:s')?>
        </div>
    </div>

    <!-- Action Buttons (hidden on print) -->
    <div class="btn-bar no-print">
        <button class="btn-print-go" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
        <a href="sales_ledger.php" class="btn-secondary" style="padding:10px 28px;border-radius:40px;background:#e2e8f0;color:#475569;font-weight:700;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>