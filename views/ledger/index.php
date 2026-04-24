<?php
// views/ledger/index.php - Main General Ledger Dashboard
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$trialBalance        = [];
$recentEntries       = [];
$currentMonthTotals  = [['total_debits' => 0, 'total_credits' => 0]];
$accounts            = [];
$ledgerSummary       = ['total_debtors' => 0, 'total_creditors' => 0, 'total_cash' => 0, 'total_invoices_outstanding' => 0];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Trial Balance from account_ledger ─────────────────────────────────────
    try {
        $trialBalance = $conn->query("
            SELECT a.account_code, a.account_name, a.account_type,
                   COALESCE(SUM(al.debit),0)  AS total_debit,
                   COALESCE(SUM(al.credit),0) AS total_credit,
                   COALESCE(SUM(al.debit) - SUM(al.credit), 0) AS balance
            FROM accounts a
            LEFT JOIN account_ledger al ON al.account_id = a.id
            GROUP BY a.id
            ORDER BY a.account_code
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Current month debits/credits ──────────────────────────────────────────
    try {
        $mtd = $conn->query("
            SELECT COALESCE(SUM(debit),0) AS total_debits, COALESCE(SUM(credit),0) AS total_credits
            FROM account_ledger
            WHERE MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())
        ")->fetch(PDO::FETCH_ASSOC);
        $currentMonthTotals = [$mtd ?: ['total_debits' => 0, 'total_credits' => 0]];
    } catch (PDOException $e) {}

    // ── Recent journal entries ────────────────────────────────────────────────
    try {
        $recentEntries = $conn->query("
            SELECT je.id, je.entry_date, je.journal_number, je.description,
                   COALESCE(SUM(jl.debit),0) AS total_amount
            FROM journal_entries je
            LEFT JOIN journal_lines jl ON jl.journal_id = je.id
            GROUP BY je.id
            ORDER BY je.entry_date DESC, je.id DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback: pull from account_ledger grouped by date + description
        try {
            $recentEntries = $conn->query("
                SELECT id, transaction_date AS entry_date, description,
                       CONCAT('AL-', id) AS journal_number,
                       (debit + credit) AS total_amount
                FROM account_ledger
                ORDER BY transaction_date DESC, id DESC
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {}
    }

    // ── Connected ledger summaries ────────────────────────────────────────────
    // Debtors
    try {
        $ledgerSummary['total_debtors'] = (float)$conn->query(
            "SELECT COALESCE(SUM(balance),0) FROM debtors WHERE status != 'settled'"
        )->fetchColumn();
        $ledgerSummary['debtor_count']  = (int)$conn->query(
            "SELECT COUNT(*) FROM debtors WHERE status != 'settled'"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // Creditors
    try {
        $ledgerSummary['total_creditors'] = (float)$conn->query(
            "SELECT COALESCE(SUM(balance),0) FROM creditors WHERE status != 'settled'"
        )->fetchColumn();
        $ledgerSummary['creditor_count']  = (int)$conn->query(
            "SELECT COUNT(*) FROM creditors WHERE status != 'settled'"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // Cash accounts
    try {
        $ledgerSummary['total_cash'] = (float)$conn->query(
            "SELECT COALESCE(SUM(balance),0) FROM cash_accounts WHERE is_active=1"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // Unpaid invoices
    try {
        $ledgerSummary['total_invoices_outstanding'] = (float)$conn->query(
            "SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM invoices WHERE payment_status != 'paid' AND status != 'cancelled'"
        )->fetchColumn();
        $ledgerSummary['invoice_count'] = (int)$conn->query(
            "SELECT COUNT(*) FROM invoices WHERE payment_status != 'paid' AND status != 'cancelled'"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // Revenue this month from invoices
    try {
        $ledgerSummary['revenue_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(amount_paid),0) FROM invoices
             WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())
             AND payment_status IN ('paid','partial') AND status != 'cancelled'"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // ── Expense Monitoring ────────────────────────────────────────────────────
    $expenseStats = ['total_mtd' => 0, 'total_ytd' => 0, 'count_mtd' => 0, 'top_category' => 'N/A', 'pending_approval' => 0];
    $recentExpenses = [];
    try {
        $expenseStats['total_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'"
        )->fetchColumn();
        $expenseStats['count_mtd'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'"
        )->fetchColumn();
        $expenseStats['total_ytd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'"
        )->fetchColumn();
        $topCat = $conn->query(
            "SELECT category, SUM(amount) AS total FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled' GROUP BY category ORDER BY total DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($topCat) $expenseStats['top_category'] = $topCat['category'];
        $expenseStats['pending_approval'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses WHERE status = 'pending'"
        )->fetchColumn();
        $recentExpenses = $conn->query(
            "SELECT expense_date, category, description, amount, status FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Labour Utilization ────────────────────────────────────────────────────
    $labourStats = ['total_hours_mtd' => 0, 'billable_hours_mtd' => 0, 'total_technicians' => 0, 'avg_efficiency' => 0];
    $topTechs = [];
    try {
        $labourStats['total_hours_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(hours_worked),0) FROM labour_entries WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();
        $labourStats['billable_hours_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(billable_hours),0) FROM labour_entries WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();
        $labourStats['total_technicians'] = (int)$conn->query(
            "SELECT COUNT(DISTINCT technician_id) FROM labour_entries WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();
        if ($labourStats['total_hours_mtd'] > 0)
            $labourStats['avg_efficiency'] = round(($labourStats['billable_hours_mtd'] / $labourStats['total_hours_mtd']) * 100, 1);
        $topTechs = $conn->query(
            "SELECT technician_name, SUM(hours_worked) AS total_hours, SUM(billable_hours) AS billable FROM labour_entries WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE()) GROUP BY technician_name ORDER BY total_hours DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Job Costing & Invoicing ───────────────────────────────────────────────
    $jobStats = ['open_jobs' => 0, 'completed_mtd' => 0, 'total_job_revenue_mtd' => 0, 'avg_job_value' => 0, 'uninvoiced_jobs' => 0];
    $recentJobs = [];
    try {
        $jobStats['open_jobs'] = (int)$conn->query(
            "SELECT COUNT(*) FROM job_cards WHERE status NOT IN ('completed','cancelled','invoiced')"
        )->fetchColumn();
        $jobStats['completed_mtd'] = (int)$conn->query(
            "SELECT COUNT(*) FROM job_cards WHERE status IN ('completed','invoiced') AND MONTH(completion_date)=MONTH(CURDATE()) AND YEAR(completion_date)=YEAR(CURDATE())"
        )->fetchColumn();
        $jobStats['total_job_revenue_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(total_amount),0) FROM job_cards WHERE status = 'invoiced' AND MONTH(completion_date)=MONTH(CURDATE()) AND YEAR(completion_date)=YEAR(CURDATE())"
        )->fetchColumn();
        $jobStats['uninvoiced_jobs'] = (int)$conn->query(
            "SELECT COUNT(*) FROM job_cards WHERE status = 'completed'"
        )->fetchColumn();
        if ($jobStats['completed_mtd'] > 0)
            $jobStats['avg_job_value'] = round($jobStats['total_job_revenue_mtd'] / $jobStats['completed_mtd']);
        $recentJobs = $conn->query(
            "SELECT jc.job_number, jc.status, jc.total_amount, jc.completion_date, c.full_name AS customer_name, jc.vehicle_reg FROM job_cards jc LEFT JOIN customers c ON jc.customer_id = c.id ORDER BY jc.id DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);
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
    <title>General Ledger | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --bg-light: #f8fafc;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        /* Sidebar */
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

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
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
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
        .stat-value.income { color: var(--success); }
        .stat-value.expense { color: var(--danger); }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .action-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid var(--border);
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            display: block;
        }
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
        }
        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: 1rem;
            background: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-size: 1.3rem;
        }
        .action-title { font-size: 0.8rem; font-weight: 600; color: var(--dark); }
        .action-desc { font-size: 0.65rem; color: var(--gray); margin-top: 0.25rem; }

        /* Trial Balance Table */
        .card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.25rem;
            background: var(--bg-light);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: var(--bg-light);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.7rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.8rem; }
        tr:hover { background: var(--bg-light); }
        .text-right { text-align: right; }
        .amount-positive { color: var(--success); font-weight: 600; }
        .amount-negative { color: var(--danger); font-weight: 600; }
        .tfoot-row { background: var(--bg-light); font-weight: 700; border-top: 2px solid var(--border); }

        /* Recent Entries */
        .recent-list { padding: 0.5rem 0; }
        .entry-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.2s;
        }
        .entry-item:hover { background: var(--bg-light); cursor: pointer; }
        .entry-date { font-size: 0.7rem; color: var(--gray); min-width: 80px; }
        .entry-desc { flex: 1; font-size: 0.8rem; font-weight: 500; }
        .entry-amount { font-size: 0.8rem; font-weight: 600; }
        .entry-amount.debit { color: var(--success); }
        .entry-amount.credit { color: var(--danger); }

        /* Buttons */
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
        .btn-sm { padding: 0.3rem 0.6rem; font-size: 0.7rem; }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray);
        }
        .empty-state i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }


        /* ── New Sections ────────────────────────────────────────────────── */
        .section-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
        .section-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; }
        .metric-card {
            background:white; border-radius:1rem; padding:1rem 1.25rem;
            border:1px solid var(--border); display:flex; flex-direction:column; gap:0.25rem;
        }
        .metric-label { font-size:0.68rem; text-transform:uppercase; letter-spacing:.05em; color:var(--gray); }
        .metric-value { font-size:1.4rem; font-weight:800; }
        .metric-sub { font-size:0.7rem; color:var(--gray); }
        .badge {
            display:inline-block; padding:2px 8px; border-radius:999px;
            font-size:0.65rem; font-weight:600;
        }
        .badge-green  { background:#dcfce7; color:#166534; }
        .badge-red    { background:#fee2e2; color:#991b1b; }
        .badge-yellow { background:#fef9c3; color:#854d0e; }
        .badge-blue   { background:#dbeafe; color:#1e40af; }
        .badge-gray   { background:#f1f5f9; color:#475569; }
        .prog-bar-wrap { background:#e2e8f0; border-radius:999px; height:8px; overflow:hidden; margin-top:4px; }
        .prog-bar-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#3b82f6,#1e40af); }
        .section-header {
            font-size:1rem; font-weight:700; display:flex; align-items:center;
            gap:.5rem; padding:1rem 1.25rem; background:var(--bg-light);
            border-bottom:1px solid var(--border);
        }
        .section-header a { margin-left:auto; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>📚 SAVANT MOTORS</h2>
            <p>General Ledger System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">LEDGER</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="index.php" class="menu-item active">📚 General Ledger</a>
            <a href="trial_balance.php" class="menu-item">⚖️ Trial Balance</a>
            <a href="income_statement.php" class="menu-item">📈 Income Statement</a>
            <a href="balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
            <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
            <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
            <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
            <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
            <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
            <div class="sidebar-title" style="margin-top:1rem;">OPERATIONS</div>
            <a href="../ledger/expenses_index.php" class="menu-item">💸 Expense Monitoring</a>
            <a href="../ledger/labour_index.php" class="menu-item">🔧 Labour Utilization</a>
            <a href="../jobs/index.php" class="menu-item">🗂️ Job Costing &amp; Invoicing</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <?php if (isset($dbError)): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:0.75rem 1rem;border-radius:0.5rem;margin-bottom:1rem;border-left:3px solid #ef4444;">
            ⚠️ Database error: <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php endif; ?>
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-book" style="color: var(--primary-light);"></i> General Ledger</h1>
                <p>Double-entry accounting — connected to Debtors, Creditors, Invoices &amp; Cash</p>
            </div>
            <a href="journal_entry.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Journal Entry
            </a>
        </div>

        <!-- Journal Ledger MTD Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value income">UGX <?php echo number_format(($currentMonthTotals[0]['total_debits'] ?? 0)); ?></div>
                <div class="stat-label">Total Debits (MTD)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value expense">UGX <?php echo number_format(($currentMonthTotals[0]['total_credits'] ?? 0)); ?></div>
                <div class="stat-label">Total Credits (MTD)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:var(--warning);">UGX <?php echo number_format(abs(($currentMonthTotals[0]['total_debits'] ?? 0) - ($currentMonthTotals[0]['total_credits'] ?? 0))); ?></div>
                <div class="stat-label">Net Difference</div>
            </div>
        </div>

        <!-- Connected Ledger Summary -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;">
            <a href="../accounting/debtors.php" class="action-card" style="border-left:4px solid #10b981;">
                <div class="action-icon" style="background:#dcfce7;"><span style="font-size:1.3rem;">📥</span></div>
                <div class="action-title" style="color:#065f46;">Receivables (Debtors)</div>
                <div style="font-size:1rem;font-weight:800;color:#059669;margin-top:0.25rem;">
                    UGX <?php echo number_format($ledgerSummary['total_debtors']); ?>
                </div>
                <div class="action-desc"><?php echo $ledgerSummary['debtor_count'] ?? 0; ?> open records</div>
            </a>
            <a href="../accounting/creditors.php" class="action-card" style="border-left:4px solid #ef4444;">
                <div class="action-icon" style="background:#fee2e2;"><span style="font-size:1.3rem;">📤</span></div>
                <div class="action-title" style="color:#991b1b;">Payables (Creditors)</div>
                <div style="font-size:1rem;font-weight:800;color:#dc2626;margin-top:0.25rem;">
                    UGX <?php echo number_format($ledgerSummary['total_creditors']); ?>
                </div>
                <div class="action-desc"><?php echo $ledgerSummary['creditor_count'] ?? 0; ?> open records</div>
            </a>
            <a href="../accounting/invoices.php" class="action-card" style="border-left:4px solid #f59e0b;">
                <div class="action-icon" style="background:#fef9c3;"><span style="font-size:1.3rem;">🧾</span></div>
                <div class="action-title" style="color:#854d0e;">Unpaid Invoices</div>
                <div style="font-size:1rem;font-weight:800;color:#d97706;margin-top:0.25rem;">
                    UGX <?php echo number_format($ledgerSummary['total_invoices_outstanding']); ?>
                </div>
                <div class="action-desc"><?php echo $ledgerSummary['invoice_count'] ?? 0; ?> outstanding</div>
            </a>
            <a href="../cash/accounts.php" class="action-card" style="border-left:4px solid #2563eb;">
                <div class="action-icon" style="background:#dbeafe;"><span style="font-size:1.3rem;">🏦</span></div>
                <div class="action-title" style="color:#1e40af;">Cash &amp; Bank</div>
                <div style="font-size:1rem;font-weight:800;color:#1d4ed8;margin-top:0.25rem;">
                    UGX <?php echo number_format($ledgerSummary['total_cash']); ?>
                </div>
                <div class="action-desc">Revenue MTD: UGX <?php echo number_format($ledgerSummary['revenue_mtd'] ?? 0); ?></div>
            </a>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="journal_entry.php" class="action-card">
                <div class="action-icon" style="background: #dbeafe;"><i class="fas fa-pen-alt" style="color: var(--primary-light);"></i></div>
                <div class="action-title">Journal Entry</div>
                <div class="action-desc">Record new transaction</div>
            </a>
            <a href="trial_balance.php" class="action-card">
                <div class="action-icon" style="background: #dcfce7;"><i class="fas fa-balance-scale" style="color: var(--success);"></i></div>
                <div class="action-title">Trial Balance</div>
                <div class="action-desc">Verify accounting equation</div>
            </a>
            <a href="income_statement.php" class="action-card">
                <div class="action-icon" style="background: #fed7aa;"><i class="fas fa-chart-line" style="color: var(--warning);"></i></div>
                <div class="action-title">Income Statement</div>
                <div class="action-desc">Profit & Loss report</div>
            </a>
            <a href="balance_sheet.php" class="action-card">
                <div class="action-icon" style="background: #e0e7ff;"><i class="fas fa-chart-pie" style="color: #6366f1;"></i></div>
                <div class="action-title">Balance Sheet</div>
                <div class="action-desc">Assets = Liabilities + Equity</div>
            </a>
        </div>

        <!-- Trial Balance Section -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-balance-scale"></i> Trial Balance (Current Month)</h3>
                <a href="trial_balance.php" class="btn btn-secondary btn-sm">View Full Report →</a>
            </div>
            <div class="table-container">
                <?php if (empty($trialBalance)): ?>
                <div class="empty-state">
                    <i class="fas fa-database"></i>
                    <p>No trial balance data available</p>
                    <p style="font-size: 0.7rem;">Add some journal entries to see the trial balance</p>
                </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th class="text-right">Debit</th>
                            <th class="text-right">Credit</th>
                            <th class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayBalance = array_slice($trialBalance, 0, 10);
                        foreach ($displayBalance as $tb): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($tb['account_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($tb['account_name'] ?? ''); ?></td>
                            <td><?php echo ucfirst($tb['account_type'] ?? ''); ?></td>
                            <td class="text-right"><?php echo ($tb['total_debit'] ?? 0) > 0 ? 'UGX ' . number_format($tb['total_debit']) : '-'; ?></td>
                            <td class="text-right"><?php echo ($tb['total_credit'] ?? 0) > 0 ? 'UGX ' . number_format($tb['total_credit']) : '-'; ?></td>
                            <td class="text-right <?php echo ($tb['balance'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                UGX <?php echo number_format(abs($tb['balance'] ?? 0)); ?>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($trialBalance) > 10): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="color: var(--gray);">
                                <i class="fas fa-ellipsis-h"></i> And <?php echo count($trialBalance) - 10; ?> more accounts
                             </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="tfoot-row">
                            <td colspan="3"><strong>TOTAL</strong></td>
                            <td class="text-right"><strong>UGX <?php echo number_format(array_sum(array_column($trialBalance, 'total_debit'))); ?></strong></td>
                            <td class="text-right"><strong>UGX <?php echo number_format(array_sum(array_column($trialBalance, 'total_credit'))); ?></strong></td>
                            <td class="text-right"><strong>UGX <?php echo number_format(abs(array_sum(array_column($trialBalance, 'total_debit')) - array_sum(array_column($trialBalance, 'total_credit')))); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Journal Entries -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Journal Entries</h3>
                <a href="journal_history.php" class="btn btn-secondary btn-sm">View All →</a>
            </div>
            <div class="recent-list">
                <?php if (empty($recentEntries)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <p>No journal entries yet</p>
                    <a href="journal_entry.php" class="btn btn-primary" style="margin-top: 0.5rem;">Create First Entry</a>
                </div>
                <?php else: ?>
                <?php foreach ($recentEntries as $entry): ?>
                <div class="entry-item" onclick="window.location.href='view_journal.php?id=<?php echo $entry['id']; ?>'">
                    <div class="entry-date"><?php echo date('d M Y', strtotime($entry['entry_date'])); ?></div>
                    <div class="entry-desc">
                        <strong><?php echo htmlspecialchars($entry['journal_number'] ?? 'JN-' . $entry['id']); ?></strong>
                        <span style="color: var(--gray);"> - <?php echo htmlspecialchars(substr($entry['description'] ?? '', 0, 50)); ?>...</span>
                    </div>
                    <div class="entry-amount debit">UGX <?php echo number_format($entry['total_amount'] ?? 0); ?></div>
                    <div><i class="fas fa-chevron-right" style="color: var(--gray);"></i></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             EXPENSE MONITORING
        ══════════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="section-header">
                <span>💸 Expense Monitoring</span>
                <a href="../expenses/index.php" class="btn btn-secondary btn-sm">View All →</a>
            </div>
            <div style="padding:1rem 1.25rem;">
                <div class="section-grid-3" style="margin-bottom:1rem;">
                    <div class="metric-card" style="border-left:4px solid #ef4444;">
                        <div class="metric-label">Expenses MTD</div>
                        <div class="metric-value" style="color:#ef4444;">UGX <?php echo number_format($expenseStats['total_mtd']); ?></div>
                        <div class="metric-sub"><?php echo $expenseStats['count_mtd']; ?> transactions this month</div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #f59e0b;">
                        <div class="metric-label">Expenses YTD</div>
                        <div class="metric-value" style="color:#d97706;">UGX <?php echo number_format($expenseStats['total_ytd']); ?></div>
                        <div class="metric-sub">Top category: <?php echo htmlspecialchars($expenseStats['top_category']); ?></div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #8b5cf6;">
                        <div class="metric-label">Pending Approval</div>
                        <div class="metric-value" style="color:#7c3aed;"><?php echo $expenseStats['pending_approval']; ?></div>
                        <div class="metric-sub">Expenses awaiting approval</div>
                    </div>
                </div>
                <?php if (!empty($recentExpenses)): ?>
                <table>
                    <thead><tr>
                        <th>Date</th><th>Category</th><th>Description</th>
                        <th class="text-right">Amount (UGX)</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($recentExpenses as $exp): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                        <td><?php echo htmlspecialchars($exp['category'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars(substr($exp['description'] ?? '—', 0, 40)); ?></td>
                        <td class="text-right" style="font-weight:600;color:#ef4444;"><?php echo number_format($exp['amount']); ?></td>
                        <td><?php
                            $s = $exp['status'] ?? 'unknown';
                            $cls = $s==='approved' ? 'badge-green' : ($s==='pending' ? 'badge-yellow' : ($s==='rejected' ? 'badge-red' : 'badge-gray'));
                            echo '<span class="badge '.$cls.'">'.ucfirst($s).'</span>';
                        ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-receipt"></i><p>No expense records found</p>
                    <a href="../ledger/add_expense.php" class="btn btn-primary" style="margin-top:.5rem;">Record Expense</a></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             LABOUR UTILIZATION
        ══════════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="section-header">
                <span>🔧 Labour Utilization</span>
                <a href="../labour/index.php" class="btn btn-secondary btn-sm">View All →</a>
            </div>
            <div style="padding:1rem 1.25rem;">
                <div class="section-grid-3" style="margin-bottom:1rem;">
                    <div class="metric-card" style="border-left:4px solid #3b82f6;">
                        <div class="metric-label">Total Hours (MTD)</div>
                        <div class="metric-value" style="color:#1d4ed8;"><?php echo number_format($labourStats['total_hours_mtd'],1); ?> hrs</div>
                        <div class="metric-sub"><?php echo $labourStats['total_technicians']; ?> active technicians</div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #10b981;">
                        <div class="metric-label">Billable Hours (MTD)</div>
                        <div class="metric-value" style="color:#059669;"><?php echo number_format($labourStats['billable_hours_mtd'],1); ?> hrs</div>
                        <div class="metric-sub">Of <?php echo number_format($labourStats['total_hours_mtd'],1); ?> total logged</div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #f59e0b;">
                        <div class="metric-label">Efficiency Rate</div>
                        <div class="metric-value" style="color:<?php echo $labourStats['avg_efficiency'] >= 75 ? '#059669' : ($labourStats['avg_efficiency'] >= 50 ? '#d97706' : '#dc2626'); ?>;">
                            <?php echo $labourStats['avg_efficiency']; ?>%
                        </div>
                        <div class="prog-bar-wrap"><div class="prog-bar-fill" style="width:<?php echo min($labourStats['avg_efficiency'],100); ?>%;"></div></div>
                    </div>
                </div>
                <?php if (!empty($topTechs)): ?>
                <table>
                    <thead><tr>
                        <th>Technician</th><th class="text-right">Total Hours</th>
                        <th class="text-right">Billable Hours</th><th>Utilization</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($topTechs as $tech):
                        $eff = $tech['total_hours'] > 0 ? round(($tech['billable'] / $tech['total_hours']) * 100) : 0;
                        $cls = $eff >= 75 ? 'badge-green' : ($eff >= 50 ? 'badge-yellow' : 'badge-red');
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($tech['technician_name']); ?></td>
                        <td class="text-right"><?php echo number_format($tech['total_hours'],1); ?> hrs</td>
                        <td class="text-right"><?php echo number_format($tech['billable'],1); ?> hrs</td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo $eff; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-hard-hat"></i><p>No labour entries this month</p>
                    <a href="../ledger/add.php" class="btn btn-primary" style="margin-top:.5rem;">Log Labour</a></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             JOB COSTING & INVOICING
        ══════════════════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="section-header">
                <span>🗂️ Job Costing &amp; Invoicing</span>
                <a href="../jobs/index.php" class="btn btn-secondary btn-sm">View All →</a>
            </div>
            <div style="padding:1rem 1.25rem;">
                <div class="section-grid-3" style="margin-bottom:1rem;">
                    <div class="metric-card" style="border-left:4px solid #0ea5e9;">
                        <div class="metric-label">Open Jobs</div>
                        <div class="metric-value" style="color:#0284c7;"><?php echo $jobStats['open_jobs']; ?></div>
                        <div class="metric-sub">Jobs currently in progress</div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #10b981;">
                        <div class="metric-label">Revenue MTD (Invoiced)</div>
                        <div class="metric-value" style="color:#059669;">UGX <?php echo number_format($jobStats['total_job_revenue_mtd']); ?></div>
                        <div class="metric-sub"><?php echo $jobStats['completed_mtd']; ?> jobs completed · avg UGX <?php echo number_format($jobStats['avg_job_value']); ?></div>
                    </div>
                    <div class="metric-card" style="border-left:4px solid #f59e0b;">
                        <div class="metric-label">Awaiting Invoice</div>
                        <div class="metric-value" style="color:#d97706;"><?php echo $jobStats['uninvoiced_jobs']; ?></div>
                        <div class="metric-sub">Completed but not yet invoiced</div>
                    </div>
                </div>
                <?php if (!empty($recentJobs)): ?>
                <table>
                    <thead><tr>
                        <th>Job #</th><th>Customer</th><th>Vehicle Reg</th>
                        <th>Completion</th><th class="text-right">Total (UGX)</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($recentJobs as $job):
                        $s = $job['status'] ?? 'open';
                        $cls = $s==='invoiced' ? 'badge-green' : ($s==='completed' ? 'badge-blue' : ($s==='cancelled' ? 'badge-red' : 'badge-yellow'));
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($job['job_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($job['customer_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars(strtoupper($job['vehicle_reg'] ?? '—')); ?></td>
                        <td><?php echo !empty($job['completion_date']) ? date('d/m/Y', strtotime($job['completion_date'])) : '—'; ?></td>
                        <td class="text-right" style="font-weight:600;"><?php echo $job['total_amount'] > 0 ? number_format($job['total_amount']) : '—'; ?></td>
                        <td><span class="badge <?php echo $cls; ?>"><?php echo ucfirst($s); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No job records found</p>
                    <a href="../jobs/create.php" class="btn btn-primary" style="margin-top:.5rem;">Create Job Card</a></div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</body>
</html>