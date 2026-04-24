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
    
    // Get technicians data
    $stmt = $conn->query("
        SELECT 
            t.*,
            COUNT(DISTINCT ta.id) as total_tools_assigned,
            COUNT(DISTINCT CASE WHEN ta.status = 'assigned' AND ta.actual_return_date IS NULL THEN ta.id END) as current_tools,
            COUNT(DISTINCT CASE WHEN ta.is_overdue = TRUE THEN ta.id END) as overdue_tools
        FROM technicians t
        LEFT JOIN tool_assignments ta ON t.id = ta.technician_id
        GROUP BY t.id
        ORDER BY t.is_blocked DESC, t.status DESC, t.full_name ASC
    ");
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' AND is_blocked = 0 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked,
            SUM(CASE WHEN status = 'on_leave' AND is_blocked = 0 THEN 1 ELSE 0 END) as on_leave
        FROM technicians
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get departments for dropdown
    $stmt = $conn->query("SELECT DISTINCT department FROM technicians WHERE department IS NOT NULL UNION SELECT 'mechanical' UNION SELECT 'electrical' UNION SELECT 'bodywork' UNION SELECT 'diagnostic' UNION SELECT 'general' ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    $technicians = [];
    $stats = ['total' => 0, 'active' => 0, 'blocked' => 0, 'on_leave' => 0];
    $departments = ['mechanical', 'electrical', 'bodywork', 'diagnostic', 'general'];
}

// Handle AJAX request for adding technician
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_technician'])) {
    header('Content-Type: application/json');
    
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        
        if (empty($full_name) || empty($phone)) {
            echo json_encode(['success' => false, 'message' => 'Name and phone are required']);
            exit();
        }
        
        // Generate technician code
        $code = generateTechnicianCode($full_name);
        
        $stmt = $conn->prepare("
            INSERT INTO technicians (technician_code, full_name, department, phone, email, hire_date, experience_years, specialization, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $code,
            $full_name,
            $_POST['department'] ?? null,
            $phone,
            $_POST['email'] ?? null,
            $_POST['hire_date'] ?? date('Y-m-d'),
            $_POST['experience_years'] ?? 0,
            $_POST['specialization'] ?? null,
            $_POST['status'] ?? 'active'
        ]);
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Technician added successfully', 'code' => $code]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add technician']);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

