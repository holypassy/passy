<?php
// print_invoice.php – Print invoice matching exact image format (modern & larger font)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php'); exit();
}
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid invoice ID.";
    header('Location: invoices.php'); exit();
}

$invoice_id = (int)$_GET['id'];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if customers table has tin_number column
    $stmt = $conn->query("SHOW COLUMNS FROM customers");
    $customerColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $hasCustomerTin = in_array('tin_number', $customerColumns);
    
    // Build SELECT list accordingly
    $tinSelect = $hasCustomerTin ? "c.tin_number" : "'' as tin_number";
    
    $sql = "
        SELECT i.*, c.full_name as customer_name, c.telephone, c.email, c.address,
               {$tinSelect},
               q.quotation_number
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN quotations q ON i.quotation_id = q.id
        WHERE i.id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $_SESSION['error'] = "Invoice not found.";
        header('Location: invoices.php'); exit();
    }

    // Fallback: if TIN still empty, try to get from invoices table (if column exists)
    $tin = $invoice['tin_number'] ?? '';
    if (empty($tin)) {
        try {
            $icols = $conn->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('tin_number', $icols)) {
                $tinRow = $conn->prepare("SELECT tin_number FROM invoices WHERE id=?");
                $tinRow->execute([$invoice_id]);
                $tin = $tinRow->fetchColumn() ?: '';
                $invoice['tin_number'] = $tin;
            }
        } catch (Exception $e2) {
            // ignore
        }
    }

    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Detect the actual item-name column — different installs use different names
    $itemNameCol   = 'item_name';   // default
    $itemDescCol   = 'description'; // default
    if (!empty($items)) {
        $cols = array_keys($items[0]);
        // Name column: try in priority order
        foreach (['item_name','service_name','name','part_name','product_name','title','item'] as $c) {
            if (in_array($c, $cols)) { $itemNameCol = $c; break; }
        }
        // Description column
        foreach (['description','item_description','details','notes','desc','service_description'] as $c) {
            if (in_array($c, $cols)) { $itemDescCol = $c; break; }
        }
    } else {
        // Table is empty — detect from schema directly
        $schemaCols = $conn->query("SHOW COLUMNS FROM invoice_items")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['item_name','service_name','name','part_name','product_name','title','item'] as $c) {
            if (in_array($c, $schemaCols)) { $itemNameCol = $c; break; }
        }
        foreach (['description','item_description','details','notes','desc','service_description'] as $c) {
            if (in_array($c, $schemaCols)) { $itemDescCol = $c; break; }
        }
    }

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

$MIN_ROWS = 12;
$filler   = max(0, $MIN_ROWS - count($items));
$subtotal = (float)($invoice['subtotal']      ?? 0);
$tax      = (float)($invoice['tax']           ?? 0);
$discount = (float)($invoice['discount']      ?? 0);
$total    = (float)($invoice['total_amount']  ?? ($subtotal - $discount + $tax));
$tin      = $invoice['tin_number']  ?? '';
$odo      = $invoice['odo_reading'] ?? $invoice['vehicle_odo'] ?? '';
$inv_date = !empty($invoice['invoice_date']) ? date('d/m/Y', strtotime($invoice['invoice_date'])) : '';
$inv_no   = $invoice['invoice_number'] ?? '';
$vat_pct  = $tax > 0 ? round(($tax / max($subtotal - $discount, 1)) * 100, 1) : 0;

// Company TIN (adjust as needed)
$company_tin = "1024070144";

