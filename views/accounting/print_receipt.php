<?php
// print_receipt.php – Print a single Receipt / Payment Voucher
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid receipt ID.');
}

$receipt_id  = (int)$_GET['id'];
$auto_print  = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $conn->prepare("
        SELECT r.*, a.account_name, a.account_code,
               c.full_name as cust_fullname, c.telephone as cust_phone, c.email as cust_email
        FROM receipts r
        LEFT JOIN accounts a  ON r.account_id  = a.id
        LEFT JOIN customers c ON r.customer_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        die('Receipt not found.');
    }

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

$isReceived  = $receipt['receipt_type'] === 'received';
$party       = $receipt['cust_fullname'] ?: $receipt['party_name'] ?: 'Unknown';
$payMethod   = ucwords(str_replace('_', ' ', $receipt['payment_method']));
$statusColor = $isReceived ? '#059669' : '#dc2626';
$docTitle    = $isReceived ? 'RECEIPT' : 'PAYMENT VOUCHER';

// Set page title for unified header
$page_title = $isReceived ? 'Receipt' : 'Payment Voucher';
include_once '../header.php';
?>

<style>
    /* ── Fixed badge — visible on screen, hidden on print ── */
    .rcpt-badge {
        position: fixed; top: 18px; right: 24px; text-align: right;
        z-index: 9999; background: white;
        border: 1.5px solid <?= htmlspecialchars($statusColor) ?>;
        border-radius: 12px; padding: 8px 16px 8px 14px;
        box-shadow: 0 4px 14px rgba(0,0,0,.12); line-height: 1.4;
    }
    .rcpt-badge .rn  { font-size: 15px; font-weight: 800; color: <?= htmlspecialchars($statusColor) ?>; font-family: monospace; }
    .rcpt-badge .rd  { font-size: 11px; color: #64748b; }
    .rcpt-badge .rpill {
        display: inline-block; padding: 2px 10px; border-radius: 20px;
        font-size: 10px; font-weight: 700; margin-top: 3px;
        background: <?= htmlspecialchars($statusColor) ?>22;
        color: <?= htmlspecialchars($statusColor) ?>;
        border: 1.5px solid <?= htmlspecialchars($statusColor) ?>;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }

    .main-content { background: #f1f5f9; }

    .print-container {
        max-width: 760px; margin: 24px auto 40px;
        background: white; border-radius: 16px;
        box-shadow: 0 8px 30px rgba(0,0,0,.10);
        padding: 32px 38px 38px;
        display: flex; flex-direction: column; min-height: 75vh;
    }

    .print-header {
        display: flex; justify-content: flex-end;
        margin-bottom: 24px; padding-bottom: 16px;
        border-bottom: 3px double <?= htmlspecialchars($statusColor) ?>;
    }
    .print-header-right { display: none; }

    .doc-type-banner {
        text-align: center; margin-bottom: 20px;
        font-size: 1.4rem; font-weight: 900; letter-spacing: 3px;
        color: <?= htmlspecialchars($statusColor) ?>;
        text-transform: uppercase;
    }

    .info-band {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 12px 30px; background: #f8faff;
        border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 14px 18px; margin-bottom: 24px;
    }
    .info-item { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
    .info-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; min-width: 80px; }
    .info-value { font-size: 13px; font-weight: 600; color: #1e293b; }

    .amount-block {
        text-align: center; margin: 24px 0;
        padding: 20px; border-radius: 12px;
        background: <?= htmlspecialchars($statusColor) ?>11;
        border: 2px solid <?= htmlspecialchars($statusColor) ?>33;
    }
    .amount-label { font-size: 11px; font-weight: 700; text-transform: uppercase;
                    color: #64748b; letter-spacing: 1px; margin-bottom: 6px; }
    .amount-value { font-size: 2.8rem; font-weight: 900;
                    color: <?= htmlspecialchars($statusColor) ?>;
                    -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .amount-words { font-size: 12px; color: #64748b; margin-top: 6px; font-style: italic; }

    .notes-box {
        background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
        padding: 12px 16px; font-size: 12px; color: #78350f; margin-bottom: 20px;
    }

    .sig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px; }
    .sig-box { border-top: 1px solid #94a3b8; padding-top: 8px; font-size: 11px; color: #64748b; }

    .footer-area { margin-top: auto; }
    .testify {
        text-align: center; font-size: 11px; color: #475569;
        margin: 20px 0 12px; font-style: italic;
    }
    .footer-note {
        text-align: center; font-size: 10px; color: #94a3b8;
        border-top: 1px dotted #cbd5e1; padding-top: 12px;
    }

    .btn-bar {
        text-align: center; margin-top: 28px; padding-top: 16px;
        border-top: 1px solid #e2e8f0;
    }
    .btn-bar button, .btn-bar a {
        padding: 10px 28px; border-radius: 40px; border: none;
        font-weight: 700; font-size: 13px; cursor: pointer; margin: 0 6px;
        display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
    }
    .btn-print-go { background: <?= htmlspecialchars($statusColor) ?>; color: white; }
    .btn-close-pg { background: #e2e8f0; color: #475569; }

    @media print {
        .top-nav, .sidebar, .page-title-bar, nav, header:not(.print-doc-header),
        .btn-bar, .rcpt-badge, .no-print { display: none !important; }
        body, .main-content { background: white; padding: 0; margin: 0; }
        .print-container {
            box-shadow: none; border-radius: 0; margin: 0;
            padding: 0.2in 0.35in 0.35in; max-width: 100%; min-height: auto;
        }
        .print-header { justify-content: flex-end; margin-bottom: 20px; }
        .print-header-right { display: block; text-align: right; font-family: monospace; }
        .rn-print { font-size: 18px; font-weight: 800; color: <?= htmlspecialchars($statusColor) ?>; }
        .rd-print  { font-size: 11px; color: #64748b; margin-top: 2px; }
        .rpill-print {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 9px; font-weight: 700; margin-top: 5px;
            background: <?= htmlspecialchars($statusColor) ?>22;
            color: <?= htmlspecialchars($statusColor) ?>;
            border: 1px solid <?= htmlspecialchars($statusColor) ?>;
        }
    }
</style>

<!-- Fixed screen badge (hidden on print) -->
<div class="rcpt-badge no-print">
    <div class="rn"><?= htmlspecialchars($receipt['receipt_number']) ?></div>
    <div class="rd"><?= date('d F Y', strtotime($receipt['receipt_date'])) ?></div>
    <div><span class="rpill"><?= strtoupper($receipt['receipt_type']) ?></span></div>
</div>

<div class="print-container">

    <!-- Print-only header (top-right corner on paper) -->
    <div class="print-header">
        <div class="print-header-right">
            <div class="rn-print"><?= htmlspecialchars($receipt['receipt_number']) ?></div>
            <div class="rd-print"><?= date('d F Y', strtotime($receipt['receipt_date'])) ?></div>
            <div style="margin-top:5px;"><span class="rpill-print"><?= strtoupper($receipt['receipt_type']) ?></span></div>
        </div>
    </div>

    <!-- Document type banner -->
    <div class="doc-type-banner">
        <?= $isReceived ? '✅ Receipt' : '💸 Payment Voucher' ?>
    </div>

    <!-- Info band -->
    <div class="info-band">
        <div class="info-item">
            <span class="info-label"><?= $isReceived ? 'Received From' : 'Paid To' ?></span>
            <span class="info-value"><?= htmlspecialchars($party) ?></span>
        </div>
        <?php if (!empty($receipt['cust_phone'])): ?>
        <div class="info-item">
            <span class="info-label">Phone</span>
            <span class="info-value"><?= htmlspecialchars($receipt['cust_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($receipt['cust_email'])): ?>
        <div class="info-item">
            <span class="info-label">Email</span>
            <span class="info-value"><?= htmlspecialchars($receipt['cust_email']) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <span class="info-label">Receipt #</span>
            <span class="info-value" style="font-family:monospace;"><?= htmlspecialchars($receipt['receipt_number']) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Date</span>
            <span class="info-value"><?= date('d F Y', strtotime($receipt['receipt_date'])) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Payment Method</span>
            <span class="info-value"><?= htmlspecialchars($payMethod) ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Account</span>
            <span class="info-value"><?= htmlspecialchars($receipt['account_name'] ?? '—') ?></span>
        </div>
        <?php if (!empty($receipt['reference'])): ?>
        <div class="info-item">
            <span class="info-label">Reference</span>
            <span class="info-value"><?= htmlspecialchars($receipt['reference']) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <span class="info-label">Processed By</span>
            <span class="info-value"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Staff') ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Printed</span>
            <span class="info-value"><?= date('d M Y, H:i') ?></span>
        </div>
    </div>

    <!-- Amount block -->
    <div class="amount-block">
        <div class="amount-label"><?= $isReceived ? 'Amount Received' : 'Amount Paid Out' ?></div>
        <div class="amount-value">UGX <?= number_format($receipt['amount']) ?></div>
        <?php if (!empty($receipt['description'])): ?>
        <div class="amount-words"><?= htmlspecialchars($receipt['description']) ?></div>
        <?php endif; ?>
    </div>

    <!-- Signatures -->
    <div class="sig-row">
        <div class="sig-box">
            <?= $isReceived ? 'Received by (Customer)' : 'Authorised Payment' ?><br><br><br>
        </div>
        <div class="sig-box">
            Authorised by (Savant Motors)<br><br><br>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer-area">
        <div class="testify">
            ⭐ "Savant Motors – reliable, professional, and great value!" – Happy Customer
        </div>
        <div class="footer-note">
            Thank you for your business &nbsp;•&nbsp; Savant Motors &nbsp;•&nbsp; Bugolobi, Bunyonyi Drive, Kampala &nbsp;•&nbsp; +256 774 537 017
            &nbsp;•&nbsp; Printed <?= date('d/m/Y H:i:s') ?>
        </div>
    </div>

    <!-- Action buttons (hidden on print) -->
    <div class="btn-bar no-print">
        <button class="btn-print-go" onclick="window.print()">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
        <a href="receipt.php" class="btn-close-pg">
            <i class="fas fa-arrow-left"></i> Back to Receipts
        </a>
    </div>

</div>

<?php if ($auto_print): ?>
<script>
    window.addEventListener('load', () => {
        setTimeout(() => window.print(), 600);
    });
</script>
<?php endif; ?>
