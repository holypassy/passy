<?php
// taken.php - View for Taken Tools (includes approved requests)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Pagination & filter params
$perPage    = 25;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';   // all | overdue | ontime
$filterSource = $_GET['source'] ?? 'all';   // all | assigned | approved_request

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $columns = $conn->query("SHOW COLUMNS FROM tool_assignments")->fetchAll(PDO::FETCH_COLUMN);
    $hasRequestId = in_array('request_id', $columns);

    // ── Build full union of assigned + approved-request tools ──────────────
    $assignedSQL = $hasRequestId ? "
        SELECT
            t.id, t.tool_code, t.tool_name, t.category, t.brand, t.model, t.location,
            ta.id as assignment_id,
            ta.assigned_date,
            ta.expected_return_date,
            tech.full_name as technician_name,
            tech.technician_code,
            DATEDIFF(NOW(), ta.assigned_date) as days_taken,
            CASE WHEN ta.expected_return_date < NOW() THEN 'Overdue' ELSE 'On Time' END as return_status,
            DATEDIFF(NOW(), ta.expected_return_date) as days_overdue,
            'assigned' as source_type,
            tr.request_number as request_number
        FROM tools t
        INNER JOIN tool_assignments ta ON t.id = ta.tool_id
        INNER JOIN technicians tech ON ta.technician_id = tech.id
        LEFT JOIN tool_requests tr ON ta.request_id = tr.id
        WHERE ta.actual_return_date IS NULL
    " : "
        SELECT
            t.id, t.tool_code, t.tool_name, t.category, t.brand, t.model, t.location,
            ta.id as assignment_id,
            ta.assigned_date,
            ta.expected_return_date,
            tech.full_name as technician_name,
            tech.technician_code,
            DATEDIFF(NOW(), ta.assigned_date) as days_taken,
            CASE WHEN ta.expected_return_date < NOW() THEN 'Overdue' ELSE 'On Time' END as return_status,
            DATEDIFF(NOW(), ta.expected_return_date) as days_overdue,
            'assigned' as source_type,
            NULL as request_number
        FROM tools t
        INNER JOIN tool_assignments ta ON t.id = ta.tool_id
        INNER JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.actual_return_date IS NULL
    ";

    $approvedSQL = "
        SELECT
            t.id, t.tool_code, t.tool_name, t.category, t.brand, t.model, t.location,
            NULL as assignment_id,
            tr.created_at as assigned_date,
            DATE_ADD(tr.created_at, INTERVAL tr.expected_duration_days DAY) as expected_return_date,
            tech.full_name as technician_name,
            tech.technician_code,
            DATEDIFF(NOW(), tr.created_at) as days_taken,
            CASE WHEN DATE_ADD(tr.created_at, INTERVAL tr.expected_duration_days DAY) < NOW() THEN 'Overdue' ELSE 'On Time' END as return_status,
            DATEDIFF(NOW(), DATE_ADD(tr.created_at, INTERVAL tr.expected_duration_days DAY)) as days_overdue,
            'approved_request' as source_type,
            tr.request_number as request_number
        FROM tool_requests tr
        INNER JOIN technicians tech ON tr.technician_id = tech.id
        INNER JOIN tool_request_items tri ON tr.id = tri.request_id
        INNER JOIN tools t ON tri.tool_id = t.id
        WHERE tr.status = 'approved'
        AND t.status != 'taken'
        AND tri.is_new_tool = 0
    ";

    // Apply source filter
    if ($filterSource === 'assigned') {
        $unionSQL = $assignedSQL;
    } elseif ($filterSource === 'approved_request') {
        $unionSQL = $approvedSQL;
    } else {
        $unionSQL = "($assignedSQL) UNION ALL ($approvedSQL)";
    }

    // Wrap for search + status filter
    $whereClause = "WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $whereClause .= " AND (tool_name LIKE ? OR tool_code LIKE ? OR technician_name LIKE ? OR request_number LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s, $s]);
    }
    if ($filterStatus === 'overdue') {
        $whereClause .= " AND return_status = 'Overdue'";
    } elseif ($filterStatus === 'ontime') {
        $whereClause .= " AND return_status = 'On Time'";
    }

    // Count total for pagination
    $countSQL = "SELECT COUNT(*) FROM ($unionSQL) as all_tools $whereClause";
    $countStmt = $conn->prepare($countSQL);
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages   = max(1, (int)ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // Fetch page
    $dataSQL = "SELECT * FROM ($unionSQL) as all_tools $whereClause ORDER BY expected_return_date ASC LIMIT $perPage OFFSET $offset";
    $dataStmt = $conn->prepare($dataSQL);
    $dataStmt->execute($params);
    $allTools = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats (always from full set, no page filter)
    $statsStmt = $conn->prepare("SELECT
        COUNT(*) as total_taken,
        SUM(CASE WHEN return_status = 'Overdue' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN return_status = 'On Time' THEN 1 ELSE 0 END) as on_time
        FROM ($unionSQL) as all_tools");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $stats = [
        'total_taken' => (int)($stats['total_taken'] ?? 0),
        'overdue'     => (int)($stats['overdue'] ?? 0),
        'on_time'     => (int)($stats['on_time'] ?? 0),
    ];

} catch(PDOException $e) {
    $allTools    = [];
    $totalPages  = 1;
    $totalRecords = 0;
    $stats = ['total_taken' => 0, 'overdue' => 0, 'on_time' => 0];
    $error = $e->getMessage();
}

