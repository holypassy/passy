<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Handle AJAX for pending requests (not yet approved)
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'pending_requests') {
        header('Content-Type: application/json');
        $techId = isset($_GET['technician_id']) ? (int)$_GET['technician_id'] : null;
        
        $query = "
            SELECT 
                tr.id,
                tr.request_number,
                tr.created_at,
                tr.urgency,
                tr.number_plate,
                tr.reason,
                tr.status as request_status,
                GROUP_CONCAT(
                    CONCAT(
                        COALESCE(tl.tool_code, 'NEW:'),
                        ' ',
                        COALESCE(tl.tool_name, rt.new_tool_description),
                        ' (x', rt.quantity, ')'
                    ) SEPARATOR '<br>'
                ) as tools_summary
            FROM tool_requests tr
            LEFT JOIN request_tools rt ON tr.id = rt.request_id
            LEFT JOIN tools tl ON rt.tool_id = tl.id
            WHERE tr.status = 'pending'
        ";
        if ($techId) {
            $query .= " AND tr.technician_id = :tech_id";
        }
        $query .= " GROUP BY tr.id ORDER BY tr.created_at DESC";
        
        $stmt = $conn->prepare($query);
        if ($techId) {
            $stmt->bindParam(':tech_id', $techId);
        }
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($requests);
        exit;
    }
    
    // Handle AJAX for approved/assigned tools (Tools Taken - already approved and assigned)
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'approved_assignments') {
        header('Content-Type: application/json');
        $techId = isset($_GET['technician_id']) ? (int)$_GET['technician_id'] : null;
        
        $query = "
            SELECT 
                ta.id as assignment_id,
                ta.tool_id,
                ta.assigned_date,
                ta.expected_return_date,
                ta.actual_return_date,
                ta.condition_on_assign,
                ta.condition_on_return,
                ta.notes as assignment_notes,
                t.tool_code,
                t.tool_name,
                t.category,
                t.serial_number,
                t.status as tool_status,
                DATEDIFF(NOW(), ta.assigned_date) as days_assigned,
                CASE 
                    WHEN ta.actual_return_date IS NOT NULL THEN 'Returned'
                    WHEN ta.expected_return_date < CURDATE() AND ta.actual_return_date IS NULL THEN 'Overdue'
                    ELSE 'Currently Assigned'
                END as assignment_status
            FROM tool_assignments ta
            INNER JOIN tools t ON ta.tool_id = t.id
            WHERE 1=1
        ";
        if ($techId) {
            $query .= " AND ta.technician_id = :tech_id";
        }
        $query .= " ORDER BY ta.assigned_date DESC";
        
        $stmt = $conn->prepare($query);
        if ($techId) {
            $stmt->bindParam(':tech_id', $techId);
        }
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($assignments);
        exit;
    }
    
    // Get technicians data with tool assignment stats
    $stmt = $conn->query("
        SELECT 
            t.id,
            t.technician_code,
            t.full_name,
            t.specialization,
            t.phone,
            t.email,
            t.hire_date,
            t.experience_years,
            t.status,
            t.is_blocked,
            COUNT(DISTINCT ta.id) as total_tools_assigned,
            COUNT(DISTINCT CASE WHEN ta.status = 'assigned' AND ta.actual_return_date IS NULL THEN ta.id END) as current_tools,
            COUNT(DISTINCT CASE WHEN ta.is_overdue = 1 OR (ta.expected_return_date < CURDATE() AND ta.actual_return_date IS NULL) THEN ta.id END) as overdue_tools
        FROM technicians t
        LEFT JOIN tool_assignments ta ON t.id = ta.technician_id
        WHERE t.deleted_at IS NULL
        GROUP BY t.id
        ORDER BY t.is_blocked DESC, t.status DESC, t.full_name ASC
    ");
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get ALL staff members
    $stmt = $conn->query("
        SELECT 
            s.id,
            s.full_name,
            s.staff_code,
            s.position,
            s.department,
            s.phone,
            s.email,
            s.hire_date,
            s.status,
            s.is_blocked,
            s.profile_image
        FROM staff s
        WHERE s.deleted_at IS NULL
        ORDER BY s.department, s.full_name
    ");
    $staffMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending request counts per technician (not yet approved)
    $stmt = $conn->query("
        SELECT technician_id, COUNT(*) as pending_count
        FROM tool_requests
        WHERE status = 'pending'
        GROUP BY technician_id
    ");
    $pending_counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pending_counts[$row['technician_id']] = $row['pending_count'];
    }
    
    // Get active/approved tool assignments counts per technician (tools currently taken)
    $stmt = $conn->query("
        SELECT technician_id, COUNT(*) as active_count
        FROM tool_assignments
        WHERE actual_return_date IS NULL
        GROUP BY technician_id
    ");
    $active_assignments = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $active_assignments[$row['technician_id']] = $row['active_count'];
    }
    
    // Get technician statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' AND is_blocked = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN status = 'on_leave' AND is_blocked = 0 THEN 1 ELSE 0 END) as on_leave
        FROM technicians
        WHERE deleted_at IS NULL
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get staff statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' AND is_blocked = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN status = 'on_leave' AND is_blocked = 0 THEN 1 ELSE 0 END) as on_leave
        FROM staff
        WHERE deleted_at IS NULL
    ");
    $staffStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get departments for filter
    $stmt = $conn->query("SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    error_log("Technicians Error: " . $e->getMessage());
    $technicians = [];
    $staffMembers = [];
    $pending_counts = [];
    $active_assignments = [];
    $stats = ['total' => 0, 'active' => 0, 'blocked' => 0, 'on_leave' => 0];
    $staffStats = ['total' => 0, 'active' => 0, 'blocked' => 0, 'on_leave' => 0];
    $departments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff & Technicians Management | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif;
            background: radial-gradient(circle at 10% 30%, rgba(59,130,246,0.05), rgba(15,23,42,0.02));
            min-height: 100vh;
        }

        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo-area { display: flex; align-items: center; gap: 20px; }
        .logo-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        .logo-text { font-size: 20px; font-weight: 700; color: var(--dark); }

        .user-menu { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-name { font-weight: 600; color: var(--dark); }
        .user-role { font-size: 11px; color: var(--gray); text-transform: uppercase; }
        .logout-btn {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .logout-btn:hover { background: var(--danger); color: white; }

        .sidebar {
            position: fixed;
            left: 0;
            top: 70px;
            width: 260px;
            height: calc(100vh - 70px);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }

        .sidebar-menu { padding: 24px 12px; }
        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.2s;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(59, 130, 246, 0.1); color: var(--primary-light); }

        .main-content { margin-left: 260px; padding: 30px 40px; min-height: calc(100vh - 70px); }

        .tabs-container { margin-bottom: 30px; }
        .tabs { display: flex; gap: 8px; border-bottom: 2px solid var(--border); }
        .tab-btn {
            padding: 12px 28px;
            background: none;
            border: none;
            font-size: 15px;
            font-weight: 600;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 40px 40px 0 0;
            position: relative;
        }
        .tab-btn:hover { color: var(--primary-light); background: rgba(59,130,246,0.05); }
        .tab-btn.active { color: var(--primary); background: white; }
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary);
        }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--dark), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: var(--gray);
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59,130,246,0.4); }
        .btn-secondary { background: white; border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--light); transform: translateY(-1px); }
        .btn-sm { padding: 6px 12px; font-size: 11px; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            border-radius: 28px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.blue { background: #dbeafe; color: var(--primary-light); }
        .stat-icon.purple { background: #e9d5ff; color: #9333ea; }
        .stat-icon.green { background: #dcfce7; color: var(--success); }
        .stat-icon.orange { background: #fed7aa; color: var(--warning); }
        .stat-info h3 { font-size: 12px; color: var(--gray); margin-bottom: 4px; text-transform: uppercase; }
        .stat-info .value { font-size: 28px; font-weight: 800; color: var(--dark); }

        .filter-bar {
            background: white;
            border-radius: 28px;
            padding: 20px 24px;
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid var(--border);
        }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 24px;
            font-size: 13px;
        }

        .table-container {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th {
            text-align: left;
            padding: 16px 16px;
            background: var(--light);
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            font-size: 11px;
            border-bottom: 1px solid var(--border);
        }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:hover { background: #fafbff; }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            min-width: 80px;
            text-align: center;
        }
        .status-active { background: #dcfce7; color: #166534; }
        .status-blocked { background: #fee2e2; color: #991b1b; }
        .status-on_leave { background: #fed7aa; color: #9a3412; }
        .pending-badge {
            background: #fef3c7;
            color: #d97706;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 30px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .tools-badge {
            background: #dbeafe;
            color: #1e40af;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 30px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .position-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 600;
            background: #e0e7ff;
            color: #4338ca;
        }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            border: none;
            background: white;
            border: 1px solid var(--border);
            color: var(--gray);
            transition: all 0.2s;
        }
        .action-btn.view:hover { background: var(--primary-light); color: white; }
        .action-btn.edit:hover { background: var(--warning); color: white; }
        .action-btn.requests:hover { background: #f97316; color: white; }
        .action-btn.tools-taken:hover { background: #10b981; color: white; }
        .action-btn.unblock:hover { background: var(--success); color: white; }

        .checkbox-col { width: 40px; text-align: center; }
        .checkbox-col input { width: 18px; height: 18px; cursor: pointer; }
        .bulk-actions {
            background: white;
            border-radius: 16px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
        }
        .bulk-actions.show { display: flex; }
        .selected-count { font-size: 13px; font-weight: 600; color: var(--primary); }
        .empty-state { text-align: center; padding: 60px; color: var(--gray); }
        .empty-state i { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }
        .pagination { margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }

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
            border-radius: 32px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 20px 25px;
            border-radius: 32px 32px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 40px;
            color: white;
            cursor: pointer;
        }
        .modal-body { padding: 25px; }
        .request-item, .assignment-item {
            background: #f9fafb;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid;
            transition: all 0.2s;
        }
        .request-item { border-left-color: var(--warning); }
        .assignment-item { border-left-color: var(--success); }
        .assignment-item.overdue { border-left-color: var(--danger); background: #fee2e2; }
        .request-item:hover, .assignment-item:hover { transform: translateX(4px); background: white; }
        .request-header, .assignment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .request-number, .tool-code { font-weight: 800; color: var(--primary-dark); font-size: 1rem; }
        .request-urgency, .assignment-status {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 40px;
        }
        .urgency-emergency, .assignment-status.overdue { background: #fee2e2; color: #dc2626; }
        .urgency-urgent, .assignment-status.active { background: #fed7aa; color: #9a3412; }
        .urgency-normal, .assignment-status.returned { background: #dcfce7; color: #166534; }
        .request-detail { font-size: 13px; margin-top: 10px; color: var(--gray); display: flex; gap: 8px; }
        .request-detail i { width: 20px; }

        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 20px; }
            .stats-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
            .action-buttons { flex-direction: column; }
        }
        .text-danger { color: var(--danger); }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="logo-area">
            <div class="logo-icon"><i class="fas fa-users-cog"></i></div>
            <div class="logo-text">SAVANT MOTORS</div>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div>
            </div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="sidebar">
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="job_cards.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
            <a href="technicians.php" class="menu-item active"><i class="fas fa-users-cog"></i> Staff & Technicians</a>
            <a href="tools/index.php" class="menu-item"><i class="fas fa-tools"></i> Tool Management</a>
            <a href="tool_requests/index.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Tool Requests</a>
            <a href="customers/index.php" class="menu-item"><i class="fas fa-users"></i> Customers</a>
            <a href="attendance.php" class="menu-item"><i class="fas fa-clock"></i> Attendance</a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-users-cog"></i> Staff & Technicians Management</h1>
            </div>
            <div>
                <button class="btn btn-primary" onclick="openAddStaffModal()"><i class="fas fa-user-plus"></i> Add Staff</button>
                <button class="btn btn-secondary" onclick="openAddTechnicianModal()" style="margin-left: 10px;"><i class="fas fa-wrench"></i> Add Technician</button>
                <button class="btn btn-secondary" onclick="exportToExcel()" style="margin-left: 10px;"><i class="fas fa-file-excel"></i> Export</button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div class="stat-info"><h3>Total Staff</h3><div class="value"><?php echo $staffStats['total']; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-user-cog"></i></div><div class="stat-info"><h3>Total Technicians</h3><div class="value"><?php echo $stats['total']; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-info"><h3>Active Staff</h3><div class="value"><?php echo $staffStats['active']; ?></div></div></div>
            <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-umbrella-beach"></i></div><div class="stat-info"><h3>On Leave</h3><div class="value"><?php echo ($staffStats['on_leave'] + $stats['on_leave']); ?></div></div></div>
        </div>

        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('staff')">All Staff Members (<?php echo count($staffMembers); ?>)</button>
                <button class="tab-btn" onclick="switchTab('technicians')">Technicians (<?php echo count($technicians); ?>)</button>
            </div>
        </div>

        <!-- Staff Tab -->
        <div id="staffTab" class="tab-content active">
            <div class="filter-bar">
                <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="staffSearchInput" placeholder="Name, code, phone..."></div>
                <div class="filter-group"><label><i class="fas fa-building"></i> Department</label><select id="departmentFilter"><option value="all">All Departments</option><?php foreach ($departments as $dept): ?><option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option><?php endforeach; ?></select></div>
                <div class="filter-group"><label><i class="fas fa-filter"></i> Status</label><select id="staffStatusFilter"><option value="all">All Status</option><option value="active">Active</option><option value="on_leave">On Leave</option><option value="blocked">Blocked</option></select></div>
                <button class="btn btn-secondary" onclick="filterStaff()"><i class="fas fa-search"></i> Filter</button>
                <button class="btn btn-secondary" onclick="resetStaffFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
            </div>
            <div class="bulk-actions" id="staffBulkActions"><i class="fas fa-check-circle" style="color: var(--primary);"></i><span class="selected-count" id="staffSelectedCount">0</span> staff member(s) selected<button class="btn btn-sm btn-danger" onclick="bulkDeleteStaff()"><i class="fas fa-trash-alt"></i> Delete Selected</button><button class="btn btn-sm btn-secondary" onclick="clearStaffSelection()"><i class="fas fa-times"></i> Clear</button></div>
            <div class="table-container"><table id="staffTable"><thead><tr><th class="checkbox-col"><input type="checkbox" id="selectAllStaff" onclick="toggleSelectAllStaff()"></th><th>Staff Code</th><th>Full Name</th><th>Position</th><th>Department</th><th>Phone</th><th>Email</th><th>Hire Date</th><th>Status</th><th>Actions</th></tr></thead><tbody id="staffTableBody">
                <?php if (empty($staffMembers)): ?><tr><td colspan="10" class="empty-state"><i class="fas fa-users"></i><h3>No Staff Members Found</h3><p>Click "Add Staff" to get started</p></td></tr>
                <?php else: foreach ($staffMembers as $staff): $statusClass = $staff['is_blocked'] ? 'blocked' : ($staff['status'] == 'on_leave' ? 'on_leave' : 'active'); ?>
                <tr data-id="<?php echo $staff['id']; ?>" data-name="<?php echo strtolower($staff['full_name']); ?>" data-status="<?php echo $statusClass; ?>" data-department="<?php echo strtolower($staff['department'] ?? ''); ?>">
                    <td class="checkbox-col"><input type="checkbox" class="staff-checkbox" value="<?php echo $staff['id']; ?>"></td>
                    <td><strong><?php echo htmlspecialchars($staff['staff_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($staff['full_name']); ?></td>
                    <td><span class="position-badge"><?php echo htmlspecialchars($staff['position'] ?? 'Staff'); ?></span></td>
                    <td><?php echo htmlspecialchars($staff['department'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($staff['phone']); ?></td>
                    <td><?php echo htmlspecialchars($staff['email'] ?: 'N/A'); ?></td>
                    <td><?php echo $staff['hire_date'] ? date('d M Y', strtotime($staff['hire_date'])) : 'N/A'; ?></td>
                    <td><span class="status-badge status-<?php echo $statusClass; ?>"><?php echo $staff['is_blocked'] ? 'BLOCKED' : strtoupper(str_replace('_', ' ', $staff['status'])); ?></span></td>
                    <td class="action-buttons">
                        <button class="action-btn view" onclick="viewStaff(<?php echo $staff['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                        <button class="action-btn edit" onclick="editStaff(<?php echo $staff['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <?php if ($staff['is_blocked']): ?><button class="action-btn unblock" onclick="unblockStaff(<?php echo $staff['id']; ?>)"><i class="fas fa-check"></i> Unblock</button><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <div class="pagination"><div><i class="fas fa-list"></i> Showing <span id="staffVisibleCount">0</span> of <span id="staffTotalCount"><?php echo count($staffMembers); ?></span> staff members</div><div><button class="btn btn-secondary btn-sm" onclick="previousStaffPage()" id="staffPrevBtn" disabled>Previous</button><span id="staffPageInfo">Page 1</span><button class="btn btn-secondary btn-sm" onclick="nextStaffPage()" id="staffNextBtn">Next</button></div></div>
        </div>

        <!-- Technicians Tab -->
        <div id="techniciansTab" class="tab-content">
            <div class="filter-bar">
                <div class="filter-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="techSearchInput" placeholder="Name, code, phone..."></div>
                <div class="filter-group"><label><i class="fas fa-filter"></i> Status</label><select id="techStatusFilter"><option value="all">All Status</option><option value="active">Active</option><option value="on_leave">On Leave</option><option value="blocked">Blocked</option></select></div>
                <button class="btn btn-secondary" onclick="filterTechnicians()"><i class="fas fa-search"></i> Filter</button>
                <button class="btn btn-secondary" onclick="resetTechnicianFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
            </div>
            <div class="bulk-actions" id="techBulkActions"><i class="fas fa-check-circle" style="color: var(--primary);"></i><span class="selected-count" id="techSelectedCount">0</span> technician(s) selected<button class="btn btn-sm btn-danger" onclick="bulkDeleteTechnicians()"><i class="fas fa-trash-alt"></i> Delete Selected</button><button class="btn btn-sm btn-secondary" onclick="clearTechSelection()"><i class="fas fa-times"></i> Clear</button></div>
            <div class="table-container"><table id="techniciansTable"><thead>
                <tr><th class="checkbox-col"><input type="checkbox" id="selectAllTech" onclick="toggleSelectAllTech()"></th><th>Tech Code</th><th>Full Name</th><th>Specialization</th><th>Phone</th><th>Email</th><th>Experience</th><th>Hire Date</th><th>Tools Taken</th><th>Pending Requests</th><th>Status</th><th>Actions</th></tr>
            </thead><tbody id="techniciansTableBody">
                                <?php if (empty($technicians)): ?>
                <tr><td colspan="12" class="empty-state"><i class="fas fa-users-cog"></i><h3>No Technicians Found</h3><p>Click "Add Technician" to get started</p></td></tr>
                <?php else: foreach ($technicians as $tech): 
                    $statusClass = $tech['is_blocked'] ? 'blocked' : ($tech['status'] == 'on_leave' ? 'on_leave' : 'active');
                    $pendingCount = $pending_counts[$tech['id']] ?? 0;
                    $activeCount = $active_assignments[$tech['id']] ?? 0;
                ?>
                <tr data-id="<?php echo $tech['id']; ?>" data-name="<?php echo strtolower($tech['full_name']); ?>" data-status="<?php echo $statusClass; ?>">
                    <td class="checkbox-col"><input type="checkbox" class="tech-checkbox" value="<?php echo $tech['id']; ?>"><?php echo $tech['id']; ?>  </td>
                    <td><strong><?php echo htmlspecialchars($tech['technician_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($tech['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($tech['specialization'] ?: 'General'); ?></td>
                    <td><?php echo htmlspecialchars($tech['phone']); ?></td>
                    <td><?php echo htmlspecialchars($tech['email'] ?: 'N/A'); ?></td>
                    <td><?php echo $tech['experience_years']; ?> yrs</td>
                    <td><?php echo $tech['hire_date'] ? date('d M Y', strtotime($tech['hire_date'])) : 'N/A'; ?></td>
                    <td>
                        <span class="tools-badge">
                            <i class="fas fa-hand-holding"></i> <?php echo $activeCount; ?>/<?php echo $tech['total_tools_assigned']; ?>
                        </span>
                        <?php if ($tech['overdue_tools'] > 0): ?>
                            <span class="text-danger" style="display:block; font-size:10px;"><?php echo $tech['overdue_tools']; ?> overdue</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pending-badge" id="pending-count-<?php echo $tech['id']; ?>">
                            <i class="fas fa-hourglass-half"></i> <?php echo $pendingCount; ?>
                        </span>
                    </td>
                    <td><span class="status-badge status-<?php echo $statusClass; ?>"><?php echo $tech['is_blocked'] ? 'BLOCKED' : strtoupper(str_replace('_', ' ', $tech['status'])); ?></span></td>
                    <td class="action-buttons">
                        <button class="action-btn view" onclick="viewTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                        <button class="action-btn edit" onclick="editTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <button class="action-btn requests" onclick="showRequestsModal(<?php echo $tech['id']; ?>, '<?php echo addslashes($tech['full_name']); ?>')"><i class="fas fa-clock"></i> Pending (<?php echo $pendingCount; ?>)</button>
                        <button class="action-btn tools-taken" onclick="showApprovedAssignments(<?php echo $tech['id']; ?>, '<?php echo addslashes($tech['full_name']); ?>')"><i class="fas fa-hand-holding"></i> Taken (<?php echo $activeCount; ?>)</button>
                        <?php if ($tech['is_blocked']): ?>
                        <button class="action-btn unblock" onclick="unblockTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-check"></i> Unblock</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            </table>
            </div>
            <div class="pagination">
                <div><i class="fas fa-list"></i> Showing <span id="techVisibleCount">0</span> of <span id="techTotalCount"><?php echo count($technicians); ?></span> technicians</div>
                <div><button class="btn btn-secondary btn-sm" onclick="previousTechPage()" id="techPrevBtn" disabled>Previous</button><span id="techPageInfo">Page 1</span><button class="btn btn-secondary btn-sm" onclick="nextTechPage()" id="techNextBtn">Next</button></div>
            </div>
        </div>
    </div>

    <!-- Modal for Pending Requests -->
    <div id="requestsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-clock"></i> Pending Tool Requests - <span id="modalTechName"></span></h3>
                <button class="close-btn" onclick="closeRequestsModal()">&times;</button>
            </div>
            <div class="modal-body" id="requestsModalBody"><p>Loading...</p></div>
        </div>
    </div>

    <!-- Modal for Approved/Assigned Tools (Tools Taken) -->
    <div id="assignmentsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-hand-holding"></i> Tools Taken (Approved & Assigned) - <span id="assignmentsTechName"></span></h3>
                <button class="close-btn" onclick="closeAssignmentsModal()">&times;</button>
            </div>
            <div class="modal-body" id="assignmentsModalBody"><p>Loading...</p></div>
        </div>
    </div>

    <script>
        let staffCurrentPage = 1, techCurrentPage = 1, rowsPerPage = 15, staffFilteredRows = [], techFilteredRows = [];

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('staffTab').classList.toggle('active', tab === 'staff');
            document.getElementById('techniciansTab').classList.toggle('active', tab === 'technicians');
            document.querySelector(`.tab-btn:${tab === 'staff' ? 'first-child' : 'last-child'}`).classList.add('active');
            if (tab === 'staff') updateStaffPagination(); else updateTechPagination();
        }

        function updateStaffPagination() {
            const rows = Array.from(document.querySelectorAll('#staffTableBody tr')).filter(r => r.style.display !== 'none' && !r.querySelector('.empty-state'));
            staffFilteredRows = rows;
            const totalPages = Math.ceil(rows.length / rowsPerPage);
            rows.forEach((row, i) => row.style.display = Math.floor(i / rowsPerPage) + 1 === staffCurrentPage ? '' : 'none');
            document.getElementById('staffPageInfo').innerText = `Page ${staffCurrentPage} of ${totalPages || 1}`;
            document.getElementById('staffPrevBtn').disabled = staffCurrentPage <= 1;
            document.getElementById('staffNextBtn').disabled = staffCurrentPage >= totalPages;
            document.getElementById('staffVisibleCount').innerText = rows.length;
        }
        
        function previousStaffPage() { if (staffCurrentPage > 1) { staffCurrentPage--; updateStaffPagination(); } }
        function nextStaffPage() { const total = Math.ceil(staffFilteredRows.length / rowsPerPage); if (staffCurrentPage < total) { staffCurrentPage++; updateStaffPagination(); } }
        
        function updateTechPagination() {
            const rows = Array.from(document.querySelectorAll('#techniciansTableBody tr')).filter(r => r.style.display !== 'none' && !r.querySelector('.empty-state'));
            techFilteredRows = rows;
            const totalPages = Math.ceil(rows.length / rowsPerPage);
            rows.forEach((row, i) => row.style.display = Math.floor(i / rowsPerPage) + 1 === techCurrentPage ? '' : 'none');
            document.getElementById('techPageInfo').innerText = `Page ${techCurrentPage} of ${totalPages || 1}`;
            document.getElementById('techPrevBtn').disabled = techCurrentPage <= 1;
            document.getElementById('techNextBtn').disabled = techCurrentPage >= totalPages;
            document.getElementById('techVisibleCount').innerText = rows.length;
        }
        
        function previousTechPage() { if (techCurrentPage > 1) { techCurrentPage--; updateTechPagination(); } }
        function nextTechPage() { const total = Math.ceil(techFilteredRows.length / rowsPerPage); if (techCurrentPage < total) { techCurrentPage++; updateTechPagination(); } }

        function filterStaff() {
            const search = document.getElementById('staffSearchInput').value.toLowerCase();
            const dept = document.getElementById('departmentFilter').value.toLowerCase();
            const status = document.getElementById('staffStatusFilter').value;
            let visible = 0;
            document.querySelectorAll('#staffTableBody tr').forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const name = row.dataset.name || '', rowStatus = row.dataset.status || '', rowDept = row.dataset.department || '';
                const show = (search === '' || name.includes(search)) && (dept === 'all' || rowDept.includes(dept)) && (status === 'all' || rowStatus === status);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('staffVisibleCount').innerText = visible;
            staffCurrentPage = 1;
            updateStaffPagination();
        }
        
        function resetStaffFilters() { 
            document.getElementById('staffSearchInput').value = ''; 
            document.getElementById('departmentFilter').value = 'all'; 
            document.getElementById('staffStatusFilter').value = 'all'; 
            filterStaff(); 
        }

        function filterTechnicians() {
            const search = document.getElementById('techSearchInput').value.toLowerCase();
            const status = document.getElementById('techStatusFilter').value;
            let visible = 0;
            document.querySelectorAll('#techniciansTableBody tr').forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const name = row.dataset.name || '', rowStatus = row.dataset.status || '';
                const show = (search === '' || name.includes(search)) && (status === 'all' || rowStatus === status);
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('techVisibleCount').innerText = visible;
            techCurrentPage = 1;
            updateTechPagination();
        }
        
        function resetTechnicianFilters() { 
            document.getElementById('techSearchInput').value = ''; 
            document.getElementById('techStatusFilter').value = 'all'; 
            filterTechnicians(); 
        }

        // Staff bulk actions
        function toggleSelectAllStaff() { 
            const selectAll = document.getElementById('selectAllStaff');
            document.querySelectorAll('.staff-checkbox').forEach(cb => { if (cb.closest('tr').style.display !== 'none') cb.checked = selectAll.checked; }); 
            updateStaffBulkActions(); 
        }
        
        function updateStaffBulkActions() { 
            const count = document.querySelectorAll('.staff-checkbox:checked').length; 
            const el = document.getElementById('staffBulkActions'); 
            if (count > 0) { el.classList.add('show'); document.getElementById('staffSelectedCount').innerText = count; } 
            else { el.classList.remove('show'); document.getElementById('selectAllStaff').checked = false; } 
        }
        
        function clearStaffSelection() { document.querySelectorAll('.staff-checkbox').forEach(cb => cb.checked = false); updateStaffBulkActions(); }
        
        function bulkDeleteStaff() { 
            const ids = [...document.querySelectorAll('.staff-checkbox:checked')].map(cb => cb.value); 
            if (ids.length && confirm(`Delete ${ids.length} staff member(s)?`)) { 
                fetch('delete_staff.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }) })
                .then(() => location.reload()); 
            } 
        }

        // Technician bulk actions
        function toggleSelectAllTech() { 
            const selectAll = document.getElementById('selectAllTech');
            document.querySelectorAll('.tech-checkbox').forEach(cb => { if (cb.closest('tr').style.display !== 'none') cb.checked = selectAll.checked; }); 
            updateTechBulkActions(); 
        }
        
        function updateTechBulkActions() { 
            const count = document.querySelectorAll('.tech-checkbox:checked').length; 
            const el = document.getElementById('techBulkActions'); 
            if (count > 0) { el.classList.add('show'); document.getElementById('techSelectedCount').innerText = count; } 
            else { el.classList.remove('show'); document.getElementById('selectAllTech').checked = false; } 
        }
        
        function clearTechSelection() { document.querySelectorAll('.tech-checkbox').forEach(cb => cb.checked = false); updateTechBulkActions(); }
        
        function bulkDeleteTechnicians() { 
            const ids = [...document.querySelectorAll('.tech-checkbox:checked')].map(cb => cb.value); 
            if (ids.length && confirm(`Delete ${ids.length} technician(s)?`)) { 
                fetch('delete_technicians.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids }) })
                .then(() => location.reload()); 
            } 
        }

        // Show Pending Requests (Not yet approved)
        function showRequestsModal(techId, techName) {
            const modal = document.getElementById('requestsModal');
            document.getElementById('modalTechName').innerText = techName;
            document.getElementById('requestsModalBody').innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Loading pending requests...</p>';
            modal.style.display = 'flex';
            
            fetch(`technicians.php?ajax=pending_requests&technician_id=${techId}`)
                .then(res => res.json())
                .then(requests => {
                    if (!requests.length) { 
                        document.getElementById('requestsModalBody').innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending requests for this technician.</p></div>'; 
                        return; 
                    }
                    document.getElementById('requestsModalBody').innerHTML = requests.map(req => `
                        <div class="request-item">
                            <div class="request-header">
                                <span class="request-number">${escapeHtml(req.request_number)}</span>
                                <span class="request-urgency urgency-${req.urgency}">${req.urgency.toUpperCase()}</span>
                            </div>
                            <div class="request-detail"><i class="fas fa-car"></i> Plate: ${escapeHtml(req.number_plate)}</div>
                            <div class="request-detail"><i class="fas fa-calendar"></i> Requested: ${new Date(req.created_at).toLocaleString()}</div>
                            <div class="request-detail"><i class="fas fa-comment"></i> Reason: ${escapeHtml(req.reason)}</div>
                            <div class="tools-list" style="margin-top:8px;padding-left:28px;"><i class="fas fa-tools"></i> Tools:<br>${req.tools_summary || 'N/A'}</div>
                        </div>
                    `).join('');
                }).catch(() => document.getElementById('requestsModalBody').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading requests</p></div>');
        }

        // Show Approved/Assigned Tools (Tools Taken)
        function showApprovedAssignments(techId, techName) {
            const modal = document.getElementById('assignmentsModal');
            document.getElementById('assignmentsTechName').innerText = techName;
            document.getElementById('assignmentsModalBody').innerHTML = '<p><i class="fas fa-spinner fa-spin"></i> Loading approved tool assignments...</p>';
            modal.style.display = 'flex';
            
            fetch(`technicians.php?ajax=approved_assignments&technician_id=${techId}`)
                .then(res => res.json())
                .then(assignments => {
                    if (!assignments.length) { 
                        document.getElementById('assignmentsModalBody').innerHTML = '<div class="empty-state"><i class="fas fa-hand-holding"></i><p>No tools currently assigned to this technician.</p></div>'; 
                        return; 
                    }
                    document.getElementById('assignmentsModalBody').innerHTML = assignments.map(assign => `
                        <div class="assignment-item ${assign.assignment_status === 'Overdue' ? 'overdue' : ''}">
                            <div class="assignment-header">
                                <span class="tool-code"><i class="fas fa-tools"></i> ${escapeHtml(assign.tool_code)} - ${escapeHtml(assign.tool_name)}</span>
                                <span class="assignment-status ${assign.assignment_status === 'Overdue' ? 'overdue' : (assign.assignment_status === 'Currently Assigned' ? 'active' : 'returned')}">
                                    <i class="fas ${assign.assignment_status === 'Overdue' ? 'fa-exclamation-triangle' : (assign.assignment_status === 'Currently Assigned' ? 'fa-hand-holding' : 'fa-check-circle')}"></i> 
                                    ${assign.assignment_status}
                                </span>
                            </div>
                            <div class="request-detail"><i class="fas fa-calendar"></i> Assigned: ${new Date(assign.assigned_date).toLocaleDateString()}</div>
                            ${assign.expected_return_date ? `<div class="request-detail"><i class="fas fa-clock"></i> Expected Return: ${new Date(assign.expected_return_date).toLocaleDateString()}</div>` : ''}
                            ${assign.actual_return_date ? `<div class="request-detail"><i class="fas fa-undo-alt"></i> Returned: ${new Date(assign.actual_return_date).toLocaleDateString()}</div>` : ''}
                            <div class="request-detail"><i class="fas fa-hourglass-half"></i> Days with technician: ${assign.days_assigned} days</div>
                            ${assign.condition_on_assign ? `<div class="request-detail"><i class="fas fa-clipboard-list"></i> Condition: ${escapeHtml(assign.condition_on_assign)}</div>` : ''}
                            ${assign.serial_number ? `<div class="request-detail"><i class="fas fa-barcode"></i> Serial: ${escapeHtml(assign.serial_number)}</div>` : ''}
                            ${assign.assignment_notes ? `<div class="request-detail"><i class="fas fa-sticky-note"></i> Notes: ${escapeHtml(assign.assignment_notes)}</div>` : ''}
                        </div>
                    `).join('');
                }).catch(() => document.getElementById('assignmentsModalBody').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading assignments</p></div>');
        }

        function closeRequestsModal() { document.getElementById('requestsModal').style.display = 'none'; }
        function closeAssignmentsModal() { document.getElementById('assignmentsModal').style.display = 'none'; }

        // Staff functions
        function openAddStaffModal() { window.location.href = 'add_staff.php'; }
        function openAddTechnicianModal() { window.location.href = 'add_technician.php'; }
        function viewStaff(id) { window.location.href = `view_staff.php?id=${id}`; }
        function editStaff(id) { window.location.href = `edit_staff.php?id=${id}`; }
        function viewTechnician(id) { window.location.href = `view_technician.php?id=${id}`; }
        function editTechnician(id) { window.location.href = `edit_technician.php?id=${id}`; }
        
        function unblockStaff(id) { 
            if (confirm('Unblock this staff member?')) 
                fetch('unblock_staff.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ staff_id: id }) })
                .then(() => location.reload()); 
        }
        
        function unblockTechnician(id) { 
            if (confirm('Unblock this technician?')) 
                fetch('unblock_technician.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ technician_id: id }) })
                .then(() => location.reload()); 
        }

        function exportToExcel() {
            const active = document.querySelector('.tab-content.active').id;
            let csv = [];
            if (active === 'staffTab') {
                csv.push(['Staff Code','Full Name','Position','Department','Phone','Email','Hire Date','Status'].join(','));
                document.querySelectorAll('#staffTableBody tr').forEach(row => { 
                    if (!row.querySelector('.empty-state')) { 
                        const cells = row.querySelectorAll('td'); 
                        const data = [cells[1]?.innerText, cells[2]?.innerText, cells[3]?.innerText, cells[4]?.innerText, cells[5]?.innerText, cells[6]?.innerText, cells[7]?.innerText, cells[8]?.innerText].map(t => `"${t?.trim().replace(/,/g,';') || ''}"`); 
                        if(data.length) csv.push(data.join(',')); 
                    } 
                });
            } else {
                csv.push(['Tech Code','Full Name','Specialization','Phone','Email','Experience','Hire Date','Tools Taken','Pending Requests','Status'].join(','));
                document.querySelectorAll('#techniciansTableBody tr').forEach(row => { 
                    if (!row.querySelector('.empty-state')) { 
                        const cells = row.querySelectorAll('td'); 
                        const data = [cells[1]?.innerText, cells[2]?.innerText, cells[3]?.innerText, cells[4]?.innerText, cells[5]?.innerText, cells[6]?.innerText, cells[7]?.innerText, cells[8]?.innerText, cells[9]?.innerText, cells[10]?.innerText].map(t => `"${t?.trim().replace(/,/g,';') || ''}"`); 
                        if(data.length) csv.push(data.join(',')); 
                    } 
                });
            }
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' }); 
            const a = document.createElement('a'); 
            a.href = URL.createObjectURL(blob); 
            a.download = `${active}_${new Date().toISOString().slice(0,10)}.csv`; 
            a.click(); 
            URL.revokeObjectURL(a.href);
        }

        function escapeHtml(str) { 
            if (!str) return ''; 
            return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m])); 
        }

        // Event listeners
        document.getElementById('staffSearchInput')?.addEventListener('keyup', () => { clearTimeout(window.staffTimeout); window.staffTimeout = setTimeout(filterStaff, 300); });
        document.getElementById('techSearchInput')?.addEventListener('keyup', () => { clearTimeout(window.techTimeout); window.techTimeout = setTimeout(filterTechnicians, 300); });
        document.addEventListener('change', e => { 
            if (e.target.classList.contains('staff-checkbox')) updateStaffBulkActions(); 
            if (e.target.classList.contains('tech-checkbox')) updateTechBulkActions(); 
        });
        
        document.addEventListener('DOMContentLoaded', () => { 
            filterStaff(); 
            filterTechnicians(); 
            // Refresh pending counts every 30 seconds
            setInterval(() => {
                fetch('technicians.php?ajax=pending_requests')
                    .then(res => res.json())
                    .then(requests => {
                        const counts = {};
                        requests.forEach(req => { counts[req.technician_id] = (counts[req.technician_id] || 0) + 1; });
                        document.querySelectorAll('#techniciansTableBody tr').forEach(row => {
                            const techId = row.dataset.id;
                            if (techId) {
                                const count = counts[techId] || 0;
                                const pendingSpan = document.getElementById(`pending-count-${techId}`);
                                if (pendingSpan) pendingSpan.innerHTML = `<i class="fas fa-hourglass-half"></i> ${count}`;
                                const reqBtn = row.querySelector('.requests');
                                if (reqBtn) reqBtn.innerHTML = `<i class="fas fa-clock"></i> Pending (${count})`;
                            }
                        });
                    }).catch(err => console.error('Error fetching pending counts:', err));
            }, 30000);
        });
        
        window.onclick = e => { if (e.target.classList.contains('modal')) { e.target.style.display = 'none'; } };
    </script>
</body>
</html>