$page_title    = 'INVOICE';
$page_subtitle = 'Vehicle Repair Invoice';
if (file_exists('header.php')) include_once 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================
           GLOBAL RESET & TYPOGRAPHY
           ============================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body, .q-doc, .q-items, .q-pay, .q-tot,
        .q-foot, .q-cust, .q-dt, .q-veh {
            font-family: 'Yu Gothic UI', 'Yu Gothic', sans-serif !important;
            font-weight: 400 !important;
        }

        .unified-header .header-right h3,
        .page-header .page-title,
        .main-header h1, .main-header h2, .main-header h3,
        [class*="page-title"] {
            color: #1a56db !important;
            font-size: 1.8rem !important;
            font-weight: 800 !important;
            letter-spacing: 2px !important;
        }

        /* ============================================
           TOOLBAR
           ============================================ */
        .q-toolbar {
            max-width: 1000px;
            margin: 0.5rem auto;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        .q-toolbar button, .q-toolbar a {
            background: #1a56db;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 400;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        /* ============================================
           MAIN DOCUMENT
           ============================================ */
        .q-doc {
            max-width: 1000px;
            margin: 0 auto 1rem;
            background: white;
            border: 1px solid #000000;
            font-size: 11px;
            color: #000000;
        }

        /* ============================================
           TOP BAND
           ============================================ */
        .q-to-band {
            background: #f0f4fe;
            color: #1a56db;
            font-weight: 400;
            font-size: 14px;
            letter-spacing: 1.5px;
            padding: 8px 16px;
            border-bottom: 1px solid #000000;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* ============================================
           CUSTOMER + DATE AREA
           ============================================ */
        .q-meta {
            display: grid;
            grid-template-columns: 1fr 240px;
            border-bottom: 1px solid #000000;
        }
        .q-cust { padding: 8px 14px; border-right: 1px solid #000000; }
        .q-cust-name { font-weight: 400; font-size: 16px; color: #000000; margin-bottom: 4px; }
        .q-cust-model { font-weight: 400; font-size: 12px; color: #000000; margin-bottom: 3px; }
        .q-contact-row {
            display: flex; flex-wrap: wrap; align-items: baseline;
            gap: 12px; margin-top: 4px; font-size: 11px;
        }
        .q-cust-tel { color: #000000; font-weight: 400; }
        .q-tin { font-weight: 400; color: #000000; }
        .q-tin span { color: #b91c1c; font-weight: 400; }
        .q-dt-wrap { padding: 8px; background: #fafcff; }
        .q-dt { width: 100%; border-collapse: collapse; }
        .q-dt th {
            background: #eef2ff; color: #000000; font-weight: 400;
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
            padding: 6px 8px; text-align: center; border: 1px solid #000000;
        }
        .q-dt td {
            padding: 6px 8px; text-align: center; font-weight: 400;
            font-size: 12px; border: 1px solid #000000; color: #000000; background: white;
        }

        /* ============================================
           VEHICLE STRIP
           ============================================ */
        .q-veh { display: grid; grid-template-columns: 1fr 100px 1fr; border-bottom: 1px solid #000000; }
        .q-vc { border-right: 1px solid #000000; }
        .q-vc:last-child { border-right: none; }
        .q-vc-lbl {
            background: #f1f5f9; color: #000000; font-weight: 400; font-size: 9px;
            text-transform: uppercase; letter-spacing: 0.5px; padding: 5px 8px;
            text-align: center; border-bottom: 1px solid #000000;
        }
        .q-vc-val { font-weight: 400; color: #b91c1c; text-align: center; padding: 6px 8px; font-size: 12px; background: #ffffff; }

        /* ============================================
           ITEMS TABLE — no visible row lines
           ============================================ */
        .q-items { width: 100%; border-collapse: collapse; font-size: 13px; }
        .q-items thead th {
            background: #eef2ff; color: #2563eb !important; font-weight: 400; font-size: 11px;
            text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 5px;
            border-top: none; border-bottom: 1px solid #1a56db; border-left: none; border-right: 0.3px solid #bbbbbb;
        }
        .q-items thead th:last-child { border-right: none; }
        .q-items thead th.l { text-align: left; }
        .q-items thead th.r { text-align: right; }
        .q-items tbody td {
            padding: 5px 6px; border-top: none; border-bottom: none; border-left: none;
            border-right: 0.3px solid #bbbbbb; vertical-align: top;
            color: #000000 !important; font-weight: 400; line-height: 1.2;
        }
        .q-items tbody td:last-child { border-right: none; }
        .q-items tbody td.c { text-align: center; }
        .q-items tbody td.r { text-align: right; }
        .q-items tbody td:first-child, .q-items tbody td.amt, .q-items tbody td.r { color: #000000 !important; font-weight: 400; }
        .q-items tbody tr:nth-child(odd) td  { background: #f8fafc; }
        .q-items tbody tr:nth-child(even) td { background: #ffffff; }
        .q-items .er td {
            background: #ffffff !important; height: 20px;
            border-top: none; border-bottom: none; border-left: none;
            border-right: 0.3px solid #bbbbbb; color: transparent !important;
        }
        .q-items .er td:last-child { border-right: none; }
        .q-items tbody tr:first-child td { border-top: none; }
        .q-items tbody tr:last-child td { border-bottom: 1px solid #000000; }

        /* ============================================
           BOTTOM: PAYMENT + TOTALS
           ============================================ */
        .q-bot { display: block; border-top: 1px solid #000000; }
        .q-bot-row { display: grid; grid-template-columns: 1fr 240px; }
        .q-pay { padding: 8px 14px; border-right: 1px solid #000000; line-height: 1.5; }
        .q-pay-title { font-weight: 400; font-size: 12px; color: #000000; margin-bottom: 6px; }
        .q-pay-body  { font-size: 11px; font-weight: 400; color: #000000; }
        .q-pay-body .red { color: #b91c1c; font-weight: 400; font-size: 12px; }
        .q-pay-note {
            font-size: 9px; color: #000000; font-style: italic;
            border-top: 1px solid #000000; padding-top: 6px; margin-top: 6px; font-weight: 400; line-height: 1.4;
        }
        .q-partial { margin-top: 6px; font-size: 11px; font-weight: 400; color: #b91c1c; line-height: 1.6; }
        .q-tot {
            padding: 8px 10px; display: flex; flex-direction: column;
            justify-content: flex-end; gap: 4px; background: #f8fafc;
        }
        .q-trow {
            display: flex; justify-content: space-between; font-size: 10.5px;
            font-weight: 400; color: #000000; padding: 3px 0; border-bottom: 0.5px solid #d1d5db;
        }
        .q-grand {
            display: flex; justify-content: space-between; align-items: center;
            background: #ffffff; color: #000000; padding: 8px 12px; border-radius: 0;
            margin-top: auto; font-weight: 700; font-size: 13px; border: 1px solid #000000;
        }
        .q-grand .q-gamt { color: #000000; font-size: 15px; font-weight: 700; }

        /* ============================================
           STATUS BAR
           ============================================ */
        .q-status {
            padding: 6px 16px; text-align: center; font-weight: 400; font-size: 11px;
            border-top: 1px solid #000000; letter-spacing: 0.5px; color: #000000; background: #f0f0f0;
        }
        .q-status.paid    { color: #065f46; background: #d1fae5; }
        .q-status.unpaid  { color: #991b1b; background: #fee2e2; }
        .q-status.partial { color: #92400e; background: #fef3c7; }

        /* ============================================
           NOTES & FOOTER
           ============================================ */
        .q-notes { padding: 6px 14px; font-size: 10px; background: #f9f9f9; border-top: 1px solid #000000; color: #000000; font-weight: 400; }
        .q-foot {
            background: #eef2ff; border-top: 1px solid #000000; padding: 6px 16px;
            text-align: center; color: #000000; font-style: italic; font-size: 10px; font-weight: 400;
        }

        /* ============================================
           PRINT STYLES
           ============================================ */
        @media print {
            @page { size: A4 portrait; margin: 0.3cm 0.4cm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body, .q-doc, .q-items, .q-pay, .q-tot, .q-foot, .q-cust, .q-dt, .q-veh {
                font-family: 'Yu Gothic UI', 'Yu Gothic', sans-serif !important; font-weight: 400 !important;
            }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .q-toolbar, .sidebar, .main-header, .no-print { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .q-doc { max-width: 100% !important; margin: 0 !important; border: 1px solid #000000 !important; }
            .q-to-band { background: #f0f4fe !important; border-bottom: 1px solid #000000 !important; }
            .q-cust { border-right: 1px solid #000000 !important; }
            .q-dt th, .q-dt td { border: 1px solid #000000 !important; }
            .q-vc { border-right: 1px solid #000000 !important; }
            .q-vc-lbl { border-bottom: 1px solid #000000 !important; }
            .q-items thead th { border-top: none !important; border-bottom: 1px solid #1a56db !important; border-left: none !important; border-right: 0.3px solid #bbbbbb !important; }
            .q-items thead th:last-child { border-right: none !important; }
            .q-items tbody td { border-top: none !important; border-bottom: none !important; border-left: none !important; border-right: 0.3px solid #bbbbbb !important; }
            .q-items tbody td:last-child { border-right: none !important; }
            .q-items tbody tr:first-child td { border-top: none !important; }
            .q-items .er td { border-top: none !important; border-bottom: none !important; border-left: none !important; border-right: 0.3px solid #bbbbbb !important; color: transparent !important; }
            .q-items .er td:last-child { border-right: none !important; }
            .q-items tbody tr:last-child td { border-bottom: 1px solid #000000 !important; }
            .q-pay { border-right: 1px solid #000000 !important; }
            .q-pay-note { border-top: 1px solid #000000 !important; }
            .q-trow { border-bottom: 0.5px solid #d1d5db !important; }
            .q-grand { border: 1px solid #000000 !important; background: #ffffff !important; color: #000000 !important; font-weight: 700 !important; }
            .q-foot { border-top: 1px solid #000000 !important; }
            .q-status.paid    { background: #d1fae5 !important; color: #065f46 !important; }
            .q-status.unpaid  { background: #fee2e2 !important; color: #991b1b !important; }
            .q-status.partial { background: #fef3c7 !important; color: #92400e !important; }
        }
    </style>
</head>
<body>

<?php if (file_exists('header.php')): ?>
    <!-- Header will be included here -->
<?php endif; ?>

<div class="q-toolbar no-print">
    <button onclick="window.print()"><i class="fas fa-print"></i> Print Invoice</button>
    <a href="invoices.php"><i class="fas fa-arrow-left"></i> Back to Invoices</a>
</div>

<div class="q-doc">

    <div class="q-to-band">
        <span style="color:#1a56db;font-weight:900;font-size:14px;">INVOICE TO</span>
        <span style="font-size:11px;font-weight:700;">
            TIN: <span style="font-weight:900;"><?= htmlspecialchars($company_tin) ?></span>
        </span>
    </div>

    <div class="q-meta">
        <div class="q-cust">
            <div class="q-cust-name">
                <?= htmlspecialchars(strtoupper($invoice['customer_name'] ?? 'N/A')) ?>
            </div>
            <?php if (!empty($invoice['vehicle_model'])): ?>
                <div class="q-cust-model">MODEL: <?= htmlspecialchars(strtoupper($invoice['vehicle_model'])) ?></div>
            <?php endif; ?>
            <div class="q-contact-row">
                <?php if (!empty($invoice['telephone'])): ?>
                    <span class="q-cust-tel">📞 <?= htmlspecialchars($invoice['telephone']) ?></span>
                <?php endif; ?>
                <?php if (!empty($tin)): ?>
                    <span class="q-tin">🏢 TIN: <span><?= htmlspecialchars($tin) ?></span></span>
                <?php else: ?>
                    <span class="q-tin" style="color:#64748b;">🏢 TIN: <span style="color:#64748b;">——————</span></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="q-dt-wrap">
            <table class="q-dt">
                <thead>
                    <tr><th>📅 DATE</th><th>📄 INVOICE NO.</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= htmlspecialchars($inv_date) ?></td>
                        <td><?= htmlspecialchars($inv_no) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="q-veh">
        <div class="q-vc">
            <div class="q-vc-lbl">🔧 CHASSIS NO.</div>
            <div class="q-vc-val"><?= htmlspecialchars($invoice['chassis_no'] ?? '-') ?></div>
        </div>
        <div class="q-vc">
            <div class="q-vc-lbl">📊 ODO (KM)</div>
            <div class="q-vc-val"><?= htmlspecialchars($odo ?: '-') ?></div>
        </div>
        <div class="q-vc">
            <div class="q-vc-lbl">🚗 REG NO.</div>
            <div class="q-vc-val"><?= htmlspecialchars(strtoupper($invoice['vehicle_reg'] ?? '-')) ?></div>
        </div>
    </div>

    <table class="q-items">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th class="l" style="width:130px;">ITEM</th>
                <th class="l">DESCRIPTION</th>
                <th style="width:45px;">QTY</th>
                <th class="r" style="width:100px;">RATE (UGX)</th>
                <th class="r" style="width:110px;">AMOUNT (UGX)</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $line_no = 1;
        $shown   = 0;
        foreach ($items as $item):
            $qty        = (float)($item['quantity']   ?? 0);
            $price      = (float)($item['unit_price'] ?? $item['rate'] ?? $item['price'] ?? 0);
            $total_line = (float)($item['total_price'] ?? $item['line_total'] ?? $item['amount'] ?? ($qty * $price));
            $item_name  = strtoupper(trim($item[$itemNameCol] ?? ''));
            $desc       = strtoupper(trim($item[$itemDescCol] ?? ''));
            if ($desc === $item_name) $desc = '';
            $shown++;
        ?>
            <tr>
                <td class="c"><?= $line_no++ ?></td>
                <td><?= htmlspecialchars($item_name) ?></td>
                <td><?= nl2br(htmlspecialchars($desc)) ?></td>
                <td class="c"><?= $qty > 0 ? ($qty == intval($qty) ? intval($qty) : $qty) : '' ?></td>
                <td class="r"><?= $price > 0 ? number_format($price, 2) : '' ?></td>
                <td class="r amt"><?= $total_line > 0 ? number_format($total_line, 2) : '' ?></td>
            </tr>
        <?php endforeach;
        $emp = max(0, 25 - $shown);
        for ($e = 0; $e < $emp; $e++): ?>
            <tr class="er"><td class="c"></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <div class="q-bot">
        <div class="q-bot-row">
            <div class="q-pay">
                <div class="q-pay-title">💰 PAYMENT DETAILS — SAVANT MOTORS</div>
                <div class="q-pay-body">
                    🏦 ABSA A/C NO: <span class="red">6007717553</span> &nbsp;|&nbsp; 📱 MOMO: <span class="red">915573</span>
                </div>
                <div class="q-pay-note">
                    A penalty of 5% per month applies on any outstanding balance after thirty (30) days from invoice date.
                </div>
                <?php if (($invoice['payment_status'] ?? '') === 'partial'): ?>
                <div class="q-partial">
                    💰 Amount Paid: UGX <?= number_format($invoice['amount_paid'] ?? 0, 0) ?><br>
                    ⚖️ Balance Due: UGX <?= number_format($total - ($invoice['amount_paid'] ?? 0), 0) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="q-tot">
                <div class="q-trow">
                    <span>Sub Total</span>
                    <span>UGX <?= number_format($subtotal, 2) ?></span>
                </div>
                <?php if ($discount > 0): ?>
                <div class="q-trow">
                    <span>Discount</span>
                    <span>UGX <?= number_format($discount, 2) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($tax > 0): ?>
                <div class="q-trow">
                    <span>VAT (<?= $vat_pct ?>%)</span>
                    <span>UGX <?= number_format($tax, 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="q-grand">
                    <span>💰 TOTAL</span>
                    <span class="q-gamt">UGX <?= number_format($total, 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php $pstatus = $invoice['payment_status'] ?? 'unpaid'; ?>
    <div class="q-status <?= htmlspecialchars($pstatus) ?>">
        Status: <strong><?= strtoupper($pstatus) ?></strong>
        <?php if ($pstatus === 'paid'): ?> — ✓ Paid in Full
        <?php elseif ($pstatus === 'partial'): ?> — Partially Paid
        <?php else: ?> — Awaiting Payment
        <?php endif; ?>
    </div>

    <?php if (!empty($invoice['notes'])): ?>
    <div class="q-notes"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($invoice['notes'])) ?></div>
    <?php endif; ?>

    <div class="q-foot">✨ "Testify" — Thank you for choosing Savant Motors ✨</div>
</div>

<script>
if (window.location.search.includes('autoprint=1')) {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>