<?php
// job_cards.php - Job Cards Management (Modern UI with Real Database Data)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ==================== AUTO‑FIX TABLE STRUCTURE ====================
    $columns = $conn->query("SHOW COLUMNS FROM job_cards")->fetchAll(PDO::FETCH_COLUMN);

    // Ensure quotations table has job_card_id column so status sync works
    try {
        $conn->exec("ALTER TABLE quotations ADD COLUMN job_card_id INT DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists — ignore
    }
    // Add foreign-key-style index if missing (non-fatal)
    try {
        $conn->exec("ALTER TABLE quotations ADD INDEX idx_job_card_id (job_card_id)");
    } catch (PDOException $e) { /* ignore */ }
    
    $requiredColumns = [
        'job_number' => 'VARCHAR(50) NOT NULL',
        'customer_id' => 'INT NOT NULL',
        'vehicle_reg' => 'VARCHAR(50)',
        'vehicle_make' => 'VARCHAR(50)',
        'vehicle_model' => 'VARCHAR(100)',
        'vehicle_year' => 'VARCHAR(4)',
        'odometer_reading' => 'VARCHAR(20)',
        'fuel_level' => 'VARCHAR(20)',
        'date_received' => 'DATE NOT NULL',
        'date_promised' => 'DATE',
        'date_completed' => 'DATE',
        'status' => "ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending'",
        'priority' => "VARCHAR(20) DEFAULT 'normal'",
        'assigned_technician_id' => 'INT',
        'customer_signature' => 'TEXT',
        'notes' => 'TEXT',
        'inspection_data' => 'TEXT',
        'work_items' => 'TEXT',
        'brought_by' => 'VARCHAR(100)',
        'terms_accepted' => 'TINYINT DEFAULT 0',
        'created_by' => 'INT',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME ON UPDATE CURRENT_TIMESTAMP',
        'deleted_at' => 'DATETIME'
    ];
    
    foreach ($requiredColumns as $col => $def) {
        if (!in_array($col, $columns)) {
            try {
                $conn->exec("ALTER TABLE job_cards ADD COLUMN {$col} {$def}");
            } catch (PDOException $e) {
                // column might already exist, ignore
            }
        }
    }
    
    // ==================== STATISTICS ====================
    $totalStmt = $conn->query("SELECT COUNT(*) as total FROM job_cards WHERE deleted_at IS NULL");
    $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
    
    $pendingStmt = $conn->query("SELECT COUNT(*) as count FROM job_cards WHERE status = 'pending' AND deleted_at IS NULL");
    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);
    
    $inProgressStmt = $conn->query("SELECT COUNT(*) as count FROM job_cards WHERE status = 'in_progress' AND deleted_at IS NULL");
    $inProgress = $inProgressStmt->fetch(PDO::FETCH_ASSOC);
    
    $completedStmt = $conn->query("SELECT COUNT(*) as count FROM job_cards WHERE status = 'completed' AND deleted_at IS NULL");
    $completed = $completedStmt->fetch(PDO::FETCH_ASSOC);
    
    $cancelledStmt = $conn->query("SELECT COUNT(*) as count FROM job_cards WHERE status = 'cancelled' AND deleted_at IS NULL");
    $cancelled = $cancelledStmt->fetch(PDO::FETCH_ASSOC);
    
    $job_stats = [
        'total_jobs' => $total['total'] ?? 0,
        'pending_jobs' => $pending['count'] ?? 0,
        'in_progress_jobs' => $inProgress['count'] ?? 0,
        'completed_jobs' => $completed['count'] ?? 0,
        'cancelled_jobs' => $cancelled['count'] ?? 0
    ];
    
    // ==================== FETCH JOB CARDS ====================
    $stmt = $conn->query("
        SELECT 
            jc.*,
            c.full_name as customer_full_name,
            c.telephone as customer_phone,
            c.email as customer_email,
            tech.full_name as technician_name
        FROM job_cards jc
        LEFT JOIN customers c ON jc.customer_id = c.id
        LEFT JOIN technicians tech ON jc.assigned_technician_id = tech.id
        WHERE jc.deleted_at IS NULL
        ORDER BY jc.created_at DESC
    ");
    $job_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Flash messages
    if (isset($_SESSION['flash_success'])) {
        $flash_success = $_SESSION['flash_success'];
        unset($_SESSION['flash_success']);
    }
    
    if (isset($_SESSION['flash_error'])) {
        $flash_error = $_SESSION['flash_error'];
        unset($_SESSION['flash_error']);
    }
    
} catch(PDOException $e) {
    error_log("Job Cards Error: " . $e->getMessage());
    $job_stats = ['total_jobs' => 0, 'pending_jobs' => 0, 'in_progress_jobs' => 0, 'completed_jobs' => 0, 'cancelled_jobs' => 0];
    $job_cards = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Savant Motors | Job Cards Management</title>
    <!-- Modern Fonts + Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at 15% 30%, #f4f9fe 0%, #eef2f8 100%);
            color: #0b2b3f;
            scroll-behavior: smooth;
        }

        /* Glass morphism + modern variables */
        :root {
            --primary: #2266e3;
            --primary-dark: #0e4ac0;
            --primary-soft: #eef4ff;
            --secondary: #8b5cf6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafc;
            --gray-100: #f1f4f9;
            --gray-300: #e2e8f0;
            --gray-500: #6b7280;
            --gray-700: #334155;
            --dark: #0f172a;
            --glass-bg: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.6);
            --shadow-sm: 0 8px 20px rgba(0, 0, 0, 0.02), 0 2px 6px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 20px 35px -12px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0, 0, 0, 0.02);
            --shadow-glow: 0 8px 30px -6px rgba(34, 102, 227, 0.2);
            --transition-smooth: all 0.25s cubic-bezier(0.2, 0, 0, 1);
        }

        /* --- Sidebar (modern frosted) --- */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-right: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.02);
            z-index: 1000;
            overflow-y: auto;
            transition: var(--transition-smooth);
        }

        .sidebar-header {
            padding: 32px 24px;
            text-align: center;
            border-bottom: 1px solid rgba(34, 102, 227, 0.1);
        }

        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            box-shadow: 0 12px 20px -10px rgba(34, 102, 227, 0.3);
        }

        .logo-icon i {
            font-size: 32px;
            color: white;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(120deg, #1e3a8a, #3b82f6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.3px;
        }

        .sidebar-menu {
            padding: 24px 0;
        }

        .nav-section-title {
            padding: 12px 24px 6px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
            color: var(--gray-500);
        }

        .nav-item {
            padding: 12px 24px;
            margin: 4px 16px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--gray-700);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition-smooth);
            font-size: 14px;
            cursor: pointer;
        }

        .nav-item i {
            width: 24px;
            font-size: 1.2rem;
        }

        .nav-item:hover {
            background: rgba(34, 102, 227, 0.08);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-item.active {
            background: linear-gradient(95deg, rgba(34, 102, 227, 0.12), rgba(139, 92, 246, 0.08));
            color: var(--primary);
            font-weight: 600;
            border-left: 3px solid var(--primary);
        }

        /* main content area */
        .main-content {
            margin-left: 280px;
            padding: 32px 36px;
            min-height: 100vh;
        }

        /* modern card style */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(8px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow-md);
            transition: var(--transition-smooth);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #0f2b3d, #2266e3);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: var(--gray-700);
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(105deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(34, 102, 227, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 22px -10px rgba(34, 102, 227, 0.4);
        }

        .btn-secondary {
            background: white;
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-50);
            border-color: var(--primary);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
            transform: scale(0.98);
        }

        .btn-sm {
            padding: 6px 16px;
            font-size: 0.75rem;
        }

        /* stats grid modern */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            padding: 20px 20px;
            border: 1px solid rgba(255, 255, 255, 0.6);
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.85);
            box-shadow: var(--shadow-glow);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .stat-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--gray-500);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.2;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        /* filter bar */
        .filter-bar {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 28px;
            padding: 20px 24px;
            margin-bottom: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
            border: 1px solid rgba(255,255,240,0.6);
        }

        .filter-group {
            flex: 1;
            min-width: 140px;
        }

        .filter-group label {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray-500);
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            display: block;
        }

        .filter-group input, .filter-group select {
            width: 100%;
            padding: 10px 16px;
            border-radius: 24px;
            border: 1.5px solid #e9eef3;
            background: white;
            font-family: 'Inter', monospace;
            font-size: 0.85rem;
            transition: 0.2s;
        }

        .filter-group input:focus, .filter-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(34,102,227,0.15);
        }

        /* table modern */
        .table-container {
            border-radius: 32px;
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.7);
            overflow-x: auto;
            box-shadow: var(--shadow-sm);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            text-align: left;
            padding: 18px 16px;
            background: rgba(249, 250, 252, 0.7);
            font-weight: 700;
            color: var(--gray-700);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--gray-300);
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
            vertical-align: middle;
        }

        tr {
            transition: background 0.2s;
        }

        tr:hover td {
            background: rgba(34, 102, 227, 0.03);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 14px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            gap: 6px;
            backdrop-filter: blur(2px);
        }

        .status-pending { background: #ffedd5; color: #9a3412; }
        .status-in_progress { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #ffe4e4; color: #991b1b; }

        .priority-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .priority-high { background: #fee2e2; color: #b91c1c; }
        .priority-urgent { background: #ffedd5; color: #c2410c; }
        .priority-normal { background: #e0f2fe; color: #0369a1; }
        .priority-low { background: #dcfce7; color: #15803d; }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 28px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .action-btn.view:hover { background: var(--primary); color: white; border-color: var(--primary); transform: translateY(-1px);}
        .action-btn.edit:hover { background: #2563eb20; color: var(--primary-dark); border-color: var(--primary);}
        .action-btn.print:hover { background: #10b98120; color: #0b7e4a; border-color: #10b981;}

        .bulk-actions {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            border-radius: 60px;
            padding: 10px 20px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 16px;
            border: 1px solid white;
            box-shadow: 0 6px 14px rgba(0,0,0,0.02);
        }

        .bulk-actions.show {
            display: flex;
        }

        .empty-state {
            text-align: center;
            padding: 64px 20px;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .alert {
            padding: 12px 20px;
            border-radius: 20px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }

        .checkbox-col {
            width: 40px;
            text-align: center;
        }
        .checkbox-col input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .pagination-modern {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        @media (max-width: 1024px) {
            .sidebar {
                left: -280px;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 680px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .filter-bar {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .action-buttons {
                flex-direction: column;
            }
            .action-btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar Frosted Glass -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="logo-icon"><i class="fas fa-clipboard-list"></i></div>
        <div class="logo-text">SAVANT MOTORS</div>
    </div>
    <div class="sidebar-menu">
        <div class="nav-section">
            <div class="nav-section-title">MAIN</div>
            <a href="dashboard_erp.php" class="nav-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="job_cards.php" class="nav-item active"><i class="fas fa-clipboard-list"></i> Job Cards</a>
            <a href="new_job.php" class="nav-item"><i class="fas fa-plus-circle"></i> New Job Card</a>
            <div style="margin-top: 40px;"><div class="nav-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div></div>
        </div>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fas fa-clipboard-list" style="background: none; -webkit-background-clip: unset; color: #2266e3;"></i> Job Cards Management</h1>
        </div>
        <a href="new_job.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> New Job Card</a>
    </div>

    <!-- Flash Messages -->
    <?php if (isset($flash_success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($flash_success); ?></div>
    <?php endif; ?>
    <?php if (isset($flash_error)): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($flash_error); ?></div>
    <?php endif; ?>

    <!-- Stats Glassmorphism -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Total Jobs</span><div class="stat-icon" style="background:#eef4ff; color:#2266e3;"><i class="fas fa-chart-simple"></i></div></div>
            <div class="stat-value"><?php echo number_format($job_stats['total_jobs']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Pending</span><div class="stat-icon" style="background:#fff3e3; color:#f59e0b;"><i class="fas fa-hourglass-half"></i></div></div>
            <div class="stat-value"><?php echo number_format($job_stats['pending_jobs']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">In Progress</span><div class="stat-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fas fa-gear"></i></div></div>
            <div class="stat-value"><?php echo number_format($job_stats['in_progress_jobs']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Completed</span><div class="stat-icon" style="background:#dcfce7; color:#10b981;"><i class="fas fa-check-circle"></i></div></div>
            <div class="stat-value"><?php echo number_format($job_stats['completed_jobs']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-header"><span class="stat-title">Cancelled</span><div class="stat-icon" style="background:#ffe4e4; color:#ef4444;"><i class="fas fa-ban"></i></div></div>
            <div class="stat-value"><?php echo number_format($job_stats['cancelled_jobs']); ?></div>
        </div>
    </div>

    <!-- Bulk Actions Bar -->
    <div class="bulk-actions" id="bulkActions">
        <i class="fas fa-check-circle" style="color: var(--primary);"></i>
        <span class="selected-count" id="selectedCount">0</span> job(s) selected
        <button class="btn btn-danger btn-sm" onclick="bulkDelete()"><i class="fas fa-trash-alt"></i> Delete Selected</button>
        <button class="btn btn-secondary btn-sm" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-group">
            <label><i class="fas fa-search"></i> Search</label>
            <input type="text" id="searchInput" placeholder="Job #, Customer, Vehicle, Phone...">
        </div>
        <div class="filter-group">
            <label><i class="fas fa-filter"></i> Status</label>
            <select id="statusFilter">
                <option value="all">All Status</option>
                <option value="pending">Pending</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-flag"></i> Priority</label>
            <select id="priorityFilter">
                <option value="all">All Priority</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Date Range</label>
            <select id="dateFilter">
                <option value="all">All Time</option>
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month">This Month</option>
            </select>
        </div>
        <button class="btn btn-secondary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
        <button class="btn btn-secondary" onclick="resetFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
        <button class="btn btn-primary" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export CSV</button>
    </div>

    <!-- Job Cards Table -->
    <div class="table-container">
        <table id="jobCardsTable">
            <thead>
                <tr>
                    <th class="checkbox-col"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                    <th>Job #</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Vehicle Reg</th>
                    <th>Model</th>
                    <th>Received Date</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Technician</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="jobCardsTableBody">
                <?php if (empty($job_cards)): ?>
                    <tr>
                        <td colspan="11" class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>No Job Cards Found</h3>
                            <p>Click "New Job Card" to create your first job card</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($job_cards as $job): 
                        $priority = $job['priority'] ?? 'normal';
                        $priorityClass = '';
                        if ($priority == 'high') $priorityClass = 'priority-high';
                        elseif ($priority == 'urgent') $priorityClass = 'priority-urgent';
                        elseif ($priority == 'normal') $priorityClass = 'priority-normal';
                        elseif ($priority == 'low') $priorityClass = 'priority-low';
                    ?>
                        <tr data-job="<?php echo strtolower($job['job_number'] ?? ''); ?>" 
                            data-customer="<?php echo strtolower($job['customer_full_name'] ?? ''); ?>" 
                            data-vehicle="<?php echo strtolower($job['vehicle_reg'] ?? ''); ?>"
                            data-phone="<?php echo strtolower($job['customer_phone'] ?? ''); ?>"
                            data-status="<?php echo $job['status'] ?? ''; ?>"
                            data-priority="<?php echo $priority; ?>"
                            data-date="<?php echo $job['date_received']; ?>">
                            <td class="checkbox-col"><input type="checkbox" class="job-checkbox" value="<?php echo $job['id']; ?>"></td>
                            <td><strong><?php echo htmlspecialchars($job['job_number'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($job['customer_full_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($job['customer_phone'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($job['vehicle_reg'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($job['vehicle_model'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($job['date_received'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $job['status']; ?>">
                                    <i class="fas <?php echo $job['status'] == 'completed' ? 'fa-check-circle' : ($job['status'] == 'pending' ? 'fa-clock' : 'fa-spinner'); ?>"></i>
                                    <?php echo strtoupper(str_replace('_', ' ', $job['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="priority-badge <?php echo $priorityClass; ?>">
                                    <?php echo strtoupper($priority); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($job['technician_name'] ?? 'Unassigned'); ?></td>
                            <td class="action-buttons">
                                <a href="view_job.php?id=<?php echo $job['id']; ?>" class="action-btn view" target="_blank"><i class="fas fa-eye"></i> View</a>
                                <a href="edit_job.php?id=<?php echo $job['id']; ?>" class="action-btn edit"><i class="fas fa-edit"></i> Edit</a>
                                <a href="print_job.php?id=<?php echo $job['id']; ?>" class="action-btn print" target="_blank"><i class="fas fa-print"></i> Print</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination-modern">
        <div style="font-size:0.8rem; color:var(--gray-500);">
            <i class="fas fa-list-ul"></i> Showing <span id="visibleCount"><?php echo count($job_cards); ?></span> of <span id="totalCount"><?php echo count($job_cards); ?></span> job cards
        </div>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-secondary btn-sm" id="prevBtn" disabled><i class="fas fa-chevron-left"></i> Previous</button>
            <span style="padding:6px 16px; background:white; border-radius:40px; font-size:0.8rem;" id="pageInfo">Page 1</span>
            <button class="btn btn-secondary btn-sm" id="nextBtn">Next <i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<script>
    // Pagination and filtering variables
    let currentPage = 1;
    let rowsPerPage = 10;
    let currentRows = [];

    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const status = document.getElementById('statusFilter').value;
        const priority = document.getElementById('priorityFilter').value;
        const dateRange = document.getElementById('dateFilter').value;
        const today = new Date().toISOString().split('T')[0];
        
        const rows = document.querySelectorAll('#jobCardsTableBody tr');
        let visibleRows = [];
        
        rows.forEach(row => {
            if (row.querySelector('.empty-state')) return;
            
            const job = row.getAttribute('data-job') || '';
            const customer = row.getAttribute('data-customer') || '';
            const vehicle = row.getAttribute('data-vehicle') || '';
            const phone = row.getAttribute('data-phone') || '';
            const rowStatus = row.getAttribute('data-status') || '';
            const rowPriority = row.getAttribute('data-priority') || '';
            const rowDate = row.getAttribute('data-date') || '';
            
            let matchesSearch = search === '' || job.includes(search) || customer.includes(search) || vehicle.includes(search) || phone.includes(search);
            let matchesStatus = status === 'all' || rowStatus === status;
            let matchesPriority = priority === 'all' || rowPriority === priority;
            let matchesDate = true;
            
            if (dateRange !== 'all') {
                const rowDateObj = new Date(rowDate);
                const todayObj = new Date();
                if (dateRange === 'today') {
                    matchesDate = rowDate === today;
                } else if (dateRange === 'week') {
                    const weekAgo = new Date();
                    weekAgo.setDate(weekAgo.getDate() - 7);
                    matchesDate = rowDateObj >= weekAgo;
                } else if (dateRange === 'month') {
                    const monthAgo = new Date();
                    monthAgo.setDate(monthAgo.getDate() - 30);
                    matchesDate = rowDateObj >= monthAgo;
                }
            }
            
            const show = matchesSearch && matchesStatus && matchesPriority && matchesDate;
            if (show) {
                visibleRows.push(row);
            }
        });
        
        currentRows = visibleRows;
        document.getElementById('totalCount').innerText = currentRows.length;
        currentPage = 1;
        updatePagination();
    }
    
    function updatePagination() {
        const totalPages = Math.ceil(currentRows.length / rowsPerPage);
        
        currentRows.forEach((row, index) => {
            const page = Math.floor(index / rowsPerPage) + 1;
            row.style.display = page === currentPage ? '' : 'none';
        });
        
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = Math.min(startIndex + rowsPerPage, currentRows.length);
        const visibleCount = Math.min(rowsPerPage, currentRows.length - (currentPage - 1) * rowsPerPage);
        
        document.getElementById('visibleCount').innerText = visibleCount > 0 ? visibleCount : 0;
        document.getElementById('pageInfo').innerText = `Page ${currentPage} of ${totalPages || 1}`;
        document.getElementById('prevBtn').disabled = currentPage <= 1;
        document.getElementById('nextBtn').disabled = currentPage >= totalPages;
    }
    
    function previousPage() {
        if (currentPage > 1) {
            currentPage--;
            updatePagination();
        }
    }
    
    function nextPage() {
        const totalPages = Math.ceil(currentRows.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            updatePagination();
        }
    }
    
    function resetFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('statusFilter').value = 'all';
        document.getElementById('priorityFilter').value = 'all';
        document.getElementById('dateFilter').value = 'all';
        applyFilters();
    }
    
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.job-checkbox');
        checkboxes.forEach(cb => {
            if (cb.closest('tr').style.display !== 'none') {
                cb.checked = selectAll.checked;
            }
        });
        updateBulkActions();
    }
    
    function updateBulkActions() {
        const checkboxes = document.querySelectorAll('.job-checkbox:checked');
        const visibleCheckboxes = Array.from(checkboxes).filter(cb => cb.closest('tr').style.display !== 'none');
        const count = visibleCheckboxes.length;
        const bulkActions = document.getElementById('bulkActions');
        
        if (count > 0) {
            bulkActions.classList.add('show');
            document.getElementById('selectedCount').innerText = count;
        } else {
            bulkActions.classList.remove('show');
            document.getElementById('selectAll').checked = false;
        }
    }
    
    function clearSelection() {
        document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = false);
        updateBulkActions();
    }
    
    function bulkDelete() {
        const checkboxes = document.querySelectorAll('.job-checkbox:checked');
        const visibleCheckboxes = Array.from(checkboxes).filter(cb => cb.closest('tr').style.display !== 'none');
        if (visibleCheckboxes.length === 0) return;
        
        if (confirm(`Are you sure you want to delete ${visibleCheckboxes.length} job card(s)? This action cannot be undone.`)) {
            const ids = visibleCheckboxes.map(cb => cb.value);
            
            fetch('delete_jobs.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: ids })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting');
            });
        }
    }
    
    function exportToExcel() {
        const table = document.getElementById('jobCardsTable');
        const rows = table.querySelectorAll('tbody tr');
        let csv = [];
        
        // Get headers
        const headers = [];
        table.querySelectorAll('thead th').forEach((th, index) => {
            if (index !== 0) {
                headers.push(th.innerText.trim());
            }
        });
        csv.push(headers.join(','));
        
        // Get data rows (only visible ones)
        rows.forEach(row => {
            if (row.querySelector('.empty-state')) return;
            if (row.style.display === 'none') return;
            
            const rowData = [];
            const cells = row.querySelectorAll('td');
            cells.forEach((cell, index) => {
                if (index !== 0) {
                    let text = cell.innerText.trim();
                    text = text.replace(/\n/g, ' ').replace(/\s+/g, ' ');
                    rowData.push('"' + text + '"');
                }
            });
            if (rowData.length > 0) {
                csv.push(rowData.join(','));
            }
        });
        
        const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `job_cards_${new Date().toISOString().slice(0,10)}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
    }
    
    // Event listeners
    document.getElementById('searchInput')?.addEventListener('keyup', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('priorityFilter')?.addEventListener('change', applyFilters);
    document.getElementById('dateFilter')?.addEventListener('change', applyFilters);
    document.getElementById('prevBtn')?.addEventListener('click', previousPage);
    document.getElementById('nextBtn')?.addEventListener('click', nextPage);
    
    document.querySelectorAll('.job-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkActions);
    });
    
    function logout() {
        fetch('/api/auth.php?action=logout', { method: 'POST' }).catch(() => {});
        window.location.href = 'index.php';
    }
    document.getElementById('logoutBtn')?.addEventListener('click', logout);
    
    // Initialize
    setTimeout(() => {
        applyFilters();
        updateBulkActions();
    }, 100);
</script>
</body>
</html>