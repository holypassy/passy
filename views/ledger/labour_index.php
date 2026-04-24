<?php
// views/labour/index.php - Labour Utilization Dashboard
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

/* ── Database ──────────────────────────────────────────────────────────────── */
$labourStats  = ['total_hours_mtd'=>0,'billable_hours_mtd'=>0,'total_technicians'=>0,
                 'avg_efficiency'=>0,'total_labour_cost_mtd'=>0,'billable_revenue_mtd'=>0];
$topTechs     = [];
$dailyTrend   = [];
$jobTypeBreakdown = [];
$techDetails  = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Core MTD Stats ────────────────────────────────────────────────────────
    try {
        $labourStats['total_hours_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(hours_worked),0) FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();

        $labourStats['billable_hours_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(billable_hours),0) FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();

        $labourStats['total_technicians'] = (int)$conn->query(
            "SELECT COUNT(DISTINCT technician_id) FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();

        if ($labourStats['total_hours_mtd'] > 0)
            $labourStats['avg_efficiency'] = round(($labourStats['billable_hours_mtd'] / $labourStats['total_hours_mtd']) * 100, 1);

        // Labour cost (hourly_rate × hours_worked)
        $labourStats['total_labour_cost_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(hours_worked * COALESCE(hourly_rate,0)),0) FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();

        // Billable revenue (billable_hours × COALESCE(charge_rate, hourly_rate))
        $labourStats['billable_revenue_mtd'] = (float)$conn->query(
            "SELECT COALESCE(SUM(billable_hours * COALESCE(charge_rate, hourly_rate, 0)),0) FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())"
        )->fetchColumn();
    } catch (PDOException $e) {}

    // ── Top Technicians ───────────────────────────────────────────────────────
    try {
        $topTechs = $conn->query(
            "SELECT technician_name,
                    SUM(hours_worked)   AS total_hours,
                    SUM(billable_hours) AS billable,
                    COALESCE(SUM(hours_worked * COALESCE(hourly_rate,0)),0)           AS cost,
                    COALESCE(SUM(billable_hours * COALESCE(charge_rate,hourly_rate,0)),0) AS revenue,
                    COUNT(DISTINCT job_id) AS jobs_handled
             FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())
             GROUP BY technician_name ORDER BY total_hours DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Daily trend (last 14 days) ────────────────────────────────────────────
    try {
        $dailyTrend = $conn->query(
            "SELECT DATE_FORMAT(work_date,'%d %b') AS day_label, work_date,
                    SUM(hours_worked)   AS total_hrs,
                    SUM(billable_hours) AS billable_hrs
             FROM labour_entries
             WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             GROUP BY work_date ORDER BY work_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── Hours by job type / service category ─────────────────────────────────
    try {
        $jobTypeBreakdown = $conn->query(
            "SELECT COALESCE(service_type,'General') AS service_type,
                    SUM(hours_worked)   AS total_hrs,
                    SUM(billable_hours) AS billable_hrs,
                    COUNT(*) AS entry_count
             FROM labour_entries
             WHERE MONTH(work_date)=MONTH(CURDATE()) AND YEAR(work_date)=YEAR(CURDATE())
             GROUP BY service_type ORDER BY total_hrs DESC LIMIT 7"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

    // ── All technician rows ───────────────────────────────────────────────────
    try {
        $techDetails = $conn->query(
            "SELECT le.technician_name,
                    le.work_date, le.hours_worked, le.billable_hours,
                    le.description, le.service_type,
                    COALESCE(jc.job_number,'—') AS job_number
             FROM labour_entries le
             LEFT JOIN job_cards jc ON jc.id = le.job_id
             WHERE MONTH(le.work_date)=MONTH(CURDATE()) AND YEAR(le.work_date)=YEAR(CURDATE())
             ORDER BY le.work_date DESC, le.id DESC LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function fmt($n){ return 'UGX '.number_format($n); }
function hrs($n){ return number_format($n,1).' hrs'; }
function eff($billable,$total){ return $total > 0 ? round(($billable/$total)*100) : 0; }
function effCol($pct){ return $pct >= 75 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626'); }
function effBadge($pct){ return $pct >= 75 ? 'badge-green' : ($pct >= 50 ? 'badge-yellow' : 'badge-red'); }

$typeColors = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#06b6d4','#84cc16'];
$typeTotal  = array_sum(array_column($jobTypeBreakdown,'total_hrs')) ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Labour Utilization | SAVANT MOTORS</title>
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
        .kpi-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gray);margin-bottom:.4rem;}
        .kpi-value{font-size:1.5rem;font-weight:800;line-height:1;}
        .kpi-sub{font-size:.7rem;color:var(--gray);margin-top:.4rem;}
        .kpi-icon{position:absolute;right:1rem;top:50%;transform:translateY(-50%);
            font-size:1.8rem;opacity:.12;}

        /* ── Efficiency Gauge ────────────────────────────────────────────── */
        .gauge-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:.5rem 0;}
        .gauge-ring{position:relative;width:140px;height:140px;}
        .gauge-ring svg{transform:rotate(-90deg);}
        .gauge-ring circle{fill:none;stroke-width:14;stroke-linecap:round;transition:stroke-dashoffset .8s ease;}
        .gauge-label{position:absolute;inset:0;display:flex;flex-direction:column;
            align-items:center;justify-content:center;}
        .gauge-pct{font-size:1.8rem;font-weight:800;}
        .gauge-desc{font-size:.65rem;color:var(--gray);margin-top:-.15rem;}

        /* ── Technician gauge row ─────────────────────────────────────────── */
        .tech-eff-bar{background:#e2e8f0;border-radius:999px;height:8px;overflow:hidden;margin-top:4px;}
        .tech-eff-fill{height:100%;border-radius:999px;transition:width .5s ease;}

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

        /* ── Buttons ─────────────────────────────────────────────────────── */
        .btn{padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.8rem;
            cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;}
        .btn-primary{background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white;}
        .btn-secondary{background:#e2e8f0;color:var(--dark);}
        .btn-sm{padding:.3rem .6rem;font-size:.7rem;}

        /* ── Progress ────────────────────────────────────────────────────── */
        .prog-wrap{background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;margin-top:4px;}
        .prog-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#3b82f6,#1e40af);}

        /* ── Type breakdown ──────────────────────────────────────────────── */
        .type-row{margin-bottom:.9rem;}
        .type-meta{display:flex;justify-content:space-between;margin-bottom:4px;}
        .type-name{font-size:.8rem;font-weight:600;}
        .type-hrs{font-size:.75rem;color:var(--gray);}
        .type-bar-wrap{background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;}
        .type-bar-fill{height:100%;border-radius:999px;transition:width .6s ease;}

        /* ── Rank badge ──────────────────────────────────────────────────── */
        .rank{width:26px;height:26px;border-radius:50%;display:inline-flex;
            align-items:center;justify-content:center;font-size:.7rem;font-weight:800;}
        .rank-1{background:#fbbf24;color:#78350f;}
        .rank-2{background:#e2e8f0;color:#374151;}
        .rank-3{background:#f97316;color:#fff;}
        .rank-n{background:#f1f5f9;color:#64748b;}

        /* ── Empty state ─────────────────────────────────────────────────── */
        .empty-state{text-align:center;padding:2.5rem;color:var(--gray);}
        .empty-state i{font-size:2.5rem;margin-bottom:.75rem;display:block;opacity:.4;}
        .empty-state p{font-size:.85rem;}

        /* ── Alert ───────────────────────────────────────────────────────── */
        .alert{border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1rem;
            display:flex;align-items:center;gap:.75rem;font-size:.82rem;}
        .alert-danger{background:#fee2e2;border-left:4px solid #ef4444;color:#991b1b;}

        @media(max-width:768px){
            .sidebar{left:-260px;}
            .main-content{margin-left:0;padding:1rem;}
            .kpi-grid{grid-template-columns:1fr 1fr;}
            .two-col{grid-template-columns:1fr;}
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
        <a href="../ledger/expenses_index.php" class="menu-item">💸 Expense Monitoring</a>
        <a href="index.php" class="menu-item active">🔧 Labour Utilization</a>
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

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-hard-hat" style="color:#3b82f6;"></i> Labour Utilization</h1>
            <p>Technician hours, billable efficiency &amp; service type analysis — <?php echo date('F Y'); ?></p>
        </div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <a href="../ledger/add.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Log Labour</a>
            <a href="export.php" class="btn btn-secondary"><i class="fas fa-file-csv"></i> Export</a>
        </div>
    </div>

    <!-- ── KPI Row ─────────────────────────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi-card" style="border-left-color:#3b82f6;">
            <div class="kpi-label">Total Hours (MTD)</div>
            <div class="kpi-value" style="color:#1d4ed8;"><?php echo hrs($labourStats['total_hours_mtd']); ?></div>
            <div class="kpi-sub"><?php echo $labourStats['total_technicians']; ?> active technician<?php echo $labourStats['total_technicians']!=1?'s':''; ?></div>
            <i class="fas fa-clock kpi-icon" style="color:#3b82f6;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#10b981;">
            <div class="kpi-label">Billable Hours (MTD)</div>
            <div class="kpi-value" style="color:#059669;"><?php echo hrs($labourStats['billable_hours_mtd']); ?></div>
            <div class="kpi-sub">of <?php echo hrs($labourStats['total_hours_mtd']); ?> logged</div>
            <i class="fas fa-hand-holding-usd kpi-icon" style="color:#10b981;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#f59e0b;">
            <div class="kpi-label">Efficiency Rate</div>
            <div class="kpi-value" style="color:<?php echo effCol($labourStats['avg_efficiency']); ?>;">
                <?php echo $labourStats['avg_efficiency']; ?>%
            </div>
            <div class="kpi-sub">
                <?php
                $e = $labourStats['avg_efficiency'];
                echo $e >= 75 ? '✅ On target' : ($e >= 50 ? '⚠️ Below target' : '🔴 Needs attention');
                ?>
            </div>
            <i class="fas fa-tachometer-alt kpi-icon" style="color:#f59e0b;"></i>
        </div>
        <div class="kpi-card" style="border-left-color:#8b5cf6;">
            <div class="kpi-label">Billable Revenue (MTD)</div>
            <div class="kpi-value" style="color:#7c3aed;"><?php echo fmt($labourStats['billable_revenue_mtd']); ?></div>
            <div class="kpi-sub">Labour cost: <?php echo fmt($labourStats['total_labour_cost_mtd']); ?></div>
            <i class="fas fa-coins kpi-icon" style="color:#8b5cf6;"></i>
        </div>
    </div>

    <!-- ── Charts Row ─────────────────────────────────────────────────────── -->
    <div class="two-col">

        <!-- Daily Hours Line Chart -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line" style="color:#3b82f6;"></i> Daily Hours — Last 14 Days</h3>
            </div>
            <div class="card-body">
                <?php if(!empty($dailyTrend)): ?>
                <canvas id="dailyChart" height="220"></canvas>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-chart-line"></i><p>No daily data available</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Overall Efficiency Gauge -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-tachometer-alt" style="color:#f59e0b;"></i> Overall Efficiency</h3>
                <span style="font-size:.7rem;color:var(--gray);">Billable ÷ Total hours</span>
            </div>
            <div class="card-body" style="display:flex;align-items:center;justify-content:space-around;flex-wrap:wrap;gap:1.5rem;">
                <!-- SVG gauge -->
                <div class="gauge-wrap">
                    <?php
                    $effPct  = $labourStats['avg_efficiency'];
                    $gaugeCol = effCol($effPct);
                    $r = 56; $circ = round(2 * 3.14159 * $r, 2);
                    $offset = round($circ - ($effPct / 100 * $circ), 2);
                    ?>
                    <div class="gauge-ring">
                        <svg width="140" height="140" viewBox="0 0 140 140">
                            <circle cx="70" cy="70" r="<?php echo $r; ?>" stroke="#e2e8f0" stroke-width="14"/>
                            <circle cx="70" cy="70" r="<?php echo $r; ?>"
                                stroke="<?php echo $gaugeCol; ?>"
                                stroke-width="14"
                                stroke-dasharray="<?php echo $circ; ?>"
                                stroke-dashoffset="<?php echo $offset; ?>"/>
                        </svg>
                        <div class="gauge-label">
                            <span class="gauge-pct" style="color:<?php echo $gaugeCol; ?>;"><?php echo $effPct; ?>%</span>
                            <span class="gauge-desc">Efficiency</span>
                        </div>
                    </div>
                </div>
                <!-- Summary stats -->
                <div style="display:flex;flex-direction:column;gap:1rem;min-width:160px;">
                    <div>
                        <div style="font-size:.68rem;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;">Total Logged</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#1d4ed8;"><?php echo hrs($labourStats['total_hours_mtd']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:.68rem;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;">Billable</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#059669;"><?php echo hrs($labourStats['billable_hours_mtd']); ?></div>
                    </div>
                    <div>
                        <div style="font-size:.68rem;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;">Non-Billable</div>
                        <div style="font-size:1.3rem;font-weight:800;color:#d97706;"><?php echo hrs($labourStats['total_hours_mtd'] - $labourStats['billable_hours_mtd']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Technician Leaderboard + Service Breakdown ────────────────────── -->
    <div class="two-col">

        <!-- Technician Leaderboard -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-medal" style="color:#f59e0b;"></i> Technician Leaderboard (MTD)</h3>
            </div>
            <div class="card-body">
                <?php if(!empty($topTechs)): ?>
                <?php foreach($topTechs as $i=>$tech):
                    $techEff = eff($tech['billable'], $tech['total_hours']);
                    $techCol = effCol($techEff);
                    $rankCls = $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-n'));
                ?>
                <div style="display:flex;align-items:flex-start;gap:.85rem;margin-bottom:1rem;">
                    <span class="rank <?php echo $rankCls; ?>"><?php echo $i+1; ?></span>
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2px;">
                            <span style="font-size:.82rem;font-weight:700;"><?php echo htmlspecialchars($tech['technician_name']); ?></span>
                            <span style="font-size:.72rem;font-weight:800;color:<?php echo $techCol; ?>;"><?php echo $techEff; ?>%</span>
                        </div>
                        <div class="tech-eff-bar">
                            <div class="tech-eff-fill" style="width:<?php echo $techEff; ?>%;background:<?php echo $techCol; ?>;"></div>
                        </div>
                        <div style="display:flex;gap:1rem;margin-top:4px;font-size:.68rem;color:var(--gray);">
                            <span><?php echo hrs($tech['total_hours']); ?> total</span>
                            <span><?php echo hrs($tech['billable']); ?> billable</span>
                            <span><?php echo $tech['jobs_handled']; ?> job<?php echo $tech['jobs_handled']!=1?'s':''; ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-users"></i><p>No labour entries this month</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Hours by Service Type -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-wrench" style="color:#10b981;"></i> Hours by Service Type (MTD)</h3>
            </div>
            <div class="card-body">
                <?php if(!empty($jobTypeBreakdown)): ?>
                <?php foreach($jobTypeBreakdown as $i=>$type):
                    $pct = $typeTotal > 0 ? round(($type['total_hrs']/$typeTotal)*100) : 0;
                    $col = $typeColors[$i % count($typeColors)];
                ?>
                <div class="type-row">
                    <div class="type-meta">
                        <span class="type-name"><?php echo htmlspecialchars($type['service_type']); ?></span>
                        <span class="type-hrs" style="color:<?php echo $col; ?>;font-weight:700;"><?php echo hrs($type['total_hrs']); ?></span>
                    </div>
                    <div class="type-bar-wrap">
                        <div class="type-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div>
                    </div>
                    <div style="display:flex;gap:1rem;margin-top:3px;font-size:.68rem;color:var(--gray);">
                        <span><?php echo $pct; ?>% of hours</span>
                        <span><?php echo hrs($type['billable_hrs']); ?> billable</span>
                        <span><?php echo $type['entry_count']; ?> entr<?php echo $type['entry_count']!=1?'ies':'y'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <canvas id="serviceChart" height="180" style="margin-top:1rem;"></canvas>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-wrench"></i><p>No service type data available</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Full Labour Entries Table ─────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-alt" style="color:#3b82f6;"></i> Labour Entries — <?php echo date('F Y'); ?></h3>
            <div style="display:flex;gap:.5rem;">
                <a href="../ledger/add.php" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Log Labour</a>
                <a href="export.php" class="btn btn-sm btn-secondary"><i class="fas fa-file-csv"></i> Export</a>
            </div>
        </div>
        <div class="table-wrap">
            <?php if(!empty($techDetails)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Technician</th>
                        <th>Job #</th>
                        <th>Service Type</th>
                        <th>Description</th>
                        <th class="text-right">Hours Worked</th>
                        <th class="text-right">Billable Hrs</th>
                        <th class="text-center">Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($techDetails as $le):
                    $rowEff = eff($le['billable_hours'], $le['hours_worked']);
                    $rowBadge = effBadge($rowEff);
                ?>
                <tr>
                    <td style="white-space:nowrap;"><?php echo date('d/m/Y', strtotime($le['work_date'])); ?></td>
                    <td style="font-weight:700;"><?php echo htmlspecialchars($le['technician_name']); ?></td>
                    <td>
                        <?php if($le['job_number'] !== '—'): ?>
                        <a href="../jobs/view.php?job=<?php echo htmlspecialchars($le['job_number']); ?>"
                           style="color:var(--primary-light);font-weight:600;">
                            <?php echo htmlspecialchars($le['job_number']); ?>
                        </a>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <td>
                        <?php if(!empty($le['service_type'])): ?>
                        <span class="badge badge-blue"><?php echo htmlspecialchars($le['service_type']); ?></span>
                        <?php else: echo '<span style="color:var(--gray);">—</span>'; endif; ?>
                    </td>
                    <td style="max-width:200px;color:var(--gray);">
                        <?php echo htmlspecialchars(substr($le['description'] ?? '—', 0, 50)); ?>
                    </td>
                    <td class="text-right" style="font-weight:600;"><?php echo number_format($le['hours_worked'],1); ?></td>
                    <td class="text-right" style="font-weight:600;color:#059669;"><?php echo number_format($le['billable_hours'],1); ?></td>
                    <td class="text-center"><span class="badge <?php echo $rowBadge; ?>"><?php echo $rowEff; ?>%</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--bg-light);font-weight:700;border-top:2px solid var(--border);">
                        <td colspan="5" style="padding:.75rem 1rem;">TOTALS (<?php echo date('F Y'); ?>)</td>
                        <td class="text-right" style="padding:.75rem 1rem;"><?php echo number_format($labourStats['total_hours_mtd'],1); ?></td>
                        <td class="text-right" style="padding:.75rem 1rem;color:#059669;"><?php echo number_format($labourStats['billable_hours_mtd'],1); ?></td>
                        <td class="text-center" style="padding:.75rem 1rem;">
                            <span class="badge <?php echo effBadge($labourStats['avg_efficiency']); ?>"><?php echo $labourStats['avg_efficiency']; ?>%</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-hard-hat"></i>
                <p>No labour entries this month</p>
                <a href="add.php" class="btn btn-primary" style="margin-top:.75rem;">Log First Entry</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /main-content -->

<!-- ── Chart.js ─────────────────────────────────────────────────────────── -->
<script>
(function(){

    /* Daily Hours — Line + Bar combo */
    <?php if(!empty($dailyTrend)): ?>
    const dailyLabels   = <?php echo json_encode(array_column($dailyTrend,'day_label')); ?>;
    const dailyTotal    = <?php echo json_encode(array_column($dailyTrend,'total_hrs')); ?>;
    const dailyBillable = <?php echo json_encode(array_column($dailyTrend,'billable_hrs')); ?>;
    new Chart(document.getElementById('dailyChart'),{
        type:'bar',
        data:{
            labels:dailyLabels,
            datasets:[
                {label:'Total Hours',data:dailyTotal,backgroundColor:'rgba(59,130,246,.3)',
                 borderColor:'#3b82f6',borderWidth:2,borderRadius:6,order:2},
                {label:'Billable Hours',data:dailyBillable,type:'line',
                 borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.12)',
                 pointBackgroundColor:'#10b981',pointRadius:4,tension:.4,fill:true,order:1},
            ]
        },
        options:{
            responsive:true,
            plugins:{legend:{position:'bottom',labels:{font:{size:11}}},
                tooltip:{callbacks:{label:ctx=>ctx.dataset.label+': '+ctx.parsed.y.toFixed(1)+' hrs'}}},
            scales:{y:{beginAtZero:true,ticks:{callback:v=>v+' h'}},x:{grid:{display:false}}}
        }
    });
    <?php endif; ?>

    /* Service Type Doughnut */
    <?php if(!empty($jobTypeBreakdown)): ?>
    const svcLabels = <?php echo json_encode(array_column($jobTypeBreakdown,'service_type')); ?>;
    const svcData   = <?php echo json_encode(array_column($jobTypeBreakdown,'total_hrs')); ?>;
    const svcColors = <?php echo json_encode(array_slice($typeColors, 0, count($jobTypeBreakdown))); ?>;
    new Chart(document.getElementById('serviceChart'),{
        type:'doughnut',
        data:{labels:svcLabels,datasets:[{data:svcData,backgroundColor:svcColors,borderWidth:3,borderColor:'#fff',hoverOffset:5}]},
        options:{
            cutout:'60%',
            plugins:{legend:{position:'bottom',labels:{font:{size:11}}},
                tooltip:{callbacks:{label:ctx=>ctx.label+': '+ctx.parsed.toFixed(1)+' hrs'}}}
        }
    });
    <?php endif; ?>

})();
</script>
</body>
</html>
