<?php
// views/tools/purchase_assets.php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$user_id        = $_SESSION['user_id']   ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
date_default_timezone_set('Africa/Kampala');

$assetAccountMap = [
    'tool'      => ['code' => '1500', 'name' => 'Workshop Tools & Equipment'],
    'equipment' => ['code' => '1510', 'name' => 'Workshop Equipment'],
    'furniture' => ['code' => '1520', 'name' => 'Furniture & Fittings'],
    'vehicle'   => ['code' => '1530', 'name' => 'Motor Vehicles'],
    'other'     => ['code' => '1540', 'name' => 'Other Workshop Assets'],
];

$paymentAccountMap = [
    'cash'         => ['code' => '1010', 'name' => 'Cash on Hand'],
    'mobile_money' => ['code' => '1020', 'name' => 'Mobile Money'],
    'bank'         => ['code' => '1030', 'name' => 'Bank Account'],
    'cheque'       => ['code' => '1040', 'name' => 'Cheque Account'],
    'credit'       => ['code' => '2000', 'name' => 'Accounts Payable'],
];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn->exec("
        CREATE TABLE IF NOT EXISTS asset_purchases (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            purchase_number  VARCHAR(50) UNIQUE,
            asset_type       VARCHAR(30) NOT NULL DEFAULT 'tool',
            asset_name       VARCHAR(150) NOT NULL,
            tool_id          INT DEFAULT NULL,
            supplier_name    VARCHAR(150),
            quantity         INT NOT NULL DEFAULT 1,
            unit_price       DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_amount     DECIMAL(15,2) NOT NULL DEFAULT 0,
            payment_method   VARCHAR(30) NOT NULL DEFAULT 'cash',
            purchase_date    DATE NOT NULL,
            reference_no     VARCHAR(80),
            notes            TEXT,
            account_code     VARCHAR(20),
            created_by       INT,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $toolCols = $conn->query("SHOW COLUMNS FROM tools")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quantity',       $toolCols)) $conn->exec("ALTER TABLE tools ADD COLUMN quantity INT NOT NULL DEFAULT 1");
    if (!in_array('purchase_price', $toolCols)) $conn->exec("ALTER TABLE tools ADD COLUMN purchase_price DECIMAL(15,2) DEFAULT 0");

    $conn->exec("INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1500','Workshop Tools & Equipment','asset',0),
        ('1510','Workshop Equipment','asset',0),
        ('1520','Furniture & Fittings','asset',0),
        ('1530','Motor Vehicles','asset',0),
        ('1540','Other Workshop Assets','asset',0),
        ('2000','Accounts Payable','liability',0),
        ('5300','Asset & Equipment Expense','expense',0),
        ('1010','Cash on Hand','asset',0),
        ('1020','Mobile Money','asset',0),
        ('1030','Bank Account','asset',0),
        ('1040','Cheque Account','asset',0)
    ");

    $toolsList = $conn->query("
        SELECT id, tool_code, tool_name, category, purchase_price, quantity
        FROM tools WHERE is_active=1 OR is_active IS NULL ORDER BY tool_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Pre-select tool if coming from "Buy More" button
    $preselect_tool_id = isset($_GET['tool_id']) ? (int)$_GET['tool_id'] : 0;

    $cashAccounts = [];
    try {
        $cashAccounts = $conn->query("SELECT id, account_name, account_type, balance FROM cash_accounts WHERE is_active=1 ORDER BY account_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── HANDLE POST ───────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_purchase'])) {
        $conn->beginTransaction();
        try {
            $asset_type     = trim($_POST['asset_type']     ?? 'tool');
            $asset_name     = trim($_POST['asset_name']     ?? '');
            $tool_id        = !empty($_POST['tool_id']) ? (int)$_POST['tool_id'] : null;
            $supplier       = trim($_POST['supplier_name']  ?? '');
            $qty            = max(1, (int)$_POST['quantity']);
            $unit_price     = (float)$_POST['unit_price'];
            $total_amount   = $qty * $unit_price;
            $payment_method = trim($_POST['payment_method'] ?? 'cash');
            $purchase_date  = $_POST['purchase_date'] ?? date('Y-m-d');
            $reference_no   = trim($_POST['reference_no']   ?? '');
            $notes          = trim($_POST['notes']           ?? '');
            $cash_acct_id   = !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;

            if (empty($asset_name)) throw new Exception("Asset name is required.");
            if ($unit_price <= 0)   throw new Exception("Unit price must be greater than zero.");

            $last = $conn->query("SELECT purchase_number FROM asset_purchases ORDER BY id DESC LIMIT 1")->fetchColumn();
            $num  = $last ? (intval(substr($last, -4)) + 1) : 1;
            $purchase_number = 'PAP-' . date('Ymd') . '-' . str_pad($num, 4, '0', STR_PAD_LEFT);

            $assetAcct = $assetAccountMap[$asset_type] ?? $assetAccountMap['other'];

            $conn->prepare("
                INSERT INTO asset_purchases
                    (purchase_number,asset_type,asset_name,tool_id,supplier_name,
                     quantity,unit_price,total_amount,payment_method,purchase_date,
                     reference_no,notes,account_code,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $purchase_number,$asset_type,$asset_name,$tool_id,$supplier,
                $qty,$unit_price,$total_amount,$payment_method,$purchase_date,
                $reference_no,$notes,$assetAcct['code'],$user_id
            ]);
            $purchase_id = $conn->lastInsertId();

            // ── Update tool quantity & price ───────────────────────────────────
            if ($tool_id) {
                $conn->prepare("
                    UPDATE tools
                    SET quantity       = quantity + ?,
                        purchase_price = ?,
                        status         = CASE WHEN (quantity + ?) > 0 THEN 'available' ELSE status END
                    WHERE id = ?
                ")->execute([$qty, $unit_price, $qty, $tool_id]);
            }

            // ── DOUBLE-ENTRY POSTING ──────────────────────────────────────────
            $now  = date('Y-m-d H:i:s');
            $desc = "Asset purchase: {$asset_name}" . ($supplier ? " from {$supplier}" : '') . " [{$purchase_number}]";

            // DR: Fixed Asset account
            $assetId = $conn->query("SELECT id FROM accounts WHERE account_code='{$assetAcct['code']}'")->fetchColumn();
            if ($assetId) {
                $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                     ->execute([$now,$desc,$assetId,$total_amount,0,'asset_purchase',$purchase_id]);
                $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id=?")->execute([$total_amount,$assetId]);
            }

            if ($payment_method === 'credit') {
                // CR: Accounts Payable
                $apId = $conn->query("SELECT id FROM accounts WHERE account_code='2000'")->fetchColumn();
                if ($apId) {
                    $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                         ->execute([$now,$desc,$apId,0,$total_amount,'asset_purchase',$purchase_id]);
                    $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id=?")->execute([$total_amount,$apId]);
                }
                // Add to creditors for AP tracking
                $conn->prepare("INSERT INTO creditors (supplier_name,reference_type,reference_no,amount_owed,balance,due_date,notes) VALUES (?,?,?,?,?,NULL,?)")
                     ->execute([$supplier ?: $asset_name,'asset_purchase',$purchase_number,$total_amount,$total_amount,"Asset purchase: {$asset_name}"]);
            } else {
                // CR: Cash / Bank / Mobile / Cheque
                $payAcct = $paymentAccountMap[$payment_method] ?? $paymentAccountMap['cash'];
                $payId   = $conn->query("SELECT id FROM accounts WHERE account_code='{$payAcct['code']}'")->fetchColumn();
                if ($payId) {
                    $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,?,?)")
                         ->execute([$now,$desc,$payId,0,$total_amount,'asset_purchase',$purchase_id]);
                    $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id=?")->execute([$total_amount,$payId]);
                }
                if ($cash_acct_id) {
                    $conn->prepare("UPDATE cash_accounts SET balance = balance - ? WHERE id=?")->execute([$total_amount,$cash_acct_id]);
                }
            }

            $conn->commit();
            $_SESSION['success'] = "Purchase recorded! {$purchase_number} — {$asset_name} (x{$qty}) for UGX " . number_format($total_amount);
            header('Location: purchase_assets.php'); exit();

        } catch (Exception $e) {
            $conn->rollBack();
            $error_msg = $e->getMessage();
        }
    }

    $history = $conn->query("
        SELECT ap.*, t.tool_name, t.tool_code
        FROM asset_purchases ap
        LEFT JOIN tools t ON ap.tool_id = t.id
        ORDER BY ap.purchase_date DESC, ap.id DESC LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    $summary = $conn->query("
        SELECT COUNT(*) AS total_purchases,
               COALESCE(SUM(total_amount),0) AS total_spent,
               COALESCE(SUM(CASE WHEN payment_method='credit' THEN total_amount ELSE 0 END),0) AS credit_outstanding,
               COALESCE(SUM(CASE WHEN asset_type='tool'      THEN total_amount ELSE 0 END),0) AS tools_spent,
               COALESCE(SUM(CASE WHEN asset_type='equipment' THEN total_amount ELSE 0 END),0) AS equipment_spent
        FROM asset_purchases
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msg    = "DB error: " . $e->getMessage();
    $toolsList    = []; $cashAccounts = []; $history = [];
    $summary      = ['total_purchases'=>0,'total_spent'=>0,'credit_outstanding'=>0,'tools_spent'=>0,'equipment_spent'=>0];
}

$success_msg = $_SESSION['success'] ?? null;
unset($_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Assets | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh;}
        :root{--primary:#1e40af;--primary-light:#3b82f6;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;--border:#e2e8f0;--gray:#64748b;--dark:#0f172a;--bg:#f8fafc;}
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100%;background:linear-gradient(180deg,#e0f2fe,#bae6fd);color:#0c4a6e;z-index:1000;overflow-y:auto;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08);}
        .sidebar-header h2{font-size:1.15rem;font-weight:700;color:#0369a1;}
        .sidebar-header p{font-size:.7rem;opacity:.7;margin-top:.2rem;color:#0284c7;}
        .sidebar-menu{padding:1rem 0;}
        .sidebar-title{padding:.5rem 1.5rem;font-size:.65rem;text-transform:uppercase;letter-spacing:1px;color:#0369a1;font-weight:700;}
        .menu-item{padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;color:#0c4a6e;text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.85rem;font-weight:500;}
        .menu-item:hover,.menu-item.active{background:rgba(14,165,233,.2);color:#0284c7;border-left-color:#0284c7;}
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;border:1px solid var(--border);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem;}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.2rem;}
        .summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem;}
        .sum-card{background:white;border-radius:1rem;padding:1rem;border:1px solid var(--border);text-align:center;}
        .sum-value{font-size:1.1rem;font-weight:800;color:var(--dark);}
        .sum-label{font-size:.65rem;text-transform:uppercase;color:var(--gray);margin-top:.2rem;}
        .layout{display:grid;grid-template-columns:420px 1fr;gap:1.5rem;}
        .form-card,.table-card{background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden;}
        .card-header{padding:1rem 1.25rem;background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .card-header h3{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
        .card-header p{font-size:.72rem;opacity:.9;margin-top:.15rem;}
        .card-body{padding:1.25rem;}
        .form-group{margin-bottom:1rem;}
        .form-group label{display:block;font-size:.68rem;font-weight:700;color:var(--gray);text-transform:uppercase;margin-bottom:.3rem;letter-spacing:.5px;}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:.55rem .8rem;border:1.5px solid var(--border);border-radius:.6rem;font-size:.85rem;font-family:inherit;background:white;transition:border-color .2s;}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.08);}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;}
        .required{color:var(--danger);}
        .total-preview{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:.6rem;padding:.75rem 1rem;margin:.25rem 0 .75rem;font-weight:800;font-size:1.05rem;color:var(--primary);text-align:center;}
        .acct-note{background:#f0fdf4;border-left:3px solid var(--success);border-radius:.5rem;padding:.6rem .9rem;font-size:.75rem;color:#065f46;margin-bottom:1rem;line-height:1.5;}
        .acct-note strong{display:block;margin-bottom:.2rem;font-size:.72rem;}
        .alert{padding:.75rem 1rem;border-radius:.6rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;font-size:.85rem;}
        .alert-success{background:#dcfce7;color:#166534;border-left:3px solid var(--success);}
        .alert-error{background:#fee2e2;color:#991b1b;border-left:3px solid var(--danger);}
        .btn{padding:.6rem 1.2rem;border-radius:.6rem;font-weight:600;font-size:.85rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .2s;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,.3);}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-full{width:100%;justify-content:center;padding:.75rem;}
        .table-header{padding:.85rem 1.25rem;background:var(--bg);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
        .table-header h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
        table{width:100%;border-collapse:collapse;}
        th{background:var(--bg);padding:.75rem 1rem;text-align:left;font-weight:700;font-size:.68rem;color:var(--gray);border-bottom:1px solid var(--border);}
        td{padding:.7rem 1rem;border-bottom:1px solid var(--border);font-size:.83rem;}
        tr:hover td{background:#fafbff;}
        .badge{display:inline-block;padding:.15rem .5rem;border-radius:2rem;font-size:.65rem;font-weight:700;}
        .badge-tool{background:#dbeafe;color:#1e40af;}
        .badge-equipment{background:#e0e7ff;color:#4338ca;}
        .badge-furniture{background:#fef9c3;color:#854d0e;}
        .badge-vehicle{background:#dcfce7;color:#166534;}
        .badge-other{background:#f1f5f9;color:#475569;}
        .badge-cash{background:#dcfce7;color:#166534;}
        .badge-credit{background:#fee2e2;color:#dc2626;}
        .badge-bank{background:#dbeafe;color:#1e40af;}
        .badge-mobile_money{background:#fef3c7;color:#92400e;}
        .badge-cheque{background:#e0e7ff;color:#4338ca;}
        .divider{border:none;border-top:1px dashed var(--border);margin:.75rem 0;}
        @media(max-width:1100px){.layout{grid-template-columns:1fr;}.summary-grid{grid-template-columns:repeat(3,1fr);}}
        @media(max-width:768px){.sidebar{left:-260px;}.main-content{margin-left:0;padding:1rem;}.summary-grid{grid-template-columns:1fr 1fr;}.form-row{grid-template-columns:1fr;}}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h2>🔧 SAVANT MOTORS</h2>
        <p>Asset Purchases</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">TOOLS</div>
        <a href="/savant/views/dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="index.php" class="menu-item">🔧 Tool Management</a>
        <a href="purchase_assets.php" class="menu-item active">🛒 Purchase Assets</a>
        <a href="/savant/views/tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
        <div class="sidebar-title" style="margin-top:1rem;">FINANCE</div>
        <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
        <a href="../accounting/creditors.php" class="menu-item">📤 Creditors</a>
        <a href="../ledger/balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
        <a href="../ledger/trial_balance.php" class="menu-item">⚖️ Trial Balance</a>
        <a href="../ledger/income_statement.php" class="menu-item">📈 Income Statement</a>
        <div style="margin-top:2rem;"><a href="/savant/views/logout.php" class="menu-item">🚪 Logout</a></div>
    </div>
</div>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-shopping-cart"></i> Purchase Assets</h1>
            <p>Buy tools &amp; workshop assets — updates inventory quantity &amp; all accounting ledgers automatically</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="index.php" class="btn btn-secondary"><i class="fas fa-tools"></i> Tool List</a>
            <a href="../ledger/balance_sheet.php" class="btn btn-secondary"><i class="fas fa-chart-pie"></i> Balance Sheet</a>
            <a href="../accounting/creditors.php" class="btn btn-secondary"><i class="fas fa-file-invoice"></i> Creditors</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="summary-grid">
        <div class="sum-card">
            <div class="sum-value"><?php echo number_format($summary['total_purchases']); ?></div>
            <div class="sum-label">Total Purchases</div>
        </div>
        <div class="sum-card">
            <div class="sum-value" style="color:var(--primary);font-size:.95rem;">UGX <?php echo number_format($summary['total_spent']); ?></div>
            <div class="sum-label">Total Spent</div>
        </div>
        <div class="sum-card">
            <div class="sum-value" style="color:#059669;font-size:.95rem;">UGX <?php echo number_format($summary['tools_spent']); ?></div>
            <div class="sum-label">On Tools</div>
        </div>
        <div class="sum-card">
            <div class="sum-value" style="color:#7c3aed;font-size:.95rem;">UGX <?php echo number_format($summary['equipment_spent']); ?></div>
            <div class="sum-label">On Equipment</div>
        </div>
        <div class="sum-card">
            <div class="sum-value" style="color:var(--danger);font-size:.95rem;">UGX <?php echo number_format($summary['credit_outstanding']); ?></div>
            <div class="sum-label">Credit (Unpaid)</div>
        </div>
    </div>

    <div class="layout">

        <!-- FORM -->
        <div class="form-card">
            <div class="card-header">
                <h3><i class="fas fa-plus-circle"></i> Record New Purchase</h3>
                <p>Inventory &amp; double-entry ledgers update automatically on save</p>
            </div>
            <div class="card-body">
                <form method="POST" id="purchaseForm">

                    <div class="form-group">
                        <label>Asset Type <span class="required">*</span></label>
                        <select name="asset_type" id="assetType" required onchange="updateAssetType()">
                            <option value="tool">🔧 Tool</option>
                            <option value="equipment">⚙️ Equipment</option>
                            <option value="furniture">🪑 Furniture &amp; Fittings</option>
                            <option value="vehicle">🚗 Motor Vehicle</option>
                            <option value="other">📦 Other Workshop Asset</option>
                        </select>
                    </div>

                    <div class="form-group" id="toolLinkGroup">
                        <label>Link to Existing Tool <small style="text-transform:none;font-weight:400;">(optional — adds qty to existing record)</small></label>
                        <select name="tool_id" id="toolSelect" onchange="fillFromTool()">
                            <option value="">— New item / not in list —</option>
                            <?php foreach ($toolsList as $t): ?>
                            <option value="<?php echo $t['id']; ?>"
                                data-name="<?php echo htmlspecialchars($t['tool_name']); ?>"
                                data-price="<?php echo $t['purchase_price']; ?>"
                                data-qty="<?php echo $t['quantity']; ?>"
                                <?php echo ($preselect_tool_id === (int)$t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['tool_code'] . ' — ' . $t['tool_name']); ?> (<?php echo $t['quantity']; ?> in stock)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Item / Asset Name <span class="required">*</span></label>
                        <input type="text" name="asset_name" id="assetName" required placeholder="e.g. Torque Wrench Set, Hydraulic Jack">
                    </div>

                    <div class="form-group">
                        <label>Supplier / Vendor</label>
                        <input type="text" name="supplier_name" placeholder="Supplier name (leave blank if unknown)">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Quantity <span class="required">*</span></label>
                            <input type="number" name="quantity" id="qty" min="1" value="1" required oninput="updateTotal()">
                        </div>
                        <div class="form-group">
                            <label>Unit Price (UGX) <span class="required">*</span></label>
                            <input type="number" name="unit_price" id="unitPrice" min="1" step="100" required oninput="updateTotal()" placeholder="0">
                        </div>
                    </div>

                    <div class="total-preview" id="totalPreview">Total: UGX 0</div>

                    <hr class="divider">

                    <div class="form-group">
                        <label>Payment Method <span class="required">*</span></label>
                        <select name="payment_method" id="paymentMethod" required onchange="toggleCashAccount()">
                            <option value="cash">💵 Cash</option>
                            <option value="mobile_money">📱 Mobile Money</option>
                            <option value="bank">🏦 Bank Transfer</option>
                            <option value="cheque">📋 Cheque</option>
                            <option value="credit">📄 Credit / Buy on Account</option>
                        </select>
                    </div>

                    <?php if (!empty($cashAccounts)): ?>
                    <div class="form-group" id="cashAccountGroup">
                        <label>Deduct from Specific Account <small style="text-transform:none;font-weight:400;">(optional)</small></label>
                        <select name="cash_account_id">
                            <option value="">— General / not specified —</option>
                            <?php foreach ($cashAccounts as $ca): ?>
                            <option value="<?php echo $ca['id']; ?>">
                                <?php echo htmlspecialchars($ca['account_name']); ?> — Bal: UGX <?php echo number_format($ca['balance']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Purchase Date <span class="required">*</span></label>
                            <input type="date" name="purchase_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Supplier Invoice / Ref #</label>
                            <input type="text" name="reference_no" placeholder="e.g. INV-2024-001">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any additional details…"></textarea>
                    </div>

                    <div class="acct-note">
                        <strong>📒 Accounting entries that will be posted:</strong>
                        <span id="acctNoteText">DR Workshop Tools &amp; Equipment (1500) &nbsp;/&nbsp; CR Cash on Hand (1010)</span>
                        <span id="acctNoteCredit" style="display:none;color:#991b1b;"><br>+ Tool added to Creditors ledger (Accounts Payable)</span>
                    </div>

                    <button type="submit" name="record_purchase" class="btn btn-primary btn-full">
                        <i class="fas fa-save"></i> Record Purchase &amp; Update Ledgers
                    </button>
                </form>
            </div>
        </div>

        <!-- HISTORY TABLE -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-history"></i> Purchase History</h3>
                <span style="font-size:.78rem;color:var(--gray);"><?php echo count($history); ?> records</span>
            </div>
            <?php if (empty($history)): ?>
            <div style="text-align:center;padding:3rem;color:var(--gray);">
                <i class="fas fa-shopping-cart" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:1rem;"></i>
                <p>No asset purchases recorded yet.</p>
                <p style="font-size:.8rem;margin-top:.5rem;">Use the form to record your first purchase.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Ref #</th>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Linked Tool</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Supplier</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><strong style="font-size:.72rem;color:var(--primary);"><?php echo htmlspecialchars($h['purchase_number']); ?></strong></td>
                    <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($h['purchase_date'])); ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($h['asset_name']); ?></strong>
                        <?php if ($h['notes']): ?>
                        <br><small style="color:var(--gray);"><?php echo htmlspecialchars(substr($h['notes'],0,45)); ?><?php echo strlen($h['notes'])>45?'…':''; ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?php echo $h['asset_type']; ?>"><?php echo ucfirst($h['asset_type']); ?></span></td>
                    <td style="font-size:.76rem;">
                        <?php if ($h['tool_name']): ?>
                        <span style="color:var(--primary);"><?php echo htmlspecialchars($h['tool_code']); ?></span><br>
                        <?php echo htmlspecialchars($h['tool_name']); ?>
                        <?php else: ?>
                        <span style="color:var(--gray);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo number_format($h['quantity']); ?></strong></td>
                    <td>UGX <?php echo number_format($h['unit_price']); ?></td>
                    <td><strong style="color:var(--dark);">UGX <?php echo number_format($h['total_amount']); ?></strong></td>
                    <td><span class="badge badge-<?php echo $h['payment_method']; ?>"><?php echo ucfirst(str_replace('_',' ',$h['payment_method'])); ?></span></td>
                    <td style="font-size:.78rem;"><?php echo htmlspecialchars($h['supplier_name'] ?: '—'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:700;">
                        <td colspan="7" style="text-align:right;padding:.8rem 1rem;">TOTAL SPENT</td>
                        <td style="padding:.8rem 1rem;color:var(--primary);">UGX <?php echo number_format($summary['total_spent']); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    const assetAcctNames = {
        tool:      'Workshop Tools & Equipment (1500)',
        equipment: 'Workshop Equipment (1510)',
        furniture: 'Furniture & Fittings (1520)',
        vehicle:   'Motor Vehicles (1530)',
        other:     'Other Workshop Assets (1540)'
    };
    const payAcctNames = {
        cash:         'Cash on Hand (1010)',
        mobile_money: 'Mobile Money (1020)',
        bank:         'Bank Account (1030)',
        cheque:       'Cheque Account (1040)',
        credit:       'Accounts Payable (2000)'
    };

    function updateTotal() {
        const qty   = parseFloat(document.getElementById('qty').value)       || 0;
        const price = parseFloat(document.getElementById('unitPrice').value) || 0;
        const total = qty * price;
        document.getElementById('totalPreview').textContent = 'Total: UGX ' + total.toLocaleString('en-UG');
        updateAcctNote();
    }

    function updateAssetType() {
        const type = document.getElementById('assetType').value;
        document.getElementById('toolLinkGroup').style.display = type === 'tool' ? '' : 'none';
        if (type !== 'tool') { document.getElementById('toolSelect').value = ''; }
        updateAcctNote();
    }

    function toggleCashAccount() {
        const pm  = document.getElementById('paymentMethod').value;
        const grp = document.getElementById('cashAccountGroup');
        if (grp) grp.style.display = pm === 'credit' ? 'none' : '';
        document.getElementById('acctNoteCredit').style.display = pm === 'credit' ? '' : 'none';
        updateAcctNote();
    }

    function updateAcctNote() {
        const at = document.getElementById('assetType').value;
        const pm = document.getElementById('paymentMethod').value;
        document.getElementById('acctNoteText').innerHTML =
            'DR ' + (assetAcctNames[at]||'Asset Account') +
            ' &nbsp;/&nbsp; CR ' + (payAcctNames[pm]||'Payment Account');
    }

    function fillFromTool() {
        const sel = document.getElementById('toolSelect');
        const opt = sel.options[sel.selectedIndex];
        if (!sel.value) return;
        document.getElementById('assetName').value = opt.dataset.name  || '';
        document.getElementById('unitPrice').value = opt.dataset.price || '';
        updateTotal();
    }

    updateAssetType();
    toggleCashAccount();
    updateAcctNote();

    // Auto-fill if arriving from "Buy More" with a pre-selected tool
    const preselectId = <?php echo $preselect_tool_id ?: 'null'; ?>;
    if (preselectId) {
        const sel = document.getElementById('toolSelect');
        if (sel) {
            sel.value = preselectId;
            fillFromTool();
        }
    }
</script>
</body>
</html>
