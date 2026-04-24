<?php
// views/ledger/balance_sheet.php - Balance Sheet Statement
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

$assets = [];
$liabilities = [];
$equity = [];
$totalAssets = 0;
$totalLiabilities = 0;
$totalEquity = 0;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. ACCOUNTS LEDGER (existing double-entry accounts) ───────────────────
    $ledgerRows = $conn->prepare("
        SELECT a.account_code, a.account_name, a.account_type,
               COALESCE(SUM(al.debit),0)  AS total_debit,
               COALESCE(SUM(al.credit),0) AS total_credit,
               a.balance
        FROM accounts a
        LEFT JOIN account_ledger al ON al.account_id = a.id
            AND DATE(al.transaction_date) <= ?
        GROUP BY a.id
        ORDER BY a.account_code
    ");
    $ledgerRows->execute([$asOfDate]);
    $ledgerAccounts = $ledgerRows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ledgerAccounts as $row) {
        $type = $row['account_type'];
        $bal  = (float)$row['balance'];
        if ($bal == 0) continue;
        $entry = ['account_code' => $row['account_code'], 'account_name' => $row['account_name'], 'balance' => $bal];
        if ($type === 'asset')     $assets[]      = $entry;
        elseif ($type === 'liability') $liabilities[] = $entry;
        elseif ($type === 'equity')    $equity[]      = $entry;
    }

    // ── 2. CASH ACCOUNTS (from cash_accounts table) ────────────────────────────
    try {
        $cashRows = $conn->query("
            SELECT account_name, account_type AS sub_type, balance
            FROM cash_accounts WHERE is_active=1 AND balance != 0
            ORDER BY account_name
        ")->fetchAll(PDO::FETCH_ASSOC);
        $cashTotal = 0;
        foreach ($cashRows as $c) $cashTotal += (float)$c['balance'];

        // Only add if not already captured by account_code '1010'-'1040'
        $existingCodes = array_column($assets, 'account_code');
        if (!in_array('1010', $existingCodes) && $cashTotal > 0) {
            foreach ($cashRows as $c) {
                $assets[] = [
                    'account_code' => '10xx',
                    'account_name' => $c['account_name'] . ' (Cash)',
                    'balance'      => (float)$c['balance']
                ];
            }
        }
    } catch (PDOException $e) {}

    // ── 3. DEBTORS → Accounts Receivable (Asset) ──────────────────────────────
    try {
        $drTotal = $conn->query("SELECT COALESCE(SUM(balance),0) FROM debtors WHERE status!='settled'")->fetchColumn();
        $cvTotal = $conn->query("SELECT COALESCE(SUM(amount_owed),0) FROM debtor_company_vehicles")->fetchColumn();
        $arBalance = (float)$drTotal + (float)$cvTotal;

        // Merge into existing AR (1200) or add new row
        $arIdx = null;
        foreach ($assets as $i => $a) { if ($a['account_code'] === '1200') { $arIdx = $i; break; } }
        if ($arIdx !== null) {
            $assets[$arIdx]['balance'] = max($assets[$arIdx]['balance'], $arBalance);
        } elseif ($arBalance > 0) {
            $assets[] = ['account_code' => '1200', 'account_name' => 'Accounts Receivable (Debtors)', 'balance' => $arBalance];
        }
    } catch (PDOException $e) {}

    // ── 3b. INVENTORY from stock purchases (creditor type = 'purchase') ───────
    try {
        // First check accounts table (populated by our updated creditors.php)
        $invAcct = $conn->query("SELECT COALESCE(balance,0) FROM accounts WHERE account_code='1300'")->fetchColumn();
        $invAcct = (float)($invAcct ?? 0);

        // Also sum directly from creditors as fallback
        $invCred = $conn->query("
            SELECT COALESCE(SUM(amount_owed),0) FROM creditors WHERE reference_type='purchase'
        ")->fetchColumn();
        $invBalance = max($invAcct, (float)$invCred);

        $invIdx = null;
        foreach ($assets as $i => $a) { if ($a['account_code'] === '1300') { $invIdx = $i; break; } }
        if ($invIdx !== null) {
            $assets[$invIdx]['balance'] = max($assets[$invIdx]['balance'], $invBalance);
        } elseif ($invBalance > 0) {
            $assets[] = ['account_code' => '1300', 'account_name' => 'Inventory / Stock', 'balance' => $invBalance];
        }
    } catch (PDOException $e) {}

    // ── 4. UNPAID INVOICES → also part of receivables ─────────────────────────
    try {
        $invOutstanding = $conn->query("
            SELECT COALESCE(SUM(total_amount - amount_paid),0)
            FROM invoices WHERE payment_status != 'paid' AND status != 'cancelled'
        ")->fetchColumn();
        if ((float)$invOutstanding > 0) {
            // Fold into AR row if AR already exists, else add separately
            $arIdx = null;
            foreach ($assets as $i => $a) { if ($a['account_code'] === '1200') { $arIdx = $i; break; } }
            if ($arIdx !== null) {
                // Already included via debtors — avoid double-count: only add if distinct
                // (debtor records come from invoiced amounts, so don't double-add)
            } else {
                $assets[] = ['account_code' => '1201', 'account_name' => 'Unpaid Invoices Receivable', 'balance' => (float)$invOutstanding];
            }
        }
    } catch (PDOException $e) {}

    // ── 5. CREDITORS → Accounts Payable (Liability) ───────────────────────────
    try {
        $apTotal = $conn->query("SELECT COALESCE(SUM(balance),0) FROM creditors WHERE status!='settled'")->fetchColumn();
        $apBalance = (float)$apTotal;

        $apIdx = null;
        foreach ($liabilities as $i => $l) { if ($l['account_code'] === '2000') { $apIdx = $i; break; } }
        if ($apIdx !== null) {
            $liabilities[$apIdx]['balance'] = max($liabilities[$apIdx]['balance'], $apBalance);
        } elseif ($apBalance > 0) {
            $liabilities[] = ['account_code' => '2000', 'account_name' => 'Accounts Payable (Creditors)', 'balance' => $apBalance];
        }
    } catch (PDOException $e) {}

    // ── 6. FIXED ASSETS from asset_purchases AND accounts table ──────────────
    try {
        $fixedAssetGroups = $conn->query("
            SELECT account_code,
                   CASE asset_type
                       WHEN 'tool'      THEN 'Workshop Tools & Equipment'
                       WHEN 'equipment' THEN 'Workshop Equipment'
                       WHEN 'furniture' THEN 'Furniture & Fittings'
                       WHEN 'vehicle'   THEN 'Motor Vehicles'
                       ELSE 'Other Workshop Assets'
                   END AS asset_name,
                   COALESCE(SUM(total_amount),0) AS balance
            FROM asset_purchases
            WHERE purchase_date <= '$asOfDate'
            GROUP BY asset_type, account_code
            HAVING balance > 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        $existingCodes = array_column($assets, 'account_code');
        foreach ($fixedAssetGroups as $fa) {
            $idx = array_search($fa['account_code'], $existingCodes);
            if ($idx !== false) {
                $assets[$idx]['balance'] = max($assets[$idx]['balance'], (float)$fa['balance']);
            } else {
                $assets[]         = ['account_code' => $fa['account_code'], 'account_name' => $fa['asset_name'] . ' (Fixed)', 'balance' => (float)$fa['balance']];
                $existingCodes[]  = $fa['account_code'];
            }
        }

        // Also pull fixed asset accounts populated by creditors (reference_type in tool/equipment/furniture/vehicle)
        $fixedFromCreditors = [
            ['1500', 'Fixed Assets – Tools & Equipment',    'tool'],
            ['1510', 'Fixed Assets – Workshop Equipment',   'equipment'],
            ['1520', 'Fixed Assets – Furniture & Fittings', 'furniture'],
            ['1530', 'Fixed Assets – Motor Vehicles',       'vehicle'],
        ];
        $existingCodes = array_column($assets, 'account_code');
        foreach ($fixedFromCreditors as [$code, $name, $type]) {
            // Sum from accounts table (where creditors.php debits)
            $acctBal = (float)($conn->query("SELECT COALESCE(balance,0) FROM accounts WHERE account_code='$code'")->fetchColumn() ?? 0);
            // Sum from creditors table as fallback
            $credBal = (float)($conn->query("SELECT COALESCE(SUM(amount_owed),0) FROM creditors WHERE reference_type='$type'")->fetchColumn() ?? 0);
            $bal = max($acctBal, $credBal);
            if ($bal <= 0) continue;
            $idx = array_search($code, $existingCodes);
            if ($idx !== false) {
                $assets[$idx]['balance'] = max($assets[$idx]['balance'], $bal);
            } else {
                $assets[]       = ['account_code' => $code, 'account_name' => $name, 'balance' => $bal];
                $existingCodes[] = $code;
            }
        }

        // Asset purchase credit side — uncleared AP from credit purchases
        $apCredit = $conn->query("
            SELECT COALESCE(SUM(total_amount),0) FROM asset_purchases
            WHERE payment_method='credit' AND purchase_date <= '$asOfDate'
        ")->fetchColumn();
        if ((float)$apCredit > 0) {
            $apIdx2 = null;
            foreach ($liabilities as $i => $l) { if ($l['account_code'] === '2000') { $apIdx2 = $i; break; } }
            if ($apIdx2 !== null) {
                $liabilities[$apIdx2]['balance'] = max($liabilities[$apIdx2]['balance'], (float)$apCredit);
            } elseif ($apBalance <= 0) {
                $liabilities[] = ['account_code' => '2000', 'account_name' => 'Accounts Payable (Asset Purchases)', 'balance' => (float)$apCredit];
            }
        }
    } catch (PDOException $e) {}

    // ── 6. TOTALS ─────────────────────────────────────────────────────────────
    $totalAssets      = array_sum(array_column($assets, 'balance'));
    $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
    $totalEquity      = array_sum(array_column($equity, 'balance'));

    // If no explicit equity accounts, derive from accounting equation
    if ($totalEquity == 0 && ($totalAssets - $totalLiabilities) != 0) {
        $derived = $totalAssets - $totalLiabilities;
        $equity[] = ['account_code' => '3000', 'account_name' => "Owner's Equity (Derived)", 'balance' => $derived];
        $totalEquity = $derived;
    }

    // Sort each section by account_code
    usort($assets,      fn($a,$b) => strcmp($a['account_code'], $b['account_code']));
    usort($liabilities, fn($a,$b) => strcmp($a['account_code'], $b['account_code']));
    usort($equity,      fn($a,$b) => strcmp($a['account_code'], $b['account_code']));

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Sheet | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .filter-group input { width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid var(--border); border-radius: 0.5rem; font-size: 0.85rem; }

        /* Balance Sheet Grid */
        .balance-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        .balance-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .balance-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }
        .balance-header h2 { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; }
        .balance-header p { font-size: 0.7rem; opacity: 0.9; margin-top: 0.25rem; }
        .balance-body { padding: 1rem; }

        /* Balance Sheet Table */
        .balance-table { width: 100%; border-collapse: collapse; }
        .balance-table th {
            background: var(--bg-light);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 700;
            font-size: 0.7rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        .balance-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .balance-table tr:hover { background: var(--bg-light); }
        .text-right { text-align: right; }
        .amount-positive { color: var(--success); font-weight: 700; }
        .section-header {
            background: var(--bg-light);
            font-weight: 700;
            border-top: 2px solid var(--border);
        }
        .total-row { background: var(--bg-light); font-weight: 700; border-top: 2px solid var(--border); }
        .equation-row {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            margin-top: 1rem;
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
        }
        .equation-row .check { font-size: 1.5rem; font-weight: 800; }

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
            padding: 2rem;
            color: var(--gray);
        }
        .empty-state i { font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .filter-bar { flex-direction: column; }
            .balance-grid { grid-template-columns: 1fr; }
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
            <a href="income_statement.php" class="menu-item">📈 Income Statement</a>
            <a href="balance_sheet.php" class="menu-item active">📊 Balance Sheet</a>
            <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
            <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
            <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
            <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
            <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
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
                <h1><i class="fas fa-chart-pie"></i> Balance Sheet</h1>
                <p>Live data — Cash Accounts + Debtors + Creditors + Invoices + Journal Entries</p>
            </div>
            <div>
                <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-primary" onclick="exportReport()"><i class="fas fa-file-excel"></i> Export</button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>As of Date</label>
                <input type="date" id="asOfDate" value="<?php echo $asOfDate; ?>" onchange="applyFilters()">
            </div>
            <div class="filter-group">
                <button class="btn btn-secondary" onclick="resetFilters()">Reset to Today</button>
            </div>
        </div>

        <!-- Balance Sheet Grid -->
        <div class="balance-grid">
            <!-- Assets Column -->
            <div class="balance-card">
                <div class="balance-header">
                    <h2><i class="fas fa-money-bill-wave"></i> ASSETS</h2>
                    <p>What the business owns</p>
                </div>
                <div class="balance-body">
                    <?php if (empty($assets)): ?>
                    <div class="empty-state">
                        <i class="fas fa-database"></i>
                        <p>No asset data available</p>
                    </div>
                    <?php else: ?>
                    <table class="balance-table">
                        <thead>
                            <tr>
                                <th>Account Code</th>
                                <th>Account Name</th>
                                <th class="text-right">Balance (UGX)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $asset): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($asset['account_code'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($asset['account_name'] ?? ''); ?></td>
                                <td class="text-right amount-positive">UGX <?php echo number_format($asset['balance'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="2"><strong>Total Assets</strong></td>
                                <td class="text-right amount-positive"><strong>UGX <?php echo number_format($totalAssets); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Liabilities & Equity Column -->
            <div>
                <!-- Liabilities -->
                <div class="balance-card" style="margin-bottom: 1.5rem;">
                    <div class="balance-header">
                        <h2><i class="fas fa-hand-holding-usd"></i> LIABILITIES</h2>
                        <p>What the business owes</p>
                    </div>
                    <div class="balance-body">
                        <?php if (empty($liabilities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-database"></i>
                            <p>No liability data available</p>
                        </div>
                        <?php else: ?>
                        <table class="balance-table">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th class="text-right">Balance (UGX)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($liabilities as $liability): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($liability['account_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($liability['account_name'] ?? ''); ?></td>
                                    <td class="text-right amount-positive">UGX <?php echo number_format($liability['balance'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="2"><strong>Total Liabilities</strong></td>
                                    <td class="text-right amount-positive"><strong>UGX <?php echo number_format($totalLiabilities); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Equity -->
                <div class="balance-card">
                    <div class="balance-header">
                        <h2><i class="fas fa-building"></i> EQUITY</h2>
                        <p>Owner's interest in the business</p>
                    </div>
                    <div class="balance-body">
                        <?php if (empty($equity)): ?>
                        <div class="empty-state">
                            <i class="fas fa-database"></i>
                            <p>No equity data available</p>
                        </div>
                        <?php else: ?>
                        <table class="balance-table">
                            <thead>
                                <tr>
                                    <th>Account Code</th>
                                    <th>Account Name</th>
                                    <th class="text-right">Balance (UGX)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equity as $eq): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($eq['account_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($eq['account_name'] ?? ''); ?></td>
                                    <td class="text-right amount-positive">UGX <?php echo number_format($eq['balance'] ?? 0); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="2"><strong>Total Equity</strong></td>
                                    <td class="text-right amount-positive"><strong>UGX <?php echo number_format($totalEquity); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accounting Equation Verification -->
        <div class="equation-row">
            <div class="check">
                <?php
                $difference = $totalAssets - ($totalLiabilities + $totalEquity);
                if (abs($difference) < 1): ?>
                    <i class="fas fa-check-circle"></i> BALANCED: Assets = Liabilities + Equity
                <?php else: ?>
                    <i class="fas fa-exclamation-triangle"></i> OUT OF BALANCE: Difference of UGX <?php echo number_format(abs($difference)); ?>
                <?php endif; ?>
            </div>
            <div style="display: flex; justify-content: center; gap: 2rem; margin-top: 0.5rem; font-size: 0.8rem;">
                <span>Assets: UGX <?php echo number_format($totalAssets); ?></span>
                <span>Liabilities: UGX <?php echo number_format($totalLiabilities); ?></span>
                <span>Equity: UGX <?php echo number_format($totalEquity); ?></span>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const asOfDate = document.getElementById('asOfDate').value;
            window.location.href = `balance_sheet.php?as_of_date=${asOfDate}`;
        }
        
        function resetFilters() {
            window.location.href = 'balance_sheet.php';
        }
        
        function exportReport() {
            const asOfDate = document.getElementById('asOfDate').value;
            window.location.href = `export.php?type=balance_sheet&as_of_date=${asOfDate}&format=csv`;
        }
    </script>
</body>
</html>