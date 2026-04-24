<?php
// views/cash/index.php - Main Cash Management with Account Integration
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all active accounts for dropdown
    $accounts = $conn->query("
        SELECT id, account_name, account_type, balance 
        FROM cash_accounts 
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
    
    // Get filter parameters
    $account_filter = isset($_GET['account']) ? (int)$_GET['account'] : null;
    $type_filter = isset($_GET['type']) ? $_GET['type'] : null;
    $date_from = isset($_GET['from']) ? $_GET['from'] : null;
    $date_to = isset($_GET['to']) ? $_GET['to'] : null;
    
    // Build query for transactions with account filter
    $sql = "
        SELECT 
            ct.*,
            ca.account_name,
            ca.account_type,
            u.full_name as created_by_name
        FROM cash_transactions ct
        LEFT JOIN cash_accounts ca ON ct.account_id = ca.id
        LEFT JOIN users u ON ct.created_by = u.id
        WHERE 1=1
    ";
    $params = [];
    
    if ($account_filter) {
        $sql .= " AND ct.account_id = ?";
        $params[] = $account_filter;
    }
    if ($type_filter) {
        $sql .= " AND ct.transaction_type = ?";
        $params[] = $type_filter;
    }
    if ($date_from) {
        $sql .= " AND ct.transaction_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND ct.transaction_date <= ?";
        $params[] = $date_to;
    }
    
    $sql .= " ORDER BY ct.transaction_date DESC, ct.created_at DESC LIMIT 200";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary for current month
    $summaryStmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_cash
        FROM cash_transactions
        WHERE MONTH(transaction_date) = MONTH(CURDATE()) 
        AND YEAR(transaction_date) = YEAR(CURDATE())
    ");
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get account balances summary
    $balanceStmt = $conn->query("
        SELECT 
            COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
            COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
            COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
            COALESCE(SUM(balance), 0) as total_balance
        FROM cash_accounts
        WHERE is_active = 1
    ");
    $balances = $balanceStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get weekly trend
    $weeklyStmt = $conn->prepare("
        SELECT 
            DATE(transaction_date) as date,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
        FROM cash_transactions
        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(transaction_date)
        ORDER BY date ASC
    ");
    $weeklyStmt->execute();
    $weeklyData = $weeklyStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
    $accounts = [];
    $transactions = [];
    $summary = ['total_income' => 0, 'total_expense' => 0, 'net_cash' => 0];
    $balances = ['total_cash' => 0, 'total_bank' => 0, 'total_mobile' => 0, 'total_balance' => 0];
    $weeklyData = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Management | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; min-height: 100vh; }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --bg-light: #f8fafc;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
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
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }
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
        .stat-value.income { color: var(--success); }
        .stat-value.expense { color: var(--danger); }
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
        .chart-container { height: 250px; }
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
        tr:hover { background: var(--bg-light); }
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
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
        }
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
        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
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
            <a href="index.php" class="menu-item active">💰 Cash Management</a>
            <a href="accounts.php" class="menu-item">🏦 Accounts</a>
            <a href="reports.php" class="menu-item">📈 Reports</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-money-bill-wave"></i> Cash Management</h1>
                <p>Track income, expenses, and account balances</p>
            </div>
            <div>
                <a href="accounts.php" class="btn btn-secondary">
                    <i class="fas fa-university"></i> Manage Accounts
                </a>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus-circle"></i> Add Transaction
                </button>
            </div>
        </div>

        <!-- Account Balance Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">UGX <?php echo number_format($balances['total_cash'] ?? 0); ?></div>
                <div class="stat-label">Cash on Hand</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">UGX <?php echo number_format($balances['total_bank'] ?? 0); ?></div>
                <div class="stat-label">Bank Balance</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">UGX <?php echo number_format($balances['total_mobile'] ?? 0); ?></div>
                <div class="stat-label">Mobile Money</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">UGX <?php echo number_format($balances['total_balance'] ?? 0); ?></div>
                <div class="stat-label">Total Balance</div>
            </div>
        </div>

        <!-- Income/Expense Summary -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value income">UGX <?php echo number_format($summary['total_income'] ?? 0); ?></div>
                <div class="stat-label">Income (This Month)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value expense">UGX <?php echo number_format($summary['total_expense'] ?? 0); ?></div>
                <div class="stat-label">Expenses (This Month)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value <?php echo ($summary['net_cash'] ?? 0) >= 0 ? 'income' : 'expense'; ?>">
                    UGX <?php echo number_format(abs($summary['net_cash'] ?? 0)); ?>
                </div>
                <div class="stat-label">Net Cash Flow</div>
            </div>
        </div>

        <!-- Weekly Trend Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fas fa-chart-line"></i> Weekly Cash Flow</h3>
            </div>
            <div class="chart-container">
                <canvas id="cashFlowChart"></canvas>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fas fa-university"></i> Account</label>
                <select id="filterAccount" onchange="applyFilters()">
                    <option value="">All Accounts</option>
                    <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>" <?php echo ($account_filter == $acc['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($acc['account_name']); ?> (UGX <?php echo number_format($acc['balance']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Type</label>
                <select id="filterType" onchange="applyFilters()">
                    <option value="">All Types</option>
                    <option value="income" <?php echo ($type_filter == 'income') ? 'selected' : ''; ?>>Income</option>
                    <option value="expense" <?php echo ($type_filter == 'expense') ? 'selected' : ''; ?>>Expense</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> From Date</label>
                <input type="date" id="filterFrom" value="<?php echo $date_from; ?>" onchange="applyFilters()">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> To Date</label>
                <input type="date" id="filterTo" value="<?php echo $date_to; ?>" onchange="applyFilters()">
            </div>
            <div class="filter-group">
                <button class="btn btn-secondary" onclick="resetFilters()">Reset</button>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="table-container">
            <div style="padding: 1rem; border-bottom: 1px solid var(--border);">
                <input type="text" id="searchInput" placeholder="🔍 Search transactions..." style="width: 100%; max-width: 300px; padding: 0.5rem; border: 1px solid var(--border); border-radius: 0.5rem;">
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Account</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th>By</th>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-coins"></i>
                                    <p>No transactions found</p>
                                    <button class="btn btn-primary" onclick="openAddModal()">Add Transaction</button>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($transactions as $trans): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                                <td><span class="badge badge-<?php echo $trans['transaction_type']; ?>"><?php echo ucfirst($trans['transaction_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($trans['category'] ?? '-'); ?></td>
                                <td>
                                    <a href="view_account.php?id=<?php echo $trans['account_id']; ?>" style="text-decoration: none; color: var(--primary);">
                                        <?php echo htmlspecialchars($trans['account_name'] ?? '-'); ?>
                                    </a>
                                </td>
                                <td class="<?php echo $trans['transaction_type'] == 'income' ? 'amount-income' : 'amount-expense'; ?>">
                                    <?php echo $trans['transaction_type'] == 'income' ? '+' : '-'; ?> UGX <?php echo number_format($trans['amount']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($trans['reference_no'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(substr($trans['description'] ?? '', 0, 30)); ?>...</td>
                                <td><?php echo htmlspecialchars($trans['created_by_name'] ?? 'System'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Transaction Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add Transaction</h3>
                <button class="close-btn" onclick="closeModal('addModal')" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="store_transaction.php" id="transactionForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Transaction Type <span class="required">*</span></label>
                        <select name="transaction_type" id="transType" required onchange="updateCategoryPlaceholder()">
                            <option value="">Select Type</option>
                            <option value="income">💰 Income (Money Received)</option>
                            <option value="expense">💸 Expense (Money Paid Out)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date <span class="required">*</span></label>
                        <input type="date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <input type="text" name="category" id="categoryInput" placeholder="e.g., Sales, Rent, Utilities" required>
                    </div>
                    <div class="form-group">
                        <label>Select Account <span class="required">*</span></label>
                        <select name="account_id" id="accountSelect" required>
                            <option value="">-- Select Account --</option>
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" data-balance="<?php echo $acc['balance']; ?>">
                                🏦 <?php echo htmlspecialchars($acc['account_name']); ?> 
                                (Balance: UGX <?php echo number_format($acc['balance']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (UGX) <span class="required">*</span></label>
                        <input type="number" name="amount" id="amountInput" step="1000" placeholder="0" required>
                    </div>
                    <div class="form-group">
                        <label>Reference Number</label>
                        <input type="text" name="reference_no" placeholder="Receipt/Invoice/Payment ID">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" name="add_transaction" class="btn btn-primary">Save Transaction</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Chart Data
        const weeklyData = <?php echo json_encode($weeklyData); ?>;
        const labels = weeklyData.map(item => new Date(item.date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
        const incomeData = weeklyData.map(item => parseFloat(item.income));
        const expenseData = weeklyData.map(item => parseFloat(item.expense));
        
        const ctx = document.getElementById('cashFlowChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Income', data: incomeData, backgroundColor: 'rgba(16, 185, 129, 0.8)', borderRadius: 8 },
                    { label: 'Expenses', data: expenseData, backgroundColor: 'rgba(239, 68, 68, 0.8)', borderRadius: 8 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': UGX ' + ctx.raw.toLocaleString() } }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => 'UGX ' + v.toLocaleString() } } }
            }
        });

        function openAddModal() { document.getElementById('addModal').classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function applyFilters() {
            const params = new URLSearchParams();
            const account = document.getElementById('filterAccount').value;
            const type = document.getElementById('filterType').value;
            const from = document.getElementById('filterFrom').value;
            const to = document.getElementById('filterTo').value;
            if (account) params.set('account', account);
            if (type) params.set('type', type);
            if (from) params.set('from', from);
            if (to) params.set('to', to);
            window.location.href = 'index.php?' + params.toString();
        }

        function resetFilters() { window.location.href = 'index.php'; }

        function updateCategoryPlaceholder() {
            const type = document.getElementById('transType').value;
            const cat = document.getElementById('categoryInput');
            if (type === 'income') cat.placeholder = 'e.g., Sales, Services, Interest, Customer Payments';
            else if (type === 'expense') cat.placeholder = 'e.g., Rent, Utilities, Salaries, Supplies';
            else cat.placeholder = 'Select transaction type first';
        }

        // Balance check for expenses
        document.getElementById('amountInput')?.addEventListener('change', function() {
            const type = document.getElementById('transType').value;
            const account = document.getElementById('accountSelect');
            const amount = parseFloat(this.value);
            if (type === 'expense' && account.value && amount > 0) {
                const opt = account.options[account.selectedIndex];
                const balance = parseFloat(opt.getAttribute('data-balance') || 0);
                if (amount > balance && !confirm(`⚠️ Insufficient funds!\nBalance: UGX ${balance.toLocaleString()}\nAmount: UGX ${amount.toLocaleString()}\nContinue anyway?`)) {
                    this.value = '';
                }
            }
        });

        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keyup', function(e) {
            const term = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });

        updateCategoryPlaceholder();
    </script>
</body>
</html>