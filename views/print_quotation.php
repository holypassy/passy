<?php
// print_quotation.php — clean layout, blue headers, black data, red "QUOTATION" word
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($quotation_id <= 0) {
    header('Location: quotations.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("
        SELECT q.*,
               c.full_name AS customer_name,
               c.telephone,
               c.email,
               c.address,
               q.customer_type,
               q.tin AS customer_tin,
               q.company_name
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

    $stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY item_number");
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $company_tin = '1024070144';
    $customer_tin = ($quote['customer_type'] === 'business') ? trim($quote['customer_tin'] ?? '') : '';

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$computed_subtotal = 0;
foreach ($items as $item) {
    $computed_subtotal += (float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0);
}
$grand_total      = $computed_subtotal > 0 ? $computed_subtotal : (float)($quote['total_amount'] ?? 0);
$tax_amount       = (float)($quote['tax'] ?? 0);
$subtotal_display = $tax_amount > 0 ? $grand_total - $tax_amount : $grand_total;

// Safe defaults for all quote fields to prevent undefined index notices
$quote['customer_name']   = $quote['customer_name']   ?? 'N/A';
$quote['customer_type']   = $quote['customer_type']   ?? '';
$quote['company_name']    = $quote['company_name']    ?? '';
$quote['vehicle_model']   = $quote['vehicle_model']   ?? '';
$quote['telephone']       = $quote['telephone']       ?? '';
$quote['quotation_date']  = $quote['quotation_date']  ?? date('Y-m-d');
$quote['quotation_number']= $quote['quotation_number']?? '';
$quote['chassis_no']      = $quote['chassis_no']      ?? '-';
$quote['odo_reading']     = $quote['odo_reading']     ?? '-';
$quote['vehicle_reg']     = $quote['vehicle_reg']     ?? '-';

$page_title    = 'QUOTATION';
$page_subtitle = 'Vehicle Repair Quotation';
if (file_exists('header.php')) include_once 'header.php';
?>

<style>
/* ============================================
   GLOBAL RESET & TYPOGRAPHY - ONE PAGE OPTIMIZED
   ============================================ */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body, .q-doc, .q-items, .q-pay, .q-tot,
.q-foot, .q-cust, .q-dt, .q-veh {
    font-family: 'Yu Gothic UI', 'Yu Gothic', sans-serif !important;
    font-weight: 400 !important;
}

/* Page title red */
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
   MAIN DOCUMENT - Tighter spacing for one page
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
   CUSTOMER + DATE AREA - TIN beside telephone
   ============================================ */
.q-meta {
    display: grid;
    grid-template-columns: 1fr 240px;
    border-bottom: 1px solid #000000;
}
.q-cust {
    padding: 8px 14px;
    border-right: 1px solid #000000;
}
.q-cust-name {
    font-weight: 400;
    font-size: 16px;
    color: #000000;
    margin-bottom: 4px;
}
.q-cust-model {
    font-weight: 400;
    font-size: 12px;
    color: #000000;
    margin-bottom: 3px;
}
.q-contact-row {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 12px;
    margin-top: 4px;
    font-size: 11px;
}
.q-cust-tel {
    color: #000000;
    font-weight: 400;
}
.q-tin {
    font-weight: 400;
    color: #000000;
}
.q-tin span {
    color: #b91c1c;
    font-weight: 400;
}
.q-dt-wrap {
    padding: 8px;
    background: #fafcff;
}
.q-dt {
    width: 100%;
    border-collapse: collapse;
}
.q-dt th {
    background: #eef2ff;
    color: #000000;
    font-weight: 400;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 6px 8px;
    text-align: center;
    border: 1px solid #000000;
}
.q-dt td {
    padding: 6px 8px;
    text-align: center;
    font-weight: 400;
    font-size: 12px;
    border: 1px solid #000000;
    color: #000000;
    background: white;
}

/* ============================================
   VEHICLE STRIP - Compact
   ============================================ */
.q-veh {
    display: grid;
    grid-template-columns: 1fr 100px 1fr;
    border-bottom: 1px solid #000000;
}
.q-vc {
    border-right: 1px solid #000000;
}
.q-vc:last-child {
    border-right: none;
}
.q-vc-lbl {
    background: #f1f5f9;
    color: #000000;
    font-weight: 400;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 5px 8px;
    text-align: center;
    border-bottom: 1px solid #000000;
}
.q-vc-val {
    font-weight: 400;
    color: #b91c1c;
    text-align: center;
    padding: 6px 8px;
    font-size: 12px;
    background: #ffffff;
}

/* ============================================
   ITEMS TABLE - Original thin visible borders
   ============================================ */
.q-items {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.q-items thead th {
    background: #eef2ff;
    color: #2563eb !important;
    font-weight: 400;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 6px 5px;
    border-top: none;
    border-bottom: 1px solid #1a56db;
    border-left: none;
    border-right: 0.3px solid #bbbbbb;
}
.q-items thead th:last-child {
    border-right: none;
}
.q-items thead th.l {
    text-align: left;
}
.q-items thead th.r {
    text-align: right;
}
.q-items tbody td {
    padding: 5px 6px;
    border-top: none;
    border-bottom: none;
    border-left: none;
    border-right: 0.3px solid #bbbbbb;
    vertical-align: top;
    color: #000000 !important;
    font-weight: 400;
    line-height: 1.2;
}
.q-items tbody td:last-child {
    border-right: none;
}
.q-items tbody td.c {
    text-align: center;
}
.q-items tbody td.r {
    text-align: right;
}
.q-items tbody td:first-child,
.q-items tbody td.amt,
.q-items tbody td.r {
    color: #000000 !important;
    font-weight: 400;
}
/* Alternating row colors */
.q-items tbody tr:nth-child(odd) td {
    background: #f8fafc;
}
.q-items tbody tr:nth-child(even) td {
    background: #ffffff;
}
.q-items .er td {
    background: #ffffff !important;
    height: 20px;
    border-top: none;
    border-bottom: none;
    border-left: none;
    border-right: 0.3px solid #bbbbbb;
    color: transparent !important;
}
.q-items .er td:last-child {
    border-right: none;
}
.q-items tbody tr:first-child td {
    border-top: none;
}

/* Last row bottom border connects table to footer */
.q-items tbody tr:last-child td {
    border-bottom: 1px solid #000000;
}
   ============================================ */
.q-bot {
    display: block;
    border-top: 1px solid #000000;
}
.q-bot-row {
    display: grid;
    grid-template-columns: 1fr 240px;
}
.q-pay {
    padding: 8px 14px;
    border-right: 1px solid #000000;
    line-height: 1.5;
}
.q-pay-title {
    font-weight: 400;
    font-size: 12px;
    color: #000000;
    margin-bottom: 6px;
}
.q-pay-body {
    font-size: 11px;
    font-weight: 400;
    color: #000000;
}
.q-pay-body .red {
    color: #b91c1c;
    font-weight: 400;
    font-size: 12px;
}
.q-pay-note {
    font-size: 9px;
    color: #000000;
    font-style: italic;
    border-top: 1px solid #000000;
    padding-top: 6px;
    margin-top: 6px;
    font-weight: 400;
    line-height: 1.4;
}
.q-tot {
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    gap: 4px;
    background: #f8fafc;
}
.q-trow {
    display: flex;
    justify-content: space-between;
    font-size: 10.5px;
    font-weight: 400;
    color: #000000;
    padding: 3px 0;
    border-bottom: 0.5px solid #d1d5db;
}
.q-grand {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #ffffff;
    color: #000000;
    padding: 8px 12px;
    border-radius: 0;
    margin-top: auto;
    font-weight: 700;
    font-size: 13px;
    border: 1px solid #000000;
}
.q-grand .q-gamt {
    color: #000000;
    font-size: 15px;
    font-weight: 700;
}
.q-foot {
    background: #eef2ff;
    border-top: 1px solid #000000;
    padding: 6px 16px;
    text-align: center;
    color: #000000;
    font-style: italic;
    font-size: 10px;
    font-weight: 400;
}

/* ============================================
   PRINT STYLES - Force one page, thin borders
   ============================================ */
@media print {
    @page {
        size: A4 portrait;
        margin: 0.3cm 0.4cm;
    }
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    body, .q-doc, .q-items, .q-pay, .q-tot,
    .q-foot, .q-cust, .q-dt, .q-veh {
        font-family: 'Yu Gothic UI', 'Yu Gothic', sans-serif !important;
        font-weight: 400 !important;
    }
    body {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .q-toolbar, .sidebar, .main-header, .no-print {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .q-doc {
        max-width: 100% !important;
        margin: 0 !important;
        border: 1px solid #000000 !important;
    }
    .q-to-band {
        background: #f0f4fe !important;
        border-bottom: 1px solid #000000 !important;
    }
    .q-cust {
        border-right: 1px solid #000000 !important;
    }
    .q-dt th, .q-dt td {
        border: 1px solid #000000 !important;
    }
    .q-vc {
        border-right: 1px solid #000000 !important;
    }
    .q-vc-lbl {
        border-bottom: 1px solid #000000 !important;
    }
    .q-items thead th {
        border-top: none !important;
        border-bottom: 1px solid #1a56db !important;
        border-left: none !important;
        border-right: 0.3px solid #bbbbbb !important;
    }
    .q-items thead th:last-child {
        border-right: none !important;
    }
    .q-items tbody td {
        border-top: none !important;
        border-bottom: none !important;
        border-left: none !important;
        border-right: 0.3px solid #bbbbbb !important;
    }
    .q-items tbody td:last-child {
        border-right: none !important;
    }
    .q-items tbody tr:first-child td {
        border-top: none !important;
    }
    .q-items .er td {
        border-top: none !important;
        border-bottom: none !important;
        border-left: none !important;
        border-right: 0.3px solid #bbbbbb !important;
        color: transparent !important;
    }
    .q-items .er td:last-child {
        border-right: none !important;
    }
    .q-pay {
        border-right: 1px solid #000000 !important;
    }
    .q-pay-note {
        border-top: 1px solid #000000 !important;
    }
    .q-trow {
        border-bottom: 0.5px solid #d1d5db !important;
    }
    .q-grand {
        border: 1px solid #000000 !important;
        background: #ffffff !important;
        color: #000000 !important;
        font-weight: 700 !important;
    }
    .q-items tbody tr:last-child td {
        border-bottom: 1px solid #000000 !important;
    }
    .q-foot {
        border-top: 1px solid #000000 !important;
    }
}
</style>

<div class="q-toolbar no-print">
    <button onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
    <a href="quotations.php"><i class="fas fa-arrow-left"></i> Back to Quotations</a>
</div>

<div class="q-doc">
    <div class="q-to-band">
        <span style="color:#1a56db;font-weight:900;font-size:14px;">QUOTATION TO</span>
        <span style="font-size:11px;font-weight:700;">
            TIN: <span style="font-weight:900;"><?= htmlspecialchars($company_tin) ?></span>
        </span>
    </div>

    <div class="q-meta">
        <div class="q-cust">
            <div class="q-cust-name">
                <?= htmlspecialchars(strtoupper($quote['customer_name'] ?? 'N/A')) ?>
                <?php if ($quote['customer_type'] === 'business' && !empty($quote['company_name'])): ?>
                    <span style="font-size:11px; font-weight:normal;"> (<?= htmlspecialchars($quote['company_name']) ?>)</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($quote['vehicle_model'])): ?>
                <div class="q-cust-model">MODEL: <?= htmlspecialchars(strtoupper($quote['vehicle_model'])) ?></div>
            <?php endif; ?>
            <div class="q-contact-row">
                <?php if (!empty($quote['telephone'])): ?>
                    <span class="q-cust-tel">📞 <?= htmlspecialchars($quote['telephone']) ?></span>
                <?php endif; ?>
                <?php if ($quote['customer_type'] === 'business' && !empty($customer_tin)): ?>
                    <span class="q-tin">🏢 TIN: <span><?= htmlspecialchars($customer_tin) ?></span></span>
                <?php elseif ($quote['customer_type'] !== 'business'): ?>
                    <span class="q-tin" style="color:#64748b;">🏢 TIN: <span style="color:#64748b;">——————</span></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="q-dt-wrap">
            <table class="q-dt">
                <thead>
                    <tr><th>📅 DATE</th><th>📄 QUOTE NO.</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($quote['quotation_date'] ?? 'now')) ?></td>
                        <td><?= htmlspecialchars($quote['quotation_number'] ?? '') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="q-veh">
        <div class="q-vc">
            <div class="q-vc-lbl">🔧 CHASSIS NO.</div>
            <div class="q-vc-val"><?= htmlspecialchars($quote['chassis_no'] ?? '-') ?></div>
        </div>
        <div class="q-vc">
            <div class="q-vc-lbl">📊 ODO (KM)</div>
            <div class="q-vc-val"><?= htmlspecialchars($quote['odo_reading'] ?? '-') ?></div>
        </div>
        <div class="q-vc">
            <div class="q-vc-lbl">🚗 REG NO.</div>
            <div class="q-vc-val"><?= htmlspecialchars($quote['vehicle_reg'] ?? '-') ?></div>
        </div>
    </div>

    <table class="q-items">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th class="l" style="width:130px;">ITEM</th>
                <th class="l">DESCRIPTION</th>
                <th style="width:45px;">U/M</th>
                <th style="width:45px;">QTY</th>
                <th class="r" style="width:100px;">RATE (UGX)</th>
                <th class="r" style="width:110px;">AMOUNT (UGX)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $ctr = 1;
            $shown = 0;
            foreach ($items as $item):
                $lt = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                $nm = trim($item['item'] ?? $item['item_name'] ?? $item['name'] ?? $item['product_name'] ?? '');
                $dsc = trim($item['description'] ?? $item['item_description'] ?? $item['details'] ?? '');
                $um = strtoupper(trim($item['um'] ?? $item['unit_of_measure'] ?? $item['uom'] ?? $item['unit'] ?? ''));
                if ($nm === '' && $dsc === '' && $lt == 0) continue;
                if ($nm === '' && $dsc !== '') $nm = $dsc;
                $qty = (float)($item['quantity'] ?? 0);
                $rate = (float)($item['unit_price'] ?? 0);
                $shown++;
            ?>
                <tr>
                    <td class="c"><?= $ctr++ ?></td>
                    <td><?= htmlspecialchars(strtoupper($nm ?: '—')) ?></td>
                    <td><?= nl2br(htmlspecialchars(strtoupper($dsc ?: '—'))) ?></td>
                    <td class="c"><?= htmlspecialchars($um) ?></td>
                    <td class="c"><?= $qty > 0 ? ($qty == intval($qty) ? intval($qty) : $qty) : '' ?></td>
                    <td class="r"><?= $lt > 0 ? number_format($rate, 2) : '' ?></td>
                    <td class="r amt"><?= $lt > 0 ? number_format($lt, 2) : '' ?></td>
                </tr>
            <?php endforeach;
            $emp = max(0, 25 - $shown);
            for ($e = 0; $e < $emp; $e++): ?>
                <tr class="er">
                    <td class="c"></td>
                    <td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
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
                    70% deposit required. Balance on completion. Valid 30 days.
                </div>
            </div>
            <div class="q-tot">
                <?php if ($tax_amount > 0): ?>
                    <div class="q-trow">
                        <span>Subtotal</span>
                        <span>UGX <?= number_format($subtotal_display, 2) ?></span>
                    </div>
                    <div class="q-trow">
                        <span>VAT (18%)</span>
                        <span>UGX <?= number_format($tax_amount, 2) ?></span>
                    </div>
                <?php endif; ?>
                <div class="q-grand">
                    <span>💰 TOTAL</span>
                    <span class="q-gamt">UGX <?= number_format($grand_total, 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="q-foot">✨ "Testify" — Thank you for choosing Savant Motors ✨</div>
</div>

<script>
    if (window.location.search.includes('autoprint=1')) {
        window.addEventListener('load', () => setTimeout(() => window.print(), 400));
    }
</script>