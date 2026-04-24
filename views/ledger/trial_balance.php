<?php
// views/ledger/trial_balance.php - Trial Balance
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

$trialBalance      = [];
$totalDebits       = 0;
$totalCredits      = 0;
$isBalanced        = false;
$connectedSummary  = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. Journal / account_ledger entries ───────────────────────────────────
    try {
        $stmt = $conn->prepare("
            SELECT
                a.account_code,
                a.account_name,
                a.account_type,
                COALESCE(SUM(al.debit),  0) AS total_debit,
                COALESCE(SUM(al.credit), 0) AS total_credit,
                COALESCE(SUM(al.debit) - SUM(al.credit), 0) AS balance
            FROM accounts a
            LEFT JOIN account_ledger al ON al.account_id = a.id
                AND DATE(al.transaction_date) BETWEEN ? AND ?
            GROUP BY a.id
            ORDER BY a.account_code
        ");
        $stmt->execute([$startDate, $endDate]);
        $trialBalance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── 2. Ensure AR (1200) reflects debtors ──────────────────────────────────
    try {
        $drBalance = (float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM debtors WHERE status!='settled'")->fetchColumn();
        $drCount   = (int)$conn->query("SELECT COUNT(*) FROM debtors WHERE status!='settled'")->fetchColumn();
        $connectedSummary[] = [
            'code'  => '1200', 'name' => 'Accounts Receivable (Debtors)',
            'type'  => 'asset', 'balance' => $drBalance, 'count' => $drCount,
            'link'  => '../accounting/debtors.php', 'color' => '#059669', 'icon' => '📥'
        ];

        // Merge into trial balance
        $found = false;
        foreach ($trialBalance as &$row) {
            if ($row['account_code'] === '1200') {
                if ($row['total_debit'] == 0 && $row['total_credit'] == 0) {
                    $row['total_debit'] = $drBalance;
                    $row['balance']     = $drBalance;
                }
                $found = true; break;
            }
        } unset($row);
        if (!$found && $drBalance > 0) {
            $trialBalance[] = [
                'account_code' => '1200', 'account_name' => 'Accounts Receivable (Debtors)',
                'account_type' => 'asset', 'total_debit' => $drBalance,
                'total_credit' => 0, 'balance' => $drBalance
            ];
        }
    } catch (PDOException $e) {}

    // ── 3. Ensure AP (2000) reflects creditors ────────────────────────────────
    try {
        $crBalance = (float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM creditors WHERE status!='settled'")->fetchColumn();
        $crCount   = (int)$conn->query("SELECT COUNT(*) FROM creditors WHERE status!='settled'")->fetchColumn();
        $connectedSummary[] = [
            'code'  => '2000', 'name' => 'Accounts Payable (Creditors)',
            'type'  => 'liability', 'balance' => $crBalance, 'count' => $crCount,
            'link'  => '../accounting/creditors.php', 'color' => '#dc2626', 'icon' => '📤'
        ];

        $found = false;
        foreach ($trialBalance as &$row) {
            if ($row['account_code'] === '2000') {
                if ($row['total_debit'] == 0 && $row['total_credit'] == 0) {
                    $row['total_credit'] = $crBalance;
                    $row['balance']      = -$crBalance;
                }
                $found = true; break;
            }
        } unset($row);
        if (!$found && $crBalance > 0) {
            $trialBalance[] = [
                'account_code' => '2000', 'account_name' => 'Accounts Payable (Creditors)',
                'account_type' => 'liability', 'total_debit' => 0,
                'total_credit' => $crBalance, 'balance' => -$crBalance
            ];
        }
    } catch (PDOException $e) {}

    // ── 4. Cash accounts (1010-1040) ──────────────────────────────────────────
    try {
        $cashRows = $conn->query("SELECT account_name, balance FROM cash_accounts WHERE is_active=1 AND balance!=0")->fetchAll(PDO::FETCH_ASSOC);
        $cashTotal = array_sum(array_column($cashRows, 'balance'));
        $connectedSummary[] = [
            'code'  => '10xx', 'name' => 'Cash & Bank Accounts',
            'type'  => 'asset', 'balance' => $cashTotal, 'count' => count($cashRows),
            'link'  => '../cash/accounts.php', 'color' => '#2563eb', 'icon' => '🏦'
        ];

        // Merge with existing cash account codes
        $existingCodes = array_column($trialBalance, 'account_code');
        if (!in_array('1010', $existingCodes) && $cashTotal > 0) {
            foreach ($cashRows as $c) {
                $trialBalance[] = [
                    'account_code' => '10xx', 'account_name' => $c['account_name'],
                    'account_type' => 'asset', 'total_debit' => (float)$c['balance'],
                    'total_credit' => 0, 'balance' => (float)$c['balance']
                ];
            }
        }
    } catch (PDOException $e) {}

    // ── 4b. INVENTORY (1300) from stock purchases ─────────────────────────────
    try {
        // From accounts table (set by creditors.php journal entries)
        $invAcct = (float)($conn->query("SELECT COALESCE(balance,0) FROM accounts WHERE account_code='1300'")->fetchColumn() ?? 0);
        // Fallback: sum directly from creditors
        $invCred = (float)($conn->query("SELECT COALESCE(SUM(amount_owed),0) FROM creditors WHERE reference_type='purchase'")->fetchColumn() ?? 0);
        $invBalance = max($invAcct, $invCred);

        if ($invBalance > 0) {
            $connectedSummary[] = [
                'code'  => '1300', 'name' => 'Inventory / Stock',
                'type'  => 'asset', 'balance' => $invBalance, 'count' => null,
                'extra' => 'From stock purchases on credit',
                'link'  => '../accounting/creditors.php', 'color' => '#0369a1', 'icon' => '📦'
            ];
            $existingCodes = array_column($trialBalance, 'account_code');
            if (!in_array('1300', $existingCodes)) {
                $trialBalance[] = [
                    'account_code' => '1300', 'account_name' => 'Inventory / Stock',
                    'account_type' => 'asset', 'total_debit' => $invBalance,
                    'total_credit' => 0, 'balance' => $invBalance
                ];
            }
        }
    } catch (PDOException $e) {}

    // ── 4c. FIXED ASSETS from creditor purchases (tools/equipment/etc) ────────
    try {
        $fixedMap = [
            ['1500', 'Fixed Assets – Tools',      'tool',      '#7c3aed', '🔧'],
            ['1510', 'Fixed Assets – Equipment',  'equipment', '#7c3aed', '⚙️'],
            ['1520', 'Fixed Assets – Furniture',  'furniture', '#7c3aed', '🪑'],
            ['1530', 'Fixed Assets – Vehicles',   'vehicle',   '#7c3aed', '🚗'],
        ];
        $existingCodes = array_column($trialBalance, 'account_code');
        foreach ($fixedMap as [$code, $name, $type, $color, $icon]) {
            $acctBal = (float)($conn->query("SELECT COALESCE(balance,0) FROM accounts WHERE account_code='$code'")->fetchColumn() ?? 0);
            $credBal = (float)($conn->query("SELECT COALESCE(SUM(amount_owed),0) FROM creditors WHERE reference_type='$type'")->fetchColumn() ?? 0);
            $bal = max($acctBal, $credBal);
            if ($bal <= 0) continue;
            $connectedSummary[] = [
                'code' => $code, 'name' => $name, 'type' => 'asset',
                'balance' => $bal, 'count' => null, 'extra' => "Credit purchases – $type",
                'link' => '../accounting/creditors.php', 'color' => $color, 'icon' => $icon
            ];
            if (!in_array($code, $existingCodes)) {
                $trialBalance[] = [
                    'account_code' => $code, 'account_name' => $name,
                    'account_type' => 'asset', 'total_debit' => $bal,
                    'total_credit' => 0, 'balance' => $bal
                ];
                $existingCodes[] = $code;
            }
        }
    } catch (PDOException $e) {}

    // ── 5. Tools & Fixed Assets (1500–1530) ──────────────────────────────────
    try {
        $toolsValue = (float)$conn->query("
            SELECT COALESCE(SUM(COALESCE(quantity,1)*COALESCE(purchase_price,0)),0)
            FROM tools WHERE is_active=1 OR is_active IS NULL
        ")->fetchColumn();
        $toolsCount = (int)$conn->query("SELECT COUNT(*) FROM tools WHERE is_active=1 OR is_active IS NULL")->fetchColumn();

        // Check purchase records for a potentially higher value
        try {
            $purchaseTotal = (float)$conn->query("SELECT COALESCE(SUM(total_amount),0) FROM asset_purchases")->fetchColumn();
            $purchaseCount = (int)$conn->query("SELECT COUNT(*) FROM asset_purchases")->fetchColumn();
        } catch (PDOException $e2) { $purchaseTotal = 0; $purchaseCount = 0; }

        $fixedVal = max($toolsValue, $purchaseTotal);
        if ($fixedVal > 0) {
            $connectedSummary[] = [
                'code'  => '1500', 'name' => 'Tools & Fixed Assets',
                'type'  => 'asset', 'balance' => $fixedVal,
                'count' => $toolsCount,
                'extra' => $purchaseCount > 0 ? "$purchaseCount purchase records" : null,
                'link'  => '../tools/purchase_assets.php', 'color' => '#7c3aed', 'icon' => '🔧'
            ];
            // Merge into trial balance
            $existingCodes = array_column($trialBalance, 'account_code');
            if (!in_array('1500', $existingCodes) && $toolsValue > 0) {
                $trialBalance[] = [
                    'account_code' => '1500', 'account_name' => 'Tools & Equipment (Fixed Assets)',
                    'account_type' => 'asset', 'total_debit' => $toolsValue,
                    'total_credit' => 0, 'balance' => $toolsValue
                ];
            }
            // Add per-type purchase breakdown
            if ($purchaseTotal > 0) {
                try {
                    $byType = $conn->query("SELECT asset_type, COALESCE(SUM(total_amount),0) AS total FROM asset_purchases GROUP BY asset_type")->fetchAll(PDO::FETCH_ASSOC);
                    $codeMap = ['tool'=>'1500','equipment'=>'1510','furniture'=>'1520','other'=>'1530'];
                    $nameMap = ['tool'=>'Tools (Fixed Assets)','equipment'=>'Workshop Equipment','furniture'=>'Furniture & Fixtures','other'=>'Other Fixed Assets'];
                    foreach ($byType as $bt) {
                        $c = $codeMap[$bt['asset_type']] ?? '1530';
                        $n = $nameMap[$bt['asset_type']] ?? 'Fixed Assets';
                        if ((float)$bt['total'] > 0 && !in_array($c, array_column($trialBalance, 'account_code'))) {
                            $trialBalance[] = [
                                'account_code' => $c, 'account_name' => $n,
                                'account_type' => 'asset', 'total_debit' => (float)$bt['total'],
                                'total_credit' => 0, 'balance' => (float)$bt['total']
                            ];
                        }
                    }
                } catch (PDOException $e3) {}
            }
        }
    } catch (PDOException $e) {}

    // ── 6. Unpaid invoices → AR ───────────────────────────────────────────────
    try {
        $invOutstanding = (float)$conn->query(
            "SELECT COALESCE(SUM(total_amount-amount_paid),0) FROM invoices WHERE payment_status!='paid' AND status!='cancelled'"
        )->fetchColumn();
        $invCount = (int)$conn->query(
            "SELECT COUNT(*) FROM invoices WHERE payment_status!='paid' AND status!='cancelled'"
        )->fetchColumn();
        $invRevenue = (float)$conn->prepare(
            "SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_status IN ('paid','partial') AND status!='cancelled'"
        )->execute([$startDate, $endDate]) ? $conn->prepare(
            "SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_status IN ('paid','partial') AND status!='cancelled'"
        )->execute([$startDate, $endDate]) : 0;

        // Simpler revenue fetch
        $revStmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE DATE(payment_date) BETWEEN ? AND ? AND payment_status IN ('paid','partial') AND status!='cancelled'");
        $revStmt->execute([$startDate, $endDate]);
        $invRevenue = (float)$revStmt->fetchColumn();

        $connectedSummary[] = [
            'code'  => '1201', 'name' => 'Invoices Outstanding',
            'type'  => 'asset', 'balance' => $invOutstanding, 'count' => $invCount,
            'extra' => 'Collected in period: UGX ' . number_format($invRevenue),
            'link'  => '../invoices.php', 'color' => '#d97706', 'icon' => '🧾'
        ];

        // Add invoice revenue line if not already in journal
        $hasSalesAccount = !empty(array_filter($trialBalance, fn($r) => in_array($r['account_code'], ['4000','4100'])));
        if (!$hasSalesAccount && $invRevenue > 0) {
            $trialBalance[] = [
                'account_code' => '4000', 'account_name' => 'Service Revenue (Invoices)',
                'account_type' => 'revenue', 'total_debit' => 0,
                'total_credit' => $invRevenue, 'balance' => -$invRevenue
            ];
        }
    } catch (PDOException $e) {}

    // ── 6. Sort and compute totals ────────────────────────────────────────────
    usort($trialBalance, fn($a, $b) => strcmp($a['account_code'], $b['account_code']));

    // Remove zero rows
    $trialBalance = array_values(array_filter($trialBalance, fn($r) => $r['total_debit'] != 0 || $r['total_credit'] != 0));

    $totalDebits  = array_sum(array_column($trialBalance, 'total_debit'));
    $totalCredits = array_sum(array_column($trialBalance, 'total_credit'));
    $isBalanced   = abs($totalDebits - $totalCredits) < 1;

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// Type badge colours
$typeColors = [
    'asset'     => ['bg' => '#dbeafe', 'text' => '#1e40af'],
    'liability' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    'equity'    => ['bg' => '#e0e7ff', 'text' => '#4338ca'],
    'revenue'   => ['bg' => '#dcfce7', 'text' => '#166534'],
    'expense'   => ['bg' => '#fef9c3', 'text' => '#854d0e'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trial Balance | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; min-height: 100vh; }
        :root {
            --primary: #1e40af; --primary-light: #3b82f6;
            --success: #10b981; --danger: #ef4444;
            --border: #e2e8f0; --gray: #64748b;
            --dark: #0f172a; --bg-light: #f8fafc;
        }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 260px; height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem; display: flex; align-items: center; gap: 0.75rem;
            color: #0c4a6e; text-decoration: none; transition: all 0.2s;
            border-left: 3px solid transparent; font-size: 0.85rem; font-weight: 500;
        }
        .menu-item:hover, .menu-item.active { background: rgba(14,165,233,0.2); color: #0284c7; border-left-color: #0284c7; }
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }
        .top-bar {
            background: white; border-radius: 1rem; padding: 1rem 1.5rem;
            margin-bottom: 1.5rem; display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 1rem; border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }
        .filter-bar {
            background: white; border-radius: 1rem; padding: 1rem;
            margin-bottom: 1.5rem; border: 1px solid var(--border);
            display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label { display: block; font-size: 0.65rem; font-weight: 700; color: var(--gray); margin-bottom: 0.25rem; text-transform: uppercase; }
        .filter-group input { width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid var(--border); border-radius: 0.5rem; font-size: 0.85rem; }
        .connected-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .conn-card {
            background: white; border-radius: 1rem; border: 1px solid var(--border);
            padding: 1rem; text-decoration: none; display: block; transition: all 0.2s;
        }
        .conn-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .conn-card .cc-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
        .conn-card .cc-label { font-size: 0.65rem; color: var(--gray); text-transform: uppercase; font-weight: 700; }
        .conn-card .cc-amount { font-size: 1.1rem; font-weight: 800; margin-top: 0.15rem; }
        .conn-card .cc-count { font-size: 0.7rem; color: var(--gray); margin-top: 0.1rem; }
        .card { background: white; border-radius: 1rem; border: 1px solid var(--border); overflow: hidden; margin-bottom: 1.5rem; }
        .card-header {
            padding: 1rem 1.25rem; background: var(--bg-light);
            border-bottom: 1px solid var(--border); display: flex;
            justify-content: space-between; align-items: center;
        }
        .card-header h3 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: var(--bg-light); padding: 0.75rem 1rem; text-align: left;
            font-weight: 700; font-size: 0.7rem; color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        tr:hover td { background: #fafbff; }
        .text-right { text-align: right; }
        .amount-debit  { color: #1e40af; font-weight: 700; }
        .amount-credit { color: #059669; font-weight: 700; }
        .amount-bal-pos { color: #1e40af; font-weight: 700; }
        .amount-bal-neg { color: #dc2626; font-weight: 700; }
        .total-row td { background: #f8fafc; font-weight: 800; border-top: 2px solid var(--border); }
        .type-badge {
            display: inline-block; padding: 0.15rem 0.5rem;
            border-radius: 2rem; font-size: 0.6rem; font-weight: 700;
        }
        .balance-eq {
            border-radius: 1rem; padding: 1rem 1.5rem; text-align: center;
            font-weight: 800; font-size: 1rem; margin-bottom: 1.5rem;
        }
        .balance-eq.balanced   { background: linear-gradient(135deg,#dcfce7,#bbf7d0); color: #065f46; }
        .balance-eq.unbalanced { background: linear-gradient(135deg,#fee2e2,#fecaca); color: #991b1b; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.8rem; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; }
        .btn-primary   { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        @media (max-width: 900px) {
            .connected-grid { grid-template-columns: repeat(2,1fr); }
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
        }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header"><h2>📚 SAVANT MOTORS</h2><p>General Ledger System</p></div>
    <div class="sidebar-menu">
        <div class="sidebar-title">LEDGER</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="index.php" class="menu-item">📚 General Ledger</a>
        <a href="trial_balance.php" class="menu-item active">⚖️ Trial Balance</a>
        <a href="income_statement.php" class="menu-item">📈 Income Statement</a>
        <a href="balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
        <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
        <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
        <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
        <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
        <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
        <a href="../cash/reports.php" class="menu-item">📈 Cash Reports</a>
        <div style="margin-top:2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
    </div>
</div>

<div class="main-content">
    <?php if (isset($dbError)): ?>
    <div style="background:#fee2e2;color:#991b1b;padding:0.75rem 1rem;border-radius:0.5rem;margin-bottom:1rem;border-left:3px solid #ef4444;">
        ⚠️ <?php echo htmlspecialchars($dbError); ?>
    </div>
    <?php endif; ?>

    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-balance-scale"></i> Trial Balance</h1>
            <p>Live data — Journal Entries + Debtors + Creditors + Invoices + Cash Accounts</p>
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="balance_sheet.php" class="btn btn-primary"><i class="fas fa-chart-pie"></i> Balance Sheet</a>
            <a href="income_statement.php" class="btn btn-primary"><i class="fas fa-chart-line"></i> P&amp;L</a>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="filter-bar">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" id="startDate" value="<?php echo htmlspecialchars($startDate); ?>" onchange="applyFilters()">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" id="endDate" value="<?php echo htmlspecialchars($endDate); ?>" onchange="applyFilters()">
        </div>
        <div class="filter-group" style="flex:0;">
            <label>&nbsp;</label>
            <button class="btn btn-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> This Month</button>
        </div>
    </div>

    <!-- Connected Ledger Cards -->
    <div class="connected-grid">
        <?php foreach ($connectedSummary as $cs): ?>
        <a href="<?php echo $cs['link']; ?>" class="conn-card" style="border-left: 4px solid <?php echo $cs['color']; ?>;">
            <div class="cc-icon"><?php echo $cs['icon']; ?></div>
            <div class="cc-label"><?php echo htmlspecialchars($cs['name']); ?></div>
            <div class="cc-amount" style="color:<?php echo $cs['color']; ?>;">UGX <?php echo number_format($cs['balance']); ?></div>
            <div class="cc-count">
                <?php echo isset($cs['count']) ? $cs['count'] . ' records' : ''; ?>
                <?php echo isset($cs['extra']) ? '<br><span style="color:' . $cs['color'] . ';">' . htmlspecialchars($cs['extra']) . '</span>' : ''; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Balance check banner -->
    <div class="balance-eq <?php echo $isBalanced ? 'balanced' : 'unbalanced'; ?>">
        <?php if ($isBalanced): ?>
        ✅ BALANCED — Total Debits = Total Credits = UGX <?php echo number_format($totalDebits); ?>
        <?php else: ?>
        ⚠️ OUT OF BALANCE — Debits: UGX <?php echo number_format($totalDebits); ?> | Credits: UGX <?php echo number_format($totalCredits); ?> | Difference: UGX <?php echo number_format(abs($totalDebits - $totalCredits)); ?>
        <?php endif; ?>
    </div>

    <!-- Trial Balance Table -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-table"></i> Trial Balance — <?php echo date('d M Y', strtotime($startDate)); ?> to <?php echo date('d M Y', strtotime($endDate)); ?></h3>
            <input type="text" id="tbSearch" placeholder="🔍 Filter accounts..." onkeyup="filterTB()" style="padding:0.4rem 0.75rem;border:1.5px solid var(--border);border-radius:0.5rem;font-size:0.82rem;width:220px;">
        </div>
        <?php if (empty($trialBalance)): ?>
        <div style="text-align:center;padding:3rem;color:var(--gray);">
            <i class="fas fa-database" style="font-size:2rem;opacity:0.4;display:block;margin-bottom:0.75rem;"></i>
            <p>No journal entries found for this period.</p>
            <a href="journal_entry.php" class="btn btn-primary" style="margin-top:1rem;">Create Journal Entry</a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table id="tbTable">
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th>Type</th>
                    <th class="text-right">Debit (UGX)</th>
                    <th class="text-right">Credit (UGX)</th>
                    <th class="text-right">Balance (UGX)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trialBalance as $row):
                    $tc = $typeColors[$row['account_type']] ?? ['bg'=>'#f1f5f9','text'=>'#475569'];
                    $bal = (float)$row['balance'];
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['account_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['account_name']); ?></td>
                    <td>
                        <span class="type-badge" style="background:<?php echo $tc['bg']; ?>;color:<?php echo $tc['text']; ?>;">
                            <?php echo ucfirst($row['account_type']); ?>
                        </span>
                    </td>
                    <td class="text-right amount-debit">
                        <?php echo ($row['total_debit'] > 0) ? 'UGX ' . number_format($row['total_debit']) : '—'; ?>
                    </td>
                    <td class="text-right amount-credit">
                        <?php echo ($row['total_credit'] > 0) ? 'UGX ' . number_format($row['total_credit']) : '—'; ?>
                    </td>
                    <td class="text-right <?php echo $bal >= 0 ? 'amount-bal-pos' : 'amount-bal-neg'; ?>">
                        <?php echo $bal < 0 ? '(' : ''; ?>UGX <?php echo number_format(abs($bal)); ?><?php echo $bal < 0 ? ')' : ''; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3"><strong>TOTALS</strong></td>
                    <td class="text-right amount-debit"><strong>UGX <?php echo number_format($totalDebits); ?></strong></td>
                    <td class="text-right amount-credit"><strong>UGX <?php echo number_format($totalCredits); ?></strong></td>
                    <td class="text-right <?php echo $isBalanced ? 'amount-debit' : 'amount-bal-neg'; ?>">
                        <strong><?php echo $isBalanced ? '✅ Balanced' : 'UGX ' . number_format(abs($totalDebits - $totalCredits)); ?></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function applyFilters() {
        const s = document.getElementById('startDate').value;
        const e = document.getElementById('endDate').value;
        window.location.href = `trial_balance.php?start_date=${s}&end_date=${e}`;
    }
    function resetFilters() { window.location.href = 'trial_balance.php'; }

    function filterTB() {
        const q = document.getElementById('tbSearch').value.toLowerCase();
        document.querySelectorAll('#tbTable tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }
</script>
</body>
</html>
