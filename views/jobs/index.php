<?php
// jobs/index.php — Job Costing & Invoicing Dashboard
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php'); exit();
}

$jobs       = [];
$jobStats   = ['open_jobs' => 0, 'completed_mtd' => 0, 'total_revenue_mtd' => 0, 'uninvoiced' => 0, 'avg_value' => 0];
$dbError    = null;
$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['search'] ?? '');

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Summary Stats ──────────────────────────────────────────────────────────
    $jobStats['open_jobs'] = (int)$conn->query(
        "SELECT COUNT(*) FROM job_cards WHERE status NOT IN ('completed','cancelled','invoiced')"
    )->fetchColumn();

    $jobStats['completed_mtd'] = (int)$conn->query(
        "SELECT COUNT(*) FROM job_cards WHERE status IN ('completed','invoiced')
         AND MONTH(completion_date)=MONTH(CURDATE()) AND YEAR(completion_date)=YEAR(CURDATE())"
    )->fetchColumn();

    $jobStats['total_revenue_mtd'] = (float)$conn->query(
        "SELECT COALESCE(SUM(total_amount),0) FROM job_cards WHERE status='invoiced'
         AND MONTH(completion_date)=MONTH(CURDATE()) AND YEAR(completion_date)=YEAR(CURDATE())"
    )->fetchColumn();

    $jobStats['uninvoiced'] = (int)$conn->query(
        "SELECT COUNT(*) FROM job_cards WHERE status='completed'"
    )->fetchColumn();

    if ($jobStats['completed_mtd'] > 0)
        $jobStats['avg_value'] = round($jobStats['total_revenue_mtd'] / $jobStats['completed_mtd']);

    // ── Job List with filters ─────────────────────────────────────────────────
    $where  = [];
    $params = [];

    if ($filterStatus !== '') {
        $where[]           = "jc.status = :status";
        $params[':status'] = $filterStatus;
    }
    if ($filterSearch !== '') {
        $where[]            = "(jc.job_number LIKE :q OR c.full_name LIKE :q OR jc.vehicle_reg LIKE :q)";
        $params[':q']       = '%' . $filterSearch . '%';
    }

    $sql = "
        SELECT jc.id, jc.job_number, jc.status, jc.total_amount,
               jc.labour_cost, jc.parts_cost, jc.created_at,
               jc.completion_date, jc.vehicle_reg, jc.vehicle_make, jc.vehicle_model,
               c.full_name AS customer_name, c.phone AS customer_phone
        FROM job_cards jc
        LEFT JOIN customers c ON jc.customer_id = c.id
        " . ($where ? "WHERE " . implode(" AND ", $where) : "") . "
        ORDER BY jc.id DESC
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// ── Status badge helper ────────────────────────────────────────────────────
function statusBadge($s) {
    $map = [
        'open'      => ['badge-yellow', 'Open'],
        'in_progress'=> ['badge-blue',  'In Progress'],
        'completed' => ['badge-green',  'Completed'],
        'invoiced'  => ['badge-gray',   'Invoiced'],
        'cancelled' => ['badge-red',    'Cancelled'],
    ];
    [$cls, $label] = $map[$s] ?? ['badge-gray', ucfirst($s)];
    return "<span class=\"badge $cls\">$label</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Costing &amp; Invoicing | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f0f2f5; min-height:100vh; }
        :root {
            --primary:#1e40af; --primary-light:#3b82f6; --success:#10b981;
            --danger:#ef4444; --warning:#f59e0b; --border:#e2e8f0;
            --gray:#64748b; --dark:#0f172a; --bg-light:#f8fafc;
            --shadow-sm:0 1px 2px rgba(0,0,0,.05); --shadow-md:0 4px 6px -1px rgba(0,0,0,.1);
        }

        /* Sidebar */
        .sidebar { position:fixed;left:0;top:0;width:260px;height:100%;background:linear-gradient(180deg,#e0f2fe 0%,#bae6fd 100%);color:#0c4a6e;z-index:1000;overflow-y:auto; }
        .sidebar-header { padding:1.5rem; border-bottom:1px solid rgba(0,0,0,.08); }
        .sidebar-header h2 { font-size:1.2rem;font-weight:700;color:#0369a1; }
        .sidebar-header p { font-size:0.7rem;opacity:.7;margin-top:.25rem;color:#0284c7; }
        .sidebar-menu { padding:1rem 0; }
        .sidebar-title { padding:.5rem 1.5rem;font-size:.7rem;text-transform:uppercase;letter-spacing:1px;color:#0369a1;font-weight:600; }
        .menu-item { padding:.7rem 1.5rem;display:flex;align-items:center;gap:.75rem;color:#0c4a6e;text-decoration:none;transition:all .2s;border-left:3px solid transparent;font-size:.85rem;font-weight:500; }
        .menu-item i { width:20px; }
        .menu-item:hover,.menu-item.active { background:rgba(14,165,233,.2);color:#0284c7;border-left-color:#0284c7; }

        /* Layout */
        .main-content { margin-left:260px; padding:1.5rem; min-height:100vh; }
        .top-bar { background:white;border-radius:1rem;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;box-shadow:var(--shadow-sm);border:1px solid var(--border); }
        .page-title h1 { font-size:1.3rem;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:.5rem; }
        .page-title p { font-size:.75rem;color:var(--gray);margin-top:.25rem; }

        /* Stats */
        .stats-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem; }
        .stat-card { background:white;border-radius:1rem;padding:1.25rem;border:1px solid var(--border);border-left:4px solid var(--primary-light); }
        .stat-value { font-size:1.5rem;font-weight:800; }
        .stat-label { font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--gray);margin-top:.25rem; }
        .stat-sub { font-size:.7rem;color:var(--gray);margin-top:.2rem; }

        /* Filter bar */
        .filter-bar { background:white;border-radius:1rem;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;border:1px solid var(--border); }
        .filter-bar input,.filter-bar select { padding:.5rem .75rem;border:1px solid var(--border);border-radius:.5rem;font-family:'Inter',sans-serif;font-size:.82rem;color:var(--dark);background:var(--bg-light); }
        .filter-bar input { flex:1;min-width:200px; }

        /* Table card */
        .card { background:white;border-radius:1rem;border:1px solid var(--border);overflow:hidden; }
        .card-header { padding:1rem 1.25rem;background:var(--bg-light);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center; }
        .card-header h3 { font-size:1rem;font-weight:700;display:flex;align-items:center;gap:.5rem; }
        table { width:100%;border-collapse:collapse; }
        th { background:var(--bg-light);padding:.75rem 1rem;text-align:left;font-weight:600;font-size:.7rem;color:var(--gray);border-bottom:1px solid var(--border); }
        td { padding:.75rem 1rem;border-bottom:1px solid var(--border);font-size:.8rem; }
        tr:hover { background:#f8fafc; }
        .text-right { text-align:right; }

        /* Badges */
        .badge { display:inline-block;padding:2px 8px;border-radius:999px;font-size:.65rem;font-weight:600; }
        .badge-green  { background:#dcfce7;color:#166534; }
        .badge-red    { background:#fee2e2;color:#991b1b; }
        .badge-yellow { background:#fef9c3;color:#854d0e; }
        .badge-blue   { background:#dbeafe;color:#1e40af; }
        .badge-gray   { background:#f1f5f9;color:#475569; }

        /* Buttons */
        .btn { padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.8rem;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:all .15s; }
        .btn-primary { background:linear-gradient(135deg,var(--primary-light),var(--primary));color:white; }
        .btn-primary:hover { opacity:.9; }
        .btn-secondary { background:#e2e8f0;color:var(--dark); }
        .btn-secondary:hover { background:#cbd5e1; }
        .btn-success { background:linear-gradient(135deg,#34d399,#059669);color:white; }
        .btn-success:hover { opacity:.9; }
        .btn-sm { padding:.3rem .6rem;font-size:.7rem; }
        .btn-danger { background:#fee2e2;color:#991b1b; }

        .empty-state { text-align:center;padding:3rem;color:var(--gray); }
        .empty-state i { font-size:2.5rem;margin-bottom:.75rem;display:block; }

        .actions-cell { display:flex;gap:.4rem;flex-wrap:wrap; }

        .cost-pill { display:inline-block;font-size:.68rem;background:#f0f9ff;color:#0369a1;padding:1px 7px;border-radius:999px;margin-right:3px; }

        .alert { padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;font-size:.85rem; }
        .alert-success { background:#dcfce7;color:#166534;border-left:3px solid #22c55e; }
        .alert-error   { background:#fee2e2;color:#991b1b;border-left:3px solid #ef4444; }

        @media(max-width:768px) {
            .sidebar { left:-260px; }
            .main-content { margin-left:0; padding:1rem; }
            .stats-grid { grid-template-columns:1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════ SIDEBAR ═══════════════════════════════ -->
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
        <a href="../ledger/labour_index.php" class="menu-item">🔧 Labour Utilization</a>
        <a href="index.php" class="menu-item active">🗂️ Job Costing &amp; Invoicing</a>
        <div style="margin-top:2rem;">
            <a href="../logout.php" class="menu-item">🚪 Logout</a>
        </div>
    </div>
</div>

<!-- ═══════════════════════════ MAIN CONTENT ══════════════════════════════ -->
<div class="main-content">

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if ($dbError): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> Database error: <?php echo htmlspecialchars($dbError); ?></div>
    <?php endif; ?>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-clipboard-list" style="color:var(--primary-light);"></i> Job Costing &amp; Invoicing</h1>
            <p>Track job cards · costs · and generate invoices for completed work</p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="../new_job.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> New Job Card</a>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="stats-grid">
        <div class="stat-card" style="border-left-color:#f59e0b;">
            <div class="stat-value" style="color:#d97706;"><?php echo $jobStats['open_jobs']; ?></div>
            <div class="stat-label">Open Jobs</div>
            <div class="stat-sub">Currently in progress</div>
        </div>
        <div class="stat-card" style="border-left-color:#10b981;">
            <div class="stat-value" style="color:#059669;"><?php echo $jobStats['completed_mtd']; ?></div>
            <div class="stat-label">Completed (MTD)</div>
            <div class="stat-sub">Avg UGX <?php echo number_format($jobStats['avg_value']); ?> per job</div>
        </div>
        <div class="stat-card" style="border-left-color:#3b82f6;">
            <div class="stat-value" style="color:#1d4ed8;">UGX <?php echo number_format($jobStats['total_revenue_mtd']); ?></div>
            <div class="stat-label">Revenue MTD (Invoiced)</div>
            <div class="stat-sub">From invoiced jobs this month</div>
        </div>
        <div class="stat-card" style="border-left-color:#ef4444;">
            <div class="stat-value" style="color:#dc2626;"><?php echo $jobStats['uninvoiced']; ?></div>
            <div class="stat-label">Awaiting Invoice</div>
            <div class="stat-sub">Completed but not yet invoiced</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" action="index.php">
        <div class="filter-bar">
            <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="🔍  Search by job #, customer, or vehicle reg…">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="open"        <?php if($filterStatus==='open')        echo 'selected'; ?>>Open</option>
                <option value="in_progress" <?php if($filterStatus==='in_progress') echo 'selected'; ?>>In Progress</option>
                <option value="completed"   <?php if($filterStatus==='completed')   echo 'selected'; ?>>Completed</option>
                <option value="invoiced"    <?php if($filterStatus==='invoiced')    echo 'selected'; ?>>Invoiced</option>
                <option value="cancelled"   <?php if($filterStatus==='cancelled')   echo 'selected'; ?>>Cancelled</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            <?php if ($filterStatus || $filterSearch): ?>
            <a href="index.php" class="btn btn-secondary btn-sm">✕ Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Job Cards Table -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-list-alt"></i> Job Cards
                <span style="font-size:.75rem;font-weight:500;color:var(--gray);">(<?php echo count($jobs); ?> records)</span>
            </h3>
            <a href="../new_job.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Job</a>
        </div>

        <?php if (empty($jobs)): ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <p style="font-weight:600;margin-bottom:.5rem;">No job cards found</p>
            <p style="font-size:.8rem;margin-bottom:1rem;">
                <?php echo ($filterStatus || $filterSearch) ? 'Try adjusting your filters.' : 'Get started by creating your first job card.'; ?>
            </p>
            <a href="../new_job.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Job Card</a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Job #</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Cost Breakdown</th>
                    <th class="text-right">Total (UGX)</th>
                    <th>Completion</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
            <tr>
                <td>
                    <strong style="color:var(--primary);"><?php echo htmlspecialchars($job['job_number'] ?? '—'); ?></strong>
                    <div style="font-size:.65rem;color:var(--gray);"><?php echo date('d/m/Y', strtotime($job['created_at'])); ?></div>
                </td>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($job['customer_name'] ?? '—'); ?></div>
                    <div style="font-size:.7rem;color:var(--gray);"><?php echo htmlspecialchars($job['customer_phone'] ?? ''); ?></div>
                </td>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars(strtoupper($job['vehicle_reg'] ?? '—')); ?></div>
                    <div style="font-size:.7rem;color:var(--gray);"><?php echo htmlspecialchars(trim(($job['vehicle_make'] ?? '') . ' ' . ($job['vehicle_model'] ?? ''))); ?></div>
                </td>
                <td>
                    <?php if (($job['labour_cost'] ?? 0) > 0): ?>
                    <span class="cost-pill">Labour: <?php echo number_format($job['labour_cost']); ?></span>
                    <?php endif; ?>
                    <?php if (($job['parts_cost'] ?? 0) > 0): ?>
                    <span class="cost-pill">Parts: <?php echo number_format($job['parts_cost']); ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-right" style="font-weight:700;color:var(--dark);">
                    <?php echo ($job['total_amount'] ?? 0) > 0 ? number_format($job['total_amount']) : '—'; ?>
                </td>
                <td style="font-size:.78rem;">
                    <?php echo !empty($job['completion_date']) ? date('d/m/Y', strtotime($job['completion_date'])) : '<span style="color:var(--gray);">—</span>'; ?>
                </td>
                <td><?php echo statusBadge($job['status'] ?? 'open'); ?></td>
                <td>
                    <div class="actions-cell">
                        <a href="view.php?id=<?php echo $job['id']; ?>" class="btn btn-secondary btn-sm" title="View"><i class="fas fa-eye"></i></a>
                        <a href="create.php?id=<?php echo $job['id']; ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php if (in_array($job['status'] ?? '', ['completed'])): ?>
                        <a href="invoice.php?job_id=<?php echo $job['id']; ?>" class="btn btn-success btn-sm" title="Generate Invoice">
                            <i class="fas fa-file-invoice"></i> Invoice
                        </a>
                        <?php elseif ($job['status'] === 'invoiced'): ?>
                        <a href="invoice.php?job_id=<?php echo $job['id']; ?>" class="btn btn-secondary btn-sm" title="View Invoice">
                            <i class="fas fa-file-invoice"></i> View
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /main-content -->
</body>
</html>
