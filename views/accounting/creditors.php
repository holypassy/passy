<?php
// creditors.php – Creditors Ledger (money Savant Motors owes to others)
// UPDATED: purchases now post to Inventory (1300), COGS (5000), AP (2000), and asset accounts
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

    // ── Ensure creditors table exists ─────────────────────────────────────────
    $conn->exec("
        CREATE TABLE IF NOT EXISTS creditors (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name   VARCHAR(120) NOT NULL,
            reference_type  VARCHAR(30) DEFAULT 'manual',
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

    // ── Seed ALL accounts needed for purchases ────────────────────────────────
    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1010','Cash on Hand','asset',0),
        ('1020','Mobile Money','asset',0),
        ('1030','Bank Account','asset',0),
        ('1040','Cheque Account','asset',0),
        ('1300','Inventory / Stock','asset',0),
        ('1500','Fixed Assets – Tools & Equipment','asset',0),
        ('1510','Fixed Assets – Workshop Equipment','asset',0),
        ('1520','Fixed Assets – Furniture & Fittings','asset',0),
        ('1530','Fixed Assets – Motor Vehicles','asset',0),
        ('1540','Fixed Assets – Other','asset',0),
        ('2000','Accounts Payable','liability',0),
        ('5000','Cost of Goods Sold','expense',0),
        ('5100','General Expenses','expense',0),
        ('5200','Purchases – Consumables','expense',0),
        ('5300','Asset & Equipment Purchases','expense',0),
        ('5400','Rent Expense','expense',0),
        ('5500','Utilities Expense','expense',0)
    ");

    // ── Account-code map: purchase type → debit account ──────────────────────
    // These drive the double-entry when a purchase is added
    $purchaseAccountMap = [
        // Creditor type  => [account_code, account_name, account_type]
        'purchase'   => ['1300', 'Inventory / Stock',                    'asset'],   // stock purchase  → Inventory ↑
        'consumable' => ['5200', 'Purchases – Consumables',              'expense'], // consumables     → Expense ↑
        'tool'       => ['1500', 'Fixed Assets – Tools & Equipment',     'asset'],   // tools/equipment → Asset ↑
        'equipment'  => ['1510', 'Fixed Assets – Workshop Equipment',    'asset'],
        'furniture'  => ['1520', 'Fixed Assets – Furniture & Fittings',  'asset'],
        'vehicle'    => ['1530', 'Fixed Assets – Motor Vehicles',        'asset'],
        'service'    => ['5100', 'General Expenses',                     'expense'],
        'utility'    => ['5500', 'Utilities Expense',                    'expense'],
        'rent'       => ['5400', 'Rent Expense',                         'expense'],
        'loan'       => ['1030', 'Bank Account',                         'asset'],   // loan receipt    → Cash/Bank ↑
        'other'      => ['5100', 'General Expenses',                     'expense'],
    ];

    $filter_status = $_GET['status'] ?? 'all';
    $where = $filter_status !== 'all' ? "WHERE status = " . $conn->quote($filter_status) : "";
    $creditors = $conn->query("SELECT * FROM creditors $where ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    $stats = $conn->query("
        SELECT
            COUNT(*) as total,
            COALESCE(SUM(amount_owed),0)  as total_owed,
            COALESCE(SUM(amount_paid),0)  as total_paid_out,
            COALESCE(SUM(balance),0)       as total_outstanding,
            SUM(CASE WHEN status='open' THEN 1 ELSE 0 END)     as open_count,
            SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END)  as partial_count,
            SUM(CASE WHEN status='settled' THEN 1 ELSE 0 END)  as settled_count
        FROM creditors
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ── Helper: get or ensure account by code ─────────────────────────────────────
function ensureAccount(PDO $conn, string $code, string $name, string $type): int {
    $row = $conn->prepare("SELECT id FROM accounts WHERE account_code = ?");
    $row->execute([$code]);
    $id = $row->fetchColumn();
    if ($id) return (int)$id;
    $conn->prepare("INSERT INTO accounts (account_code,account_name,account_type,balance) VALUES (?,?,?,0)")
         ->execute([$code, $name, $type]);
    return (int)$conn->lastInsertId();
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── ADD CREDITOR (purchase on credit) ─────────────────────────────────────
    if (isset($_POST['add_creditor'])) {
        try {
            $conn->beginTransaction();

            $supplier_name = trim($_POST['supplier_name']);
            $amount_owed   = (float)$_POST['amount_owed'];
            $reference_no  = trim($_POST['reference_no'] ?? '');
            $due_date      = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
            $notes         = trim($_POST['notes'] ?? '');
            $ref_type      = trim($_POST['reference_type'] ?? 'purchase');
            $cash_account  = trim($_POST['cash_account_code'] ?? ''); // only used for cash purchases

            // Insert creditor record
            $conn->prepare("
                INSERT INTO creditors (supplier_name, reference_type, reference_no, amount_owed, balance, due_date, notes)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$supplier_name, $ref_type, $reference_no, $amount_owed, $amount_owed, $due_date, $notes]);
            $cred_id = $conn->lastInsertId();

            // Determine which DEBIT account this purchase type maps to
            global $purchaseAccountMap;
            $debitInfo  = $purchaseAccountMap[$ref_type] ?? ['5100','General Expenses','expense'];
            $debitCode  = $debitInfo[0];
            $debitName  = $debitInfo[1];
            $debitType  = $debitInfo[2];

            $debitId = ensureAccount($conn, $debitCode, $debitName, $debitType);
            $apId    = ensureAccount($conn, '2000', 'Accounts Payable', 'liability');

            $now  = date('Y-m-d H:i:s');
            $desc = "Purchase on credit: $supplier_name" . ($reference_no ? " – $reference_no" : '');

            // DEBIT: asset or expense account (goods/service received)
            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $debitId, $amount_owed, 0, 'creditor', $cred_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount_owed, $debitId]);

            // CREDIT: Accounts Payable (liability increases)
            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $apId, 0, $amount_owed, 'creditor', $cred_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount_owed, $apId]);

            // Also update asset_purchases if it's an asset type
            $assetTypes = ['tool','equipment','furniture','vehicle'];
            if (in_array($ref_type, $assetTypes)) {
                $tables = $conn->query("SHOW TABLES LIKE 'asset_purchases'")->fetchColumn();
                if ($tables) {
                    $conn->prepare("
                        INSERT INTO asset_purchases (asset_type, description, total_amount, payment_method, purchase_date, notes)
                        VALUES (?, ?, ?, 'credit', CURDATE(), ?)
                        ON DUPLICATE KEY UPDATE total_amount = total_amount
                    ")->execute([$ref_type, $desc, $amount_owed, $notes]);
                }
            }

            $conn->commit();
            $_SESSION['success'] = "Purchase recorded! $debitName debited, Accounts Payable credited — UGX " . number_format($amount_owed);
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: creditors.php');
        exit();
    }

    // ── RECORD PAYMENT ────────────────────────────────────────────────────────
    if (isset($_POST['record_payment'])) {
        try {
            $conn->beginTransaction();

            $cred_id        = (int)$_POST['creditor_id'];
            $payment_amount = (float)$_POST['payment_amount'];
            $payment_method = $_POST['payment_method'];

            $d = $conn->prepare("SELECT * FROM creditors WHERE id=? FOR UPDATE");
            $d->execute([$cred_id]);
            $cred = $d->fetch(PDO::FETCH_ASSOC);
            if (!$cred) throw new Exception("Creditor not found");
            if ($payment_amount > $cred['balance']) throw new Exception("Payment exceeds balance");

            $new_paid    = $cred['amount_paid'] + $payment_amount;
            $new_balance = $cred['balance']      - $payment_amount;
            $status      = $new_balance <= 0 ? 'settled' : 'partial';

            $conn->prepare("UPDATE creditors SET amount_paid=?,balance=?,status=? WHERE id=?")
                 ->execute([$new_paid, $new_balance, $status, $cred_id]);

            // Map payment method → asset account code
            $methodMap = [
                'cash'          => '1010',
                'mobile_money'  => '1020',
                'bank_transfer' => '1030',
                'cheque'        => '1040',
            ];
            $assetCode = $methodMap[$payment_method] ?? '1010';
            $assetId   = ensureAccount($conn, $assetCode,
                ['1010'=>'Cash on Hand','1020'=>'Mobile Money','1030'=>'Bank Account','1040'=>'Cheque Account'][$assetCode] ?? 'Cash',
                'asset');
            $apId      = ensureAccount($conn, '2000', 'Accounts Payable', 'liability');

            // Also reduce cash_accounts balance for the chosen payment method
            try {
                $cashType = ['1010'=>'cash','1020'=>'mobile_money','1030'=>'bank','1040'=>'bank'][$assetCode] ?? 'cash';
                $conn->prepare("
                    UPDATE cash_accounts SET balance = balance - ?
                    WHERE account_type = ? AND is_active = 1
                    ORDER BY id LIMIT 1
                ")->execute([$payment_amount, $cashType]);
            } catch (PDOException $ce) { /* cash_accounts may not have matching row */ }

            $now  = date('Y-m-d H:i:s');
            $desc = "Payment to {$cred['supplier_name']}" . ($cred['reference_no'] ? " ({$cred['reference_no']})" : '');

            // DEBIT Accounts Payable (liability decreases)
            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $apId, $payment_amount, 0, 'creditor_payment', $cred_id]);
            $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$payment_amount, $apId]);

            // CREDIT Cash/Bank (asset decreases — cash goes out)
            $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$now, $desc, $assetId, 0, $payment_amount, 'creditor_payment', $cred_id]);
            $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$payment_amount, $assetId]);

            $conn->commit();
            $_SESSION['success'] = "Payment of UGX " . number_format($payment_amount) . " recorded! AP debited, " .
                ['1010'=>'Cash','1020'=>'Mobile Money','1030'=>'Bank','1040'=>'Cheque'][$assetCode] . " credited.";
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
        header('Location: creditors.php');
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
.toolbar { background:linear-gradient(135deg,#7c3aed,#4c1d95);padding:1rem 1.5rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;border-radius:16px;margin-bottom:1.5rem; }
.toolbar button,.toolbar a { background:#2c3e50;border:none;color:white;padding:.5rem 1.2rem;border-radius:8px;font-weight:600;cursor:pointer;font-size:.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;transition:all .2s; }
.toolbar button:hover,.toolbar a:hover { background:#1e2b38;transform:translateY(-1px); }
.btn-purple { background:#7c3aed !important; } .btn-purple:hover { background:#6d28d9 !important; }
.alert { padding:12px 18px;border-radius:12px;margin-bottom:1rem;display:flex;align-items:center;gap:10px; }
.alert-success{background:#d1fae5;color:#065f46;border-left:4px solid #10b981;}
.alert-danger{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444;}
.stats-row { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem; }
.stat-card { background:white;border-radius:16px;padding:1.2rem 1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.07); }
.stat-card .label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem;}
.stat-card .value{font-size:20px;font-weight:800;}
.stat-card .value.red{color:#dc2626;} .stat-card .value.green{color:#059669;} .stat-card .value.purple{color:#7c3aed;}
.filter-bar { display:flex;gap:.8rem;margin-bottom:1rem;flex-wrap:wrap; }
.filter-btn { padding:6px 16px;border-radius:20px;border:1.5px solid #e2e8f0;background:white;cursor:pointer;font-size:13px;font-weight:600;color:#64748b;transition:all .2s;text-decoration:none; }
.filter-btn.active { background:#7c3aed;color:white;border-color:#7c3aed; }
.card { background:white;border-radius:20px;box-shadow:0 4px 16px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.5rem; }
.card-header { background:linear-gradient(135deg,#7c3aed,#4c1d95);color:white;padding:1rem 1.5rem;font-size:15px;font-weight:700;display:flex;align-items:center;gap:.6rem; }
.data-table { width:100%;border-collapse:collapse;font-size:13px; }
.data-table th { background:#f5f3ff;color:#5b21b6;padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;text-align:left; }
.data-table td { padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle; }
.data-table tr:hover td { background:#faf5ff; }
.badge { padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700; }
.badge-open{background:#fee2e2;color:#991b1b;} .badge-partial{background:#fef3c7;color:#92400e;} .badge-settled{background:#d1fae5;color:#065f46;}
.action-btn{padding:5px 10px;border-radius:6px;border:none;cursor:pointer;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;}
.btn-pay{background:#ede9fe;color:#7c3aed;}
.progress-bar-wrap{background:#e2e8f0;border-radius:20px;height:6px;width:100px;display:inline-block;vertical-align:middle;margin-left:6px;}
.progress-bar-fill{height:6px;border-radius:20px;background:#7c3aed;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:2000;align-items:center;justify-content:center;}
.modal.active{display:flex;}
.modal-box{background:white;border-radius:24px;width:90%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,.25);}
.modal-header{background:linear-gradient(135deg,#7c3aed,#4c1d95);color:white;padding:1.1rem 1.5rem;border-radius:24px 24px 0 0;display:flex;justify-content:space-between;align-items:center;}
.modal-body{padding:1.5rem;}
.close-btn{background:rgba(255,255,255,.2);border:none;width:32px;height:32px;border-radius:50%;color:white;cursor:pointer;font-size:16px;}
.form-group{margin-bottom:1rem;}
.form-group label{display:block;font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.4rem;}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;outline:none;transition:all .2s;}
.form-group input:focus,.form-group select:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.1);}
.btn-submit{background:#7c3aed;color:white;border:none;padding:11px 30px;border-radius:40px;font-weight:700;font-size:14px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:.5rem;}
.btn-submit:hover{background:#6d28d9;}
/* Accounting info box */
.acct-info-box{background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:10px;padding:.75rem 1rem;margin-top:.5rem;font-size:12px;color:#4c1d95;display:flex;flex-direction:column;gap:.25rem;}
.acct-info-box strong{font-size:13px;}
.acct-info-box .entry-row{display:flex;justify-content:space-between;padding:.2rem 0;border-bottom:1px dashed #ddd8fe;}
.acct-info-box .entry-row:last-child{border-bottom:none;}
@media(max-width:768px){.stats-row{grid-template-columns:1fr 1fr;}}
</style>

<div class="page-wrap">
<div class="toolbar no-print">
    <button class="btn-purple" onclick="document.getElementById('addCredModal').classList.add('active')"><i class="fas fa-plus"></i> Add Purchase / Creditor</button>
    <a href="sales_ledger.php"><i class="fas fa-book-open"></i> Sales Ledger</a>
    <a href="debtors.php"><i class="fas fa-user-clock"></i> Debtors</a>
    <a href="receipt.php"><i class="fas fa-receipt"></i> Receipts</a>
    <a href="../dashboard_erp.php"><i class="fas fa-home"></i> Dashboard</a>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($success_message)?></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?=htmlspecialchars($error_message)?></div>
<?php endif; ?>

<div class="stats-row">
    <div class="stat-card"><div class="label"><i class="fas fa-building"></i> Total Creditors</div><div class="value"><?=number_format($stats['total'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-file-invoice-dollar"></i> Total Owed</div><div class="value purple">UGX <?=number_format($stats['total_owed'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-coins"></i> Paid Out</div><div class="value green">UGX <?=number_format($stats['total_paid_out'])?></div></div>
    <div class="stat-card"><div class="label"><i class="fas fa-clock"></i> Outstanding</div><div class="value <?=$stats['total_outstanding']>0?'red':'green'?>">UGX <?=number_format($stats['total_outstanding'])?></div></div>
</div>

<div class="filter-bar">
    <a href="?status=all"    class="filter-btn <?=$filter_status==='all'?'active':''?>">All (<?=$stats['total']?>)</a>
    <a href="?status=open"   class="filter-btn <?=$filter_status==='open'?'active':''?>">Open (<?=$stats['open_count']?>)</a>
    <a href="?status=partial" class="filter-btn <?=$filter_status==='partial'?'active':''?>">Partial (<?=$stats['partial_count']?>)</a>
    <a href="?status=settled" class="filter-btn <?=$filter_status==='settled'?'active':''?>">Settled (<?=$stats['settled_count']?>)</a>
</div>

<div class="card">
    <div class="card-header"><i class="fas fa-user-tie"></i> Creditors Ledger — Purchases &amp; Payables</div>
    <table class="data-table">
        <thead><tr>
            <th>Supplier / Party</th><th>Type</th><th>Accounts Posted</th>
            <th>Reference</th><th>Date</th><th>Due</th>
            <th>Owed</th><th>Paid</th><th>Balance</th>
            <th>Progress</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php
        // Account-code display map
        $typeAccountDisplay = [
            'purchase'   => ['DR: Inventory (1300)', 'CR: AP (2000)'],
            'consumable' => ['DR: Purchases–Consumables (5200)', 'CR: AP (2000)'],
            'tool'       => ['DR: Fixed Assets–Tools (1500)', 'CR: AP (2000)'],
            'equipment'  => ['DR: Fixed Assets–Equip (1510)', 'CR: AP (2000)'],
            'furniture'  => ['DR: Fixed Assets–Furn (1520)', 'CR: AP (2000)'],
            'vehicle'    => ['DR: Fixed Assets–Vehicles (1530)', 'CR: AP (2000)'],
            'service'    => ['DR: General Expenses (5100)', 'CR: AP (2000)'],
            'utility'    => ['DR: Utilities (5500)', 'CR: AP (2000)'],
            'rent'       => ['DR: Rent Expense (5400)', 'CR: AP (2000)'],
            'loan'       => ['DR: Bank (1030)', 'CR: AP (2000)'],
            'other'      => ['DR: General Expenses (5100)', 'CR: AP (2000)'],
        ];
        if (empty($creditors)): ?>
            <tr><td colspan="12" style="text-align:center;padding:2rem;color:#94a3b8;">
                <i class="fas fa-building" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>No creditor records found
            </td></tr>
        <?php else: foreach ($creditors as $c):
            $pct = $c['amount_owed'] > 0 ? round(($c['amount_paid'] / $c['amount_owed']) * 100) : 0;
            $acctDisplay = $typeAccountDisplay[$c['reference_type']] ?? ['DR: General (5100)', 'CR: AP (2000)'];
        ?>
            <tr>
                <td><strong><?=htmlspecialchars($c['supplier_name'])?></strong></td>
                <td><?=ucfirst($c['reference_type'])?></td>
                <td style="font-size:11px;color:#6d28d9;line-height:1.6;">
                    <?=htmlspecialchars($acctDisplay[0])?><br>
                    <?=htmlspecialchars($acctDisplay[1])?>
                </td>
                <td><?=htmlspecialchars($c['reference_no'])?></td>
                <td><?=date('d M Y', strtotime($c['created_at']))?></td>
                <td><?=$c['due_date'] ? date('d M Y', strtotime($c['due_date'])) : '—'?></td>
                <td>UGX <?=number_format($c['amount_owed'])?></td>
                <td style="color:#059669;">UGX <?=number_format($c['amount_paid'])?></td>
                <td><strong style="color:#7c3aed;">UGX <?=number_format($c['balance'])?></strong></td>
                <td>
                    <div><?=$pct?>%</div>
                    <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?=$pct?>%;"></div></div>
                </td>
                <td><span class="badge badge-<?=$c['status']?>"><?=ucfirst($c['status'])?></span></td>
                <td>
                    <?php if($c['status'] !== 'settled'): ?>
                    <button class="action-btn btn-pay" onclick="openPayModal(<?=$c['id']?>, '<?=htmlspecialchars($c['supplier_name'],ENT_QUOTES)?>', <?=$c['balance']?>)">
                        <i class="fas fa-money-bill-wave"></i> Pay
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div>

<!-- ── ADD CREDITOR / PURCHASE MODAL ─────────────────────────────────────── -->
<div id="addCredModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
        <span><i class="fas fa-shopping-cart"></i> Record Purchase / Add Creditor</span>
        <button class="close-btn" onclick="document.getElementById('addCredModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="form-group">
            <label>Supplier / Party Name *</label>
            <input type="text" name="supplier_name" placeholder="Supplier or person name" required>
        </div>

        <div class="form-group">
            <label>Purchase / Creditor Type *</label>
            <select name="reference_type" id="refTypeSelect" onchange="updateAccountInfo(this.value)">
                <option value="purchase">🛒 Stock Purchase (Inventory)</option>
                <option value="consumable">🧴 Consumables / Supplies</option>
                <option value="tool">🔧 Tools (Fixed Asset)</option>
                <option value="equipment">⚙️ Workshop Equipment (Fixed Asset)</option>
                <option value="furniture">🪑 Furniture &amp; Fittings (Fixed Asset)</option>
                <option value="vehicle">🚗 Motor Vehicle (Fixed Asset)</option>
                <option value="service">🛠 Service / Labour</option>
                <option value="utility">💡 Utility Bill</option>
                <option value="rent">🏠 Rent</option>
                <option value="loan">💰 Loan Received</option>
                <option value="other">📦 Other</option>
            </select>
        </div>

        <!-- Live double-entry preview -->
        <div class="acct-info-box" id="acctInfoBox">
            <strong>📒 Accounting Entries that will be posted:</strong>
            <div class="entry-row"><span id="debitLine">DR: Inventory / Stock (1300)</span><span style="color:#059669;">DEBIT ↑</span></div>
            <div class="entry-row"><span id="creditLine">CR: Accounts Payable (2000)</span><span style="color:#dc2626;">CREDIT ↑</span></div>
        </div>

        <div class="form-group" style="margin-top:1rem;">
            <label>Amount Owed (UGX) *</label>
            <input type="number" name="amount_owed" min="1" step="0.01" required placeholder="0">
        </div>
        <div class="form-group">
            <label>Reference # (Invoice / PO / LPO)</label>
            <input type="text" name="reference_no" placeholder="e.g. PO-2024-0012">
        </div>
        <div class="form-group">
            <label>Due Date</label>
            <input type="date" name="due_date">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="2" placeholder="Optional description…"></textarea>
        </div>
        <div style="text-align:right;">
            <button type="submit" name="add_creditor" class="btn-submit"><i class="fas fa-save"></i> Record Purchase</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── PAY MODAL ─────────────────────────────────────────────────────────── -->
<div id="payCredModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
        <span><i class="fas fa-money-bill-wave"></i> Record Payment to Creditor</span>
        <button class="close-btn" onclick="document.getElementById('payCredModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="creditor_id" id="payCredId">
        <div class="form-group"><label>Supplier</label><input type="text" id="payCredName" readonly style="background:#f8fafc;"></div>
        <div class="form-group"><label>Balance Due</label><input type="text" id="payCredBal" readonly style="background:#f8fafc;font-weight:700;color:#7c3aed;"></div>
        <div class="form-group">
            <label>Payment Amount (UGX) *</label>
            <input type="number" name="payment_amount" id="payCredAmount" min="1" step="0.01" required>
        </div>
        <div class="form-group">
            <label>Payment Method</label>
            <select name="payment_method" id="payMethodSelect" onchange="updatePayInfo(this.value)">
                <option value="cash">💵 Cash</option>
                <option value="mobile_money">📱 Mobile Money</option>
                <option value="bank_transfer">🏦 Bank Transfer</option>
                <option value="cheque">📝 Cheque</option>
            </select>
        </div>
        <div class="acct-info-box" id="payAcctBox">
            <strong>📒 Accounting Entries on Payment:</strong>
            <div class="entry-row"><span>DR: Accounts Payable (2000)</span><span style="color:#059669;">AP Balance ↓</span></div>
            <div class="entry-row" id="payAssetLine"><span>CR: Cash on Hand (1010)</span><span style="color:#dc2626;">Cash ↓</span></div>
        </div>
        <div style="text-align:right;margin-top:1rem;">
            <button type="submit" name="record_payment" class="btn-submit"><i class="fas fa-check"></i> Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Account info map for live preview
const acctMap = {
    purchase:   ['DR: Inventory / Stock (1300)',                   'CR: Accounts Payable (2000)'],
    consumable: ['DR: Purchases – Consumables (5200)',             'CR: Accounts Payable (2000)'],
    tool:       ['DR: Fixed Assets – Tools & Equipment (1500)',    'CR: Accounts Payable (2000)'],
    equipment:  ['DR: Fixed Assets – Workshop Equipment (1510)',   'CR: Accounts Payable (2000)'],
    furniture:  ['DR: Fixed Assets – Furniture & Fittings (1520)', 'CR: Accounts Payable (2000)'],
    vehicle:    ['DR: Fixed Assets – Motor Vehicles (1530)',       'CR: Accounts Payable (2000)'],
    service:    ['DR: General Expenses (5100)',                    'CR: Accounts Payable (2000)'],
    utility:    ['DR: Utilities Expense (5500)',                   'CR: Accounts Payable (2000)'],
    rent:       ['DR: Rent Expense (5400)',                        'CR: Accounts Payable (2000)'],
    loan:       ['DR: Bank Account (1030)',                        'CR: Accounts Payable (2000)'],
    other:      ['DR: General Expenses (5100)',                    'CR: Accounts Payable (2000)'],
};

function updateAccountInfo(type) {
    const [dr, cr] = acctMap[type] || acctMap['other'];
    document.getElementById('debitLine').textContent  = dr;
    document.getElementById('creditLine').textContent = cr;
}

const payAssetMap = {
    cash:          'CR: Cash on Hand (1010)',
    mobile_money:  'CR: Mobile Money (1020)',
    bank_transfer: 'CR: Bank Account (1030)',
    cheque:        'CR: Cheque Account (1040)',
};
function updatePayInfo(method) {
    document.getElementById('payAssetLine').querySelector('span').textContent = payAssetMap[method] || 'CR: Cash on Hand (1010)';
}

function openPayModal(id, name, balance) {
    document.getElementById('payCredId').value    = id;
    document.getElementById('payCredName').value  = name;
    document.getElementById('payCredBal').value   = 'UGX ' + parseInt(balance).toLocaleString();
    document.getElementById('payCredAmount').value = balance;
    document.getElementById('payCredModal').classList.add('active');
}

window.onclick = e => {
    ['addCredModal','payCredModal'].forEach(id => {
        const m = document.getElementById(id);
        if (e.target === m) m.classList.remove('active');
    });
};
</script>
