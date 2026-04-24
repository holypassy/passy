<?php
// receipt.php – Simple Receipt: party name + amount → affects accounts
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
$user_id        = $_SESSION['user_id']    ?? 1;
$user_full_name = $_SESSION['full_name']  ?? 'User';
date_default_timezone_set('Africa/Kampala');

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ============================================================
    // 1. Ensure the receipts table has ALL required columns
    // ============================================================
    $conn->exec("
        CREATE TABLE IF NOT EXISTS receipts (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            receipt_number  VARCHAR(30) NOT NULL UNIQUE,
            receipt_date    DATE NOT NULL,
            receipt_type    ENUM('received','paid') DEFAULT 'received',
            party_name      VARCHAR(150) NOT NULL,
            customer_id     INT,
            description     TEXT,
            amount          DECIMAL(15,2) NOT NULL,
            payment_method  VARCHAR(30) DEFAULT 'cash',
            account_id      INT,
            reference       VARCHAR(100),
            source_type     VARCHAR(30) DEFAULT NULL COMMENT 'debtor | company_vehicle',
            source_id       INT DEFAULT NULL COMMENT 'FK to debtors.id or debtor_company_vehicles.id',
            created_by      INT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Get current columns
    $existingColumns = $conn->query("SHOW COLUMNS FROM receipts")->fetchAll(PDO::FETCH_COLUMN);
    
    // Define all required columns and their definitions (if missing)
    $requiredColumns = [
        'receipt_number' => "VARCHAR(30) NOT NULL UNIQUE",
        'receipt_date'   => "DATE NOT NULL",
        'receipt_type'   => "ENUM('received','paid') DEFAULT 'received'",
        'party_name'     => "VARCHAR(150) NOT NULL",
        'customer_id'    => "INT",
        'description'    => "TEXT",
        'amount'         => "DECIMAL(15,2) NOT NULL",
        'payment_method' => "VARCHAR(30) DEFAULT 'cash'",
        'account_id'     => "INT",
        'reference'      => "VARCHAR(100)",
        'source_type'    => "VARCHAR(30) DEFAULT NULL",
        'source_id'      => "INT DEFAULT NULL",
        'created_by'     => "INT",
        'created_at'     => "DATETIME DEFAULT CURRENT_TIMESTAMP"
    ];
    
    foreach ($requiredColumns as $col => $definition) {
        if (!in_array($col, $existingColumns)) {
            $conn->exec("ALTER TABLE receipts ADD COLUMN $col $definition");
        }
    }
    
    // Add foreign key constraint for account_id (if not exists)
    try {
        $conn->exec("ALTER TABLE receipts ADD CONSTRAINT receipts_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Constraint may already exist – ignore
    }
    
    // ============================================================
    // 2. Ensure accounts table exists with required accounts
    // ============================================================
    $conn->exec("
        CREATE TABLE IF NOT EXISTS accounts (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            account_code  VARCHAR(10) NOT NULL UNIQUE,
            account_name  VARCHAR(100) NOT NULL,
            account_type  ENUM('asset','liability','equity','revenue','expense') DEFAULT 'asset',
            balance       DECIMAL(15,2) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insert default accounts if missing
    $conn->exec("
        INSERT IGNORE INTO accounts (account_code, account_name, account_type, balance) VALUES
        ('1010','Cash on Hand','asset',0),
        ('1020','Mobile Money','asset',0),
        ('1030','Bank Account','asset',0),
        ('1040','Cheque Account','asset',0),
        ('4000','Sales Revenue','revenue',0),
        ('5100','General Expenses','expense',0)
    ");

    // ============================================================
    // 3. Fix any existing receipts that have NULL required fields
    // ============================================================
    // Set default account_id if NULL
    $cashAccId = $conn->query("SELECT id FROM accounts WHERE account_code='1010'")->fetchColumn();
    if ($cashAccId) {
        $conn->prepare("UPDATE receipts SET account_id = ? WHERE account_id IS NULL")->execute([$cashAccId]);
    }
    
    // Set default receipt_type if NULL
    $conn->exec("UPDATE receipts SET receipt_type = 'received' WHERE receipt_type IS NULL");
    
    // Set default receipt_date if NULL (use current date)
    $conn->exec("UPDATE receipts SET receipt_date = CURDATE() WHERE receipt_date IS NULL");
    
    // Set default party_name if NULL (should not happen, but safety)
    $conn->exec("UPDATE receipts SET party_name = 'Unknown' WHERE party_name IS NULL OR party_name = ''");
    
    // Set default amount to 0 if NULL (should not happen, but safety)
    $conn->exec("UPDATE receipts SET amount = 0 WHERE amount IS NULL");

    // ============================================================
    // 4. Ensure account_ledger table exists
    // ============================================================
    $conn->exec("
        CREATE TABLE IF NOT EXISTS account_ledger (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME NOT NULL,
            description    TEXT,
            account_id     INT,
            debit          DECIMAL(15,2) DEFAULT 0,
            credit         DECIMAL(15,2) DEFAULT 0,
            reference_type VARCHAR(30),
            reference_id   INT,
            FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ============================================================
    // 5. Fetch data for display
    // ============================================================
    $customers    = $conn->query("SELECT id, full_name, telephone FROM customers WHERE status=1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $assetAccounts = $conn->query("SELECT id, account_code, account_name FROM accounts WHERE account_type='asset' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
    $receipts     = $conn->query("SELECT r.*, a.account_name FROM receipts r LEFT JOIN accounts a ON r.account_id=a.id ORDER BY r.created_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);

    // For linking receipts to debtors / company vehicles
    $openDebtors = $conn->query("
        SELECT id, customer_name, reference_no, balance
        FROM debtors
        WHERE status != 'settled' AND balance > 0
        ORDER BY customer_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $openVehicles = $conn->query("
        SELECT id, number_plate, vehicle_make, company_in_charge, balance
        FROM debtor_company_vehicles
        WHERE status != 'settled' AND balance > 0
        ORDER BY company_in_charge, number_plate
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN receipt_type='received' THEN amount ELSE 0 END),0) as total_received,
            COALESCE(SUM(CASE WHEN receipt_type='paid' THEN amount ELSE 0 END),0)     as total_paid,
            COUNT(*) as total_receipts
        FROM receipts
    ")->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ── AJAX: get receipt ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_receipt' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $r = $conn->prepare("SELECT r.*, a.account_name FROM receipts r LEFT JOIN accounts a ON r.account_id=a.id WHERE r.id=?");
        $r->execute([(int)$_GET['id']]);
        echo json_encode(['success' => true, 'receipt' => $r->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: create receipt ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_receipt'])) {
    try {
        $conn->beginTransaction();

        $last = $conn->query("SELECT receipt_number FROM receipts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $nextNum = $last ? str_pad((int)substr($last['receipt_number'], -4) + 1, 4, '0', STR_PAD_LEFT) : '0001';
        $rcptNo = 'RCT-' . date('Y') . '-' . $nextNum;

        $type          = $_POST['receipt_type'];         // received | paid
        $party_name    = trim($_POST['party_name']);
        $customer_id   = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $amount        = (float)$_POST['amount'];
        $payment_method = $_POST['payment_method'];
        $description   = trim($_POST['description'] ?? '');
        $reference     = trim($_POST['reference']   ?? '');
        $source_type   = !empty($_POST['source_type']) ? $_POST['source_type'] : null;  // 'debtor' | 'company_vehicle'
        $source_id     = !empty($_POST['source_id'])   ? (int)$_POST['source_id']  : null;

        // map method → account code
        $methodMap = ['cash' => '1010', 'mobile_money' => '1020', 'bank_transfer' => '1030', 'cheque' => '1040'];
        $assetCode = $methodMap[$payment_method] ?? '1010';
        $acctId    = $conn->query("SELECT id FROM accounts WHERE account_code='$assetCode'")->fetchColumn();

        // insert receipt
        $conn->prepare("
            INSERT INTO receipts (receipt_number, receipt_date, receipt_type, party_name, customer_id,
                description, amount, payment_method, account_id, reference, source_type, source_id, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$rcptNo, date('Y-m-d'), $type, $party_name, $customer_id,
                     $description, $amount, $payment_method, $acctId, $reference,
                     $source_type, $source_id, $user_id]);
        $rcpt_id = $conn->lastInsertId();

        // ── If linked to a debtor, settle/update it ──
        if ($source_type === 'debtor' && $source_id) {
            $d = $conn->prepare("SELECT * FROM debtors WHERE id = ? FOR UPDATE");
            $d->execute([$source_id]);
            $debtor = $d->fetch(PDO::FETCH_ASSOC);
            if ($debtor && $amount <= $debtor['balance']) {
                $new_paid    = $debtor['amount_paid'] + $amount;
                $new_balance = $debtor['balance']      - $amount;
                $new_status  = $new_balance <= 0 ? 'settled' : 'partial';
                $conn->prepare("UPDATE debtors SET amount_paid=?,balance=?,status=? WHERE id=?")
                     ->execute([$new_paid, $new_balance, $new_status, $source_id]);
                // Accounting: Debit asset, Credit AR
                $arId = $conn->query("SELECT id FROM accounts WHERE account_code='1200'")->fetchColumn();
                $now2 = date('Y-m-d H:i:s');
                $dsc2 = "Receipt $rcptNo — debtor payment: {$debtor['customer_name']}";
                $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,'debtor_payment',?)")
                     ->execute([$now2, $dsc2, $acctId, $amount, 0, $source_id]);
                $conn->prepare("UPDATE accounts SET balance=balance+? WHERE id=?")->execute([$amount, $acctId]);
                $conn->prepare("INSERT INTO account_ledger (transaction_date,description,account_id,debit,credit,reference_type,reference_id) VALUES (?,?,?,?,?,'debtor_payment',?)")
                     ->execute([$now2, $dsc2, $arId, 0, $amount, $source_id]);
                $conn->prepare("UPDATE accounts SET balance=balance-? WHERE id=?")->execute([$amount, $arId]);
            }
        }

        // ── If linked to a company vehicle, settle/update it ──
        if ($source_type === 'company_vehicle' && $source_id) {
            $v = $conn->prepare("SELECT * FROM debtor_company_vehicles WHERE id = ? FOR UPDATE");
            $v->execute([$source_id]);
            $cv = $v->fetch(PDO::FETCH_ASSOC);
            if ($cv && $amount <= $cv['balance']) {
                $new_paid    = $cv['amount_paid'] + $amount;
                $new_balance = $cv['balance']      - $amount;
                $new_status  = $new_balance <= 0 ? 'settled' : 'partial';
                $conn->prepare("UPDATE debtor_company_vehicles SET amount_paid=?,balance=?,status=?,updated_at=NOW() WHERE id=?")
                     ->execute([$new_paid, $new_balance, $new_status, $source_id]);
            }
        }

        // accounting double-entry
        $now  = date('Y-m-d H:i:s');
        $desc = ($type === 'received' ? 'Receipt from ' : 'Payment to ') . $party_name . ' – ' . $rcptNo;
        
        $ledger = $conn->prepare("INSERT INTO account_ledger (transaction_date, description, account_id, debit, credit, reference_type, reference_id) VALUES (?,?,?,?,?,?,?)");

        if ($type === 'received') {
            // Money coming in → Debit asset, Credit revenue
            $revId = $conn->query("SELECT id FROM accounts WHERE account_code='4000'")->fetchColumn();
            $ledger->execute([$now, $desc, $acctId, $amount, 0, 'receipt', $rcpt_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $acctId]);
            $ledger->execute([$now, $desc, $revId, 0, $amount, 'receipt', $rcpt_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $revId]);
        } else {
            // Money going out → Credit asset, Debit expense
            $expId = $conn->query("SELECT id FROM accounts WHERE account_code='5100'")->fetchColumn();
            $ledger->execute([$now, $desc, $expId, $amount, 0, 'receipt', $rcpt_id]);
            $conn->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $expId]);
            $ledger->execute([$now, $desc, $acctId, 0, $amount, 'receipt', $rcpt_id]);
            $conn->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $acctId]);
        }

        $conn->commit();
        $_SESSION['success'] = "Receipt $rcptNo saved!";
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header('Location: receipt.php');
    exit();
}

$success_message = $_SESSION['success'] ?? null;
$error_message   = $_SESSION['error']   ?? null;
unset($_SESSION['success'], $_SESSION['error']);

// Pre-fill from URL params (e.g. from debtors.php or company_vehicles.php Pay button)
$prefill = [];
if (!empty($_GET['source_type']) && !empty($_GET['source_id'])) {
    $pType = $_GET['source_type'];
    $pId   = (int)$_GET['source_id'];
    if ($pType === 'debtor') {
        $pr = $conn->prepare("SELECT customer_name, reference_no, balance FROM debtors WHERE id=?");
        $pr->execute([$pId]);
        $pRow = $pr->fetch(PDO::FETCH_ASSOC);
        if ($pRow) $prefill = ['source_type'=>'debtor','source_id'=>$pId,'party_name'=>$pRow['customer_name'],'reference'=>$pRow['reference_no'],'amount'=>$pRow['balance'],'open_modal'=>true];
    } elseif ($pType === 'company_vehicle') {
        $pr = $conn->prepare("SELECT number_plate, vehicle_make, company_in_charge, balance FROM debtor_company_vehicles WHERE id=?");
        $pr->execute([$pId]);
        $pRow = $pr->fetch(PDO::FETCH_ASSOC);
        if ($pRow) $prefill = ['source_type'=>'company_vehicle','source_id'=>$pId,'party_name'=>$pRow['company_in_charge'],'reference'=>$pRow['number_plate'].' '.$pRow['vehicle_make'],'amount'=>$pRow['balance'],'open_modal'=>true];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #e6f0ff 0%, #cce4ff 100%);
            padding: 2rem;
            min-height: 100vh;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        /* Page-specific styles */
        .stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
        .stat-card { background:white; border-radius:16px; padding:1.2rem 1.5rem; box-shadow:0 2px 8px rgba(0,0,0,.07); }
        .stat-card .label{ font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.3rem; }
        .stat-card .value{ font-size:22px; font-weight:800; }
        .stat-card .value.green{ color:#059669; } .stat-card .value.red{ color:#dc2626; }
        .card { background:white; border-radius:20px; box-shadow:0 4px 16px rgba(0,0,0,.08); overflow:hidden; margin-bottom:1.5rem; }
        .card-header { background:linear-gradient(135deg,#2563eb,#1e3a8a); color:white; padding:1rem 1.5rem; font-size:15px; font-weight:700; display:flex; align-items:center; gap:.6rem; }
        .data-table { width:100%; border-collapse:collapse; font-size:13px; }
        .data-table th { background:#f1f5f9; color:#475569; padding:10px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; border-bottom:2px solid #e2e8f0; text-align:left; }
        .data-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .data-table tr:hover td { background:#f8fafc; }
        .badge-received{ background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        .badge-paid{ background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        .action-btn{ padding:5px 10px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:.3rem; }
        .btn-view{ background:#eff6ff; color:#2563eb; } .btn-print{ background:#f0fdf4; color:#065f46; }
        .modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:2000; align-items:center; justify-content:center; }
        .modal.active{ display:flex; }
        .modal-box{ background:white; border-radius:24px; width:90%; max-width:520px; max-height:88vh; overflow-y:auto; box-shadow:0 25px 50px rgba(0,0,0,.25); }
        .modal-header{ background:linear-gradient(135deg,#2563eb,#1e3a8a); color:white; padding:1.1rem 1.5rem; border-radius:24px 24px 0 0; display:flex; justify-content:space-between; align-items:center; }
        .modal-body{ padding:1.5rem; }
        .close-btn{ background:rgba(255,255,255,.2); border:none; width:32px; height:32px; border-radius:50%; color:white; cursor:pointer; font-size:16px; }
        .form-group{ margin-bottom:1rem; }
        .form-group label{ display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-bottom:.4rem; }
        .form-group input, .form-group select, .form-group textarea{ width:100%; padding:9px 13px; border:1.5px solid #e2e8f0; border-radius:10px; font-size:13px; font-family:inherit; outline:none; transition:all .2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus{ border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
        .btn-submit{ background:#2563eb; color:white; border:none; padding:11px 30px; border-radius:40px; font-weight:700; font-size:14px; cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:.5rem; }
        .btn-submit:hover{ background:#1e40af; transform:translateY(-2px); }
        .alert { padding:12px 18px; border-radius:12px; margin-bottom:1rem; display:flex; align-items:center; gap:10px; }
        .alert-success{ background:#d1fae5; color:#065f46; border-left:4px solid #10b981; }
        .alert-danger{ background:#fee2e2; color:#991b1b; border-left:4px solid #ef4444; }
        .new-rcpt-btn {
            background: linear-gradient(135deg,#059669,#047857);
            color: white; border: none; padding: .6rem 1.2rem;
            border-radius: 40px; font-weight: 700; font-size: .85rem;
            cursor: pointer; display: inline-flex; align-items: center; gap: .5rem;
            transition: all .2s;
        }
        .new-rcpt-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,.3); }
        .header-bar {
            background: white;
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .logo-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .logo-img {
            width: 80px;
            height: auto;
        }
        .company-title h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0ea5e9;
            margin: 0;
        }
        .company-title p {
            font-size: 11px;
            color: #64748b;
            margin: 0;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .header-bar { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Simple header (replaces the removed header.php) -->
    <div class="header-bar no-print">
        <div class="logo-area">
            <img class="logo-img" src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo" onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Crect width=\'100\' height=\'100\' fill=\'%232c3e50\'/%3E%3Ctext x=\'50\' y=\'55\' font-size=\'40\' text-anchor=\'middle\' fill=\'%23fbbf24\' font-family=\'monospace\'%3ES%3C/text%3E%3C/svg%3E';">
            <div class="company-title">
                <h1>SAVANT MOTORS</h1>
                <p>Bugolobi, Bunyonyi Drive, Kampala, Uganda</p>
            </div>
        </div>
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a;">RECEIPTS</h2>
            <p style="font-size: 0.7rem; color: #64748b;">Money Received & Paid Out</p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:.75rem;">
        <div>
            <h2 style="font-size:1.25rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:.5rem;">
                <i class="fas fa-receipt" style="color:#2563eb;"></i> Receipts
            </h2>
            <p style="font-size:.78rem; color:#64748b; margin-top:.15rem;">Money received &amp; paid out — all double-entry posted to accounts</p>
        </div>
        <div style="display:flex; align-items:center; gap:.75rem;">
            <a href="../dashboard_erp.php" class="new-rcpt-btn no-print" style="background:linear-gradient(135deg,#2563eb,#1e3a8a); text-decoration:none;">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <button class="new-rcpt-btn no-print" onclick="document.getElementById('newRcptModal').classList.add('active')">
                <i class="fas fa-plus"></i> New Receipt
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($success_message)?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?=htmlspecialchars($error_message)?></div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><div class="label"><i class="fas fa-receipt"></i> Total Receipts</div><div class="value"><?=number_format($stats['total_receipts'])?></div></div>
        <div class="stat-card"><div class="label"><i class="fas fa-arrow-down"></i> Money Received</div><div class="value green">UGX <?=number_format($stats['total_received'])?></div></div>
        <div class="stat-card"><div class="label"><i class="fas fa-arrow-up"></i> Money Paid Out</div><div class="value red">UGX <?=number_format($stats['total_paid'])?></div></div>
    </div>

    <div class="card no-print">
        <div class="card-header" style="justify-content:space-between;">
            <span><i class="fas fa-list"></i> Receipt History</span>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <div style="position:relative;">
                    <input type="text" id="rcptSearch" placeholder="Search receipt #, name, amount…"
                        oninput="filterReceipts(this.value)"
                        style="padding:7px 36px 7px 13px;border:1.5px solid #e2e8f0;border-radius:20px;
                               font-size:12px;font-family:inherit;outline:none;width:240px;transition:border .2s;">
                    <i class="fas fa-search" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;pointer-events:none;"></i>
                </div>
                <button onclick="filterReceipts('');document.getElementById('rcptSearch').value='';"
                    style="background:#f1f5f9;border:none;padding:7px 12px;border-radius:20px;cursor:pointer;font-size:12px;color:#64748b;font-weight:600;">
                    Clear
                </button>
                <span id="rcptCount" style="font-size:11px;color:#94a3b8;white-space:nowrap;"></span>
            </div>
        </div>
        <table class="data-table" id="rcptTable">
            <thead><tr><th>Receipt #</th><th>Date</th><th>Type</th><th>Party Name</th><th>Amount</th><th>Method</th><th>Account</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($receipts)): ?>
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-receipt" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>No receipts yet</td></tr>
            <?php else: foreach ($receipts as $r):
                $searchable = implode(' ', [
                    $r['receipt_number'],
                    $r['party_name'],
                    $r['receipt_type'] === 'received' ? 'received' : 'paid out',
                    $r['payment_method'],
                    $r['account_name'] ?? '',
                    number_format($r['amount']),
                    date('d M Y', strtotime($r['receipt_date'])),
                ]);
            ?>
                <tr data-searchable="<?=htmlspecialchars($searchable)?>">
                    <td><strong><?=htmlspecialchars($r['receipt_number'])?></strong></td>
                    <td><?=date('d M Y', strtotime($r['receipt_date']))?></td>
                    <td><span class="badge-<?=$r['receipt_type']?>"><?=$r['receipt_type']==='received'?'Received':'Paid Out'?></span></td>
                    <td><?=htmlspecialchars($r['party_name'])?></td>
                    <td><strong style="color:<?=$r['receipt_type']==='received'?'#059669':'#dc2626'?>">UGX <?=number_format($r['amount'])?></strong></td>
                    <td><?=ucfirst(str_replace('_',' ',$r['payment_method']))?></td>
                    <td><?=htmlspecialchars($r['account_name']??'')?></td>
                    <td>
                        <?php if (!empty($r['source_type'])): ?>
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                            background:<?=$r['source_type']==='debtor'?'#dbeafe':'#ede9fe'?>;
                            color:<?=$r['source_type']==='debtor'?'#1d4ed8':'#6d28d9'?>;">
                            <?=$r['source_type']==='debtor'?'<i class="fas fa-user-clock"></i> Debtor':'<i class="fas fa-car"></i> Vehicle'?>
                        </span>
                        <?php endif; ?>
                        <button class="action-btn btn-view" onclick="viewReceipt(<?=$r['id']?>)"><i class="fas fa-eye"></i></button>
                        <button class="action-btn btn-print" onclick="printReceipt(<?=$r['id']?>)"><i class="fas fa-print"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- New Receipt Modal -->
    <div id="newRcptModal" class="modal no-print">
        <div class="modal-box">
            <div class="modal-header">
                <span><i class="fas fa-plus-circle"></i> New Receipt</span>
                <button class="close-btn" onclick="document.getElementById('newRcptModal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <!-- Hidden source link fields (filled by JS when opened from debtors/vehicles) -->
                    <input type="hidden" name="source_type" id="rcptSourceType" value="<?=htmlspecialchars($prefill['source_type']??'')?>">
                    <input type="hidden" name="source_id"   id="rcptSourceId"   value="<?=htmlspecialchars($prefill['source_id']??'')?>">

                    <div class="form-group">
                        <label>Type</label>
                        <select name="receipt_type" required>
                            <option value="received">Money Received (from someone)</option>
                            <option value="paid">Money Paid Out (to someone)</option>
                        </select>
                    </div>

                    <!-- Link to Debtor / Company Vehicle -->
                    <div class="form-group" style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:12px 14px;">
                        <label style="color:#0369a1;"><i class="fas fa-link"></i> Link Payment to Outstanding Debt (optional)</label>
                        <select id="linkSourceSelect" onchange="onLinkSourceChange(this)" style="margin-top:6px;">
                            <option value="">— Select debtor or company vehicle —</option>
                            <?php if (!empty($openDebtors)): ?>
                            <optgroup label="📋 Debtors">
                                <?php foreach ($openDebtors as $od): ?>
                                <option value="debtor|<?=$od['id']?>"
                                    data-name="<?=htmlspecialchars($od['customer_name'])?>"
                                    data-ref="<?=htmlspecialchars($od['reference_no']??'')?>"
                                    data-balance="<?=$od['balance']?>"
                                    <?=(!empty($prefill['source_type'])&&$prefill['source_type']==='debtor'&&$prefill['source_id']==$od['id'])?'selected':''?>>
                                    <?=htmlspecialchars($od['customer_name'])?> — Ref: <?=htmlspecialchars($od['reference_no']??'—')?> — Bal: UGX <?=number_format($od['balance'])?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($openVehicles)): ?>
                            <optgroup label="🚗 Company Vehicles">
                                <?php foreach ($openVehicles as $ov): ?>
                                <option value="company_vehicle|<?=$ov['id']?>"
                                    data-name="<?=htmlspecialchars($ov['company_in_charge'])?>"
                                    data-ref="<?=htmlspecialchars($ov['number_plate'].' '.$ov['vehicle_make'])?>"
                                    data-balance="<?=$ov['balance']?>"
                                    <?=(!empty($prefill['source_type'])&&$prefill['source_type']==='company_vehicle'&&$prefill['source_id']==$ov['id'])?'selected':''?>>
                                    <?=htmlspecialchars($ov['company_in_charge'])?> — <?=htmlspecialchars($ov['number_plate'])?> (<?=htmlspecialchars($ov['vehicle_make'])?>)  — Bal: UGX <?=number_format($ov['balance'])?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <div id="linkBalanceHint" style="font-size:11px;color:#0369a1;margin-top:5px;display:none;">
                            <i class="fas fa-info-circle"></i> Outstanding balance: <strong id="linkBalanceAmt"></strong> — amount will be auto-filled.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Party Name *</label>
                        <input type="text" name="party_name" id="partyName" placeholder="Name of person / company" required value="<?=htmlspecialchars($prefill['party_name']??'')?>">
                    </div>
                    <div class="form-group">
                        <label>Link to Customer (optional)</label>
                        <select name="customer_id" onchange="document.getElementById('partyName').value=this.options[this.selectedIndex].dataset.name||''">
                            <option value="">— Select customer —</option>
                            <?php foreach($customers as $c): ?>
                            <option value="<?=$c['id']?>" data-name="<?=htmlspecialchars($c['full_name'])?>"><?=htmlspecialchars($c['full_name'])?> <?=$c['telephone']?'('.$c['telephone'].')':''?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (UGX) *</label>
                        <input type="number" name="amount" id="rcptAmount" min="1" step="0.01" required placeholder="0" value="<?=htmlspecialchars($prefill['amount']??'')?>">
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
                    <div class="form-group">
                        <label>Description / Narration</label>
                        <textarea name="description" rows="2" placeholder="What is this for?"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Reference (Invoice / Sale #, optional)</label>
                        <input type="text" name="reference" id="rcptReference" placeholder="e.g. INV-2024-0012" value="<?=htmlspecialchars($prefill['reference']??'')?>">
                    </div>
                    <div style="text-align:right;margin-top:1rem;">
                        <button type="submit" name="create_receipt" class="btn-submit"><i class="fas fa-save"></i> Save Receipt</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Receipt Modal -->
    <div id="viewRcptModal" class="modal no-print">
        <div class="modal-box">
            <div class="modal-header">
                <span><i class="fas fa-receipt"></i> Receipt</span>
                <button class="close-btn" onclick="document.getElementById('viewRcptModal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body" id="viewRcptBody"><div style="text-align:center;padding:2rem;color:#94a3b8;">Loading...</div></div>
        </div>
    </div>
</div>

<script>
function onLinkSourceChange(sel) {
    const val = sel.value; // e.g. "debtor|12" or "company_vehicle|5"
    const hint = document.getElementById('linkBalanceHint');
    const hintAmt = document.getElementById('linkBalanceAmt');
    if (!val) {
        document.getElementById('rcptSourceType').value = '';
        document.getElementById('rcptSourceId').value   = '';
        hint.style.display = 'none';
        return;
    }
    const opt = sel.options[sel.selectedIndex];
    const [type, id] = val.split('|');
    document.getElementById('rcptSourceType').value = type;
    document.getElementById('rcptSourceId').value   = id;
    document.getElementById('partyName').value       = opt.dataset.name || '';
    document.getElementById('rcptReference').value   = opt.dataset.ref  || '';
    document.getElementById('rcptAmount').value      = opt.dataset.balance || '';
    const bal = parseInt(opt.dataset.balance || 0);
    hintAmt.textContent = 'UGX ' + bal.toLocaleString();
    hint.style.display = 'block';
}

function openReceiptForDebtor(id, name, balance, refNo) {
    // Fill the source fields directly and open modal
    document.getElementById('rcptSourceType').value = 'debtor';
    document.getElementById('rcptSourceId').value   = id;
    document.getElementById('partyName').value       = name;
    document.getElementById('rcptReference').value   = refNo || '';
    document.getElementById('rcptAmount').value      = balance;
    // Update the link select
    const sel = document.getElementById('linkSourceSelect');
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === 'debtor|' + id) { sel.selectedIndex = i; break; }
    }
    const hint = document.getElementById('linkBalanceHint');
    document.getElementById('linkBalanceAmt').textContent = 'UGX ' + parseInt(balance).toLocaleString();
    hint.style.display = 'block';
    document.getElementById('newRcptModal').classList.add('active');
}

function openReceiptForVehicle(id, company, plate, balance) {
    document.getElementById('rcptSourceType').value = 'company_vehicle';
    document.getElementById('rcptSourceId').value   = id;
    document.getElementById('partyName').value       = company;
    document.getElementById('rcptReference').value   = plate;
    document.getElementById('rcptAmount').value      = balance;
    const sel = document.getElementById('linkSourceSelect');
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === 'company_vehicle|' + id) { sel.selectedIndex = i; break; }
    }
    const hint = document.getElementById('linkBalanceHint');
    document.getElementById('linkBalanceAmt').textContent = 'UGX ' + parseInt(balance).toLocaleString();
    hint.style.display = 'block';
    document.getElementById('newRcptModal').classList.add('active');
}

function filterReceipts(q) {
    const rows = document.querySelectorAll('#rcptTable tbody tr[data-searchable]');
    const term = q.trim().toLowerCase();
    let visible = 0;
    rows.forEach(row => {
        const match = !term || row.dataset.searchable.toLowerCase().includes(term);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const count = document.getElementById('rcptCount');
    if (count) count.textContent = term ? `${visible} result${visible !== 1 ? 's' : ''}` : '';
}

async function viewReceipt(id) {
    document.getElementById('viewRcptModal').classList.add('active');
    document.getElementById('viewRcptBody').innerHTML = '<div style="text-align:center;padding:2rem;"><i class="fas fa-spinner fa-spin"></i></div>';
    const res  = await fetch(`receipt.php?action=get_receipt&id=${id}`);
    const data = await res.json();
    if (!data.success) return;
    const r = data.receipt;
    document.getElementById('viewRcptBody').innerHTML = buildReceiptHTML(r);
}

function buildReceiptHTML(r) {
    return `
    <div style="font-family:'Courier New',monospace;max-width:360px;margin:0 auto;text-align:center;">
        <h2 style="font-size:18px;font-weight:900;letter-spacing:2px;">SAVANT MOTORS</h2>
        <div style="font-size:11px;color:#64748b;">Bugolobi, Bunyonyi Drive, Kampala</div>
        <div style="font-size:11px;color:#64748b;">Tel: +256 774 537 017</div>
        <hr style="margin:10px 0;border:1px dashed #ccc;">
        <div style="font-size:13px;font-weight:700;letter-spacing:1px;">${r.receipt_type === 'received' ? '✅ RECEIPT' : '💸 PAYMENT VOUCHER'}</div>
        <div style="font-size:11px;color:#64748b;">No: ${r.receipt_number}</div>
        <div style="font-size:11px;color:#64748b;">Date: ${r.receipt_date}</div>
        <hr style="margin:10px 0;border:1px dashed #ccc;">
        <div style="text-align:left;font-size:13px;">
            <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b;">${r.receipt_type==='received'?'Received from':'Paid to'}</span><strong>${r.party_name}</strong></div>
            <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b;">Method</span><span>${r.payment_method.replace('_',' ')}</span></div>
            ${r.description ? `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b;">For</span><span>${r.description}</span></div>` : ''}
            ${r.reference ? `<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b;">Ref</span><span>${r.reference}</span></div>` : ''}
        </div>
        <div style="font-size:26px;font-weight:900;margin:14px 0;color:${r.receipt_type==='received'?'#059669':'#dc2626'};">
            UGX ${parseInt(r.amount).toLocaleString()}
        </div>
        <hr style="border:1px dashed #ccc;">
        <div style="font-size:10px;color:#94a3b8;margin-top:8px;">Quality Service You Can Trust | Since 2018</div>
        <div style="margin-top:12px;">
            <button onclick="printReceipt(${r.id})" style="background:#2563eb;color:white;border:none;padding:8px 20px;border-radius:20px;cursor:pointer;font-weight:700;font-size:13px;"><i class="fas fa-print"></i> Print Receipt</button>
        </div>
    </div>`;
}

function printReceipt(id) {
    window.open('print_receipt.php?id=' + id + '&autoprint=1', '_blank');
}

window.onclick = e => {
    ['newRcptModal','viewRcptModal'].forEach(id => {
        const m = document.getElementById(id);
        if (e.target === m) m.classList.remove('active');
    });
};

<?php if (!empty($prefill['open_modal'])): ?>
// Auto-open new receipt modal if directed from debtors/vehicles
window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('newRcptModal').classList.add('active');
    // Trigger the select change to show hint
    const sel = document.getElementById('linkSourceSelect');
    if (sel.value) onLinkSourceChange(sel);
});
<?php endif; ?>
</script>
</body>
</html>