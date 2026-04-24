<?php
// jobs/invoice.php — Generate & print invoice for a completed job card
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$jobId   = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$job     = null;
$customer = null;
$labourLines = [];
$partLines   = [];
$dbError = null;

if (!$jobId) {
    header('Location: index.php'); exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Load job
    $stmt = $conn->prepare("
        SELECT jc.*, c.full_name AS customer_name, c.phone AS customer_phone,
               c.email AS customer_email, c.address AS customer_address
        FROM job_cards jc
        LEFT JOIN customers c ON jc.customer_id = c.id
        WHERE jc.id = :id
    ");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) { header('Location: index.php'); exit(); }

    // Load labour lines
    try {
        $lStmt = $conn->prepare("SELECT * FROM job_labour_lines WHERE job_id = :jid ORDER BY id");
        $lStmt->execute([':jid' => $jobId]);
        $labourLines = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Load parts lines
    try {
        $pStmt = $conn->prepare("SELECT * FROM job_parts_lines WHERE job_id = :jid ORDER BY id");
        $pStmt->execute([':jid' => $jobId]);
        $partLines = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // Mark as invoiced on first load (if completed)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_invoiced'])) {
        $conn->prepare("UPDATE job_cards SET status='invoiced', updated_at=NOW() WHERE id=:id AND status='completed'")
             ->execute([':id' => $jobId]);
        // Optionally create a record in invoices table
        try {
            $invNum = 'INV-' . strtoupper(substr(md5($jobId . time()), 0, 8));
            $conn->prepare("
                INSERT INTO invoices (invoice_number, customer_id, job_card_id, total_amount, amount_paid, payment_status, status, created_at)
                VALUES (:inv, :cid, :jid, :total, 0, 'unpaid', 'active', NOW())
            ")->execute([':inv'=>$invNum,':cid'=>$job['customer_id'],':jid'=>$jobId,':total'=>$job['total_amount']]);
        } catch (PDOException $e) {} // invoices table structure may differ
        header('Location: invoice.php?job_id=' . $jobId . '&marked=1');
        exit();
    }

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$invoiceDate = date('d F Y');
$dueDate     = date('d F Y', strtotime('+14 days'));
$invoiceNum  = 'INV-' . ($job['job_number'] ?? 'JC-' . $jobId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoiceNum); ?> | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0;padding:0;box-sizing:border-box; }
        body { font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh; }
        :root {
            --primary:#1e40af;--primary-light:#3b82f6;--success:#10b981;
            --danger:#ef4444;--border:#e2e8f0;--gray:#64748b;--dark:#0f172a;--bg-light:#f8fafc;
        }

        /* Screen-only controls */
        .screen-only { background:#1e40af; }
        .controls-bar {
            background:#1e40af;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;
        }
        .controls-bar h2 { color:white;font-size:1rem;font-weight:600; }
        .btn { padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.8rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none; }
        .btn-white { background:white;color:var(--primary); }
        .btn-green { background:#10b981;color:white; }
        .btn-outline { background:transparent;border:1px solid rgba(255,255,255,.5);color:white; }
        .status-badge { display:inline-block;padding:3px 10px;border-radius:999px;font-size:.7rem;font-weight:700;margin-left:.5rem; }
        .status-invoiced { background:#dcfce7;color:#166534; }
        .status-completed { background:#fef9c3;color:#854d0e; }
        .status-open { background:#dbeafe;color:#1e40af; }

        /* ── Invoice document ── */
        .invoice-wrapper { max-width:820px;margin:2rem auto;padding:0 1rem 3rem; }
        .invoice-doc {
            background:white;border-radius:1rem;overflow:hidden;
            box-shadow:0 4px 24px rgba(0,0,0,.12);
        }

        /* Header */
        .inv-header {
            background:linear-gradient(135deg,#1e40af 0%,#3b82f6 100%);
            color:white;padding:2.5rem;
            display:grid;grid-template-columns:1fr auto;gap:2rem;align-items:start;
        }
        .inv-logo h1 { font-size:1.8rem;font-weight:800;letter-spacing:-0.5px; }
        .inv-logo p { font-size:.8rem;opacity:.75;margin-top:.25rem; }
        .inv-logo .tagline { font-size:.7rem;opacity:.6;margin-top:.1rem; }
        .inv-meta { text-align:right; }
        .inv-meta .inv-num { font-size:1.4rem;font-weight:800;letter-spacing:-0.5px; }
        .inv-meta .inv-label { font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;opacity:.7;margin-bottom:.25rem; }
        .inv-meta .inv-dates { font-size:.75rem;opacity:.8;margin-top:.5rem;line-height:1.8; }

        /* Body */
        .inv-body { padding:2rem 2.5rem; }

        /* Bill-to / Vehicle */
        .inv-parties { display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-bottom:2rem; }
        .party-box h4 { font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:var(--gray);margin-bottom:.5rem;font-weight:700; }
        .party-box p { font-size:.85rem;color:var(--dark);line-height:1.7; }
        .party-box strong { font-weight:700; }

        /* Line items */
        .section-title { font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);margin:1.5rem 0 .5rem;padding-bottom:.4rem;border-bottom:2px solid var(--border); }
        .inv-table { width:100%;border-collapse:collapse;margin-bottom:1rem;font-size:.82rem; }
        .inv-table th { background:var(--bg-light);padding:.6rem .8rem;text-align:left;font-weight:600;font-size:.7rem;color:var(--gray);border-bottom:1px solid var(--border); }
        .inv-table td { padding:.65rem .8rem;border-bottom:1px solid var(--border);color:var(--dark); }
        .inv-table tr:last-child td { border-bottom:none; }
        .inv-table .amount { text-align:right;font-weight:600; }
        .inv-table tfoot td { font-weight:700;background:var(--bg-light); }

        /* Totals */
        .totals-section { display:flex;justify-content:flex-end;margin-top:1.5rem; }
        .totals-table { width:280px; }
        .totals-table tr td { padding:.45rem .75rem;font-size:.83rem; }
        .totals-table tr td:last-child { text-align:right;font-weight:600; }
        .totals-table .grand-row td { font-size:1rem;font-weight:800;color:var(--primary);border-top:2px solid var(--border);padding-top:.7rem; }

        /* Footer / Bank details */
        .inv-footer {
            background:var(--bg-light);border-top:1px solid var(--border);
            padding:1.5rem 2.5rem;display:grid;grid-template-columns:1fr 1fr;gap:2rem;
        }
        .inv-footer h4 { font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;color:var(--gray);margin-bottom:.5rem;font-weight:700; }
        .inv-footer p { font-size:.78rem;color:var(--dark);line-height:1.7; }

        .thank-you { text-align:center;padding:1.5rem;border-top:1px solid var(--border); }
        .thank-you p { color:var(--gray);font-size:.82rem; }
        .thank-you strong { color:var(--primary); }

        .no-lines { text-align:center;padding:1.5rem;color:var(--gray);font-size:.82rem;font-style:italic; }

        @media print {
            .screen-only,.controls-bar { display:none!important; }
            body { background:white; }
            .invoice-wrapper { margin:0;padding:0; }
            .invoice-doc { box-shadow:none;border-radius:0; }
        }
        @media(max-width:600px) {
            .inv-header { grid-template-columns:1fr; }
            .inv-meta { text-align:left; }
            .inv-parties { grid-template-columns:1fr; }
            .inv-footer  { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>

<!-- Controls bar (screen only) -->
<div class="controls-bar screen-only">
    <div>
        <h2>🧾 Invoice Preview</h2>
        <span style="color:rgba(255,255,255,.6);font-size:.75rem;"><?php echo htmlspecialchars($invoiceNum); ?></span>
        <?php
        $s = $job['status'] ?? 'open';
        $cls = $s === 'invoiced' ? 'status-invoiced' : ($s === 'completed' ? 'status-completed' : 'status-open');
        echo "<span class=\"status-badge $cls\">" . ucfirst($s) . "</span>";
        ?>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
        <a href="create.php?id=<?php echo $jobId; ?>" class="btn btn-outline"><i class="fas fa-edit"></i> Edit Job</a>
        <?php if (($job['status'] ?? '') === 'completed'): ?>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="mark_invoiced" value="1">
            <button type="submit" class="btn btn-green"><i class="fas fa-check-circle"></i> Mark Invoiced</button>
        </form>
        <?php endif; ?>
        <button class="btn btn-white" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
    </div>
</div>

<?php if (isset($_GET['marked'])): ?>
<div style="background:#dcfce7;color:#166534;padding:.75rem 2rem;font-size:.85rem;display:flex;align-items:center;gap:.5rem;" class="screen-only">
    <i class="fas fa-check-circle"></i> Job marked as <strong>Invoiced</strong> and invoice record created.
</div>
<?php endif; ?>

<!-- Invoice document -->
<div class="invoice-wrapper">
<div class="invoice-doc">

    <!-- Header -->
    <div class="inv-header">
        <div class="inv-logo">
            <h1>SAVANT MOTORS</h1>
            <p>Authorised Vehicle Service Centre</p>
            <p class="tagline">Plot 14, Industrial Area · Kampala, Uganda</p>
            <p class="tagline">Tel: +256 700 000 000 · info@savantmotors.ug</p>
        </div>
        <div class="inv-meta">
            <div class="inv-label">Invoice</div>
            <div class="inv-num"><?php echo htmlspecialchars($invoiceNum); ?></div>
            <div class="inv-label" style="margin-top:1rem;">Job Card</div>
            <div style="font-weight:700;"><?php echo htmlspecialchars($job['job_number'] ?? '—'); ?></div>
            <div class="inv-dates">
                <div>Date: <strong><?php echo $invoiceDate; ?></strong></div>
                <div>Due:  <strong><?php echo $dueDate; ?></strong></div>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="inv-body">

        <!-- Bill-to & Vehicle -->
        <div class="inv-parties">
            <div class="party-box">
                <h4>Bill To</h4>
                <p>
                    <strong><?php echo htmlspecialchars($job['customer_name'] ?? 'Customer'); ?></strong><br>
                    <?php if (!empty($job['customer_phone'])): ?>
                    <?php echo htmlspecialchars($job['customer_phone']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($job['customer_email'])): ?>
                    <?php echo htmlspecialchars($job['customer_email']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($job['customer_address'])): ?>
                    <?php echo htmlspecialchars($job['customer_address']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="party-box">
                <h4>Vehicle Details</h4>
                <p>
                    <strong><?php echo htmlspecialchars(strtoupper($job['vehicle_reg'] ?? '—')); ?></strong><br>
                    <?php echo htmlspecialchars(trim(($job['vehicle_make'] ?? '') . ' ' . ($job['vehicle_model'] ?? ''))); ?><br>
                    <?php if (!empty($job['completion_date'])): ?>
                    Completed: <?php echo date('d F Y', strtotime($job['completion_date'])); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Description -->
        <?php if (!empty($job['description'])): ?>
        <div style="background:#f0f9ff;border-left:3px solid #3b82f6;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1.5rem;font-size:.82rem;color:var(--dark);">
            <strong>Work Carried Out:</strong> <?php echo nl2br(htmlspecialchars($job['description'])); ?>
        </div>
        <?php endif; ?>

        <!-- Labour Lines -->
        <div class="section-title">🔧 Labour</div>
        <?php if (!empty($labourLines)): ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="width:100px;">Hours</th>
                    <th style="width:140px;">Rate (UGX/hr)</th>
                    <th style="width:140px;" class="amount">Amount (UGX)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($labourLines as $l): ?>
            <tr>
                <td><?php echo htmlspecialchars($l['description']); ?></td>
                <td><?php echo number_format((float)$l['hours'], 1); ?> hrs</td>
                <td><?php echo number_format((float)$l['rate']); ?></td>
                <td class="amount"><?php echo number_format((float)$l['line_total']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;font-size:.78rem;color:var(--gray);">Labour Subtotal</td>
                    <td class="amount">UGX <?php echo number_format((float)($job['labour_cost'] ?? 0)); ?></td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <p class="no-lines">No labour lines recorded.</p>
        <?php endif; ?>

        <!-- Parts Lines -->
        <div class="section-title">⚙️ Parts &amp; Materials</div>
        <?php if (!empty($partLines)): ?>
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Part / Material</th>
                    <th style="width:80px;">Qty</th>
                    <th style="width:150px;">Unit Price (UGX)</th>
                    <th style="width:140px;" class="amount">Amount (UGX)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($partLines as $p): ?>
            <tr>
                <td><?php echo htmlspecialchars($p['description']); ?></td>
                <td><?php echo number_format((float)$p['quantity'], 0); ?></td>
                <td><?php echo number_format((float)$p['unit_price']); ?></td>
                <td class="amount"><?php echo number_format((float)$p['line_total']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;font-size:.78rem;color:var(--gray);">Parts Subtotal</td>
                    <td class="amount">UGX <?php echo number_format((float)($job['parts_cost'] ?? 0)); ?></td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <p class="no-lines">No parts lines recorded.</p>
        <?php endif; ?>

        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Labour</td>
                    <td>UGX <?php echo number_format((float)($job['labour_cost'] ?? 0)); ?></td>
                </tr>
                <tr>
                    <td>Parts &amp; Materials</td>
                    <td>UGX <?php echo number_format((float)($job['parts_cost'] ?? 0)); ?></td>
                </tr>
                <tr class="grand-row">
                    <td>TOTAL DUE</td>
                    <td>UGX <?php echo number_format((float)($job['total_amount'] ?? 0)); ?></td>
                </tr>
            </table>
        </div>

    </div><!-- /inv-body -->

    <!-- Footer: payment & notes -->
    <div class="inv-footer">
        <div>
            <h4>Payment Details</h4>
            <p>
                Bank: <strong>Stanbic Bank Uganda</strong><br>
                Account Name: <strong>Savant Motors Ltd</strong><br>
                Account No: <strong>9030005678123</strong><br>
                Branch: Industrial Area
            </p>
        </div>
        <div>
            <h4>Terms &amp; Notes</h4>
            <p>
                Payment is due within <strong>14 days</strong> of invoice date.<br>
                All parts carry a <strong>90-day warranty</strong>.<br>
                Labour warranty: <strong>30 days</strong> on completed work.<br>
                Please quote invoice number with all payments.
            </p>
        </div>
    </div>

    <div class="thank-you">
        <p>Thank you for choosing <strong>Savant Motors</strong> — Your trusted vehicle service partner in Uganda.</p>
    </div>

</div><!-- /invoice-doc -->
</div><!-- /invoice-wrapper -->

</body>
</html>
