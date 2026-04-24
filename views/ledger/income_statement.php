<?php
// views/ledger/income_statement.php - Profit & Loss Statement
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate   = $_GET['end_date']   ?? date('Y-m-t');

$incomeStatement = [];
$totalRevenue    = 0;
$totalExpenses   = 0;
$netIncome       = 0;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. REVENUE & EXPENSES from account_ledger (double-entry) ─────────────
    $ledgerStmt = $conn->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(al.credit) - SUM(al.debit), 0) AS amount
        FROM accounts a
        JOIN account_ledger al ON al.account_id = a.id
            AND DATE(al.transaction_date) BETWEEN ? AND ?
        WHERE a.account_type IN ('revenue','expense')
        GROUP BY a.id
        HAVING ABS(amount) > 0
        ORDER BY a.account_type DESC, a.account_code
    ");
    $ledgerStmt->execute([$startDate, $endDate]);
    $ledgerItems = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ledgerItems as $row) {
        $amt = abs((float)$row['amount']);
        $incomeStatement[] = [
            'account_code' => $row['account_code'],
            'account_name' => $row['account_name'],
            'account_type' => $row['account_type'],
            'amount'       => $amt,
            'source'       => 'journal'
        ];
        if ($row['account_type'] === 'revenue') $totalRevenue += $amt;
        else                                    $totalExpenses += $amt;
    }

    // ── 2. INVOICE REVENUE (paid invoices in period) ───────────────────────
    try {
        $invRev = $conn->prepare("
            SELECT COALESCE(SUM(amount_paid), 0) AS amount
            FROM invoices
            WHERE payment_status IN ('paid','partial')
              AND DATE(payment_date) BETWEEN ? AND ?
              AND status != 'cancelled'
        ");
        $invRev->execute([$startDate, $endDate]);
        $invAmount = (float)$invRev->fetchColumn();

        if ($invAmount > 0) {
            // Check if already captured via account_code 4000/revenue
            $alreadyCaptured = array_filter($incomeStatement, fn($i) => $i['account_type'] === 'revenue' && $i['source'] === 'journal');
            if (empty($alreadyCaptured)) {
                $incomeStatement[] = [
                    'account_code' => '4000',
                    'account_name' => 'Service Revenue (Invoices)',
                    'account_type' => 'revenue',
                    'amount'       => $invAmount,
                    'source'       => 'invoices'
                ];
                $totalRevenue += $invAmount;
            }
        }
    } catch (PDOException $e) {}

    // ── 3. DEBTOR PAYMENTS received (cash collections in period) ────────────
    try {
        $drPayments = $conn->prepare("
            SELECT COALESCE(SUM(al.debit), 0) AS amount
            FROM account_ledger al
            JOIN accounts a ON al.account_id = a.id
            WHERE al.reference_type = 'debtor_payment'
              AND DATE(al.transaction_date) BETWEEN ? AND ?
              AND a.account_code IN ('1010','1020','1030','1040')
        ");
        $drPayments->execute([$startDate, $endDate]);
        $drPaid = (float)$drPayments->fetchColumn();
        // Debtor payments are asset increases — they don't add to P&L directly.
        // Already captured via invoice revenue above or journal entries.
    } catch (PDOException $e) {}

    // ── 4. CREDITOR PAYMENTS made (expenses paid out in period) ─────────────
    try {
        $crPayments = $conn->prepare("
            SELECT COALESCE(SUM(al.credit), 0) AS amount
            FROM account_ledger al
            JOIN accounts a ON al.account_id = a.id
            WHERE al.reference_type = 'creditor_payment'
              AND DATE(al.transaction_date) BETWEEN ? AND ?
              AND a.account_code IN ('1010','1020','1030','1040')
        ");
        $crPayments->execute([$startDate, $endDate]);
        $crPaid = (float)$crPayments->fetchColumn();

        // ── Pull creditor expenses by TYPE — only expense-type purchases hit P&L ──
        // Asset/inventory purchases (purchase, tool, equipment, furniture, vehicle) go
        // to the Balance Sheet, not P&L. Only services, consumables, utilities, rent, other
        // are income-statement expenses.
        $expenseTypes = ['consumable', 'service', 'utility', 'rent', 'other'];
        $assetTypes   = ['purchase', 'tool', 'equipment', 'furniture', 'vehicle', 'loan'];

        // Expense-type creditors in period
        $crExpense = $conn->prepare("
            SELECT reference_type, COALESCE(SUM(amount_owed), 0) AS amount
            FROM creditors
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND reference_type IN ('consumable','service','utility','rent','other')
            GROUP BY reference_type
        ");
        $crExpense->execute([$startDate, $endDate]);
        $crExpenseRows = $crExpense->fetchAll(PDO::FETCH_ASSOC);

        $expenseCodeMap = [
            'consumable' => ['5200', 'Purchases – Consumables'],
            'service'    => ['5100', 'General Expenses (Services)'],
            'utility'    => ['5500', 'Utilities Expense'],
            'rent'       => ['5400', 'Rent Expense'],
            'other'      => ['5100', 'General Expenses'],
        ];
        foreach ($crExpenseRows as $er) {
            if ((float)$er['amount'] <= 0) continue;
            [$code, $name] = $expenseCodeMap[$er['reference_type']] ?? ['5100', 'General Expenses'];
            // Skip if already captured via journal entries
            $alreadyCaptured = array_filter($incomeStatement, fn($i) => $i['account_code'] === $code && $i['source'] === 'journal');
            if (empty($alreadyCaptured)) {
                $incomeStatement[] = [
                    'account_code' => $code,
                    'account_name' => $name . ' (Creditors)',
                    'account_type' => 'expense',
                    'amount'       => (float)$er['amount'],
                    'source'       => 'creditors'
                ];
                $totalExpenses += (float)$er['amount'];
            }
        }

        // Inventory / stock purchases → COGS when sold (show as COGS if sold in period)
        // Here we show stock purchases as a separate info line if not yet captured in journal
        $crInventory = $conn->prepare("
            SELECT COALESCE(SUM(amount_owed), 0) AS amount
            FROM creditors
            WHERE DATE(created_at) BETWEEN ? AND ?
              AND reference_type = 'purchase'
        ");
        $crInventory->execute([$startDate, $endDate]);
        $invPurchased = (float)$crInventory->fetchColumn();
        if ($invPurchased > 0) {
            $has5000 = !empty(array_filter($incomeStatement, fn($i) => $i['account_code'] === '5000'));
            if (!$has5000) {
                $incomeStatement[] = [
                    'account_code' => '5000',
                    'account_name' => 'Cost of Goods Sold / Stock Purchased',
                    'account_type' => 'expense',
                    'amount'       => $invPurchased,
                    'source'       => 'creditors'
                ];
                $totalExpenses += $invPurchased;
            }
        }
    } catch (PDOException $e) {}

    // ── 5. CASH TRANSACTIONS (income/expense from cash_transactions) ──────────
    try {
        $cashTx = $conn->prepare("
            SELECT transaction_type, COALESCE(SUM(amount),0) AS amount, category
            FROM cash_transactions
            WHERE DATE(transaction_date) BETWEEN ? AND ?
            GROUP BY transaction_type, category
        ");
        $cashTx->execute([$startDate, $endDate]);
        $cashRows = $cashTx->fetchAll(PDO::FETCH_ASSOC);

        $cashIncome  = 0;
        $cashExpense = 0;
        foreach ($cashRows as $row) {
            if ($row['transaction_type'] === 'income')  $cashIncome  += (float)$row['amount'];
            if ($row['transaction_type'] === 'expense') $cashExpense += (float)$row['amount'];
        }

        // Only add if NOT already captured by journal entries
        $hasJournalRevenue = !empty(array_filter($incomeStatement, fn($i) => $i['account_type'] === 'revenue' && $i['source'] === 'journal'));
        if (!$hasJournalRevenue && $cashIncome > 0) {
            $incomeStatement[] = [
                'account_code' => '4100',
                'account_name' => 'Cash Income (Transactions)',
                'account_type' => 'revenue',
                'amount'       => $cashIncome,
                'source'       => 'cash'
            ];
            $totalRevenue += $cashIncome;
        }

        $hasJournalExpense = !empty(array_filter($incomeStatement, fn($i) => $i['account_type'] === 'expense' && $i['source'] === 'journal'));
        if (!$hasJournalExpense && $cashExpense > 0) {
            $incomeStatement[] = [
                'account_code' => '6100',
                'account_name' => 'Cash Expenses (Transactions)',
                'account_type' => 'expense',
                'amount'       => $cashExpense,
                'source'       => 'cash'
            ];
            $totalExpenses += $cashExpense;
        }
    } catch (PDOException $e) {}

    // ── 6. ASSET PURCHASES as capital expenditure (non-credit only, cash payments) ──
    // Note: credit purchases appear as AP/Creditors, not P&L expense
    // Cash-paid asset purchases reduce cash but increase fixed assets (BS items),
    // so they only hit P&L if expensed directly (consumable tools / small items)
    try {
        $assetExpenseStmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount),0) AS amount, COUNT(*) AS cnt
            FROM asset_purchases
            WHERE payment_method != 'credit'
              AND DATE(purchase_date) BETWEEN ? AND ?
        ");
        $assetExpenseStmt->execute([$startDate, $endDate]);
        $assetExpRow = $assetExpenseStmt->fetch(PDO::FETCH_ASSOC);
        $assetExpAmt = (float)($assetExpRow['amount'] ?? 0);

        if ($assetExpAmt > 0) {
            // Only add if not already in journal entries under 5300
            $has5300 = !empty(array_filter($incomeStatement, fn($i) => $i['account_code'] === '5300'));
            if (!$has5300) {
                $incomeStatement[] = [
                    'account_code' => '5300',
                    'account_name' => 'Asset & Equipment Purchases (' . ($assetExpRow['cnt']) . ' items)',
                    'account_type' => 'expense',
                    'amount'       => $assetExpAmt,
                    'source'       => 'asset_purchases'
                ];
                $totalExpenses += $assetExpAmt;
            }
        }
    } catch (PDOException $e) {}

    $netIncome = $totalRevenue - $totalExpenses;

    // Sort: revenue first, then expense, alphabetically within each
    usort($incomeStatement, function($a, $b) {
        if ($a['account_type'] !== $b['account_type'])
            return $a['account_type'] === 'revenue' ? -1 : 1;
        return strcmp($a['account_code'], $b['account_code']);
    });

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income Statement | SAVANT MOTORS</title>
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
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Filter Bar */
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

        /* Report Card */
        .report-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .report-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }
        .report-header h2 { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .report-header p { font-size: 0.7rem; opacity: 0.9; margin-top: 0.25rem; }
        .report-body { padding: 1.5rem; }

        /* Income Statement Table */
        .statement-table { width: 100%; border-collapse: collapse; }
        .statement-table th {
            background: var(--bg-light);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.7rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        .statement-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .statement-table tr:hover { background: var(--bg-light); }
        .text-right { text-align: right; }
        .amount-positive { color: var(--success); font-weight: 700; }
        .amount-negative { color: var(--danger); font-weight: 700; }
        .section-header {
            background: var(--bg-light);
            font-weight: 700;
            border-top: 2px solid var(--border);
        }
        .section-header td { padding: 0.75rem 1rem; font-weight: 700; }
        .total-row { background: var(--bg-light); font-weight: 700; border-top: 2px solid var(--border); }
        .net-income-row { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .net-income-row td { padding: 1rem; font-weight: 800; font-size: 1rem; }

        /* Chart Container */
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .filter-bar { flex-direction: column; }
            .statement-table { font-size: 0.75rem; }
            .statement-table td, .statement-table th { padding: 0.5rem; }
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
            <a href="trial_balance.php" class="menu-item">⚖️ Trial Balance</a>
            <a href="income_statement.php" class="menu-item active">📈 Income Statement</a>
            <a href="balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
            <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
            <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
            <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
            <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
            <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-chart-line"></i> Income Statement</h1>
                <p>Live data — Invoices + Creditors + Cash Transactions + Journal Entries</p>
            </div>
            <div>
                <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-primary" onclick="exportReport()"><i class="fas fa-file-excel"></i> Export</button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" id="startDate" value="<?php echo $startDate; ?>" onchange="applyFilters()">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" id="endDate" value="<?php echo $endDate; ?>" onchange="applyFilters()">
            </div>
            <div class="filter-group">
                <button class="btn btn-secondary" onclick="resetFilters()">Reset to Current Month</button>
            </div>
        </div>

        <!-- Income Statement Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fas fa-chart-pie"></i> Revenue vs Expenses</h3>
            </div>
            <div class="chart-container">
                <canvas id="incomeChart"></canvas>
            </div>
        </div>

        <!-- Income Statement Table -->
        <div class="report-card">
            <div class="report-header">
                <h2><i class="fas fa-chart-line"></i> Income Statement</h2>
                <p>For the period <?php echo date('d M Y', strtotime($startDate)); ?> - <?php echo date('d M Y', strtotime($endDate)); ?></p>
            </div>
            <div class="report-body">
                <?php if (empty($incomeStatement)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-line"></i>
                    <p>No income statement data available for this period</p>
                    <p style="font-size: 0.7rem; margin-top: 0.5rem;">Add journal entries to generate income statement</p>
                    <a href="journal_entry.php" class="btn btn-primary" style="margin-top: 1rem;">Create Journal Entry</a>
                </div>
                <?php else: ?>
                <table class="statement-table">
                    <!-- Revenue Section -->
                    <thead>
                        <tr class="section-header">
                            <td colspan="3"><strong>REVENUE</strong></td>
                            <td class="text-right"></td>
                        </tr>
                        <tr>
                            <th>Account Code</th>
                            <th>Account Name</th>
                            <th>Type</th>
                            <th class="text-right">Amount (UGX)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayRevenue = array_filter($incomeStatement, function($item) {
                            return ($item['account_type'] ?? '') == 'revenue';
                        });
                        foreach ($displayRevenue as $item): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['account_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                            <td><?php echo ucfirst($item['account_type'] ?? ''); ?></td>
                            <td class="text-right amount-positive">UGX <?php echo number_format($item['amount'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>Total Revenue</strong></td>
                            <td class="text-right amount-positive"><strong>UGX <?php echo number_format($totalRevenue); ?></strong></td>
                        </tr>
                    </tbody>

                    <!-- Expenses Section -->
                    <thead>
                        <tr class="section-header">
                            <td colspan="3"><strong>EXPENSES</strong></td>
                            <td class="text-right"></td>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $displayExpenses = array_filter($incomeStatement, function($item) {
                            return ($item['account_type'] ?? '') == 'expense';
                        });
                        foreach ($displayExpenses as $item): 
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['account_code'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></td>
                            <td><?php echo ucfirst($item['account_type'] ?? ''); ?></td>
                            <td class="text-right amount-negative">UGX <?php echo number_format($item['amount'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>Total Expenses</strong></td>
                            <td class="text-right amount-negative"><strong>UGX <?php echo number_format($totalExpenses); ?></strong></td>
                        </tr>
                    </tbody>

                    <!-- Net Income -->
                    <tfoot>
                        <tr class="net-income-row">
                            <td colspan="3"><strong>NET INCOME (Profit/Loss)</strong></td>
                            <td class="text-right">
                                <strong>
                                    <?php if ($netIncome >= 0): ?>
                                    <i class="fas fa-arrow-up"></i> UGX <?php echo number_format($netIncome); ?>
                                    <?php else: ?>
                                    <i class="fas fa-arrow-down"></i> (UGX <?php echo number_format(abs($netIncome)); ?>)
                                    <?php endif; ?>
                                </strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Key Ratios -->
        <?php if (!empty($incomeStatement) && $totalRevenue > 0): ?>
        <div class="report-card">
            <div class="report-header">
                <h2><i class="fas fa-chart-simple"></i> Key Financial Ratios</h2>
            </div>
            <div class="report-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                    <div style="text-align: center; padding: 1rem; background: var(--bg-light); border-radius: 0.5rem;">
                        <div style="font-size: 1.5rem; font-weight: 800; color: var(--success);">
                            <?php echo round(($totalRevenue - $totalExpenses) / $totalRevenue * 100, 1); ?>%
                        </div>
                        <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem;">Profit Margin</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: var(--bg-light); border-radius: 0.5rem;">
                        <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary);">
                            <?php echo round(($totalExpenses / $totalRevenue) * 100, 1); ?>%
                        </div>
                        <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem;">Expense Ratio</div>
                    </div>
                    <div style="text-align: center; padding: 1rem; background: var(--bg-light); border-radius: 0.5rem;">
                        <div style="font-size: 1.5rem; font-weight: 800; color: <?php echo $netIncome >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo $netIncome >= 0 ? 'Profit' : 'Loss'; ?>
                        </div>
                        <div style="font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem;">Status</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Prepare chart data
        const revenueTotal = <?php echo $totalRevenue; ?>;
        const expenseTotal = <?php echo $totalExpenses; ?>;
        
        const ctx = document.getElementById('incomeChart').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Revenue', 'Expenses'],
                datasets: [{
                    data: [revenueTotal, expenseTotal],
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => ctx.label + ': UGX ' + ctx.raw.toLocaleString() } }
                }
            }
        });

        function applyFilters() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `income_statement.php?start_date=${startDate}&end_date=${endDate}`;
        }
        
        function resetFilters() {
            window.location.href = 'income_statement.php';
        }
        
        function exportReport() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `export.php?type=income_statement&start_date=${startDate}&end_date=${endDate}&format=csv`;
        }
    </script>
</body>
</html>