<?php
// views/cash/accounts.php - Complete Accounts Management with Create Account
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Direct database connection to load accounts
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create cash_accounts table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS cash_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            account_name VARCHAR(100) NOT NULL,
            account_type VARCHAR(50) DEFAULT 'cash',
            account_number VARCHAR(100),
            balance DECIMAL(15,2) DEFAULT 0,
            currency VARCHAR(3) DEFAULT 'UGX',
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Handle account creation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
        $account_name = trim($_POST['account_name']);
        $account_type = $_POST['account_type'];
        $account_number = trim($_POST['account_number'] ?? '');
        $initial_balance = floatval($_POST['initial_balance'] ?? 0);
        
        $errors = [];
        
        if (empty($account_name)) {
            $errors[] = "Account name is required";
        }
        if (empty($account_type)) {
            $errors[] = "Account type is required";
        }
        
        if (empty($errors)) {
            $stmt = $conn->prepare("
                INSERT INTO cash_accounts (account_name, account_type, account_number, balance, is_active) 
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$account_name, $account_type, $account_number, $initial_balance]);
            
            $_SESSION['success'] = "Account created successfully!";
            header('Location: accounts.php');
            exit();
        } else {
            $error_message = implode("<br>", $errors);
        }
    }
    
    // Get cash accounts
    $accounts = $conn->query("
        SELECT * FROM cash_accounts 
        WHERE is_active = 1 
        ORDER BY 
            CASE account_type 
                WHEN 'cash' THEN 1 
                WHEN 'bank' THEN 2 
                WHEN 'mobile_money' THEN 3 
                ELSE 4 
            END,
            account_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cash account balances
    $balances = $conn->query("
        SELECT 
            COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
            COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
            COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
            COALESCE(SUM(balance), 0) as total_balance
        FROM cash_accounts
        WHERE is_active = 1
    ")->fetch(PDO::FETCH_ASSOC);

    // ── BALANCE SHEET DATA ────────────────────────────────────────────────────

    // Debtors (Accounts Receivable) — money owed TO Savant Motors
    $debtors_summary = ['total_outstanding' => 0, 'open_count' => 0, 'partial_count' => 0, 'top' => []];
    try {
        $dr = $conn->query("
            SELECT 
                COALESCE(SUM(balance), 0)                                   AS total_outstanding,
                SUM(CASE WHEN status='open' THEN 1 ELSE 0 END)             AS open_count,
                SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END)          AS partial_count,
                COUNT(*)                                                     AS total_count
            FROM debtors WHERE status != 'settled'
        ")->fetch(PDO::FETCH_ASSOC);
        $debtors_summary = array_merge($debtors_summary, $dr ?? []);
        $debtors_summary['top'] = $conn->query("
            SELECT customer_name, balance, status FROM debtors
            WHERE status != 'settled' ORDER BY balance DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist */ }

    // Creditors (Accounts Payable) — money Savant Motors OWES
    $creditors_summary = ['total_outstanding' => 0, 'open_count' => 0, 'partial_count' => 0, 'top' => []];
    try {
        $cr = $conn->query("
            SELECT 
                COALESCE(SUM(balance), 0)                                   AS total_outstanding,
                SUM(CASE WHEN status='open' THEN 1 ELSE 0 END)             AS open_count,
                SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END)          AS partial_count,
                COUNT(*)                                                     AS total_count
            FROM creditors WHERE status != 'settled'
        ")->fetch(PDO::FETCH_ASSOC);
        $creditors_summary = array_merge($creditors_summary, $cr ?? []);
        $creditors_summary['top'] = $conn->query("
            SELECT supplier_name, balance, status FROM creditors
            WHERE status != 'settled' ORDER BY balance DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist */ }

    // Invoices — unpaid/partial invoices outstanding
    $invoices_summary = ['unpaid_total' => 0, 'unpaid_count' => 0, 'overdue_count' => 0];
    try {
        $iv = $conn->query("
            SELECT
                COALESCE(SUM(total_amount - amount_paid), 0)               AS unpaid_total,
                SUM(CASE WHEN payment_status IN ('unpaid','partial') THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END)       AS overdue_count
            FROM invoices
            WHERE payment_status != 'paid' AND status != 'cancelled'
        ")->fetch(PDO::FETCH_ASSOC);
        $invoices_summary = array_merge($invoices_summary, $iv ?? []);
    } catch (PDOException $e) { /* table may not exist */ }

    // Revenue this month from invoices
    $revenue_summary = ['this_month' => 0, 'last_month' => 0, 'total_collected' => 0];
    try {
        $rv = $conn->query("
            SELECT
                COALESCE(SUM(CASE WHEN MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) THEN amount_paid ELSE 0 END), 0) AS this_month,
                COALESCE(SUM(CASE WHEN MONTH(payment_date)=MONTH(CURDATE())-1 AND YEAR(payment_date)=YEAR(CURDATE()) THEN amount_paid ELSE 0 END), 0) AS last_month,
                COALESCE(SUM(amount_paid), 0)                              AS total_collected
            FROM invoices WHERE status != 'cancelled'
        ")->fetch(PDO::FETCH_ASSOC);
        $revenue_summary = array_merge($revenue_summary, $rv ?? []);
    } catch (PDOException $e) { /* table may not exist */ }

    // Company vehicle debts
    $cv_outstanding = 0;
    try {
        $cv_outstanding = $conn->query("SELECT COALESCE(SUM(amount_owed),0) FROM debtor_company_vehicles")->fetchColumn();
    } catch (PDOException $e) { /* table may not exist */ }

    // Fixed Assets — Tools & Equipment
    $fixed_assets_value = 0;
    $fixed_assets_breakdown = [];
    try {
        $fixed_assets_value = (float)$conn->query("
            SELECT COALESCE(SUM(COALESCE(quantity,1) * COALESCE(purchase_price,0)),0)
            FROM tools WHERE is_active=1 OR is_active IS NULL
        ")->fetchColumn();
    } catch (PDOException $e) {}

    try {
        $fixed_assets_breakdown = $conn->query("
            SELECT asset_type, COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS count
            FROM asset_purchases GROUP BY asset_type ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        // Use purchase records total if larger
        $purchaseTotal = array_sum(array_column($fixed_assets_breakdown, 'total'));
        if ($purchaseTotal > $fixed_assets_value) $fixed_assets_value = $purchaseTotal;
    } catch (PDOException $e) {}

    // ── BALANCE SHEET TOTALS ──────────────────────────────────────────────────
    // Current Assets
    $total_current_assets = ($balances['total_balance'] ?? 0)
                          + ($debtors_summary['total_outstanding'] ?? 0)
                          + ($invoices_summary['unpaid_total'] ?? 0)
                          + ($cv_outstanding ?? 0);

    // Fixed Assets
    $total_fixed_assets = $fixed_assets_value;

    // All Assets
    $total_all_assets = $total_current_assets + $total_fixed_assets;

    // Liabilities
    $total_liabilities = $creditors_summary['total_outstanding'] ?? 0;

    // Net position (equity proxy)
    $net_position = $total_all_assets - $total_liabilities;

} catch(PDOException $e) {
    $accounts = [];
    $balances = ['total_cash' => 0, 'total_bank' => 0, 'total_mobile' => 0, 'total_balance' => 0];
    $debtors_summary = ['total_outstanding' => 0, 'open_count' => 0, 'partial_count' => 0, 'top' => []];
    $creditors_summary = ['total_outstanding' => 0, 'open_count' => 0, 'partial_count' => 0, 'top' => []];
    $invoices_summary = ['unpaid_total' => 0, 'unpaid_count' => 0, 'overdue_count' => 0];
    $revenue_summary = ['this_month' => 0, 'last_month' => 0, 'total_collected' => 0];
    $cv_outstanding = 0;
    $fixed_assets_value = 0;
    $fixed_assets_breakdown = [];
    $total_current_assets = 0;
    $total_fixed_assets = 0;
    $total_all_assets = 0;
    $total_liabilities = 0;
    $net_position = 0;
    $error_message = "Database error: " . $e->getMessage();
}

// Get success/error messages
$success_message = $_SESSION['success'] ?? null;
$error_message = $error_message ?? $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Accounts | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --bg-light: #f8fafc;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #0c4a6e;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid var(--border);
            text-align: center;
        }
        .stat-value { font-size: 1.5rem; font-weight: 700; }
        .stat-label { font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem; text-transform: uppercase; }
        .accounts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1rem;
        }
        .account-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.2s;
        }
        .account-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .card-header {
            padding: 1rem;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .account-type {
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .type-cash { background: #dcfce7; color: #166534; }
        .type-bank { background: #dbeafe; color: #1e40af; }
        .type-mobile_money { background: #fed7aa; color: #9a3412; }
        .type-petty_cash { background: #e0e7ff; color: #4338ca; }
        .card-body { padding: 1rem; }
        .balance {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
            margin: 0.5rem 0;
        }
        .card-footer {
            padding: 0.75rem 1rem;
            background: var(--bg-light);
            border-top: 1px solid var(--border);
            display: flex;
            gap: 0.5rem;
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-success { background: var(--success); color: white; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 1rem;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            border-radius: 1rem 1rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body { padding: 1.5rem; }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
            grid-column: 1 / -1;
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }

        /* ── Balance Sheet ── */
        .bs-section-title {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--gray);
            margin: 1.2rem 0 0.5rem;
            padding-bottom: 0.4rem;
            border-bottom: 2px solid var(--border);
        }
        .bs-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0;
            font-size: 0.85rem;
            border-bottom: 1px dashed #f1f5f9;
        }
        .bs-row:last-child { border-bottom: none; }
        .bs-row .bs-label { color: var(--dark); display: flex; align-items: center; gap: 0.4rem; }
        .bs-row .bs-label a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .bs-row .bs-label a:hover { text-decoration: underline; }
        .bs-row .bs-amount { font-weight: 700; }
        .bs-amount.green { color: #059669; }
        .bs-amount.red { color: #dc2626; }
        .bs-amount.blue { color: #2563eb; }
        .bs-amount.purple { color: #7c3aed; }
        .bs-total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.6rem 0.75rem;
            margin-top: 0.5rem;
            border-radius: 0.5rem;
            font-weight: 800;
            font-size: 0.9rem;
        }
        .bs-total-assets { background: #eff6ff; color: #1e40af; }
        .bs-total-liabilities { background: #fef2f2; color: #dc2626; }
        .bs-net { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #065f46; }
        .bs-net.negative { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #991b1b; }
        .bs-mini-list { margin-top: 0.4rem; font-size: 0.75rem; }
        .bs-mini-list .mini-item {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-bottom: 2px;
        }
        .bs-mini-list .mini-item:nth-child(odd) { background: #f8fafc; }
        .bs-badge {
            display: inline-block;
            padding: 0.1rem 0.4rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .bs-badge.open { background: #fee2e2; color: #dc2626; }
        .bs-badge.partial { background: #fef9c3; color: #854d0e; }
        .balance-sheet-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            padding: 1.2rem 1.5rem;
        }
        .bs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .bs-full { margin-bottom: 1.5rem; }
        @media (max-width: 900px) { .bs-grid { grid-template-columns: 1fr; } }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .accounts-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>💰 SAVANT MOTORS</h2>
            <p>Cash Management System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="index.php" class="menu-item">💰 Cash Management</a>
            <a href="accounts.php" class="menu-item active">🏦 Accounts</a>
            <a href="reports.php" class="menu-item">📈 Reports</a>
            <div class="sidebar-title" style="margin-top:1rem;">LEDGERS</div>
            <a href="../accounting/debtors.php" class="menu-item">📥 Debtors</a>
            <a href="../accounting/creditors.php" class="menu-item">📤 Creditors</a>
            <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
            <a href="../accounting/receipt.php" class="menu-item">🧾 Receipts</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-university"></i> Cash Accounts</h1>
                <p>Manage your bank, cash, and mobile money accounts</p>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Add Account
            </button>
        </div>

        <?php if ($success_message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" style="color:#1e40af;">UGX <?php echo number_format($balances['total_cash'] ?? 0); ?></div>
                <div class="stat-label">💵 Cash on Hand</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:#0369a1;">UGX <?php echo number_format($balances['total_bank'] ?? 0); ?></div>
                <div class="stat-label">🏦 Bank</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:#059669;">UGX <?php echo number_format($debtors_summary['total_outstanding'] ?? 0); ?></div>
                <div class="stat-label">📥 Receivables (Debtors)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:#dc2626;">UGX <?php echo number_format($creditors_summary['total_outstanding'] ?? 0); ?></div>
                <div class="stat-label">📤 Payables (Creditors)</div>
            </div>
        </div>

        <!-- Balance Sheet -->
        <div class="bs-full">
            <div class="balance-sheet-card">
                <h2 style="font-size:1.1rem;font-weight:800;color:var(--dark);margin-bottom:0.25rem;">
                    📊 Balance Sheet — <span style="color:var(--gray);font-weight:500;font-size:0.9rem;">As at <?php echo date('d M Y'); ?></span>
                </h2>
                <p style="font-size:0.75rem;color:var(--gray);margin-bottom:1.2rem;">Live figures pulled from Cash Accounts, Debtors, Creditors &amp; Invoices</p>

                <div class="bs-grid">
                    <!-- ── ASSETS ── -->
                    <div>
                        <div class="bs-section-title">Current Assets</div>

                        <!-- Cash accounts breakdown -->
                        <div class="bs-row">
                            <span class="bs-label">💵 Cash on Hand</span>
                            <span class="bs-amount blue">UGX <?php echo number_format($balances['total_cash'] ?? 0); ?></span>
                        </div>
                        <div class="bs-row">
                            <span class="bs-label">🏦 Bank Accounts</span>
                            <span class="bs-amount blue">UGX <?php echo number_format($balances['total_bank'] ?? 0); ?></span>
                        </div>
                        <div class="bs-row">
                            <span class="bs-label">📱 Mobile Money</span>
                            <span class="bs-amount blue">UGX <?php echo number_format($balances['total_mobile'] ?? 0); ?></span>
                        </div>

                        <!-- Debtors / AR -->
                        <div class="bs-row" style="margin-top:0.5rem;">
                            <span class="bs-label">
                                📥 <a href="../accounting/debtors.php">Accounts Receivable (Debtors)</a>
                                <span class="bs-badge open"><?php echo ($debtors_summary['open_count'] ?? 0) + ($debtors_summary['partial_count'] ?? 0); ?> open</span>
                            </span>
                            <span class="bs-amount green">UGX <?php echo number_format($debtors_summary['total_outstanding'] ?? 0); ?></span>
                        </div>
                        <?php if (!empty($debtors_summary['top'])): ?>
                        <div class="bs-mini-list">
                            <?php foreach ($debtors_summary['top'] as $dr): ?>
                            <div class="mini-item">
                                <span style="color:#475569;"><?php echo htmlspecialchars($dr['customer_name']); ?></span>
                                <span style="color:#dc2626;font-weight:600;">UGX <?php echo number_format($dr['balance']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Unpaid invoices -->
                        <div class="bs-row" style="margin-top:0.5rem;">
                            <span class="bs-label">
                                🧾 <a href="../invoices.php">Unpaid Invoices</a>
                                <?php if (($invoices_summary['overdue_count'] ?? 0) > 0): ?>
                                <span class="bs-badge open"><?php echo $invoices_summary['overdue_count']; ?> overdue</span>
                                <?php endif; ?>
                                <?php if (($invoices_summary['unpaid_count'] ?? 0) > 0): ?>
                                <span class="bs-badge partial"><?php echo $invoices_summary['unpaid_count']; ?> pending</span>
                                <?php endif; ?>
                            </span>
                            <span class="bs-amount green">UGX <?php echo number_format($invoices_summary['unpaid_total'] ?? 0); ?></span>
                        </div>

                        <!-- Company vehicle debts -->
                        <?php if ($cv_outstanding > 0): ?>
                        <div class="bs-row">
                            <span class="bs-label">🚗 Company Vehicle Debts</span>
                            <span class="bs-amount green">UGX <?php echo number_format($cv_outstanding); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="bs-total-row bs-total-assets" style="background:#eff6ff;">
                            <span>TOTAL CURRENT ASSETS</span>
                            <span>UGX <?php echo number_format($total_current_assets); ?></span>
                        </div>

                        <!-- Fixed Assets -->
                        <?php if ($total_fixed_assets > 0): ?>
                        <div class="bs-section-title" style="margin-top:1rem;">Fixed Assets</div>
                        <?php
                        $assetTypeNames = ['tool'=>'🔧 Tools','equipment'=>'⚙️ Workshop Equipment','furniture'=>'🪑 Furniture & Fixtures','vehicle'=>'🚗 Motor Vehicles','other'=>'📦 Other Assets'];
                        if (!empty($fixed_assets_breakdown)):
                            foreach ($fixed_assets_breakdown as $fa):
                        ?>
                        <div class="bs-row">
                            <span class="bs-label">
                                <?php echo $assetTypeNames[$fa['asset_type']] ?? ucfirst($fa['asset_type']); ?>
                                <span class="bs-badge partial"><?php echo $fa['count']; ?> purchase<?php echo $fa['count']!=1?'s':''; ?></span>
                            </span>
                            <span class="bs-amount blue">UGX <?php echo number_format($fa['total']); ?></span>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="bs-row">
                            <span class="bs-label">🔧 Tools &amp; Equipment</span>
                            <span class="bs-amount blue">UGX <?php echo number_format($fixed_assets_value); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="bs-row" style="margin-top:0.25rem;">
                            <span class="bs-label" style="font-size:0.7rem;color:var(--gray);">
                                <a href="../tools/purchase_assets.php" style="color:var(--primary);">View asset purchases →</a>
                            </span>
                        </div>

                        <div class="bs-total-row bs-total-assets">
                            <span>TOTAL ALL ASSETS</span>
                            <span>UGX <?php echo number_format($total_all_assets); ?></span>
                        </div>
                        <?php else: ?>
                        <div class="bs-total-row bs-total-assets">
                            <span>TOTAL ASSETS</span>
                            <span>UGX <?php echo number_format($total_current_assets); ?></span>
                        </div>
                        <?php endif; ?>

                        <!-- Revenue summary -->
                        <div class="bs-section-title" style="margin-top:1.5rem;">Revenue Summary</div>
                        <div class="bs-row">
                            <span class="bs-label">📈 Revenue This Month</span>
                            <span class="bs-amount green">UGX <?php echo number_format($revenue_summary['this_month'] ?? 0); ?></span>
                        </div>
                        <div class="bs-row">
                            <span class="bs-label">📉 Revenue Last Month</span>
                            <span class="bs-amount blue">UGX <?php echo number_format($revenue_summary['last_month'] ?? 0); ?></span>
                        </div>
                        <div class="bs-row">
                            <span class="bs-label">💰 Total Collected (All Time)</span>
                            <span class="bs-amount green">UGX <?php echo number_format($revenue_summary['total_collected'] ?? 0); ?></span>
                        </div>
                    </div>

                    <!-- ── LIABILITIES & NET ── -->
                    <div>
                        <div class="bs-section-title">Current Liabilities</div>

                        <div class="bs-row">
                            <span class="bs-label">
                                📤 <a href="../accounting/creditors.php">Accounts Payable (Creditors)</a>
                                <span class="bs-badge open"><?php echo ($creditors_summary['open_count'] ?? 0) + ($creditors_summary['partial_count'] ?? 0); ?> open</span>
                            </span>
                            <span class="bs-amount red">UGX <?php echo number_format($creditors_summary['total_outstanding'] ?? 0); ?></span>
                        </div>
                        <?php if (!empty($creditors_summary['top'])): ?>
                        <div class="bs-mini-list">
                            <?php foreach ($creditors_summary['top'] as $cr): ?>
                            <div class="mini-item">
                                <span style="color:#475569;"><?php echo htmlspecialchars($cr['supplier_name']); ?></span>
                                <span style="color:#7c3aed;font-weight:600;">UGX <?php echo number_format($cr['balance']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="bs-total-row bs-total-liabilities" style="margin-top:0.5rem;">
                            <span>TOTAL LIABILITIES</span>
                            <span>UGX <?php echo number_format($total_liabilities); ?></span>
                        </div>

                        <!-- Net Position -->
                        <div style="margin-top:1.5rem;">
                            <div class="bs-section-title">Net Position (Equity)</div>
                            <div class="bs-row">
                                <span class="bs-label">Current Assets</span>
                                <span class="bs-amount blue">UGX <?php echo number_format($total_current_assets); ?></span>
                            </div>
                            <?php if ($total_fixed_assets > 0): ?>
                            <div class="bs-row">
                                <span class="bs-label">Fixed Assets (Tools &amp; Equipment)</span>
                                <span class="bs-amount blue">UGX <?php echo number_format($total_fixed_assets); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="bs-row">
                                <span class="bs-label">Less: Total Liabilities</span>
                                <span class="bs-amount red">(UGX <?php echo number_format($total_liabilities); ?>)</span>
                            </div>
                            <div class="bs-total-row <?php echo $net_position >= 0 ? 'bs-net' : 'bs-net negative'; ?>" style="margin-top:0.5rem;">
                                <span>NET POSITION</span>
                                <span><?php echo $net_position >= 0 ? '' : '-'; ?>UGX <?php echo number_format(abs($net_position)); ?></span>
                            </div>
                            <?php if ($net_position < 0): ?>
                            <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:0.6rem 0.75rem;border-radius:0.5rem;margin-top:0.75rem;font-size:0.78rem;color:#991b1b;">
                                ⚠️ Liabilities exceed assets by UGX <?php echo number_format(abs($net_position)); ?>. Review creditor payments.
                            </div>
                            <?php else: ?>
                            <div style="background:#f0fdf4;border-left:3px solid #10b981;padding:0.6rem 0.75rem;border-radius:0.5rem;margin-top:0.75rem;font-size:0.78rem;color:#065f46;">
                                ✅ Business is in a positive net position.
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick links -->
                        <div style="margin-top:1.5rem;">
                            <div class="bs-section-title">Quick Access</div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:0.5rem;">
                                <a href="../accounting/debtors.php" class="btn btn-success" style="font-size:0.78rem;padding:0.4rem 0.9rem;">
                                    <i class="fas fa-arrow-down"></i> Debtors
                                </a>
                                <a href="../accounting/creditors.php" class="btn btn-primary" style="font-size:0.78rem;padding:0.4rem 0.9rem;background:#7c3aed;">
                                    <i class="fas fa-arrow-up"></i> Creditors
                                </a>
                                <a href="../invoices.php" class="btn btn-primary" style="font-size:0.78rem;padding:0.4rem 0.9rem;">
                                    <i class="fas fa-file-invoice"></i> Invoices
                                </a>
                                <a href="../tools/purchase_assets.php" class="btn btn-primary" style="font-size:0.78rem;padding:0.4rem 0.9rem;background:#059669;">
                                    <i class="fas fa-shopping-cart"></i> Buy Assets
                                </a>
                                <a href="../accounting/receipt.php" class="btn btn-secondary" style="font-size:0.78rem;padding:0.4rem 0.9rem;">
                                    <i class="fas fa-receipt"></i> Receipts
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Accounts Grid -->
        <div class="top-bar" style="margin-bottom:1rem;">
            <div class="page-title">
                <h1 style="font-size:1rem;"><i class="fas fa-wallet"></i> Cash Accounts</h1>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Add Account
            </button>
        </div>
        <div class="accounts-grid">
            <?php if (empty($accounts)): ?>
            <div class="empty-state">
                <i class="fas fa-university"></i>
                <h3>No Accounts Found</h3>
                <p>Click "Add Account" to create your first cash account</p>
                <button class="btn btn-primary" onclick="openAddModal()" style="margin-top: 1rem;">
                    <i class="fas fa-plus-circle"></i> Add Account
                </button>
            </div>
            <?php else: ?>
            <?php foreach ($accounts as $account): ?>
            <div class="account-card">
                <div class="card-header">
                    <h3><i class="fas fa-<?php echo $account['account_type'] == 'bank' ? 'university' : ($account['account_type'] == 'mobile_money' ? 'mobile-alt' : 'money-bill-wave'); ?>"></i> <?php echo htmlspecialchars($account['account_name']); ?></h3>
                    <span class="account-type type-<?php echo $account['account_type']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $account['account_type'])); ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if (!empty($account['account_number'])): ?>
                    <div><small class="text-muted">Account #: <?php echo htmlspecialchars($account['account_number']); ?></small></div>
                    <?php endif; ?>
                    <div class="balance">UGX <?php echo number_format($account['balance']); ?></div>
                    <div><small class="text-muted">Currency: <?php echo $account['currency'] ?? 'UGX'; ?></small></div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary" onclick="viewAccount(<?php echo $account['id']; ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button class="btn btn-success" onclick="window.location.href='../cash/index.php?account=<?php echo $account['id']; ?>'">
                        <i class="fas fa-plus-circle"></i> Add Transaction
                    </button>
                    <button class="btn btn-secondary" onclick="editAccount(<?php echo $account['id']; ?>)">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Account Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Create New Account</h3>
                <button class="close-btn" onclick="closeModal('addModal')" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="" id="accountForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Account Name <span class="required">*</span></label>
                        <input type="text" name="account_name" id="accountName" required placeholder="e.g., Main Cash, Stanbic Bank">
                        <small style="color: var(--gray);">Choose a descriptive name for this account</small>
                    </div>
                    <div class="form-group">
                        <label>Account Type <span class="required">*</span></label>
                        <select name="account_type" id="accountType" required onchange="updateTypeIcon()">
                            <option value="cash">💰 Cash</option>
                            <option value="bank">🏦 Bank Account</option>
                            <option value="mobile_money">📱 Mobile Money</option>
                            <option value="petty_cash">💵 Petty Cash</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="account_number" id="accountNumber" placeholder="Bank account number or mobile number">
                        <small style="color: var(--gray);">Optional - for reference only</small>
                    </div>
                    <div class="form-group">
                        <label>Initial Balance</label>
                        <input type="number" name="initial_balance" id="initialBalance" step="1000" value="0" placeholder="0">
                        <small style="color: var(--gray);">Starting balance for this account</small>
                    </div>
                    <div id="typeInfo" class="info-box" style="background: #eff6ff; padding: 0.75rem; border-radius: 0.5rem; margin-top: 1rem; font-size: 0.75rem;">
                        <i class="fas fa-info-circle"></i> 
                        <span id="typeInfoText">Cash accounts are used for physical money in the drawer.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_account" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-save"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() { 
            document.getElementById('addModal').classList.add('active');
            document.getElementById('accountForm').reset();
            document.getElementById('accountName').focus();
        }
        
        function closeModal(id) { 
            document.getElementById(id).classList.remove('active'); 
        }
        
        function viewAccount(id) { 
            window.location.href = 'view_account.php?id=' + id; 
        }
        
        function editAccount(id) { 
            window.location.href = 'edit_account.php?id=' + id; 
        }
        
        function updateTypeIcon() {
            const type = document.getElementById('accountType').value;
            const infoText = document.getElementById('typeInfoText');
            const infoBox = document.getElementById('typeInfo');
            
            switch(type) {
                case 'cash':
                    infoText.innerHTML = '💰 Cash accounts are used for physical money in the cash drawer. Transactions are recorded in real-time.';
                    break;
                case 'bank':
                    infoText.innerHTML = '🏦 Bank accounts track money in your business bank account. Include account number for reference.';
                    break;
                case 'mobile_money':
                    infoText.innerHTML = '📱 Mobile money accounts track funds in mobile payment systems like MTN Mobile Money or Airtel Money.';
                    break;
                case 'petty_cash':
                    infoText.innerHTML = '💵 Petty cash accounts are for small, everyday expenses. Set a reasonable initial balance.';
                    break;
            }
            infoBox.style.display = 'block';
        }
        
        // Form validation
        document.getElementById('accountForm').addEventListener('submit', function(e) {
            const accountName = document.getElementById('accountName').value.trim();
            const accountType = document.getElementById('accountType').value;
            
            if (!accountName) {
                e.preventDefault();
                alert('Please enter an account name');
                return false;
            }
            if (!accountType) {
                e.preventDefault();
                alert('Please select an account type');
                return false;
            }
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        });
        
        // Close modal on outside click
        window.onclick = function(e) { 
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active'); 
            }
        }
        
        // Initialize
        updateTypeIcon();
    </script>
</body>
</html>