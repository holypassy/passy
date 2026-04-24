<?php
// views/cash/reports.php - Cash Flow Reports
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

$transactions  = [];
$summary       = ['total_income' => 0, 'total_expense' => 0, 'net_cash' => 0];
$categoryData  = [];
$accountBreakdown = [];
$debtorPayments   = 0;
$creditorPayments = 0;
$invoiceRevenue   = 0;
$monthlyTrend     = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. CASH TRANSACTIONS ─────────────────────────────────────────────────
    try {
        $txStmt = $conn->prepare("
            SELECT ct.*, ca.account_name, ca.account_type AS account_sub_type
            FROM cash_transactions ct
            LEFT JOIN cash_accounts ca ON ct.account_id = ca.id
            WHERE DATE(ct.transaction_date) BETWEEN ? AND ?
            ORDER BY ct.transaction_date DESC, ct.id DESC
        ");
        $txStmt->execute([$startDate, $endDate]);
        $cashTransactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

        // summary from cash_transactions
        $smStmt = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type='income'  THEN amount ELSE 0 END),0) AS total_income,
                COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) AS total_expense
            FROM cash_transactions
            WHERE DATE(transaction_date) BETWEEN ? AND ?
        ");
        $smStmt->execute([$startDate, $endDate]);
        $cashSummary = $smStmt->fetch(PDO::FETCH_ASSOC);

        // category breakdown
        $catStmt = $conn->prepare("
            SELECT transaction_type, category,
                   COALESCE(SUM(amount),0) AS total_amount,
                   COUNT(*) AS count
            FROM cash_transactions
            WHERE DATE(transaction_date) BETWEEN ? AND ?
            GROUP BY transaction_type, category
            ORDER BY total_amount DESC
        ");
        $catStmt->execute([$startDate, $endDate]);
        $categoryData = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // per-account breakdown
        $accStmt = $conn->prepare("
            SELECT ca.account_name, ca.account_type,
                   COALESCE(SUM(CASE WHEN ct.transaction_type='income'  THEN ct.amount ELSE 0 END),0) AS income,
                   COALESCE(SUM(CASE WHEN ct.transaction_type='expense' THEN ct.amount ELSE 0 END),0) AS expense
            FROM cash_transactions ct
            JOIN cash_accounts ca ON ct.account_id = ca.id
            WHERE DATE(ct.transaction_date) BETWEEN ? AND ?
            GROUP BY ca.id
        ");
        $accStmt->execute([$startDate, $endDate]);
        $accountBreakdown = $accStmt->fetchAll(PDO::FETCH_ASSOC);

        $summary['total_income']  = (float)($cashSummary['total_income']  ?? 0);
        $summary['total_expense'] = (float)($cashSummary['total_expense'] ?? 0);

    } catch (PDOException $e) { $cashTransactions = []; }

    // ── 2. INVOICE REVENUE (paid in period) ──────────────────────────────────
    try {
        $invStmt = $conn->prepare("
            SELECT i.invoice_number AS reference_no,
                   i.payment_date  AS transaction_date,
                   i.amount_paid   AS amount,
                   c.full_name     AS account_name,
                   i.vehicle_reg,
                   i.payment_method
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE payment_status IN ('paid','partial')
              AND DATE(i.payment_date) BETWEEN ? AND ?
              AND i.status != 'cancelled'
            ORDER BY i.payment_date DESC
        ");
        $invStmt->execute([$startDate, $endDate]);
        $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($invRows as $r) {
            $invoiceRevenue += (float)$r['amount'];
            $cashTransactions[] = [
                'transaction_date' => $r['transaction_date'],
                'transaction_type' => 'income',
                'category'         => 'Invoice Payment',
                'account_name'     => $r['account_name'] . ($r['vehicle_reg'] ? ' — ' . $r['vehicle_reg'] : ''),
                'amount'           => (float)$r['amount'],
                'reference_no'     => $r['invoice_number'] ?? $r['reference_no'],
                'description'      => 'Invoice paid via ' . ($r['payment_method'] ?? 'N/A'),
            ];
        }
        $summary['total_income'] += $invoiceRevenue;

        // add to category data
        if ($invoiceRevenue > 0) {
            $categoryData[] = ['transaction_type' => 'income', 'category' => 'Invoice Payments', 'total_amount' => $invoiceRevenue, 'count' => count($invRows)];
        }
    } catch (PDOException $e) {}

    // ── 3. DEBTOR PAYMENTS received ───────────────────────────────────────────
    try {
        $drStmt = $conn->prepare("
            SELECT al.transaction_date, al.description, al.debit AS amount,
                   a.account_name, al.reference_id
            FROM account_ledger al
            JOIN accounts a ON al.account_id = a.id
            WHERE al.reference_type = 'debtor_payment'
              AND DATE(al.transaction_date) BETWEEN ? AND ?
              AND a.account_code IN ('1010','1020','1030','1040')
        ");
        $drStmt->execute([$startDate, $endDate]);
        $drRows = $drStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($drRows as $r) {
            $debtorPayments += (float)$r['amount'];
            $cashTransactions[] = [
                'transaction_date' => $r['transaction_date'],
                'transaction_type' => 'income',
                'category'         => 'Debtor Payment',
                'account_name'     => $r['account_name'],
                'amount'           => (float)$r['amount'],
                'reference_no'     => 'DR-' . $r['reference_id'],
                'description'      => $r['description'],
            ];
        }
        // Don't double-add to income — debtor payments usually already appear in invoices
    } catch (PDOException $e) {}

    // ── 4. CREDITOR PAYMENTS made ─────────────────────────────────────────────
    try {
        $crStmt = $conn->prepare("
            SELECT al.transaction_date, al.description, al.credit AS amount,
                   a.account_name, al.reference_id
            FROM account_ledger al
            JOIN accounts a ON al.account_id = a.id
            WHERE al.reference_type = 'creditor_payment'
              AND DATE(al.transaction_date) BETWEEN ? AND ?
              AND a.account_code IN ('1010','1020','1030','1040')
        ");
        $crStmt->execute([$startDate, $endDate]);
        $crRows = $crStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($crRows as $r) {
            $creditorPayments += (float)$r['amount'];
            $cashTransactions[] = [
                'transaction_date' => $r['transaction_date'],
                'transaction_type' => 'expense',
                'category'         => 'Creditor Payment',
                'account_name'     => $r['account_name'],
                'amount'           => (float)$r['amount'],
                'reference_no'     => 'CR-' . $r['reference_id'],
                'description'      => $r['description'],
            ];
        }
        $summary['total_expense'] += $creditorPayments;

        if ($creditorPayments > 0) {
            $categoryData[] = ['transaction_type' => 'expense', 'category' => 'Creditor Payments', 'total_amount' => $creditorPayments, 'count' => count($crRows)];
        }
    } catch (PDOException $e) {}

    // ── 5. NET & SORT ─────────────────────────────────────────────────────────
    $summary['net_cash'] = $summary['total_income'] - $summary['total_expense'];

    // Sort all transactions by date desc
    usort($cashTransactions, fn($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']));
    $transactions = $cashTransactions;

    // ── 6. MONTHLY TREND (last 6 months) ─────────────────────────────────────
    try {
        $trendStmt = $conn->query("
            SELECT DATE_FORMAT(transaction_date,'%Y-%m') AS month,
                   COALESCE(SUM(CASE WHEN transaction_type='income'  THEN amount ELSE 0 END),0) AS income,
                   COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) AS expense
            FROM cash_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month ASC
        ");
        $monthlyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Reports | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .filter-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
        }
        .chart-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }
        .chart-header h3 { font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .chart-container { height: 300px; }
        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: var(--bg-light);
            padding: 0.8rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .badge-income { background: #dcfce7; color: #166534; }
        .badge-expense { background: #fee2e2; color: #991b1b; }
        .amount-income { color: var(--success); font-weight: 700; }
        .amount-expense { color: var(--danger); font-weight: 700; }
        .summary-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
            text-align: center;
        }
        .summary-value { font-size: 1.8rem; font-weight: 700; }
        .summary-label { font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem; text-transform: uppercase; }
        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .filter-bar { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header"><h2>💰 SAVANT MOTORS</h2><p>Cash Management System</p></div>
        <div class="sidebar-menu">
            <div class="sidebar-title">CASH</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="index.php" class="menu-item">💰 Cash Management</a>
            <a href="accounts.php" class="menu-item">🏦 Accounts</a>
            <a href="reports.php" class="menu-item active">📈 Reports</a>
            <div class="sidebar-title" style="margin-top:1rem;">CONNECTED</div>
            <a href="../accounting/debtors.php" class="menu-item">📥 Debtors</a>
            <a href="../accounting/creditors.php" class="menu-item">📤 Creditors</a>
            <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
            <a href="../ledger/income_statement.php" class="menu-item">📈 Income Statement</a>
            <a href="../ledger/balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
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
                <h1><i class="fas fa-chart-bar"></i> Cash Flow Reports</h1>
                <p>Live data — Cash Transactions + Invoices + Debtors + Creditors</p>
            </div>
            <div>
                <button class="btn btn-primary" onclick="exportReport('csv')"><i class="fas fa-file-excel"></i> Export CSV</button>
                <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <div class="filter-bar">
            <div class="filter-group"><label>From Date</label><input type="date" id="dateFrom" value="<?php echo htmlspecialchars($startDate); ?>" onchange="applyFilters()"></div>
            <div class="filter-group"><label>To Date</label><input type="date" id="dateTo" value="<?php echo htmlspecialchars($endDate); ?>" onchange="applyFilters()"></div>
            <div class="filter-group" style="flex:0;">
                <label>&nbsp;</label>
                <button class="btn btn-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
            <div class="summary-card">
                <div class="summary-value amount-income">UGX <?php echo number_format($summary['total_income']); ?></div>
                <div class="summary-label">💰 Total Income</div>
                <?php if ($invoiceRevenue > 0): ?>
                <div style="font-size:0.72rem;color:var(--gray);margin-top:0.4rem;">incl. UGX <?php echo number_format($invoiceRevenue); ?> from invoices</div>
                <?php endif; ?>
            </div>
            <div class="summary-card">
                <div class="summary-value amount-expense">UGX <?php echo number_format($summary['total_expense']); ?></div>
                <div class="summary-label">💸 Total Expenses</div>
                <?php if ($creditorPayments > 0): ?>
                <div style="font-size:0.72rem;color:var(--gray);margin-top:0.4rem;">incl. UGX <?php echo number_format($creditorPayments); ?> to creditors</div>
                <?php endif; ?>
            </div>
            <div class="summary-card">
                <div class="summary-value <?php echo $summary['net_cash'] >= 0 ? 'amount-income' : 'amount-expense'; ?>">
                    <?php echo $summary['net_cash'] >= 0 ? '' : '-'; ?>UGX <?php echo number_format(abs($summary['net_cash'])); ?>
                </div>
                <div class="summary-label">📊 Net Cash Flow</div>
                <div style="font-size:0.72rem;margin-top:0.4rem;color:<?php echo $summary['net_cash'] >= 0 ? '#059669' : '#dc2626'; ?>;">
                    <?php echo $summary['net_cash'] >= 0 ? '✅ Positive' : '⚠️ Negative'; ?>
                </div>
            </div>
        </div>

        <!-- Connected Ledger Snapshot -->
        <?php if ($debtorPayments > 0 || $creditorPayments > 0 || $invoiceRevenue > 0): ?>
        <div style="background:white;border-radius:1rem;border:1px solid var(--border);padding:1rem 1.5rem;margin-bottom:1.5rem;">
            <div style="font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--gray);margin-bottom:0.75rem;">Connected Ledger Activity — <?php echo date('d M Y', strtotime($startDate)); ?> to <?php echo date('d M Y', strtotime($endDate)); ?></div>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
                <div style="text-align:center;min-width:120px;">
                    <div style="font-size:1.1rem;font-weight:800;color:#059669;">UGX <?php echo number_format($invoiceRevenue); ?></div>
                    <div style="font-size:0.7rem;color:var(--gray);">🧾 Invoice Revenue</div>
                    <a href="../accounting/invoices.php" style="font-size:0.65rem;color:var(--primary);">View →</a>
                </div>
                <div style="border-left:1px solid var(--border);"></div>
                <div style="text-align:center;min-width:120px;">
                    <div style="font-size:1.1rem;font-weight:800;color:#059669;">UGX <?php echo number_format($debtorPayments); ?></div>
                    <div style="font-size:0.7rem;color:var(--gray);">📥 Debtor Collections</div>
                    <a href="../accounting/debtors.php" style="font-size:0.65rem;color:var(--primary);">View →</a>
                </div>
                <div style="border-left:1px solid var(--border);"></div>
                <div style="text-align:center;min-width:120px;">
                    <div style="font-size:1.1rem;font-weight:800;color:#dc2626;">UGX <?php echo number_format($creditorPayments); ?></div>
                    <div style="font-size:0.7rem;color:var(--gray);">📤 Creditor Payments</div>
                    <a href="../accounting/creditors.php" style="font-size:0.65rem;color:var(--primary);">View →</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts Row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Income by Category</h3></div>
                <div class="chart-container"><canvas id="incomeChart"></canvas></div>
            </div>
            <div class="chart-card">
                <div class="chart-header"><h3><i class="fas fa-chart-pie"></i> Expenses by Category</h3></div>
                <div class="chart-container"><canvas id="expenseChart"></canvas></div>
            </div>
        </div>

        <!-- Monthly Trend -->
        <?php if (!empty($monthlyTrend)): ?>
        <div class="chart-card" style="margin-bottom:1.5rem;">
            <div class="chart-header"><h3><i class="fas fa-chart-line"></i> 6-Month Cash Flow Trend</h3></div>
            <div class="chart-container"><canvas id="trendChart"></canvas></div>
        </div>
        <?php endif; ?>

        <!-- Account Breakdown -->
        <?php if (!empty($accountBreakdown)): ?>
        <div class="table-container" style="margin-bottom:1.5rem;">
            <div style="padding:0.75rem 1rem;background:var(--bg-light);border-bottom:1px solid var(--border);font-weight:700;font-size:0.85rem;">
                🏦 By Account
            </div>
            <table>
                <thead><tr><th>Account</th><th>Type</th><th class="text-right">Income</th><th class="text-right">Expense</th><th class="text-right">Net</th></tr></thead>
                <tbody>
                <?php foreach ($accountBreakdown as $acc): $net = $acc['income'] - $acc['expense']; ?>
                <tr>
                    <td><?php echo htmlspecialchars($acc['account_name']); ?></td>
                    <td><?php echo ucfirst(str_replace('_',' ',$acc['account_type'] ?? '')); ?></td>
                    <td class="text-right amount-income">UGX <?php echo number_format($acc['income']); ?></td>
                    <td class="text-right amount-expense">UGX <?php echo number_format($acc['expense']); ?></td>
                    <td class="text-right <?php echo $net >= 0 ? 'amount-income' : 'amount-expense'; ?>">
                        <?php echo $net >= 0 ? '' : '-'; ?>UGX <?php echo number_format(abs($net)); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Full Transaction Table -->
        <div class="table-container">
            <div style="padding:0.75rem 1rem;background:var(--bg-light);border-bottom:1px solid var(--border);font-weight:700;font-size:0.85rem;display:flex;justify-content:space-between;align-items:center;">
                <span>📋 All Transactions (<?php echo count($transactions); ?>)</span>
                <input type="text" id="txSearch" placeholder="🔍 Search..." onkeyup="filterTable()" style="padding:0.3rem 0.6rem;border:1px solid var(--border);border-radius:0.4rem;font-size:0.8rem;width:200px;">
            </div>
            <table id="txTable">
                <thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Account / Customer</th><th>Amount</th><th>Reference</th><th>Description</th></tr></thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--gray);">No transactions found for this period</td></tr>
                    <?php else: foreach ($transactions as $trans): ?>
                    <tr>
                        <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                        <td><span class="badge badge-<?php echo $trans['transaction_type']; ?>"><?php echo ucfirst($trans['transaction_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($trans['category'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($trans['account_name'] ?? '-'); ?></td>
                        <td class="<?php echo $trans['transaction_type'] == 'income' ? 'amount-income' : 'amount-expense'; ?>">
                            <?php echo $trans['transaction_type'] == 'income' ? '+' : '-'; ?> UGX <?php echo number_format($trans['amount']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($trans['reference_no'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($trans['description'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const categoryData   = <?php echo json_encode($categoryData); ?>;
        const monthlyTrend   = <?php echo json_encode($monthlyTrend); ?>;
        const incomeCategories  = categoryData.filter(c => c.transaction_type === 'income');
        const expenseCategories = categoryData.filter(c => c.transaction_type === 'expense');

        const colors = {
            income:  ['#10b981','#34d399','#6ee7b7','#a7f3d0','#d1fae5','#059669'],
            expense: ['#ef4444','#f87171','#fca5a5','#fecaca','#fee2e2','#dc2626'],
        };

        const tooltipFmt = (ctx) => `${ctx.label}: UGX ${ctx.raw.toLocaleString()}`;
        const chartOpts  = (pos='right') => ({
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: pos }, tooltip: { callbacks: { label: tooltipFmt } } }
        });

        if (incomeCategories.length) {
            new Chart(document.getElementById('incomeChart'), {
                type: 'pie',
                data: { labels: incomeCategories.map(c => c.category || 'Uncategorised'),
                        datasets: [{ data: incomeCategories.map(c => c.total_amount), backgroundColor: colors.income }] },
                options: chartOpts()
            });
        } else {
            document.getElementById('incomeChart').parentElement.innerHTML = '<p style="text-align:center;padding:2rem;color:#94a3b8;">No income data for period</p>';
        }

        if (expenseCategories.length) {
            new Chart(document.getElementById('expenseChart'), {
                type: 'pie',
                data: { labels: expenseCategories.map(c => c.category || 'Uncategorised'),
                        datasets: [{ data: expenseCategories.map(c => c.total_amount), backgroundColor: colors.expense }] },
                options: chartOpts()
            });
        } else {
            document.getElementById('expenseChart').parentElement.innerHTML = '<p style="text-align:center;padding:2rem;color:#94a3b8;">No expense data for period</p>';
        }

        // Monthly trend chart
        const trendEl = document.getElementById('trendChart');
        if (trendEl && monthlyTrend.length) {
            new Chart(trendEl, {
                type: 'bar',
                data: {
                    labels: monthlyTrend.map(m => {
                        const [y, mo] = m.month.split('-');
                        return new Date(y, mo - 1).toLocaleString('default', { month: 'short', year: '2-digit' });
                    }),
                    datasets: [
                        { label: 'Income',  data: monthlyTrend.map(m => m.income),  backgroundColor: '#10b981' },
                        { label: 'Expense', data: monthlyTrend.map(m => m.expense), backgroundColor: '#ef4444' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: UGX ${ctx.raw.toLocaleString()}` } } },
                    scales: { x: { stacked: false }, y: { ticks: { callback: v => 'UGX ' + Number(v).toLocaleString() } } }
                }
            });
        }

        function applyFilters() {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            window.location.href = `reports.php?start_date=${from}&end_date=${to}`;
        }
        function resetFilters() { window.location.href = 'reports.php'; }
        function exportReport(format) {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            window.location.href = `export.php?format=${format}&start_date=${from}&end_date=${to}`;
        }

        // Live table search
        function filterTable() {
            const q = document.getElementById('txSearch').value.toLowerCase();
            document.querySelectorAll('#txTable tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }
    </script>
</body>
</html>