// Helper: build URL preserving filters
function pageUrl($p) {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools Taken | SAVANT MOTORS</title>
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
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

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
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--dark); }
        .stat-label { font-size: 0.7rem; color: var(--gray); text-transform: uppercase; margin-top: 0.25rem; }
        .stat-card.overdue .stat-value { color: var(--danger); }
        .stat-card.on-time .stat-value { color: var(--success); }

        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 0.9rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.9rem 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; vertical-align: middle; }
        tr:hover { background: #f8fafc; }

        .status-overdue { background: #fee2e2; color: #991b1b; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .status-ontime { background: #dcfce7; color: #166534; padding: 0.25rem 0.6rem; border-radius: 2rem; font-size: 0.7rem; font-weight: 600; display: inline-block; }
        .source-badge {
            background: #e2e8f0;
            color: #475569;
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        .source-badge.request { background: #dbeafe; color: #1e40af; }
        .source-badge.assigned { background: #dcfce7; color: #166534; }

        .btn {
            padding: 0.4rem 0.8rem;
            border-radius: 0.4rem;
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary-light); color: white; }
        .btn-primary:hover { background: var(--primary); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #d97706; }

        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        /* ── Filter bar ── */
        .filter-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .filter-bar input[type="text"] {
            flex: 1; min-width: 200px;
            padding: 0.5rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.82rem;
            font-family: inherit;
            outline: none;
            transition: border-color .2s;
        }
        .filter-bar input[type="text"]:focus { border-color: var(--primary-light); }
        .filter-bar select {
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.82rem;
            font-family: inherit;
            background: white;
            outline: none;
            cursor: pointer;
        }
        .filter-bar button[type="submit"] {
            padding: 0.5rem 1.1rem;
            background: var(--primary-light);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
        }
        .filter-bar a.reset-btn {
            padding: 0.5rem 1rem;
            background: #e2e8f0;
            color: var(--dark);
            border-radius: 0.5rem;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
        }
        .filter-chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 0.3rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.72rem;
            font-weight: 600;
            border: 1.5px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
        }
        .filter-chip.all    { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
        .filter-chip.over   { background:#fee2e2; color:#991b1b; border-color:#fca5a5; }
        .filter-chip.ok     { background:#dcfce7; color:#166534; border-color:#86efac; }
        .filter-chip.req    { background:#dbeafe; color:#1e40af; border-color:#93c5fd; }
        .filter-chip.asgn   { background:#dcfce7; color:#166534; border-color:#86efac; }
        .filter-chip.active { box-shadow: 0 0 0 2px var(--primary-light); }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .pagination-info { font-size: 0.78rem; color: var(--gray); }
        .pagination-links { display: flex; gap: 0.3rem; flex-wrap: wrap; }
        .page-link {
            padding: 0.35rem 0.7rem;
            border-radius: 0.4rem;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            border: 1.5px solid var(--border);
            color: var(--dark);
            background: white;
            transition: all .15s;
        }
        .page-link:hover { background: #f1f5f9; }
        .page-link.active { background: var(--primary-light); color: white; border-color: var(--primary-light); }
        .page-link.disabled { opacity: .4; pointer-events: none; }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            table { font-size: 0.75rem; }
            td, th { padding: 0.5rem; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Management System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="../tools/index.php" class="menu-item">🔧 All Tools</a>
            <a href="taken.php" class="menu-item active">📤 Tools Taken</a>
            <a href="../tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
            <a href="../customers/index.php" class="menu-item">👥 Customers</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-hand-holding"></i> Tools Taken</h1>
                <p>All active assignments &amp; approved requests — <?php echo number_format($stats['total_taken']); ?> total records</p>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <a href="../tool_requests/index.php" class="btn btn-primary">
                    <i class="fas fa-clipboard-list"></i> View Requests
                </a>
                <a href="../tools/index.php" class="btn btn-secondary">
                    <i class="fas fa-tools"></i> All Tools
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_taken']; ?></div>
                <div class="stat-label">Total Taken</div>
            </div>
            <div class="stat-card overdue">
                <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                <div class="stat-label">Overdue</div>
            </div>
            <div class="stat-card on-time">
                <div class="stat-value"><?php echo $stats['on_time']; ?></div>
                <div class="stat-label">On Time</div>
            </div>
        </div>

        <!-- ── Filter bar ── -->
        <div class="filter-bar">
            <form method="GET" style="display:contents;">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="🔍 Search tool, technician, request #…">
                <select name="status">
                    <option value="all"     <?php echo $filterStatus==='all'    ?'selected':''; ?>>All Statuses</option>
                    <option value="overdue" <?php echo $filterStatus==='overdue'?'selected':''; ?>>Overdue Only</option>
                    <option value="ontime"  <?php echo $filterStatus==='ontime' ?'selected':''; ?>>On Time Only</option>
                </select>
                <select name="source">
                    <option value="all"             <?php echo $filterSource==='all'            ?'selected':''; ?>>All Sources</option>
                    <option value="assigned"         <?php echo $filterSource==='assigned'        ?'selected':''; ?>>Direct Assignments</option>
                    <option value="approved_request" <?php echo $filterSource==='approved_request'?'selected':''; ?>>Approved Requests</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
                <a href="taken.php" class="reset-btn"><i class="fas fa-times"></i> Reset</a>
            </form>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-left:auto;">
                <a href="?status=all&source=all" class="filter-chip all <?php echo ($filterStatus==='all'&&$filterSource==='all')?'active':''; ?>">All</a>
                <a href="?status=overdue&source=all" class="filter-chip over <?php echo $filterStatus==='overdue'?'active':''; ?>"><i class="fas fa-exclamation-triangle"></i> Overdue (<?php echo $stats['overdue']; ?>)</a>
                <a href="?status=ontime&source=all"  class="filter-chip ok   <?php echo $filterStatus==='ontime' ?'active':''; ?>"><i class="fas fa-check-circle"></i> On Time (<?php echo $stats['on_time']; ?>)</a>
                <a href="?status=all&source=approved_request" class="filter-chip req  <?php echo $filterSource==='approved_request'?'active':''; ?>"><i class="fas fa-clipboard-list"></i> Requests</a>
                <a href="?status=all&source=assigned"         class="filter-chip asgn <?php echo $filterSource==='assigned'       ?'active':''; ?>"><i class="fas fa-hand-holding"></i> Assigned</a>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tool Code</th>
                        <th>Tool Name</th>
                        <th>Technician</th>
                        <th>Assigned Date</th>
                        <th>Due Date</th>
                        <th>Days Taken</th>
                        <th>Status</th>
                        <th>Source</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allTools)): ?>
                    <tr>
                        <td colspan="10" class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No Records Found</h3>
                            <p><?php echo $search ? 'No tools match your search.' : 'All tools are available in the workshop.'; ?></p>
                            <?php if ($search || $filterStatus !== 'all' || $filterSource !== 'all'): ?>
                            <a href="taken.php" class="btn btn-primary" style="margin-top:1rem;">Clear Filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($allTools as $i => $tool): ?>
                    <tr>
                        <td style="color:var(--gray);font-size:.75rem;"><?php echo $offset + $i + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($tool['tool_code']); ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($tool['tool_name']); ?>
                            <br><small style="color:var(--gray);"><?php echo htmlspecialchars($tool['category'] ?? 'General'); ?></small>
                        </td>
                        <td>
                            <i class="fas fa-user" style="color:var(--gray);"></i> <?php echo htmlspecialchars($tool['technician_name']); ?>
                            <br><small style="color:var(--gray);"><?php echo htmlspecialchars($tool['technician_code']); ?></small>
                        </td>
                        <td><?php echo date('d M Y', strtotime($tool['assigned_date'])); ?></td>
                        <td>
                            <?php echo date('d M Y', strtotime($tool['expected_return_date'])); ?>
                            <?php if ($tool['return_status'] == 'Overdue'): ?>
                            <br><small style="color:var(--danger);font-weight:600;"><?php echo abs($tool['days_overdue']); ?> day(s) overdue</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $tool['days_taken']; ?> days</td>
                        <td>
                            <?php if ($tool['return_status'] == 'Overdue'): ?>
                            <span class="status-overdue"><i class="fas fa-exclamation-triangle"></i> Overdue</span>
                            <?php else: ?>
                            <span class="status-ontime"><i class="fas fa-check-circle"></i> On Time</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (isset($tool['source_type']) && $tool['source_type'] == 'approved_request'): ?>
                            <span class="source-badge request">
                                <i class="fas fa-clipboard-list"></i> Request
                                <?php if (!empty($tool['request_number'])): ?>
                                <br><small><?php echo htmlspecialchars($tool['request_number']); ?></small>
                                <?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="source-badge assigned">
                                <i class="fas fa-hand-holding"></i> Direct
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tool['assignment_id']): ?>
                            <button class="btn btn-success" onclick="returnTool(<?php echo $tool['id']; ?>, <?php echo $tool['assignment_id']; ?>, '<?php echo addslashes($tool['tool_name']); ?>')">
                                <i class="fas fa-undo-alt"></i> Return
                            </button>
                            <?php else: ?>
                            <button class="btn btn-warning" onclick="markAsTaken(<?php echo $tool['id']; ?>, '<?php echo addslashes($tool['tool_name']); ?>')">
                                <i class="fas fa-check-circle"></i> Mark Taken
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- ── Pagination ── -->
            <?php if ($totalRecords > 0): ?>
            <div class="pagination">
                <div class="pagination-info">
                    Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perPage, $totalRecords); ?> of <?php echo number_format($totalRecords); ?> records
                    <?php if ($totalPages > 1): ?> &bull; Page <?php echo $page; ?> of <?php echo $totalPages; ?><?php endif; ?>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination-links">
                    <a href="<?php echo pageUrl(1); ?>"        class="page-link <?php echo $page===1?'disabled':''; ?>"><i class="fas fa-angle-double-left"></i></a>
                    <a href="<?php echo pageUrl($page-1); ?>"  class="page-link <?php echo $page===1?'disabled':''; ?>"><i class="fas fa-angle-left"></i></a>
                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($totalPages, $page + 2);
                    if ($start > 1) echo '<span class="page-link disabled">…</span>';
                    for ($p = $start; $p <= $end; $p++):
                    ?>
                    <a href="<?php echo pageUrl($p); ?>" class="page-link <?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
                    <?php endfor;
                    if ($end < $totalPages) echo '<span class="page-link disabled">…</span>'; ?>
                    <a href="<?php echo pageUrl($page+1); ?>"      class="page-link <?php echo $page===$totalPages?'disabled':''; ?>"><i class="fas fa-angle-right"></i></a>
                    <a href="<?php echo pageUrl($totalPages); ?>"   class="page-link <?php echo $page===$totalPages?'disabled':''; ?>"><i class="fas fa-angle-double-right"></i></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="returnModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 1rem; width: 90%; max-width: 400px; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #3b82f6, #1e40af); padding: 1rem 1.5rem; color: white;">
                <h3 style="margin: 0;"><i class="fas fa-undo-alt"></i> Return Tool</h3>
            </div>
            <div style="padding: 1.5rem;">
                <p>Are you sure you want to return this tool?</p>
                <p><strong id="returnToolName"></strong></p>
                <div style="margin-top: 1rem;">
                    <label>Condition on Return:</label>
                    <select id="returnCondition" style="width: 100%; padding: 0.5rem; margin-top: 0.25rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;">
                        <option value="Good">Good</option>
                        <option value="Fair">Fair</option>
                        <option value="Poor">Poor</option>
                        <option value="Damaged">Damaged</option>
                    </select>
                </div>
                <div style="margin-top: 1rem;">
                    <label>Notes:</label>
                    <textarea id="returnNotes" rows="2" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" placeholder="Any issues or notes..."></textarea>
                </div>
            </div>
            <div style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button>
                <button class="btn btn-primary" id="confirmReturnBtn">Confirm Return</button>
            </div>
        </div>
    </div>

    <div id="takeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 1rem; width: 90%; max-width: 400px; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #10b981, #059669); padding: 1rem 1.5rem; color: white;">
                <h3 style="margin: 0;"><i class="fas fa-check-circle"></i> Mark Tool as Taken</h3>
            </div>
            <div style="padding: 1.5rem;">
                <p>Confirm that the technician has taken this tool:</p>
                <p><strong id="takeToolName"></strong></p>
                <div style="margin-top: 1rem;">
                    <label>Expected Return Date:</label>
                    <input type="date" id="expectedReturnDate" style="width: 100%; padding: 0.5rem; margin-top: 0.25rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;">
                </div>
                <div style="margin-top: 1rem;">
                    <label>Notes:</label>
                    <textarea id="takeNotes" rows="2" style="width: 100%; padding: 0.5rem; border: 1px solid #e2e8f0; border-radius: 0.5rem;" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button class="btn btn-secondary" onclick="closeTakeModal()">Cancel</button>
                <button class="btn btn-success" id="confirmTakeBtn">Confirm Taken</button>
            </div>
        </div>
    </div>

    <script>
        let currentToolId = null;
        let currentAssignmentId = null;
        
        function returnTool(toolId, assignmentId, toolName) {
            currentToolId = toolId;
            currentAssignmentId = assignmentId;
            document.getElementById('returnToolName').innerText = toolName;
            document.getElementById('returnModal').style.display = 'flex';
        }
        
        function closeReturnModal() {
            document.getElementById('returnModal').style.display = 'none';
            currentToolId = null;
            currentAssignmentId = null;
        }
        
        function markAsTaken(toolId, toolName) {
            currentToolId = toolId;
            document.getElementById('takeToolName').innerText = toolName;
            var defaultDate = new Date();
            defaultDate.setDate(defaultDate.getDate() + 7);
            var year = defaultDate.getFullYear();
            var month = String(defaultDate.getMonth() + 1).padStart(2, '0');
            var day = String(defaultDate.getDate()).padStart(2, '0');
            document.getElementById('expectedReturnDate').value = year + '-' + month + '-' + day;
            document.getElementById('takeModal').style.display = 'flex';
        }
        
        function closeTakeModal() {
            document.getElementById('takeModal').style.display = 'none';
            currentToolId = null;
        }
        
        document.getElementById('confirmReturnBtn').addEventListener('click', function() {
            if (!currentToolId || !currentAssignmentId) return;
            
            var condition = document.getElementById('returnCondition').value;
            var notes = document.getElementById('returnNotes').value;
            
            fetch('return_tool.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    tool_id: currentToolId, 
                    assignment_id: currentAssignmentId,
                    condition: condition,
                    notes: notes
                })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    alert('Tool returned successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
                closeReturnModal();
            })
            .catch(function(error) {
                alert('Error returning tool: ' + error);
                closeReturnModal();
            });
        });
        
        document.getElementById('confirmTakeBtn').addEventListener('click', function() {
            if (!currentToolId) return;
            
            var expectedReturnDate = document.getElementById('expectedReturnDate').value;
            var notes = document.getElementById('takeNotes').value;
            
            fetch('mark_taken.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    tool_id: currentToolId,
                    expected_return_date: expectedReturnDate,
                    notes: notes
                })
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    alert('Tool marked as taken successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
                closeTakeModal();
            })
            .catch(function(error) {
                alert('Error: ' + error);
                closeTakeModal();
            });
        });
        
        window.onclick = function(e) {
            if (e.target.id === 'returnModal') {
                closeReturnModal();
            }
            if (e.target.id === 'takeModal') {
                closeTakeModal();
            }
        }
    </script>
</body>
</html>