function generateTechnicianCode($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (strlen($part) > 0) {
            $initials .= strtoupper($part[0]);
        }
    }
    $initials = substr($initials, 0, 3);
    $initials = str_pad($initials, 3, 'X');
    $random = rand(100, 999);
    return "TECH-{$initials}{$random}";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technicians Management | Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
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
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }

        /* Navbar */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--dark);
        }

        .user-role {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
        }

        .logout-btn {
            background: #fef2f2;
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
            transform: translateY(-2px);
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 70px;
            width: 260px;
            height: calc(100vh - 70px);
            background: white;
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .menu-item i {
            width: 20px;
        }

        .menu-item:hover, .menu-item.active {
            background: #f8fafc;
            border-left-color: var(--primary-light);
            color: var(--primary-light);
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: calc(100vh - 70px);
        }

        /* Page Header */
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
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title h1 i {
            color: var(--primary-light);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid var(--border);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-icon.blue { background: #dbeafe; color: var(--primary-light); }
        .stat-icon.green { background: #dcfce7; color: var(--success); }
        .stat-icon.orange { background: #fed7aa; color: var(--warning); }
        .stat-icon.red { background: #fee2e2; color: var(--danger); }

        .stat-info h3 {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .stat-info .value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid var(--border);
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

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
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
        }

        /* Technicians Grid */
        .technicians-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
        }

        .technician-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s;
        }

        .technician-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--light), white);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            color: white;
        }

        .technician-info h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .technician-code {
            font-size: 12px;
            color: var(--gray);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-active { background: #dcfce7; color: #166534; }
        .status-blocked { background: #fee2e2; color: #991b1b; }
        .status-on_leave { background: #fed7aa; color: #9a3412; }

        .card-body {
            padding: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px dashed var(--border);
        }

        .detail-label {
            font-size: 12px;
            color: var(--gray);
        }

        .detail-value {
            font-weight: 600;
            color: var(--dark);
        }

        .stats-row {
            display: flex;
            justify-content: space-around;
            margin: 15px 0;
            padding: 12px;
            background: var(--light);
            border-radius: 16px;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary-light);
        }

        .stat-label {
            font-size: 10px;
            color: var(--gray);
        }

        .card-footer {
            padding: 15px 20px;
            background: var(--light);
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-view { background: var(--primary-light); color: white; }
        .btn-edit { background: var(--warning); color: white; }
        .btn-unblock { background: var(--success); color: white; }

        .empty-state {
            grid-column: 1/-1;
            text-align: center;
            padding: 60px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 28px;
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            padding: 20px 25px;
            border-radius: 28px 28px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .close-btn:hover {
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary-light);
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 3000;
            transform: translateX(400px);
            transition: transform 0.3s;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast-success { border-left: 4px solid var(--success); }
        .toast-error { border-left: 4px solid var(--danger); }
        .toast-success i { color: var(--success); }
        .toast-error i { color: var(--danger); }

        @media (max-width: 768px) {
            .sidebar {
                left: -260px;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
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

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="job_cards.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
            <a href="technicians.php" class="menu-item active"><i class="fas fa-users-cog"></i> Technicians</a>
            <a href="tools.php" class="menu-item"><i class="fas fa-tools"></i> Tool Management</a>
            <a href="tool_requests.php" class="menu-item"><i class="fas fa-clipboard-list"></i> Tool Requests</a>
            <a href="customers.php" class="menu-item"><i class="fas fa-users"></i> Customers</a>
            <div style="margin-top: 30px;"><div class="menu-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div></div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-users-cog"></i> Technicians Management</h1>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-user-plus"></i> Add Technician
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-info"><h3>Total Technicians</h3><div class="value"><?php echo $stats['total']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div class="stat-info"><h3>Active</h3><div class="value"><?php echo $stats['active']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-umbrella-beach"></i></div>
                <div class="stat-info"><h3>On Leave</h3><div class="value"><?php echo $stats['on_leave']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-ban"></i></div>
                <div class="stat-info"><h3>Blocked</h3><div class="value"><?php echo $stats['blocked']; ?></div></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="Name, code, phone...">
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select id="statusFilter">
                    <option value="all">All Status</option>
                    <option value="active">Active</option>
                    <option value="on_leave">On Leave</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>
            <button class="btn btn-secondary" onclick="filterTechnicians()">Filter</button>
            <button class="btn btn-secondary" onclick="resetFilters()">Reset</button>
        </div>

        <!-- Technicians Grid -->
        <div class="technicians-grid" id="techniciansGrid">
            <?php if (empty($technicians)): ?>
            <div class="empty-state"><i class="fas fa-users-cog"></i><h3>No Technicians Found</h3><p>Click "Add Technician" to get started</p></div>
            <?php else: ?>
                <?php foreach ($technicians as $tech): 
                    $initials = '';
                    $nameParts = explode(' ', $tech['full_name']);
                    foreach ($nameParts as $part) $initials .= strtoupper(substr($part, 0, 1));
                    $initials = substr($initials, 0, 2);
                    $statusClass = $tech['is_blocked'] ? 'blocked' : ($tech['status'] == 'on_leave' ? 'on_leave' : 'active');
                ?>
                <div class="technician-card" data-name="<?php echo strtolower($tech['full_name']); ?>" data-status="<?php echo $statusClass; ?>">
                    <div class="card-header">
                        <div class="avatar"><?php echo $initials; ?></div>
                        <div class="technician-info">
                            <h3><?php echo htmlspecialchars($tech['full_name']); ?></h3>
                            <div class="technician-code"><?php echo $tech['technician_code']; ?></div>
                        </div>
                        <span class="status-badge status-<?php echo $statusClass; ?>">
                            <?php echo $tech['is_blocked'] ? 'BLOCKED' : strtoupper(str_replace('_', ' ', $tech['status'])); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="detail-row"><span class="detail-label"><i class="fas fa-phone"></i> Phone</span><span class="detail-value"><?php echo htmlspecialchars($tech['phone']); ?></span></div>
                        <div class="detail-row"><span class="detail-label"><i class="fas fa-envelope"></i> Email</span><span class="detail-value"><?php echo htmlspecialchars($tech['email'] ?: 'N/A'); ?></span></div>
                        <div class="detail-row"><span class="detail-label"><i class="fas fa-briefcase"></i> Experience</span><span class="detail-value"><?php echo $tech['experience_years']; ?> years</span></div>
                        <div class="detail-row"><span class="detail-label"><i class="fas fa-calendar"></i> Hire Date</span><span class="detail-value"><?php echo $tech['hire_date'] ? date('d M Y', strtotime($tech['hire_date'])) : 'N/A'; ?></span></div>
                        
                        <div class="stats-row">
                            <div class="stat"><div class="stat-number"><?php echo $tech['current_tools']; ?></div><div class="stat-label">Current Tools</div></div>
                            <div class="stat"><div class="stat-number"><?php echo $tech['total_tools_assigned']; ?></div><div class="stat-label">Total Assigned</div></div>
                            <div class="stat"><div class="stat-number <?php echo $tech['overdue_tools'] > 0 ? 'text-danger' : ''; ?>"><?php echo $tech['overdue_tools']; ?></div><div class="stat-label">Overdue</div></div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="action-btn btn-view" onclick="viewTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-eye"></i> View</button>
                        <button class="action-btn btn-edit" onclick="editTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-edit"></i> Edit</button>
                        <?php if ($tech['is_blocked']): ?>
                        <button class="action-btn btn-unblock" onclick="unblockTechnician(<?php echo $tech['id']; ?>)"><i class="fas fa-check"></i> Unblock</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Technician Modal -->
    <div id="addTechnicianModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New Technician</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <form id="addTechnicianForm" onsubmit="return submitAddTechnician(event)">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" class="form-control" name="full_name" id="fullName" required placeholder="Enter full name">
                    </div>
                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" class="form-control" name="phone" id="phone" required placeholder="+256 XXX XXX XXX">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" class="form-control" name="email" id="email" placeholder="technician@example.com">
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select class="form-control" name="department" id="department">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept; ?>"><?php echo ucfirst($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Specialization</label>
                        <input type="text" class="form-control" name="specialization" id="specialization" placeholder="e.g., Engine Specialist, AC Technician">
                    </div>
                    <div class="form-group">
                        <label>Experience (Years)</label>
                        <input type="number" class="form-control" name="experience_years" id="experience" step="0.5" value="0" min="0" max="50">
                    </div>
                    <div class="form-group">
                        <label>Hire Date</label>
                        <input type="date" class="form-control" name="hire_date" id="hireDate" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="status" id="status">
                            <option value="active">Active</option>
                            <option value="on_leave">On Leave</option>
                            <option value="inactive">Inactive</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea class="form-control" name="address" id="address" rows="2" placeholder="Physical address"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Technician</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Filter technicians
        function filterTechnicians() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;
            const cards = document.querySelectorAll('.technician-card');
            
            cards.forEach(card => {
                const name = card.dataset.name;
                const cardStatus = card.dataset.status;
                const matchesSearch = search === '' || name.includes(search);
                const matchesStatus = status === 'all' || cardStatus === status;
                card.style.display = matchesSearch && matchesStatus ? 'block' : 'none';
            });
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = 'all';
            filterTechnicians();
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('addTechnicianModal').classList.add('active');
            document.getElementById('addTechnicianForm').reset();
            document.getElementById('hireDate').value = new Date().toISOString().split('T')[0];
        }

        function closeModal() {
            document.getElementById('addTechnicianModal').classList.remove('active');
        }

        // Submit add technician form
        async function submitAddTechnician(event) {
            event.preventDefault();
            
            const fullName = document.getElementById('fullName').value.trim();
            const phone = document.getElementById('phone').value.trim();
            
            if (!fullName || !phone) {
                showToast('Please enter name and phone number', 'error');
                return false;
            }
            
            const formData = new FormData();
            formData.append('ajax_add_technician', '1');
            formData.append('full_name', fullName);
            formData.append('phone', phone);
            formData.append('email', document.getElementById('email').value);
            formData.append('department', document.getElementById('department').value);
            formData.append('specialization', document.getElementById('specialization').value);
            formData.append('experience_years', document.getElementById('experience').value);
            formData.append('hire_date', document.getElementById('hireDate').value);
            formData.append('status', document.getElementById('status').value);
            formData.append('address', document.getElementById('address').value);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (error) {
                showToast('Error adding technician', 'error');
            }
            
            return false;
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // View, Edit, Unblock functions
        function viewTechnician(id) {
            window.location.href = `view_technician.php?id=${id}`;
        }

        function editTechnician(id) {
            window.location.href = `edit_technician.php?id=${id}`;
        }

        function unblockTechnician(id) {
            if (confirm('Unblock this technician?')) {
                fetch('unblock_technician.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ technician_id: id })
                }).then(() => location.reload());
            }
        }

        // Logout
        function logout() {
            window.location.href = 'logout.php';
        }

        document.getElementById('logoutBtn')?.addEventListener('click', logout);
        document.getElementById('searchInput')?.addEventListener('keyup', filterTechnicians);
        
        // Close modal when clicking outside
        window.onclick = function(e) {
            const modal = document.getElementById('addTechnicianModal');
            if (e.target === modal) closeModal();
        }
    </script>
</body>
</html>