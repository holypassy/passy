<?php
// debtors.php – Debtors Ledger (money owed TO Savant Motors)
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
$user_id        = $_SESSION['user_id']   ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
date_default_timezone_set('Africa/Kampala');

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn->exec("
        CREATE TABLE IF NOT EXISTS debtors (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            customer_id     INT,
            customer_name   VARCHAR(120) NOT NULL,
            reference_type  VARCHAR(30) DEFAULT 'manual',
            reference_id    INT,
            reference_no    VARCHAR(50),
            amount_owed     DECIMAL(15,2) DEFAULT 0,
            amount_paid     DECIMAL(15,2) DEFAULT 0,
            balance         DECIMAL(15,2) DEFAULT 0,
            due_date        DATE,
            status          ENUM('open','partial','settled') DEFAULT 'open',
            notes           TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS debtor_company_vehicles (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            date              DATE NOT NULL,
            vehicle_make      VARCHAR(100) NOT NULL,
            number_plate      VARCHAR(30)  NOT NULL,
            company_in_charge VARCHAR(150) NOT NULL,
            work_done         TEXT,
            amount_owed       DECIMAL(15,2) DEFAULT 0,
            created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1010','Cash on Hand','asset',0),
        ('1020','Mobile Money','asset',0),
        ('1030','Bank Account','asset',0),
        ('1040','Cheque Account','asset',0),
        ('1200','Accounts Receivable','asset',0)
    ");

    $customers = $conn->query("SELECT id, full_name, telephone FROM customers WHERE status=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    $filter_status = $_GET['status'] ?? 'all';
    $where = $filter_status !== 'all' ? "WHERE status = " . $conn->quote($filter_status) : "";
    // Only show manually-added debtors in the main ledger (invoice-linked ones have their own section)
    $manualWhere = $filter_status !== 'all'
        ? "WHERE (reference_type = 'manual' OR reference_type IS NULL) AND status = " . $conn->quote($filter_status)
        : "WHERE (reference_type = 'manual' OR reference_type IS NULL)";
    $debtors = $conn->query("SELECT d.*, c.telephone FROM debtors d LEFT JOIN customers c ON d.customer_id=c.id $manualWhere ORDER BY d.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // Invoice-linked debtor records
    $invoiceDebtors = $conn->query("
        SELECT d.*, i.invoice_date, i.invoice_number AS inv_no,
               i.total_amount AS inv_total, i.payment_method,
               c.telephone, c.email
        FROM debtors d
        LEFT JOIN invoices i  ON d.reference_type = 'invoice' AND d.reference_id = i.id
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE d.reference_type = 'invoice'
        ORDER BY d.status ASC, d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Invoice debtors stats
    $invStats = $conn->query("
        SELECT
            COUNT(*)                                                      AS total,
            COALESCE(SUM(amount_owed),0)                                  AS total_owed,
            COALESCE(SUM(amount_paid),0)                                  AS total_paid,
            COALESCE(SUM(balance),0)                                      AS total_outstanding,
            SUM(CASE WHEN status='open'    THEN 1 ELSE 0 END)             AS open_count,
            SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END)             AS partial_count,
            SUM(CASE WHEN status='settled' THEN 1 ELSE 0 END)             AS settled_count
        FROM debtors WHERE reference_type = 'invoice'
    ")->fetch(PDO::FETCH_ASSOC);

    $stats = $conn->query("
        SELECT
            COUNT(*) as total,
            COALESCE(SUM(amount_owed),0)  as total_owed,
            COALESCE(SUM(amount_paid),0)  as total_collected,
            COALESCE(SUM(balance),0)       as total_outstanding,
            SUM(CASE WHEN status='open' THEN 1 ELSE 0 END)     as open_count,
            SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END)  as partial_count,
            SUM(CASE WHEN status='settled' THEN 1 ELSE 0 END)  as settled_count
        FROM debtors
        WHERE reference_type = 'manual' OR reference_type IS NULL
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ── POST: add debtor / record payment ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_debtor'])) {
        try {
            $conn->beginTransaction();
            $customer_id  = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
            $customer_name = trim($_POST['customer_name']);
            $amount_owed  = (float)$_POST['amount_owed'];
            $due_date     = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $reference_no = trim($_POST['reference_no'] ?? '');
            $notes        = trim($_POST['notes'] ?? '');

            $conn->prepare("
                INSERT INTO debtors (customer_id, customer_name, reference_type, reference_no, amount_owed, balance, due_date, notes)
                VALUES (?,?,'manual',?,?,?,?,?)
            ")->execute([$customer_id, $customer_name, $reference_no, $amount_owed, $amount_owed, $due_date, $notes]);
            $debtor_id = $conn->lastInsertId();

            // Debit Accounts Receivable
            $arId  = $conn->query("SELECT id FROM accounts WHERE account_code='1200'")->fetchColumn();
            $now   = date('Y-m-d H:i:s');
            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, "Debtor: $customer_name – $reference_no", $arId, $amount_owed, 0, 'debtor', $debtor_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount_owed, $arId]);

            $conn->commit();
            $_SESSION['success'] = "Debtor record added!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: debtors.php');
        exit();
    }

    if (isset($_POST['record_payment'])) {
        try {
            $conn->beginTransaction();
            $debtor_id      = (int)$_POST['debtor_id'];
            $payment_amount = (float)$_POST['payment_amount'];
            $payment_method = $_POST['payment_method'];

            $d = $conn->prepare("SELECT * FROM debtors WHERE id=? FOR UPDATE");
            $d->execute([$debtor_id]);
            $debtor = $d->fetch(PDO::FETCH_ASSOC);
            if (!$debtor) throw new Exception("Debtor not found");
            if ($payment_amount > $debtor['balance']) throw new Exception("Payment exceeds balance");

            $new_paid    = $debtor['amount_paid'] + $payment_amount;
            $new_balance = $debtor['balance']      - $payment_amount;
            $status      = $new_balance <= 0 ? 'settled' : 'partial';

            $conn->prepare("UPDATE debtors SET amount_paid=?,balance=?,status=? WHERE id=?")->execute([$new_paid, $new_balance, $status, $debtor_id]);

            // accounting: Debit asset, Credit AR
            $methodMap = ['cash'=>'1010','mobile_money'=>'1020','bank_transfer'=>'1030','cheque'=>'1040'];
            $assetCode = $methodMap[$payment_method] ?? '1010';
            $assetId   = $conn->query("SELECT id FROM accounts WHERE account_code='$assetCode'")->fetchColumn();
            $arId      = $conn->query("SELECT id FROM accounts WHERE account_code='1200'")->fetchColumn();
            $now       = date('Y-m-d H:i:s');
            $desc      = "Debtor payment: {$debtor['customer_name']} ({$debtor['reference_no']})";

            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $assetId, $payment_amount, 0, 'debtor_payment', $debtor_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id=?")->execute([$payment_amount, $assetId]);

            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $arId, 0, $payment_amount, 'debtor_payment', $debtor_id]);
            $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id=?")->execute([$payment_amount, $arId]);

            $conn->commit();
            $_SESSION['success'] = "Payment of UGX " . number_format($payment_amount) . " recorded!";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: debtors.php');
        exit();
    }
}

$success_message = $_SESSION['success'] ?? null;
$error_message   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);


?>
<style>
* { margin:0;padding:0;box-sizing:border-box; }
body { font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(135deg,#e6f0ff,#cce4ff); padding:2rem; font-size:14px; }
.page-wrap { max-width:1200px; margin:0 auto; }
.toolbar { background:linear-gradient(135deg,#2563eb,#1e3a8a);padding:1rem 1.5rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;border-radius:16px;margin-bottom:1.5rem; }
.toolbar button,.toolbar a { background:#2c3e50;border:none;color:white;padding:.5rem 1.2rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;transition:all .2s; }
.toolbar button:hover,.toolbar a:hover { background:#1e2b38;transform:translateY(-1px); }
.btn-green { background:#059669 !important; } .btn-green:hover { background:#047857 !important; }
.alert { padding:12px 18px;border-radius:12px;margin-bottom:1rem;display:flex;align-items:center;gap:10px; }
.alert-success{background:#d1fae5;color:#065f46;border-left:4px solid #10b981;}
.alert-danger{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;}
.stats-row { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem; }
.stat-card { background:white;border-radius:16px;padding:1.2rem 1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.07); }
.stat-card .label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem;}
.stat-card .value{font-size:20px;font-weight:800;}
.stat-card .value.red{color:#dc2626;} .stat-card .value.green{color:#059669;} .stat-card .value.orange{color:#d97706;}
.filter-bar { display:flex;gap:.8rem;margin-bottom:1rem;flex-wrap:wrap; }
.filter-btn { padding:6px 16px;border-radius:20px;border:1.5px solid #e2e8f0;background:white;cursor:pointer;font-size:13px;font-weight:600;color:#64748b;transition:all .2s; }
.filter-btn.active { background:#2563eb;color:white;border-color:#2563eb; }
.card { background:white;border-radius:20px;box-shadow:0 4px 16px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.5rem; }
.card-header { background:linear-gradient(135deg,#2563eb,#1e3a8a);color:white;padding:1rem 1.5rem;font-size:15px;font-weight:700;display:flex;align-items:center;gap:.6rem; }
.data-table { width:100%;border-collapse:collapse;font-size:13px; }
.data-table th { background:#f1f5f9;color:#475569;padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;text-align:left; }
.data-table td { padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle; }
.data-table tr:hover td { background:#f8fafc; }
.badge { padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700; }
.badge-open{background:#fee2e2;color:#991b1b;} .badge-partial{background:#fef3c7;color:#92400e;} .badge-settled{background:#d1fae5;color:#065f46;}
.action-btn{padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;}
.btn-pay{background:#d1fae5;color:#065f46;} .progress-bar-wrap{background:#e2e8f0;border-radius:20px;height:6px;width:100px;display:inline-block;vertical-align:middle;margin-left:6px;}
.progress-bar-fill{height:6px;border-radius:20px;background:#2563eb;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:2000;align-items:center;justify-content:center;}
.modal.active{display:flex;}
.modal-box{background:white;border-radius:24px;width:90%;max-width:480px;max-height:88vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,.25);}
.modal-header{background:linear-gradient(135deg,#2563eb,#1e3a8a);color:white;padding:1.1rem 1.5rem;border-radius:24px 24px 0 0;display:flex;justify-content:space-between;align-items:center;}
.modal-body{padding:1.5rem;}
.close-btn{background:rgba(255,255,255,.2);border:none;width:32px;height:32px;border-radius:50%;color:white;cursor:pointer;font-size:16px;}
.form-group{margin-bottom:1rem;}
.form-group label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;outline:none;transition:all .2s;}
.form-group input:focus,.form-group select:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.btn-submit{background:#2563eb;color:white;border:none;padding:11px 30px;border-radius:40px;font-weight:700;font-size:14px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:.5rem;}
.btn-submit:hover{background:#1e40af;}
@media(max-width:768px){.stats-row{grid-template-columns:1fr 1fr;}}
</style>

<div class="page-wrap">
<div class="toolbar no-print">
    <button class="btn-green" onclick="document.getElementById('addDebtorModal').classList.add('active')"><i class="fas fa-plus"></i> Add Debtor</button>
    <a href="company_vehicles.php" style="background:#7c3aed;"><i class="fas fa-car"></i> Company Vehicles</a>
    <a href="../invoices.php"><i class="fas fa-file-invoice-dollar"></i> Invoices</a>
    <a href="sales_ledger.php"><i class="fas fa-book-open"></i> Sales Ledger</a>
    <a href="creditors.php"><i class="fas fa-user-tie"></i> Creditors</a>
    <a href="receipt.php"><i class="fas fa-receipt"></i> Receipts</a>
    <a href="../dashboard_erp.php"><i class="fas fa-home"></i> Dashboard</a>
    <button onclick="syncInvoices()" style="background:#0284c7;" id="syncBtn"><i class="fas fa-sync-alt"></i> Sync Invoices</button>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($success_message)?></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?=htmlspecialchars($error_message)?></div>
<?php endif; ?>

<div class="stats-row" style="grid-template-columns:repeat(5,1fr);">
    <div class="stat-card"><div class="label"><i class="fas fa-users"></i> Manual Debtors</div><div class="value"><?=number_format($stats['total'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-file-invoice-dollar"></i> Invoice Debtors</div><div class="value" style="color:#0284c7;"><?=number_format($invStats['total'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-file-invoice-dollar"></i> Total Owed</div><div class="value red">UGX <?=number_format($stats['total_owed'] + $invStats['total_owed'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-coins"></i> Collected</div><div class="value green">UGX <?=number_format($stats['total_collected'] + $invStats['total_paid'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-clock"></i> Outstanding</div><div class="value <?=($stats['total_outstanding']+$invStats['total_outstanding'])>0?'red':'green'?>">UGX <?=number_format($stats['total_outstanding'] + $invStats['total_outstanding'])?></div></div>
</div>

<div class="filter-bar">
    <a href="?status=all"    class="filter-btn <?=$filter_status==='all'?'active':''?>">All (<?=$stats['total']?>)</a>
    <a href="?status=open"   class="filter-btn <?=$filter_status==='open'?'active':''?>">Open (<?=$stats['open_count']?>)</a>
    <a href="?status=partial" class="filter-btn <?=$filter_status==='partial'?'active':''?>">Partial (<?=$stats['partial_count']?>)</a>
    <a href="?status=settled" class="filter-btn <?=$filter_status==='settled'?'active':''?>">Settled (<?=$stats['settled_count']?>)</a>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-user-clock"></i> Debtors Ledger</div>
    <table class="data-table">
        <thead><tr><th>Customer</th><th>Reference</th><th>Date</th><th>Due Date</th><th>Owed</th><th>Paid</th><th>Balance</th><th>Progress</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (empty($debtors)): ?>
            <tr><td colspan="10" style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-user-check" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>No debtors found</td></tr>
        <?php else: foreach ($debtors as $d):
            $pct = $d['amount_owed'] > 0 ? round(($d['amount_paid'] / $d['amount_owed']) * 100) : 0;
        ?>
            <tr>
                <td>
                    <strong><?=htmlspecialchars($d['customer_name'])?></strong>
                    <?php if($d['telephone']): ?><div style="font-size:11px;color:#94a3b8;"><?=htmlspecialchars($d['telephone'])?></div><?php endif; ?>
                </td>
                <td><?=htmlspecialchars($d['reference_no'])?></td>
                <td><?=date('d M Y', strtotime($d['created_at']))?></td>
                <td><?=$d['due_date'] ? date('d M Y', strtotime($d['due_date'])) : '—'?></td>
                <td>UGX <?=number_format($d['amount_owed'])?></td>
                <td style="color:#059669;">UGX <?=number_format($d['amount_paid'])?></td>
                <td><strong style="color:#dc2626;">UGX <?=number_format($d['balance'])?></strong></td>
                <td>
                    <div><?=$pct?>%</div>
                    <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?=$pct?>%;"></div></div>
                </td>
                <td><span class="badge badge-<?=$d['status']?>"><?=ucfirst($d['status'])?></span></td>
                <td>
                    <?php if($d['status'] !== 'settled'): ?>
                    <a class="action-btn btn-pay"
                       href="receipt.php?source_type=debtor&source_id=<?=$d['id']?>"
                       title="Pay via Receipt">
                        <i class="fas fa-receipt"></i> Pay via Receipt
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════
     INVOICE-LINKED DEBTORS SECTION
════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header" style="background:linear-gradient(135deg,#0284c7,#0369a1);display:flex;justify-content:space-between;align-items:center;">
        <span><i class="fas fa-file-invoice-dollar"></i> Invoice-Linked Debtors
            <span style="font-size:12px;font-weight:400;opacity:.8;margin-left:8px;">(auto-synced from unpaid &amp; partially paid invoices)</span>
        </span>
        <div style="display:flex;gap:8px;align-items:center;font-size:12px;">
            <span style="background:rgba(255,255,255,.2);padding:3px 10px;border-radius:20px;">
                Open: <?= $invStats['open_count'] ?> &nbsp;|&nbsp;
                Partial: <?= $invStats['partial_count'] ?> &nbsp;|&nbsp;
                Settled: <?= $invStats['settled_count'] ?>
            </span>
            <a href="invoices.php" style="background:rgba(255,255,255,.2);color:white;text-decoration:none;padding:5px 14px;border-radius:20px;font-weight:600;font-size:12px;">
                <i class="fas fa-external-link-alt"></i> Go to Invoices
            </a>
        </div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Invoice Date</th>
                <th>Total Invoice</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Progress</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($invoiceDebtors)): ?>
            <tr>
                <td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;">
                    <i class="fas fa-file-invoice" style="font-size:2rem;display:block;margin-bottom:.5rem;color:#bae6fd;"></i>
                    No invoice-linked debtor records found.<br>
                    <span style="font-size:12px;">Click <strong>Sync Invoices</strong> in the toolbar to import all existing unpaid invoices, or create new invoices — they sync automatically.</span>
                </td>
            </tr>
        <?php else: foreach ($invoiceDebtors as $inv):
            $pct = $inv['amount_owed'] > 0 ? round(($inv['amount_paid'] / $inv['amount_owed']) * 100) : 0;
        ?>
            <tr style="<?= $inv['status'] === 'settled' ? 'opacity:.6;' : '' ?>">
                <td>
                    <strong style="color:#0284c7;"><?= htmlspecialchars($inv['reference_no'] ?? '—') ?></strong>
                    <div style="font-size:10px;color:#94a3b8;margin-top:1px;">
                        <a href="invoices.php" style="color:#0284c7;text-decoration:none;"><i class="fas fa-link"></i> View in Invoices</a>
                    </div>
                </td>
                <td>
                    <strong><?= htmlspecialchars($inv['customer_name']) ?></strong>
                    <?php if (!empty($inv['telephone'])): ?>
                    <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($inv['telephone']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= !empty($inv['invoice_date']) ? date('d M Y', strtotime($inv['invoice_date'])) : date('d M Y', strtotime($inv['created_at'])) ?></td>
                <td><strong>UGX <?= number_format($inv['amount_owed']) ?></strong></td>
                <td style="color:#059669;">UGX <?= number_format($inv['amount_paid']) ?></td>
                <td><strong style="color:#dc2626;">UGX <?= number_format($inv['balance']) ?></strong></td>
                <td>
                    <div style="font-size:11px;"><?= $pct ?>%</div>
                    <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct ?>%;background:#0284c7;"></div></div>
                </td>
                <td><span class="badge badge-<?= $inv['status'] ?>"><?= ucfirst($inv['status']) ?></span></td>
                <td>
                    <?php if ($inv['status'] !== 'settled'): ?>
                    <a href="receipt.php?source_type=debtor&source_id=<?=$inv['id']?>"
                       class="action-btn" style="background:#dbeafe;color:#0284c7;text-decoration:none;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;">
                        <i class="fas fa-receipt"></i> Pay via Receipt
                    </a>
                    <?php else: ?>
                    <span style="color:#10b981;font-size:12px;font-weight:600;"><i class="fas fa-check-circle"></i> Settled</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Debtor Modal -->
<div id="addDebtorModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
        <span><i class="fas fa-user-plus"></i> Add Debtor</span>
        <button class="close-btn" onclick="document.getElementById('addDebtorModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="form-group">
            <label>Customer</label>
            <select name="customer_id" onchange="fillName(this)">
                <option value="">— Select or type below —</option>
                <?php foreach($customers as $c): ?>
                <option value="<?=$c['id']?>" data-name="<?=htmlspecialchars($c['full_name'])?>"><?=htmlspecialchars($c['full_name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Customer Name *</label>
            <input type="text" name="customer_name" id="debtorName" placeholder="Name" required>
        </div>
        <div class="form-group">
            <label>Amount Owed (UGX) *</label>
            <input type="number" name="amount_owed" min="1" step="0.01" required>
        </div>
        <div class="form-group">
            <label>Reference # (Invoice / Sale)</label>
            <input type="text" name="reference_no" placeholder="e.g. INV-2024-0001">
        </div>
        <div class="form-group">
            <label>Due Date</label>
            <input type="date" name="due_date">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="2"></textarea>
        </div>
        <div style="text-align:right;">
            <button type="submit" name="add_debtor" class="btn-submit"><i class="fas fa-save"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Pay Modal -->
<div id="payModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
        <span><i class="fas fa-money-bill-wave"></i> Record Payment</span>
        <button class="close-btn" onclick="document.getElementById('payModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="debtor_id" id="payDebtorId">
        <div class="form-group"><label>Customer</label><input type="text" id="payDebtorName" readonly style="background:#f8fafc;"></div>
        <div class="form-group"><label>Balance Due</label><input type="text" id="payDebtorBal" readonly style="background:#f8fafc;font-weight:700;color:#dc2626;"></div>
        <div class="form-group">
            <label>Payment Amount (UGX) *</label>
            <input type="number" name="payment_amount" id="payAmount" min="1" step="0.01" required>
        </div>
        <div class="form-group">
            <label>Payment Method</label>
            <select name="payment_method">
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
            </select>
        </div>
        <div style="text-align:right;">
            <button type="submit" name="record_payment" class="btn-submit"><i class="fas fa-check"></i> Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function fillName(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('debtorName').value = opt.dataset.name || '';
}
// Payment now handled via receipt.php — redirect for compatibility
function openPayModal(id, name, balance) {
    window.location.href = 'receipt.php?source_type=debtor&source_id=' + id;
}

async function syncInvoices() {
    const btn = document.getElementById('syncBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
    btn.disabled = true;
    try {
        const res  = await fetch('invoices.php?action=sync_invoices_to_debtors');
        const data = await res.json();
        if (data.success) {
            showToast('✅ Synced ' + data.synced + ' invoice(s) to debtors ledger.', 'success');
            setTimeout(() => location.reload(), 1400);
        } else {
            showToast('❌ Sync failed: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        showToast('❌ Network error: ' + e.message, 'error');
    } finally {
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}

function showToast(msg, type) {
    const t = document.createElement('div');
    t.innerHTML = msg;
    t.style.cssText = `position:fixed;bottom:24px;right:24px;padding:14px 22px;border-radius:12px;
        font-size:14px;font-weight:600;color:white;z-index:9999;
        background:${type === 'success' ? '#059669' : '#dc2626'};
        box-shadow:0 8px 24px rgba(0,0,0,.18);animation:fadeIn .3s ease;`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

window.onclick = e => {
    ['addDebtorModal','payModal'].forEach(id => {
        const m = document.getElementById(id);
        if (e.target === m) m.classList.remove('active');
    });
};
</script>
</body>
</html>
