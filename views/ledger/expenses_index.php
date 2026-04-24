<?php
// views/expenses/index.php - Expense Monitoring Dashboard
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

/* ── Database ──────────────────────────────────────────────────────────────── */
$expenseStats  = ['total_mtd'=>0,'total_ytd'=>0,'count_mtd'=>0,'top_category'=>'N/A','pending_approval'=>0,'approved_mtd'=>0,'rejected_mtd'=>0];
$recentExpenses  = [];
$categoryBreakdown = [];
$monthlyTrend    = [];
$budgetVsActual  = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── KPI Stats ────────────────────────────────────────────────────────────
    try {
        $expenseStats['total_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
             WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())
             AND status != 'cancelled'")->fetchColumn();

        $expenseStats['count_mtd'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses
             WHERE MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())
             AND status != 'cancelled'")->fetchColumn();

        $expenseStats['total_ytd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(amount),0) FROM expenses
             WHERE YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'")->fetchColumn();

        $expenseStats['pending_approval'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses WHERE status='pending'")->fetchColumn();

        $expenseStats['approved_mtd'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses
             WHERE status='approved' AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())")->fetchColumn();

        $expenseStats['rejected_mtd'] = (int)$conn->query(
            "SELECT COUNT(*) FROM expenses
             WHERE status='rejected' AND MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE())")->fetchColumn();

        $topCat = $conn->query(
            "SELECT category, SUM(amount) AS total FROM expenses
             WHERE YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'
             GROUP BY category ORDER BY total DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($topCat) $expenseStats['top_category'] = $topCat['category'];
    } catch (PDOException $e) {}

    // ── Category Breakdown (YTD) ──────────────────────────────────────────────
    try {
        $categoryBreakdown = $conn->query(
            "SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
             FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND status != 'cancelled'
             GROUP BY category ORDER BY total DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Monthly Trend (last 6 months) ─────────────────────────────────────────
    try {
        $monthlyTrend = $conn->query(
            "SELECT DATE_FORMAT(expense_date,'%b %Y') AS month_label,
                    DATE_FORMAT(expense_date,'%Y-%m') AS month_key,
                    SUM(amount) AS total, COUNT(*) AS cnt
             FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             AND status != 'cancelled'
             GROUP BY month_key, month_label ORDER BY month_key ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Recent Expenses ───────────────────────────────────────────────────────
    try {
        $recentExpenses = $conn->query(
            "SELECT e.id, e.expense_date, e.category, e.description,
                    e.amount, e.status, e.payment_method,
                    COALESCE(e.vendor,'—') AS vendor
             FROM expenses e ORDER BY e.expense_date DESC, e.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Budget vs Actual (if budgets table exists) ───────────────────────────
    try {
        $budgetVsActual = $conn->query(
            "SELECT b.category, b.monthly_budget,
                    COALESCE(SUM(e.amount),0) AS actual_mtd
             FROM expense_budgets b
             LEFT JOIN expenses e ON e.category=b.category
                 AND MONTH(e.expense_date)=MONTH(CURDATE())
                 AND YEAR(e.expense_date)=YEAR(CURDATE())
                 AND e.status != 'cancelled'
             GROUP BY b.category, b.monthly_budget ORDER BY b.category")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt($n){ return 'UGX '.number_format($n); }
function pct($a,$b){ return $b > 0 ? round(($a/$b)*100,1) : 0; }

$categoryTotal = array_sum(array_column($categoryBreakdown,'total')) ?: 1;
$categoryColors = ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Monitoring | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:#f0f2f5;min-height:100vh;}
        :root{
            --primary:#1e40af; --primary-light:#3b82f6;
            --success:#10b981; --danger:#ef4444; --warning:#f59e0b;
            --border:#e2e8f0; --gray:#64748b; --dark:#0f172a;
            --bg-light:#f8fafc;
            --shadow-sm:0 1px 2px rgba(0,0,0,.05);
            --shadow-md:0 4px 6px -1px rgba(0,0,0,.1);
        }

        /* ── Sidebar ─────────────────────────────────────────────────────── */
        .sidebar{position:fixed;left:0;top:0;width:260px;height:100%;
            background:linear-gradient(180deg,#e0f2fe 0%,#bae6fd 100%);
            color:#0c4a6e;z-index:1000;overflow-y:auto;}
        .sidebar-header{padding:1.5rem;border-bottom:1px solid rgba(0,0,0,.08);}
        .sidebar-header h2{font-size:1.2rem;font-weight:700;color:#0369a1;}
        .sidebar-header p{font-size:.7rem;opacity:.7;margin-top:.25rem;color:#0284c7;}
        .sidebar-menu{padding:1rem 0;}
        .sidebar-title{padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;
            letter-spacing:1px;color:#0369a1;font-weight:600;}
        .menu-item{padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;
            color:#0c4a6e;text-decoration:none;transition:all .2s;
            border-left:3px solid transparent;font-size:.85rem;font-weight:500;}
        .menu-item:hover,.menu-item.active{background:rgba(14,165,233,.2);
            color:#0284c7;border-left-color:#0284c7;}

        /* ── Layout ──────────────────────────────────────────────────────── */
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh;}
        .top-bar{background:white;border-radius:1rem;padding:1rem 1.5rem;
            margin-bottom:1.5rem;display:flex;justify-content:space-between;
            align-items:center;flex-wrap:wrap;gap:1rem;
            box-shadow:var(--shadow-sm);border:1px solid var(--border);}
        .page-title h1{font-size:1.3rem;font-weight:700;color:var(--dark);
            display:flex;align-items:center;gap:.5rem;}
        .page-title p{font-size:.75rem;color:var(--gray);margin-top:.25rem;}

        /* ── KPI Cards ───────────────────────────────────────────────────── */
        .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
        .kpi-card{background:white;border-radius:1rem;padding:1.25rem;
            border:1px solid var(--border);border-left-width:4px;position:relative;overflow:hidden;}
        .kpi-card::after{content:'';position:absolute;right:-12px;top:-12px;width:60px;
            height:60px;border-radius:50%;opacity:.07;}
        .kpi-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);margin-bottom:.4rem;}
        .kpi-value{font-size:1.5rem;font-weight:800;line-height:1;}
        .kpi-sub{font-size:.7rem;color:var(--gray);margin-top:.4rem;}
        .kpi-icon{position:absolute;right:1rem;top:50%;transform:translateY(-50%);
            font-size:1.8rem;opacity:.12;}

        /* ── Grid layouts ────────────────────────────────────────────────── */
        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem;}
        .three-col{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;}

        /* ── Cards ───────────────────────────────────────────────────────── */
        .card{background:white;border-radius:1rem;border:1px solid var(--border);
            margin-bottom:1.5rem;overflow:hidden;box-shadow:var(--shadow-sm);}
        .card-header{padding:1rem 1.25rem;background:var(--bg-light);
            border-bottom:1px solid var(--border);display:flex;
            justify-content:space-between;align-items:center;}
        .card-header h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
        .card-body{padding:1.25rem;}

        /* ── Tables ──────────────────────────────────────────────────────── */
        .table-wrap{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th{background:var(--bg-light);padding:.65rem 1rem;text-align:left;
            font-weight:600;font-size:.68rem;color:var(--gray);
            border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em;}
        td{padding:.7rem 1rem;border-bottom:1px solid var(--border);font-size:.8rem;}
        tr:last-child td{border-bottom:none;}
        tr:hover{background:var(--bg-light);}
        .text-right{text-align:right;}
        .text-center{text-align:center;}

        /* ── Badges ──────────────────────────────────────────────────────── */
        .badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.62rem;font-weight:700;}
        .badge-green{background:#dcfce7;color:#166534;}
        .badge-red{background:#fee2e2;color:#991b1b;}
        .badge-yellow{background:#fef9c3;color:#854d0e;}
        .badge-blue{background:#dbeafe;color:#1e40af;}
        .badge-gray{background:#f1f5f9;color:#475569;}
        .badge-purple{background:#f3e8ff;color:#6b21a8;}

        /* ── Buttons ─────────────────────────────────────────────────────── */
        .btn{padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.8rem;
            cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-danger{background:linear-gradient(135deg,#f87171,#ef4444);color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-sm{padding:.3rem .6rem;font-size:.7rem;}

        /* ── Category bar ────────────────────────────────────────────────── */
        .cat-bar-wrap{background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;margin-top:4px;}
        .cat-bar-fill{height:100%;border-radius:999px;transition:width .6s ease;}

        /* ── Status pills row ────────────────────────────────────────────── */
        .status-row{display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem;}
        .status-pill{display:flex;align-items:center;gap:.4rem;background:white;
            border:1px solid var(--border);border-radius:.75rem;padding:.4rem .85rem;font-size:.75rem;font-weight:600;}

        /* ── Budget progress ─────────────────────────────────────────────── */
        .budget-row{display:flex;flex-direction:column;gap:.35rem;padding:.75rem 0;border-bottom:1px solid var(--border);}
        .budget-row:last-child{border-bottom:none;}
        .budget-meta{display:flex;justify-content:space-between;align-items:center;}
        .budget-name{font-size:.8rem;font-weight:600;}
        .budget-nums{font-size:.72rem;color:var(--gray);}
        .budget-pct{font-size:.7rem;font-weight:700;}
        .budget-track{background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;}
        .budget-fill{height:100%;border-radius:999px;transition:width .5s ease;}

        /* ── Empty state ─────────────────────────────────────────────────── */
        .empty-state{text-align:center;padding:2.5rem;color:var(--gray);}
        .empty-state i{font-size:2.5rem;margin-bottom:.75rem;display:block;opacity:.4;}
        .empty-state p{font-size:.85rem;}

        /* ── Alert banner ────────────────────────────────────────────────── */
        .alert{border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1rem;
            display:flex;align-items:center;gap:.75rem;font-size:.82rem;}
        .alert-warning{background:#fef9c3;border-left:4px solid #f59e0b;color:#854d0e;}
        .alert-danger{background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;}

        @media(max-width:768px){
            .sidebar{left:-260px;}
            .main-content{margin-left:0;padding:1rem;}
            .kpi-grid{grid-template-columns:1fr 1fr;}
            .two-col,.three-col{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>

<!-- ══ Sidebar ══════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <h2>📚 SAVANT MOTORS</h2>
        <p>General Ledger System</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">LEDGER</div>
        <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
        <a href="../ledger/index.php" class="menu-item">📚 General Ledger</a>
        <a href="../ledger/trial_balance.php" class="menu-item">⚖️ Trial Balance</a>
        <a href="../ledger/income_statement.php" class="menu-item">📈 Income Statement</a>
        <a href="../ledger/balance_sheet.php" class="menu-item">📊 Balance Sheet</a>
        <div class="sidebar-title" style="margin-top:1rem;">CONNECTED LEDGERS</div>
        <a href="../accounting/debtors.php" class="menu-item">📥 Debtors (AR)</a>
        <a href="../accounting/creditors.php" class="menu-item">📤 Creditors (AP)</a>
        <a href="../invoices.php" class="menu-item">🧾 Invoices</a>
        <a href="../cash/accounts.php" class="menu-item">🏦 Cash Accounts</a>
        <div class="sidebar-title" style="margin-top:1rem;">OPERATIONS</div>
        <a href="index.php" class="menu-item active">💸 Expense Monitoring</a>
        <a href="../ledger/labour_index.php" class="menu-item">🔧 Labour Utilization</a>
        <a href="../jobs/index.php" class="menu-item">🗂️ Job Costing &amp; Invoicing</a>
        <div style="margin-top:2rem;">
            <a href="../logout.php" class="menu-item">🚪 Logout</a>
        </div>
    </div>
</div>

<!-- ══ Main Content ══════════════════════════════════════════════════════════ -->
<div class="main-content">

    <?php if(isset($dbError)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i>
        Database error: <?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>

    <?php if($expenseStats['pending_approval'] > 0): ?>
    <div class="alert alert-warning">
        <i class="fas fa-clock"></i>
        <strong><?php echo $expenseStats['pending_approval']; ?> expense<?php echo $expenseStats['pending_approval']>1?'s':''; ?> pending approval.</strong>
        &nbsp;<a href="?filter=pending" style="color:inherit;text-decoration:underline;">Review now →</a>
    </div>
    <?php endif; ?>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-receipt" style="color:#ef4444;"></i> Expense Monitoring</h1>
            <p>Track, categorise and control business expenditure — <?php echo date('F Y'); ?></p>
        </div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <a href="add.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Record Expense</a>
            <a href="export.php" class="btn btn-secondary"><i class="fas fa-file-csv"></i> Export</a>
        </div>
    </div>

    <!-- ── KPI Row ─────────────────────────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi-card" style="border-left-color:#ef4444;">
            <div class="kpi-label">Expenses MTD</div>
            <div class="kpi-value" style="color:#ef4444;"><?php echo fmt($expenseStats['total_mtd']); ?></div>
            <div class="kpi-sub"><?php echo $expenseStats['count_mtd']; ?> transactions this month</div>
            <i class="fas fa-arrow-trend-up kpi-icon" style="color:#ef4444;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#f59e0b;">
            <div class="kpi-label">Expenses YTD</div>
            <div class="kpi-value" style="color:#d97706;"><?php echo fmt($expenseStats['total_ytd']); ?></div>
            <div class="kpi-sub">Top: <?php echo htmlspecialchars($expenseStats['top_category']); ?></div>
            <i class="fas fa-calendar-alt kpi-icon" style="color:#f59e0b;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#8b5cf6;">
            <div class="kpi-label">Pending Approval</div>
            <div class="kpi-value" style="color:#7c3aed;"><?php echo $expenseStats['pending_approval']; ?></div>
            <div class="kpi-sub">Awaiting authorisation</div>
            <i class="fas fa-hourglass-half kpi-icon" style="color:#8b5cf6;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#10b981;">
            <div class="kpi-label">Approved (MTD)</div>
            <div class="kpi-value" style="color:#059669;"><?php echo $expenseStats['approved_mtd']; ?></div>
            <div class="kpi-sub"><?php echo $expenseStats['rejected_mtd']; ?> rejected this month</div>
            <i class="fas fa-check-circle kpi-icon" style="color:#10b981;"></i>
        </div>
    </div>

    <!-- ── Charts Row ─────────────────────────────────────────────────────── -->
    <div class="two-col">

        <!-- Monthly Trend Chart -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-bar" style="color:#3b82f6;"></i> Monthly Spend Trend</h3>
                <span style="font-size:.7rem;color:var(--gray);">Last 6 months</span>
            </div>
            <div class="card-body">
                <?php if(!empty($monthlyTrend)): ?>
                <canvas id="trendChart" height="220"></canvas>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-chart-bar"></i><p>No trend data available</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Category Doughnut -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie" style="color:#ef4444;"></i> Spend by Category (YTD)</h3>
                <span style="font-size:.7rem;color:var(--gray);"><?php echo fmt($categoryTotal); ?> total</span>
            </div>
            <div class="card-body" style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;">
                <?php if(!empty($categoryBreakdown)): ?>
                <div style="position:relative;width:170px;height:170px;flex-shrink:0;">
                    <canvas id="donutChart"></canvas>
                </div>
                <div style="flex:1;min-width:160px;">
                    <?php foreach($categoryBreakdown as $i=>$cat):
                        $pct = pct($cat['total'], $categoryTotal);
                        $col = $categoryColors[$i % count($categoryColors)];
                    ?>
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                        <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $col; ?>;flex-shrink:0;"></span>
                        <span style="font-size:.75rem;flex:1;color:var(--dark);font-weight:500;"><?php echo htmlspecialchars($cat['category']); ?></span>
                        <span style="font-size:.72rem;color:var(--gray);"><?php echo $pct; ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state" style="width:100%;"><i class="fas fa-chart-pie"></i><p>No category data</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Category Breakdown + Budget vs Actual ─────────────────────────── -->
    <div class="two-col">

        <!-- Category Detail -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tags" style="color:#8b5cf6;"></i> Category Breakdown (YTD)</h3>
            </div>
            <div class="card-body">
                <?php if(!empty($categoryBreakdown)): ?>
                <?php foreach($categoryBreakdown as $i=>$cat):
                    $pct = pct($cat['total'], $categoryTotal);
                    $col = $categoryColors[$i % count($categoryColors)];
                ?>
                <div style="margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span style="font-size:.8rem;font-weight:600;"><?php echo htmlspecialchars($cat['category']); ?></span>
                        <span style="font-size:.78rem;font-weight:700;color:<?php echo $col; ?>;"><?php echo fmt($cat['total']); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <div class="cat-bar-wrap" style="flex:1;">
                            <div class="cat-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div>
                        </div>
                        <span style="font-size:.68rem;color:var(--gray);min-width:38px;text-align:right;"><?php echo $pct; ?>% · <?php echo $cat['cnt']; ?>x</span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-tags"></i><p>No expense categories yet</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Budget vs Actual -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-bullseye" style="color:#f59e0b;"></i> Budget vs Actual (MTD)</h3>
                <a href="budgets.php" class="btn btn-secondary btn-sm">Manage Budgets →</a>
            </div>
            <div class="card-body">
                <?php if(!empty($budgetVsActual)): ?>
                <?php foreach($budgetVsActual as $b):
                    $pct = pct($b['actual_mtd'], $b['monthly_budget']);
                    $over = $pct > 100;
                    $barCol = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
                ?>
                <div class="budget-row">
                    <div class="budget-meta">
                        <span class="budget-name"><?php echo htmlspecialchars($b['category']); ?></span>
                        <span class="budget-pct" style="color:<?php echo $barCol; ?>;"><?php echo $pct; ?>%</span>
                    </div>
                    <div class="budget-track">
                        <div class="budget-fill" style="width:<?php echo min($pct,100); ?>%;background:<?php echo $barCol; ?>;"></div>
                    </div>
                    <div class="budget-nums"><?php echo fmt($b['actual_mtd']); ?> of <?php echo fmt($b['monthly_budget']); ?> budget
                        <?php if($over): ?><span style="color:#ef4444;font-weight:700;"> — OVER BUDGET</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bullseye"></i>
                    <p>No budgets configured</p>
                    <a href="budgets.php" class="btn btn-primary btn-sm" style="margin-top:.75rem;">Set Up Budgets</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Recent Expenses Table ──────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-alt" style="color:#ef4444;"></i> Recent Expenses</h3>
            <div style="display:flex;gap:.5rem;align-items:center;">
                <!-- Quick filter links -->
                <a href="?filter=pending"  class="btn btn-sm btn-secondary">Pending</a>
                <a href="?filter=approved" class="btn btn-sm btn-secondary">Approved</a>
                <a href="index.php"        class="btn btn-sm btn-secondary">All</a>
                <a href="add.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add</a>
            </div>
        </div>
        <div class="table-wrap">
            <?php if(!empty($recentExpenses)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Vendor</th>
                        <th>Payment</th>
                        <th class="text-right">Amount (UGX)</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($recentExpenses as $exp):
                    $s   = $exp['status'] ?? 'unknown';
                    $cls = $s==='approved' ? 'badge-green' : ($s==='pending' ? 'badge-yellow' : ($s==='rejected' ? 'badge-red' : 'badge-gray'));
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo date('d/m/Y', strtotime($exp['expense_date'])); ?></td>
                    <td>
                        <span class="badge badge-blue"><?php echo htmlspecialchars($exp['category'] ?? '—'); ?></span>
                    </td>
                    <td style="max-width:220px;"><?php echo htmlspecialchars(substr($exp['description'] ?? '—', 0, 55)); ?></td>
                    <td><?php echo htmlspecialchars($exp['vendor']); ?></td>
                    <td style="font-size:.72rem;color:var(--gray);">
                        <?php echo htmlspecialchars(ucwords(str_replace('_',' ',$exp['payment_method'] ?? '—'))); ?>
                    </td>
                    <td class="text-right" style="font-weight:700;color:#ef4444;">
                        <?php echo number_format($exp['amount']); ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $cls; ?>"><?php echo ucfirst($s); ?></span>
                    </td>
                    <td class="text-center">
                        <a href="view.php?id=<?php echo $exp['id']; ?>" class="btn btn-sm btn-secondary" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if($s === 'pending'): ?>
                        <a href="approve.php?id=<?php echo $exp['id']; ?>" class="btn btn-sm btn-primary" title="Approve">
                            <i class="fas fa-check"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>No expense records found</p>
                <a href="add.php" class="btn btn-primary" style="margin-top:.75rem;">Record First Expense</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /main-content -->

<!-- ── Chart.js ─────────────────────────────────────────────────────────── -->
<script>
(function(){

    /* Monthly Trend Bar */
    <?php if(!empty($monthlyTrend)): ?>
    const trendLabels = <?php echo json_encode(array_column($monthlyTrend,'month_label')); ?>;
    const trendData   = <?php echo json_encode(array_column($monthlyTrend,'total')); ?>;
    new Chart(document.getElementById('trendChart'),{
        type:'bar',
        data:{
            labels: trendLabels,
            datasets:[{
                label:'Expenses (UGX)',
                data: trendData,
                backgroundColor: trendData.map((_,i)=> i===trendData.length-1 ? '#ef4444' : 'rgba(239,68,68,.4)'),
                borderColor: '#ef4444',
                borderWidth: 2,
                borderRadius: 8,
            }]
        },
        options:{
            responsive:true,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>'UGX '+ctx.parsed.y.toLocaleString()}}},
            scales:{y:{beginAtZero:true,ticks:{callback:v=>'UGX '+Number(v).toLocaleString()}},
                    x:{grid:{display:false}}}
        }
    });
    <?php endif; ?>

    /* Category Doughnut */
    <?php if(!empty($categoryBreakdown)): ?>
    const donutLabels = <?php echo json_encode(array_column($categoryBreakdown,'category')); ?>;
    const donutData   = <?php echo json_encode(array_column($categoryBreakdown,'total')); ?>;
    const donutColors = <?php echo json_encode(array_slice($categoryColors, 0, count($categoryBreakdown))); ?>;
    new Chart(document.getElementById('donutChart'),{
        type:'doughnut',
        data:{labels:donutLabels,datasets:[{data:donutData,backgroundColor:donutColors,borderWidth:3,borderColor:'#fff',hoverOffset:6}]},
        options:{
            cutout:'68%',
            plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' UGX '+ctx.parsed.toLocaleString()}}},
        }
    });
    <?php endif; ?>

})();
</script>
</body>
</html>
