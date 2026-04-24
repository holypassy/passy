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
    </div>
</body>
</html>