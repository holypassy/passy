<?php
// sales_ledger.php - Sales Ledger: affects inventory stock + cash accounts
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
$user_id       = $_SESSION['user_id']    ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role     = $_SESSION['role']       ?? 'user';

date_default_timezone_set('Africa/Kampala');

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── ensure tables ──────────────────────────────────────────────────────
    $conn->exec("
        CREATE TABLE IF NOT EXISTS sales_ledger (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            sale_number     VARCHAR(30) NOT NULL UNIQUE,
            sale_date       DATE NOT NULL,
            customer_id     INT,
            customer_name   VARCHAR(120),
            payment_method  VARCHAR(30) NOT NULL DEFAULT 'cash',
            subtotal        DECIMAL(15,2) DEFAULT 0,
            discount        DECIMAL(15,2) DEFAULT 0,
            total_amount    DECIMAL(15,2) DEFAULT 0,
            amount_paid     DECIMAL(15,2) DEFAULT 0,
            balance_due     DECIMAL(15,2) DEFAULT 0,
            payment_status  ENUM('paid','partial','credit') DEFAULT 'paid',
            notes           TEXT,
            created_by      INT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS sales_ledger_items (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            sale_id         INT NOT NULL,
            inventory_id    INT,
            description     VARCHAR(200) NOT NULL,
            quantity        DECIMAL(10,2) DEFAULT 1,
            unit_price      DECIMAL(15,2) DEFAULT 0,
            total_price     DECIMAL(15,2) DEFAULT 0,
            FOREIGN KEY (sale_id) REFERENCES sales_ledger(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // accounting tables (mirror invoices.php setup)
    $conn->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            account_code VARCHAR(20) NOT NULL UNIQUE,
            account_name VARCHAR(100) NOT NULL,
            account_type ENUM('asset','liability','equity','revenue','expense') DEFAULT 'asset',
            balance      DECIMAL(15,2) DEFAULT 0.00,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $conn->exec("
        CREATE TABLE IF NOT EXISTS account_ledger (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            description      VARCHAR(255),
            account_id       INT NOT NULL,
            debit            DECIMAL(15,2) DEFAULT 0.00,
            credit           DECIMAL(15,2) DEFAULT 0.00,
            reference_type   VARCHAR(50),
            reference_id     INT,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (account_id) REFERENCES accounts(id)
        )
    ");
    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1010', 'Cash on Hand',       'asset',   0),
        ('1020', 'Mobile Money',        'asset',   0),
        ('1030', 'Bank Account',        'asset',   0),
        ('1040', 'Cheque Account',      'asset',   0),
        ('1200', 'Accounts Receivable', 'asset',   0),
        ('4000', 'Sales Revenue',       'revenue', 0),
        ('5000', 'Cost of Goods Sold',  'expense', 0)
    ");

    // detect inventory columns
    $invCols = $conn->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
    $stockCol  = in_array('current_stock', $invCols) ? 'current_stock' : (in_array('quantity', $invCols) ? 'quantity' : null);
    $costCol   = in_array('cost_price', $invCols) ? 'cost_price' : (in_array('unit_cost', $invCols) ? 'unit_cost' : (in_array('price', $invCols) ? 'price' : null));
    $sellCol   = in_array('selling_price', $invCols) ? 'selling_price' : (in_array('sale_price', $invCols) ? 'sale_price' : null);

    // fetch inventory for item picker
    $inventoryItems = [];
    if ($stockCol) {
        $sql = "SELECT id, " . ($invCols[1] ?? 'id') . " as part_number";
        foreach (['item_name','name','product_name','description'] as $nc) {
            if (in_array($nc, $invCols)) { $sql .= ", $nc as item_name"; break; }
        }
        $sql .= ", $stockCol as stock";
        if ($sellCol) $sql .= ", $sellCol as selling_price";
        if ($costCol) $sql .= ", $costCol as cost_price";
        $sql .= " FROM inventory WHERE $stockCol > 0 ORDER BY item_name";
        $inventoryItems = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // fetch services if table exists
    $serviceItems = [];
    try {
        $svcTables = $conn->query("SHOW TABLES LIKE 'services'")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($svcTables)) {
            $svcCols = $conn->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $svcName  = in_array('service_name', $svcCols) ? 'service_name' : (in_array('name', $svcCols) ? 'name' : 'id');
            $svcPrice = in_array('price', $svcCols) ? 'price' : (in_array('rate', $svcCols) ? 'rate' : (in_array('amount', $svcCols) ? 'amount' : null));
            $svcSql   = "SELECT id, $svcName as service_name" . ($svcPrice ? ", $svcPrice as price" : ", 0 as price") . " FROM services ORDER BY $svcName";
            $serviceItems = $conn->query($svcSql)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) { $serviceItems = []; }

    // build unified catalog for JS (inventory + services)
    $catalog = [];
    foreach ($inventoryItems as $it) {
        $catalog[] = [
            'id'    => 'inv_' . $it['id'],
            'inv_id'=> $it['id'],
            'type'  => 'Inventory',
            'name'  => $it['item_name'],
            'stock' => $it['stock'] ?? 0,
            'price' => $it['selling_price'] ?? 0,
        ];
    }
    foreach ($serviceItems as $sv) {
        $catalog[] = [
            'id'    => 'svc_' . $sv['id'],
            'inv_id'=> null,
            'type'  => 'Service',
            'name'  => $sv['service_name'],
            'stock' => null,
            'price' => $sv['price'] ?? 0,
        ];
    }

    // fetch customers
    $customers = $conn->query("SELECT id, full_name, telephone FROM customers WHERE status=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

    // fetch accounts for payment method dropdown
    $assetAccounts = $conn->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='asset' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);

    // fetch sales for listing
    $sales = $conn->query("
        SELECT s.*, c.full_name as cust_name
        FROM sales_ledger s
        LEFT JOIN customers c ON s.customer_id = c.id
        ORDER BY s.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);

    // summary stats
    $stats = $conn->query("
        SELECT
            COUNT(*) as total_sales,
            COALESCE(SUM(total_amount),0) as total_revenue,
            COALESCE(SUM(amount_paid),0)  as total_collected,
            COALESCE(SUM(balance_due),0)  as total_outstanding
        FROM sales_ledger
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ── AJAX: get inventory item details ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_item' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $item = $conn->query("SELECT * FROM inventory WHERE id = " . (int)$_GET['id'])->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'item' => $item]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: get sale details ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_sale' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $sale = $conn->prepare("SELECT s.*, c.full_name, c.telephone FROM sales_ledger s LEFT JOIN customers c ON s.customer_id=c.id WHERE s.id=?");
        $sale->execute([(int)$_GET['id']]);
        $saleData = $sale->fetch(PDO::FETCH_ASSOC);
        $items = $conn->prepare("SELECT sli.*, i.item_name FROM sales_ledger_items sli LEFT JOIN inventory i ON sli.inventory_id=i.id WHERE sli.sale_id=?");
        $items->execute([(int)$_GET['id']]);
        echo json_encode(['success' => true, 'sale' => $saleData, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: create sale ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sale'])) {
    try {
        $conn->beginTransaction();

        // generate sale number
        $last = $conn->query("SELECT sale_number FROM sales_ledger ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $nextNum = $last ? str_pad((int)substr($last['sale_number'], -4) + 1, 4, '0', STR_PAD_LEFT) : '0001';
        $saleNumber = 'SL-' . date('Y') . '-' . $nextNum;

        $customer_id   = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $customer_name = trim($_POST['customer_name'] ?? '');
        $payment_method = $_POST['payment_method'];
        $amount_paid   = (float)$_POST['amount_paid'];
        $discount      = (float)($_POST['discount'] ?? 0);
        $notes         = trim($_POST['notes'] ?? '');

        $descriptions  = $_POST['description']  ?? [];
        $quantities    = $_POST['quantity']      ?? [];
        $unit_prices   = $_POST['unit_price']    ?? [];
        $inventory_ids = $_POST['inventory_id']  ?? [];

        // calculate totals
        $subtotal = 0;
        foreach ($quantities as $i => $qty) {
            $subtotal += (float)$qty * (float)$unit_prices[$i];
        }
        $total_amount = $subtotal - $discount;
        $balance_due  = $total_amount - $amount_paid;
        $payment_status = $balance_due <= 0 ? 'paid' : ($amount_paid > 0 ? 'partial' : 'credit');

        // insert sale header
        $stmt = $conn->prepare("
            INSERT INTO sales_ledger (sale_number, sale_date, customer_id, customer_name, payment_method,
                subtotal, discount, total_amount, amount_paid, balance_due, payment_status, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $saleNumber, date('Y-m-d'), $customer_id, $customer_name, $payment_method,
            $subtotal, $discount, $total_amount, $amount_paid, $balance_due, $payment_status, $notes, $user_id
        ]);
        $sale_id = $conn->lastInsertId();

        // insert items + deduct stock
        $itemStmt = $conn->prepare("INSERT INTO sales_ledger_items (sale_id, inventory_id, description, quantity, unit_price, total_price) VALUES (?,?,?,?,?,?)");
        foreach ($descriptions as $i => $desc) {
            if (empty(trim($desc))) continue;
            $qty   = (float)$quantities[$i];
            $price = (float)$unit_prices[$i];
            $inv_id = !empty($inventory_ids[$i]) ? (int)$inventory_ids[$i] : null;
            $itemStmt->execute([$sale_id, $inv_id, $desc, $qty, $price, $qty * $price]);

            // deduct inventory stock
            if ($inv_id && $stockCol) {
                $conn->prepare("UPDATE inventory SET $stockCol = $stockCol - ? WHERE id = ? AND $stockCol >= ?")->execute([$qty, $inv_id, $qty]);
            }
        }

        // ── accounting double-entry ──
        $now = date('Y-m-d H:i:s');
        $desc_acc = "Sale $saleNumber – " . ($customer_name ?: 'Walk-in');

        // map payment method to account code
        $methodMap = ['cash' => '1010', 'mobile_money' => '1020', 'bank_transfer' => '1030', 'cheque' => '1040'];
        $assetCode = $methodMap[$payment_method] ?? '1010';

        $getAcct = fn($code) => $conn->query("SELECT id FROM accounts WHERE account_code='$code'")->fetchColumn();

        $assetAcctId   = $getAcct($assetCode);
        $arAcctId      = $getAcct('1200');
        $revenueAcctId = $getAcct('4000');

        $ledger = $conn->prepare("INSERT INTO account_ledger (transaction_date, description, account_id, debit, credit, reference_type, reference_id) VALUES (?,?,?,?,?,?,?)");

        if ($amount_paid > 0) {
            // Cash received: Debit asset account
            $ledger->execute([$now, $desc_acc, $assetAcctId, $amount_paid, 0, 'sale', $sale_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount_paid, $assetAcctId]);
        }
        if ($balance_due > 0) {
            // Credit sale: Debit Accounts Receivable
            $ledger->execute([$now, $desc_acc . ' (Credit)', $arAcctId, $balance_due, 0, 'sale', $sale_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$balance_due, $arAcctId]);
        }
        // Credit Sales Revenue for full amount
        $ledger->execute([$now, $desc_acc, $revenueAcctId, 0, $total_amount, 'sale', $sale_id]);
        $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$total_amount, $revenueAcctId]);

        // Also add to debtors table if credit
        if ($balance_due > 0 && ($customer_id || $customer_name)) {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS debtors (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    customer_id    INT,
                    customer_name  VARCHAR(120),
                    reference_type VARCHAR(30) DEFAULT 'sale',
                    reference_id   INT,
                    reference_no   VARCHAR(50),
                    amount_owed    DECIMAL(15,2) DEFAULT 0,
                    amount_paid    DECIMAL(15,2) DEFAULT 0,
                    balance        DECIMAL(15,2) DEFAULT 0,
                    due_date       DATE,
                    status         ENUM('open','partial','settled') DEFAULT 'open',
                    notes          TEXT,
                    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $conn->prepare("INSERT INTO debtors (customer_id, customer_name, reference_type, reference_id, reference_no, amount_owed, balance, status) VALUES (?,?,?,?,?,?,?,'open')")
                 ->execute([$customer_id, $customer_name, 'sale', $sale_id, $saleNumber, $balance_due, $balance_due]);
        }

        $conn->commit();
        $_SESSION['success'] = "Sale $saleNumber recorded successfully!";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header('Location: sales_ledger.php');
    exit();
}

$success_message = $_SESSION['success'] ?? null;
$error_message   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);


?>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(135deg,#e6f0ff,#cce4ff); padding:2rem; font-size:14px; }

.page-wrap { max-width:1300px; margin:0 auto; }

.toolbar { background:linear-gradient(135deg,#2563eb,#1e3a8a); padding:1rem 1.5rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap; border-radius:16px; margin-bottom:1.5rem; }
.toolbar button, .toolbar a { background:#2c3e50; border:none; color:white; padding:.5rem 1.2rem; border-radius:8px; font-weight:600; cursor:pointer; font-size:.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:.5rem; transition:all .2s; }
.toolbar button:hover, .toolbar a:hover { background:#1e2b38; transform:translateY(-1px); }
.btn-green { background:#059669 !important; }
.btn-green:hover { background:#047857 !important; }

.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:white; border-radius:16px; padding:1.2rem 1.5rem; box-shadow:0 2px 8px rgba(0,0,0,.07); }
.stat-card .label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.3rem; }
.stat-card .value { font-size:22px; font-weight:800; color:#1e293b; }
.stat-card .value.green { color:#059669; }
.stat-card .value.orange { color:#d97706; }
.stat-card .value.red { color:#dc2626; }

.alert { padding:12px 18px; border-radius:12px; margin-bottom:1rem; display:flex; align-items:center; gap:10px; }
.alert-success { background:#d1fae5; color:#065f46; border-left:4px solid #10b981; }
.alert-danger  { background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; }

.card { background:white; border-radius:20px; box-shadow:0 4px 16px rgba(0,0,0,.08); overflow:hidden; margin-bottom:1.5rem; }
.card-header { background:linear-gradient(135deg,#2563eb,#1e3a8a); color:white; padding:1rem 1.5rem; font-size:15px; font-weight:700; display:flex; align-items:center; gap:.6rem; }
.card-body { padding:1.5rem; }

/* Form */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.form-grid.three { grid-template-columns:1fr 1fr 1fr; }
.form-group { margin-bottom:1rem; }
.form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.4rem; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:9px 13px; border:1.5px solid #e2e8f0; border-radius:10px;
    font-size:13px; font-family:inherit; outline:none; transition:all .2s; background:white;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1);
}

/* Items table */
.items-table { width:100%; border-collapse:collapse; margin-bottom:1rem; }
.items-table th { background:#3b82f6; color:white; padding:9px 10px; font-size:12px; text-align:left; }
.items-table td { border:1px solid #e2e8f0; padding:6px 8px; vertical-align:middle; }
.items-table input, .items-table select { width:100%; border:none; background:transparent; font-family:inherit; font-size:13px; outline:none; padding:3px; }
.items-table input:focus, .items-table select:focus { background:#eff6ff; border-radius:4px; }
.btn-del { background:#fee2e2; color:#dc2626; border:none; border-radius:6px; padding:4px 9px; cursor:pointer; font-size:12px; }

.total-row { display:flex; justify-content:flex-end; gap:2rem; margin-top:.8rem; font-size:14px; }
.total-row .total-label { color:#64748b; font-weight:600; }
.total-row .total-val { font-weight:800; color:#1e293b; min-width:120px; text-align:right; }
.grand-total .total-val { color:#2563eb; font-size:18px; }

.btn-submit { background:#2563eb; color:white; border:none; padding:11px 30px; border-radius:40px; font-weight:700; font-size:14px; cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:.5rem; }
.btn-submit:hover { background:#1e40af; transform:translateY(-2px); }
.btn-add-row { background:#2563eb; color:white; border:none; padding:6px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600; margin-bottom:.5rem; display:inline-flex; align-items:center; gap:.4rem; }
.btn-add-row:hover { background:#1e40af; }

/* Sales list table */
.data-table { width:100%; border-collapse:collapse; font-size:13px; }
.data-table th { background:#f1f5f9; color:#475569; padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #e2e8f0; text-align:left; }
.data-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.data-table tr:hover td { background:#f8fafc; }

.badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-paid    { background:#d1fae5; color:#065f46; }
.badge-partial { background:#fef3c7; color:#92400e; }
.badge-credit  { background:#fee2e2; color:#991b1b; }

.action-btn { padding:5px 10px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:.3rem; text-decoration:none; }
.btn-view { background:#eff6ff; color:#2563eb; }
.btn-print { background:#f0fdf4; color:#065f46; }

/* Modal */
.modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:2000; align-items:center; justify-content:center; }
.modal.active { display:flex; }
.modal-box { background:white; border-radius:24px; width:90%; max-width:700px; max-height:88vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,.25); }
.modal-header { background:linear-gradient(135deg,#2563eb,#1e3a8a); color:white; padding:1.1rem 1.5rem; border-radius:24px 24px 0 0; display:flex; justify-content:space-between; align-items:center; }
.modal-body { padding:1.5rem; }
.close-btn { background:rgba(255,255,255,.2); border:none; width:32px; height:32px; border-radius:50%; color:white; cursor:pointer; font-size:16px; }

/* Receipt-style view */
.receipt-view { font-size:13px; line-height:1.6; }
.receipt-view .rv-row { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #e2e8f0; }
.receipt-view .rv-label { color:#64748b; font-weight:600; }

@media(max-width:768px){
    .stats-row { grid-template-columns:1fr 1fr; }
    .form-grid, .form-grid.three { grid-template-columns:1fr; }
}
@media print {
    .no-print { display:none !important; }
    body { background:white; padding:0; }
    .card { box-shadow:none; border:1px solid #ccc; }
}
</style>

<div class="page-wrap">
<div class="toolbar no-print">
    <button class="btn-green" onclick="document.getElementById('newSaleModal').classList.add('active')">
        <i class="fas fa-plus"></i> New Sale
    </button>
    <a href="debtors.php"><i class="fas fa-user-clock"></i> Debtors</a>
    <a href="creditors.php"><i class="fas fa-user-tie"></i> Creditors</a>
    <a href="receipt.php"><i class="fas fa-receipt"></i> Receipts</a>
    <a href="../dashboard_erp.php"><i class="fas fa-home"></i> Dashboard</a>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($success_message)?></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?=htmlspecialchars($error_message)?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card">
        <div class="label"><i class="fas fa-receipt"></i> Total Sales</div>
        <div class="value"><?=number_format($stats['total_sales'])?></div>
    </div>
    <div class="stat-card">
        <div class="label"><i class="fas fa-chart-line"></i> Total Revenue</div>
        <div class="value green">UGX <?=number_format($stats['total_revenue'])?></div>
    </div>
    <div class="stat-card">
        <div class="label"><i class="fas fa-coins"></i> Collected</div>
        <div class="value green">UGX <?=number_format($stats['total_collected'])?></div>
    </div>
    <div class="stat-card">
        <div class="label"><i class="fas fa-clock"></i> Outstanding</div>
        <div class="value <?=$stats['total_outstanding'] > 0 ? 'red' : 'green'?>">UGX <?=number_format($stats['total_outstanding'])?></div>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-header"><i class="fas fa-book-open"></i> Sales Ledger Entries</div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sale #</th><th>Date</th><th>Customer</th><th>Payment</th>
                    <th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-receipt" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>No sales recorded yet</td></tr>
            <?php else: foreach ($sales as $s): ?>
                <tr>
                    <td><strong><?=htmlspecialchars($s['sale_number'])?></strong></td>
                    <td><?=date('d M Y', strtotime($s['sale_date']))?></td>
                    <td><?=htmlspecialchars($s['cust_name'] ?: $s['customer_name'] ?: 'Walk-in')?></td>
                    <td><?=ucfirst(str_replace('_',' ',$s['payment_method']))?></td>
                    <td><strong>UGX <?=number_format($s['total_amount'])?></strong></td>
                    <td style="color:#059669;">UGX <?=number_format($s['amount_paid'])?></td>
                    <td style="color:<?=$s['balance_due']>0?'#dc2626':'#059669'?>;">UGX <?=number_format($s['balance_due'])?></td>
                    <td><span class="badge badge-<?=$s['payment_status']?>"><?=ucfirst($s['payment_status'])?></span></td>
                    <td>
                        <button class="action-btn btn-view" onclick="viewSale(<?=$s['id']?>)"><i class="fas fa-eye"></i></button>
                        <button class="action-btn btn-print" onclick="printSale(<?=$s['id']?>)"><i class="fas fa-print"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- New Sale Modal -->
<div id="newSaleModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="fas fa-plus-circle"></i> Record New Sale</span>
      <button class="close-btn" onclick="document.getElementById('newSaleModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <div class="form-grid">
          <div class="form-group">
            <label>Customer</label>
            <select name="customer_id" id="custSelect" onchange="fillCustName(this)">
              <option value="">— Walk-in / Select —</option>
              <?php foreach($customers as $c): ?>
              <option value="<?=$c['id']?>" data-name="<?=htmlspecialchars($c['full_name'])?>"><?=htmlspecialchars($c['full_name'])?> <?=$c['telephone']?'('.$c['telephone'].')':''?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Customer Name (manual / walk-in)</label>
            <input type="text" name="customer_name" id="custName" placeholder="Type name if not listed">
          </div>
        </div>
        <div class="form-grid three">
          <div class="form-group">
            <label>Payment Method</label>
            <select name="payment_method" required>
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cheque">Cheque</option>
              <option value="credit">Credit (Pay Later)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Amount Paid (UGX)</label>
            <input type="number" name="amount_paid" id="amountPaid" min="0" step="0.01" value="0" oninput="recalc()">
          </div>
          <div class="form-group">
            <label>Discount (UGX)</label>
            <input type="number" name="discount" id="discountField" min="0" step="0.01" value="0" oninput="recalc()">
          </div>
        </div>

        <!-- Items -->
        <div style="margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center;">
          <strong style="color:#1e293b;">Sale Items</strong>
          <button type="button" class="btn-add-row" onclick="addRow()"><i class="fas fa-plus"></i> Add Row</button>
        </div>

        <!-- Catalog search -->
        <div style="position:relative;margin-bottom:10px;">
          <input type="text" id="catalogSearch" placeholder="🔍 Search inventory, services or stock by name…"
            style="width:100%;padding:9px 13px;border:1.5px solid #2563eb;border-radius:10px;font-size:13px;font-family:inherit;outline:none;"
            oninput="filterCatalog(this.value)" onfocus="showCatalogDrop()" autocomplete="off">
          <div id="catalogDrop" style="display:none;position:absolute;left:0;right:0;background:white;border:1.5px solid #2563eb;border-radius:10px;max-height:220px;overflow-y:auto;z-index:9999;box-shadow:0 8px 24px rgba(37,99,235,.15);top:calc(100% + 4px);">
          </div>
        </div>

        <table class="items-table" id="itemsTable">
          <thead><tr>
            <th style="width:220px;">Item / Description</th>
            <th style="width:60px;">Qty</th>
            <th style="width:120px;">Unit Price</th>
            <th style="width:120px;">Total</th>
            <th style="width:40px;"></th>
          </tr></thead>
          <tbody id="itemsBody">
            <tr>
              <td>
                <input type="hidden" name="inventory_id[]" class="inv-id-field">
                <input type="text" name="description[]" placeholder="Type item or pick from search above" required>
              </td>
              <td><input type="number" name="quantity[]" value="1" min="0.01" step="0.01" oninput="recalc()" class="qty-input"></td>
              <td><input type="number" name="unit_price[]" value="0" min="0" step="0.01" oninput="recalc()" class="price-input"></td>
              <td><input type="number" name="row_total[]" value="0" readonly style="color:#2563eb;font-weight:700;background:#f8fafc;" class="row-total"></td>
              <td><button type="button" class="btn-del" onclick="delRow(this)"><i class="fas fa-trash"></i></button></td>
            </tr>
          </tbody>
        </table>

        <div class="total-row"><span class="total-label">Subtotal</span><span class="total-val" id="subtotalDisplay">UGX 0</span></div>
        <div class="total-row"><span class="total-label">Discount</span><span class="total-val" id="discountDisplay" style="color:#dc2626;">UGX 0</span></div>
        <div class="total-row grand-total"><span class="total-label" style="font-size:16px;font-weight:800;">TOTAL</span><span class="total-val" id="totalDisplay">UGX 0</span></div>
        <div class="total-row"><span class="total-label">Amount Paid</span><span class="total-val" id="paidDisplay" style="color:#059669;">UGX 0</span></div>
        <div class="total-row"><span class="total-label">Balance Due</span><span class="total-val" id="balDisplay" style="color:#dc2626;">UGX 0</span></div>

        <div class="form-group" style="margin-top:1rem;">
          <label>Notes</label>
          <textarea name="notes" rows="2" placeholder="Optional notes..."></textarea>
        </div>

        <div style="text-align:right;margin-top:1rem;">
          <button type="submit" name="create_sale" class="btn-submit"><i class="fas fa-save"></i> Record Sale</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Sale Modal -->
<div id="viewSaleModal" class="modal no-print">
  <div class="modal-box">
    <div class="modal-header">
      <span><i class="fas fa-receipt"></i> Sale Details</span>
      <button class="close-btn" onclick="document.getElementById('viewSaleModal').classList.remove('active')">&times;</button>
    </div>
    <div class="modal-body" id="viewSaleBody"><div style="text-align:center;padding:2rem;color:#94a3b8;">Loading...</div></div>
  </div>
</div>

<script>
// ── Catalog data from PHP ──
const CATALOG = <?php echo json_encode(array_values($catalog)); ?>;

// ── customer name fill ──
function fillCustName(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('custName').value = opt.dataset.name || '';
}

// ── Catalog search/dropdown ──
let activeRow = null;

function showCatalogDrop() {
    filterCatalog(document.getElementById('catalogSearch').value);
}

function filterCatalog(q) {
    const drop = document.getElementById('catalogDrop');
    const term = q.trim().toLowerCase();
    const results = term.length === 0
        ? CATALOG.slice(0, 40)
        : CATALOG.filter(c => c.name.toLowerCase().includes(term)).slice(0, 40);

    if (results.length === 0) { drop.style.display = 'none'; return; }

    drop.innerHTML = results.map(c => {
        const stockBadge = c.type === 'Inventory'
            ? `<span style="font-size:10px;background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:10px;margin-left:6px;">Stk: ${c.stock}</span>`
            : `<span style="font-size:10px;background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:10px;margin-left:6px;">Service</span>`;
        const priceText = c.price > 0 ? `<span style="margin-left:auto;font-weight:700;color:#2563eb;">UGX ${parseInt(c.price).toLocaleString()}</span>` : '';
        return `<div onclick='selectCatalogItem(${JSON.stringify(c)})'
            style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:4px;font-size:13px;"
            onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='white'">
            <span style="flex:1;">${c.name}${stockBadge}</span>${priceText}
        </div>`;
    }).join('');
    drop.style.display = 'block';
}

function selectCatalogItem(item) {
    document.getElementById('catalogDrop').style.display = 'none';
    document.getElementById('catalogSearch').value = '';

    // Find the last empty row or add a new one
    const rows = document.querySelectorAll('#itemsBody tr');
    let targetRow = null;
    for (const r of rows) {
        const desc = r.querySelector('input[name="description[]"]');
        if (desc && desc.value.trim() === '') { targetRow = r; break; }
    }
    if (!targetRow) { addRow(); targetRow = document.querySelector('#itemsBody tr:last-child'); }

    targetRow.querySelector('input[name="description[]"]').value = item.name;
    targetRow.querySelector('.price-input').value = item.price || 0;
    targetRow.querySelector('.inv-id-field').value = item.inv_id || '';
    recalc();
}

document.addEventListener('click', e => {
    const drop = document.getElementById('catalogDrop');
    if (drop && !drop.contains(e.target) && e.target.id !== 'catalogSearch') {
        drop.style.display = 'none';
    }
});

// ── add item row ──
function addRow() {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="hidden" name="inventory_id[]" class="inv-id-field">
            <input type="text" name="description[]" placeholder="Type item or pick from search above" required>
        </td>
        <td><input type="number" name="quantity[]" value="1" min="0.01" step="0.01" oninput="recalc()" class="qty-input"></td>
        <td><input type="number" name="unit_price[]" value="0" min="0" step="0.01" oninput="recalc()" class="price-input"></td>
        <td><input type="number" name="row_total[]" value="0" readonly style="color:#2563eb;font-weight:700;background:#f8fafc;" class="row-total"></td>
        <td><button type="button" class="btn-del" onclick="delRow(this)"><i class="fas fa-trash"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
}

function delRow(btn) {
    const rows = document.querySelectorAll('#itemsBody tr');
    if (rows.length > 1) { btn.closest('tr').remove(); recalc(); }
}

// ── recalculate totals ──
function recalc() {
    let subtotal = 0;
    document.querySelectorAll('#itemsBody tr').forEach(tr => {
        const qty   = parseFloat(tr.querySelector('.qty-input')?.value) || 0;
        const price = parseFloat(tr.querySelector('.price-input')?.value) || 0;
        const tot   = qty * price;
        const rt    = tr.querySelector('.row-total');
        if (rt) rt.value = tot.toFixed(2);
        subtotal += tot;
    });
    const discount = parseFloat(document.getElementById('discountField')?.value) || 0;
    const total    = subtotal - discount;
    const paid     = parseFloat(document.getElementById('amountPaid')?.value) || 0;
    const bal      = total - paid;
    const fmt = v => 'UGX ' + Math.round(v).toLocaleString();
    document.getElementById('subtotalDisplay').textContent = fmt(subtotal);
    document.getElementById('discountDisplay').textContent = fmt(discount);
    document.getElementById('totalDisplay').textContent    = fmt(total);
    document.getElementById('paidDisplay').textContent     = fmt(paid);
    document.getElementById('balDisplay').textContent      = fmt(bal < 0 ? 0 : bal);
}

// ── view sale ──
async function viewSale(id) {
    document.getElementById('viewSaleModal').classList.add('active');
    document.getElementById('viewSaleBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    const res = await fetch(`sales_ledger.php?action=get_sale&id=${id}`);
    const data = await res.json();
    if (!data.success) { document.getElementById('viewSaleBody').innerHTML = 'Error loading sale'; return; }
    const s = data.sale;
    const items = data.items;
    let itemsHTML = items.map(it => `<tr><td>${it.description}</td><td>${it.quantity}</td><td>UGX ${parseInt(it.unit_price).toLocaleString()}</td><td>UGX ${parseInt(it.total_price).toLocaleString()}</td></tr>`).join('');
    document.getElementById('viewSaleBody').innerHTML = `
        <div class="receipt-view">
            <div class="rv-row"><span class="rv-label">Sale #</span><strong>${s.sale_number}</strong></div>
            <div class="rv-row"><span class="rv-label">Date</span>${s.sale_date}</div>
            <div class="rv-row"><span class="rv-label">Customer</span>${s.full_name || s.customer_name || 'Walk-in'}</div>
            <div class="rv-row"><span class="rv-label">Payment Method</span>${s.payment_method}</div>
        </div>
        <table class="items-table" style="margin-top:1rem;">
            <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
            <tbody>${itemsHTML}</tbody>
        </table>
        <div class="total-row"><span class="total-label">Subtotal</span><span class="total-val">UGX ${parseInt(s.subtotal).toLocaleString()}</span></div>
        <div class="total-row"><span class="total-label">Discount</span><span class="total-val" style="color:#dc2626;">UGX ${parseInt(s.discount).toLocaleString()}</span></div>
        <div class="total-row grand-total"><span class="total-label" style="font-weight:800;">TOTAL</span><span class="total-val">UGX ${parseInt(s.total_amount).toLocaleString()}</span></div>
        <div class="total-row"><span class="total-label">Paid</span><span class="total-val" style="color:#059669;">UGX ${parseInt(s.amount_paid).toLocaleString()}</span></div>
        <div class="total-row"><span class="total-label">Balance</span><span class="total-val" style="color:#dc2626;">UGX ${parseInt(s.balance_due).toLocaleString()}</span></div>
        <div style="margin-top:1rem;text-align:right;">
            <button class="action-btn btn-print" onclick="printSale(${s.id})"><i class="fas fa-print"></i> Print</button>
        </div>`;
}

// ── print single sale in new window ──
function printSale(id) {
    window.open('print_sale.php?id=' + id, '_blank', 'width=900,height=700');
}

window.onclick = e => {
    ['newSaleModal','viewSaleModal'].forEach(id => {
        const m = document.getElementById(id);
        if (e.target === m) m.classList.remove('active');
    });
};
</script>
</body>
</html>
