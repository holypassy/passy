<?php
// view_account.php - View Account Details with Transaction History
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$account_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($account_id <= 0) {
    header('Location: accounts.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get account details
    $stmt = $conn->prepare("
        SELECT * FROM cash_accounts WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        header('Location: accounts.php');
        exit();
    }
    
    // Get transaction summary
    $summaryStmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
            COUNT(*) as transaction_count
        FROM cash_transactions
        WHERE account_id = ?
    ");
    $summaryStmt->execute([$account_id]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent transactions
    $transStmt = $conn->prepare("
        SELECT 
            ct.*,
            u.full_name as created_by_name
        FROM cash_transactions ct
        LEFT JOIN users u ON ct.created_by = u.id
        WHERE ct.account_id = ?
        ORDER BY ct.transaction_date DESC, ct.created_at DESC
        LIMIT 50
    ");
    $transStmt->execute([$account_id]);
    $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get monthly breakdown
    $monthlyStmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(transaction_date, '%Y-%m') as month,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
        FROM cash_transactions
        WHERE account_id = ?
        GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");
    $monthlyStmt->execute([$account_id]);
    $monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Account | SAVANT MOTORS</title>
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
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --bg-light: #f8fafc;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
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

        /* Account Header */
        .account-header {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
            background: linear-gradient(135deg, #fff, var(--bg-light));
        }
        .account-name {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .account-balance {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--primary);
            margin: 0.5rem 0;
        }
        .account-type-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .type-cash { background: #dcfce7; color: #166534; }
        .type-bank { background: #dbeafe; color: #1e40af; }
        .type-mobile_money { background: #fed7aa; color: #9a3412; }
        .type-petty_cash { background: #e0e7ff; color: #4338ca; }

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
        .stat-value { font-size: 1.3rem; font-weight: 700; }
        .stat-label { font-size: 0.7rem; color: var(--gray); margin-top: 0.25rem; text-transform: uppercase; }
        .stat-value.income { color: var(--success); }
        .stat-value.expense { color: var(--danger); }

        /* Chart Card */
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

        /* Table */
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
        .btn-danger { background: var(--danger); color: white; }

        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .account-name { font-size: 1.3rem; }
            .account-balance { font-size: 1.8rem; }
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
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-university"></i> Account Details</h1>
                <p>View account information and transaction history</p>
            </div>
            <div>
                <a href="accounts.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Accounts
                </a>
                <a href="edit_account.php?id=<?php echo $account_id; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Account
                </a>
            </div>
        </div>

        <!-- Account Header -->
        <div class="account-header">
            <div class="account-name">
                <i class="fas fa-<?php echo $account['account_type'] == 'bank' ? 'university' : ($account['account_type'] == 'mobile_money' ? 'mobile-alt' : 'money-bill-wave'); ?>"></i>
                <?php echo htmlspecialchars($account['account_name']); ?>
                <span class="account-type-badge type-<?php echo $account['account_type']; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $account['account_type'])); ?>
                </span>
            </div>
            <?php if (!empty($account['account_number'])): ?>
            <div style="color: var(--gray); margin-top: 0.25rem;">
                <i class="fas fa-hashtag"></i> Account Number: <?php echo htmlspecialchars($account['account_number']); ?>
            </div>
            <?php endif; ?>
            <div class="account-balance">
                UGX <?php echo number_format($account['balance']); ?>
            </div>
            <div style="color: var(--gray);">
                <i class="fas fa-clock"></i> Last updated: <?php echo date('d M Y H:i', strtotime($account['updated_at'] ?? $account['created_at'])); ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value income">UGX <?php echo number_format($summary['total_income'] ?? 0); ?></div>
                <div class="stat-label">Total Income</div>
            </div>
            <div class="stat-card">
                <div class="stat-value expense">UGX <?php echo number_format($summary['total_expense'] ?? 0); ?></div>
                <div class="stat-label">Total Expenses</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $summary['transaction_count'] ?? 0; ?></div>
                <div class="stat-label">Transactions</div>
            </div>
        </div>

        <!-- Monthly Trend Chart -->
        <?php if (!empty($monthlyData)): ?>
        <div class="chart-card">
            <div class="chart-header">
                <h3><i class="fas fa-chart-line"></i> Monthly Trend</h3>
            </div>
            <div class="chart-container">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- Transactions Table -->
        <div class="table-container">
            <div class="chart-header" style="padding: 1rem; margin-bottom: 0;">
                <h3><i class="fas fa-history"></i> Transaction History</h3>
                <div>
                    <input type="text" id="searchInput" placeholder="Search transactions..." style="padding: 0.4rem 0.8rem; border: 1px solid var(--border); border-radius: 0.5rem; width: 200px;">
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table id="transactionsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th>Recorded By</th>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fas fa-coins"></i>
                                    <p>No transactions found for this account</p>
                                    <a href="../cash/index.php" class="btn btn-primary" style="margin-top: 1rem;">
                                        <i class="fas fa-plus-circle"></i> Add Transaction
                                    </a>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($transactions as $trans): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $trans['transaction_type']; ?>">
                                        <i class="fas fa-<?php echo $trans['transaction_type'] == 'income' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                        <?php echo ucfirst($trans['transaction_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($trans['category'] ?? '-'); ?></td>
                                <td class="<?php echo $trans['transaction_type'] == 'income' ? 'amount-income' : 'amount-expense'; ?>">
                                    <?php echo $trans['transaction_type'] == 'income' ? '+' : '-'; ?> UGX <?php echo number_format($trans['amount']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($trans['reference_no'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(substr($trans['description'] ?? '', 0, 40)); ?></td>
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

    <script>
        // Monthly Chart
        <?php if (!empty($monthlyData)): ?>
        const monthlyLabels = <?php echo json_encode(array_column(array_reverse($monthlyData), 'month')); ?>;
        const monthlyIncome = <?php echo json_encode(array_column(array_reverse($monthlyData), 'income')); ?>;
        const monthlyExpense = <?php echo json_encode(array_column(array_reverse($monthlyData), 'expense')); ?>;
        
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: monthlyLabels,
                datasets: [
                    { label: 'Income', data: monthlyIncome, backgroundColor: 'rgba(16, 185, 129, 0.8)', borderRadius: 8 },
                    { label: 'Expenses', data: monthlyExpense, backgroundColor: 'rgba(239, 68, 68, 0.8)', borderRadius: 8 }
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
        <?php endif; ?>

        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keyup', function(e) {
            const term = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#transactionsTable tbody tr');
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    </script>
</body>
</html>