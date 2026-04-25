<?php
// users.php - User Management with Custom Permissions
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Only admin can access user management
if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create permissions tables if they don't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            permission_name VARCHAR(100) NOT NULL,
            description TEXT,
            category VARCHAR(50),
            is_active TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_id INT NOT NULL,
            granted TINYINT DEFAULT 1,
            granted_by INT,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_permission (user_id, permission_id)
        )
    ");
    
    // Insert default permissions if not exist
    $default_permissions = [
        ['view_dashboard', 'View Dashboard', 'Access to main dashboard', 'General'],
        ['create_job_card', 'Create Job Cards', 'Create new job cards', 'Jobs'],
        ['edit_job_card', 'Edit Job Cards', 'Modify existing job cards', 'Jobs'],
        ['delete_job_card', 'Delete Job Cards', 'Remove job cards', 'Jobs'],
        ['view_job_cards', 'View Job Cards', 'View all job cards', 'Jobs'],
        ['create_quotation', 'Create Quotations', 'Generate new quotations', 'Sales'],
        ['edit_quotation', 'Edit Quotations', 'Modify existing quotations', 'Sales'],
        ['delete_quotation', 'Delete Quotations', 'Remove quotations', 'Sales'],
        ['approve_quotation', 'Approve Quotations', 'Approve customer quotations', 'Sales'],
        ['create_invoice', 'Create Invoices', 'Generate invoices', 'Finance'],
        ['edit_invoice', 'Edit Invoices', 'Modify invoices', 'Finance'],
        ['delete_invoice', 'Delete Invoices', 'Remove invoices', 'Finance'],
        ['record_payment', 'Record Payments', 'Record customer payments', 'Finance'],
        ['void_transaction', 'Void Transactions', 'Void financial transactions', 'Finance'],
        ['view_reports', 'View Reports', 'Access to reports section', 'Reports'],
        ['export_data', 'Export Data', 'Export data to CSV/Excel', 'Reports'],
        ['manage_users', 'Manage Users', 'Add/edit/delete users', 'Admin'],
        ['manage_roles', 'Manage Roles', 'Create and edit roles', 'Admin'],
        ['manage_permissions', 'Manage Permissions', 'Create custom permissions', 'Admin'],
        ['manage_inventory', 'Manage Inventory', 'Add/edit inventory items', 'Inventory'],
        ['view_inventory', 'View Inventory', 'View inventory items', 'Inventory'],
        ['adjust_stock', 'Adjust Stock', 'Perform stock adjustments', 'Inventory'],
        ['manage_tools', 'Manage Tools', 'Add/edit/delete tools', 'Tools'],
        ['assign_tools', 'Assign Tools', 'Assign tools to technicians', 'Tools'],
        ['view_tool_requests', 'View Tool Requests', 'View tool requests', 'Tools'],
        ['approve_tool_requests', 'Approve Tool Requests', 'Approve/decline tool requests', 'Tools'],
        ['manage_customers', 'Manage Customers', 'Add/edit/delete customers', 'CRM'],
        ['view_customers', 'View Customers', 'View customer information', 'CRM'],
        ['manage_technicians', 'Manage Technicians', 'Add/edit/delete technicians', 'HR'],
        ['view_attendance', 'View Attendance', 'View staff attendance', 'HR'],
        ['record_attendance', 'Record Attendance', 'Record staff check-in/out', 'HR'],
        ['edit_prices', 'Edit Prices', 'Modify product/service prices', 'Sales'],
        ['approve_discounts', 'Approve Discounts', 'Approve discount requests', 'Sales'],
        ['manage_suppliers', 'Manage Suppliers', 'Add/edit/delete suppliers', 'Procurement'],
        ['create_purchase_order', 'Create Purchase Orders', 'Create purchase orders', 'Procurement'],
        ['approve_purchase_order', 'Approve Purchase Orders', 'Approve purchase orders', 'Procurement']
    ];
    
    $checkPermissions = $conn->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    if ($checkPermissions == 0) {
        $stmt = $conn->prepare("INSERT INTO permissions (permission_key, permission_name, description, category) VALUES (?, ?, ?, ?)");
        foreach ($default_permissions as $perm) {
            $stmt->execute([$perm[0], $perm[1], $perm[2], $perm[3]]);
        }
    }
    
    // Get all users
    $stmt = $conn->query("
        SELECT u.*, 
               COUNT(DISTINCT jc.id) as jobs_created,
               COUNT(DISTINCT qt.id) as quotations_created,
               COUNT(DISTINCT inv.id) as invoices_created,
               DATE_FORMAT(u.last_login, '%Y-%m-%d %H:%i') as last_login_formatted
        FROM users u
        LEFT JOIN job_cards jc ON u.id = jc.created_by
        LEFT JOIN quotations qt ON u.id = qt.created_by
        LEFT JOIN invoices inv ON u.id = inv.created_by
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all permissions for dropdown
    $stmt = $conn->query("SELECT * FROM permissions WHERE is_active = 1 ORDER BY category, permission_name");
    $all_permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get permissions by category
    $permissions_by_category = [];
    foreach ($all_permissions as $perm) {
        $permissions_by_category[$perm['category']][] = $perm;
    }
    
    // Get available roles
    $roles = ['admin', 'manager', 'cashier', 'technician', 'receptionist'];
    
} catch(PDOException $e) {
    $users = [];
    $all_permissions = [];
    $permissions_by_category = [];
    $roles = ['admin', 'manager', 'cashier', 'technician', 'receptionist'];
    error_log("Error fetching users: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - SAVANT MOTORS UGANDA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }

        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
        }

        .garage-name {
            font-size: 20px;
            border-left: 2px solid rgba(255,255,255,0.3);
            padding-left: 15px;
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
        }

        .user-role {
            font-size: 12px;
            opacity: 0.8;
        }

        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }

        /* Main Container */
        .main-container {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            padding: 20px 0;
        }

        .menu-item {
            padding: 15px 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #555;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .menu-item:hover {
            background: #f8f9fa;
            border-left-color: #2a5298;
            color: #2a5298;
        }

        .menu-item.active {
            background: #e8f0fe;
            border-left-color: #2a5298;
            color: #2a5298;
            font-weight: 600;
        }

        .menu-item i {
            width: 20px;
            font-size: 18px;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .page-title {
            margin-bottom: 30px;
            color: #333;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: #2a5298;
            color: white;
        }

        .btn-primary:hover {
            background: #1e3c72;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Users Grid */
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .user-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .user-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }

        .user-card.status-active { border-left: 4px solid #28a745; }
        .user-card.status-inactive { border-left: 4px solid #ffc107; }
        .user-card.status-suspended { border-left: 4px solid #dc3545; }

        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 600;
        }

        .user-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-admin { background: #dc3545; color: white; }
        .badge-manager { background: #fd7e14; color: white; }
        .badge-cashier { background: #28a745; color: white; }
        .badge-technician { background: #17a2b8; color: white; }
        .badge-receptionist { background: #6f42c1; color: white; }

        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #fff3cd; color: #856404; }
        .badge-suspended { background: #f8d7da; color: #721c24; }

        .user-info {
            margin: 15px 0;
        }

        .info-row {
            display: flex;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .info-label {
            width: 100px;
            color: #666;
            font-weight: 500;
        }

        .info-value {
            color: #333;
            font-weight: 600;
        }

        .user-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 20px;
            font-weight: 700;
            color: #2a5298;
        }

        .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }

        .user-permissions {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 12px;
        }

        .permission-tag {
            display: inline-block;
            padding: 3px 8px;
            background: #e9ecef;
            border-radius: 12px;
            margin: 2px;
            font-size: 10px;
        }

        .user-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            min-width: 70px;
        }

        .btn-edit { background: #ffc107; color: #333; }
        .btn-permissions { background: #17a2b8; color: white; }
        .btn-reset { background: #6c757d; color: white; }
        .btn-disable { background: #dc3545; color: white; }
        .btn-enable { background: #28a745; color: white; }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 30px auto;
            padding: 0;
            border: 1px solid #888;
            width: 90%;
            max-width: 800px;
            border-radius: 15px;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close:hover {
            color: #ffd700;
        }

        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 25px;
            background: #f8f9fa;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            border-top: 1px solid #dee2e6;
        }

        /* Form Styles */
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-section h4 {
            color: #2a5298;
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }

        .form-group label i {
            margin-right: 5px;
            color: #2a5298;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #2a5298;
            outline: none;
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Permissions Grid */
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .permission-category {
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }

        .category-header {
            background: #e9ecef;
            padding: 12px 15px;
            font-weight: 600;
            color: #2a5298;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .category-header i {
            transition: transform 0.3s;
        }

        .category-header.collapsed i {
            transform: rotate(-90deg);
        }

        .category-permissions {
            padding: 15px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .category-permissions.collapsed {
            display: none;
        }

        .permission-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .permission-item:hover {
            border-color: #2a5298;
            background: #f8f9fa;
        }

        .permission-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .permission-item label {
            flex: 1;
            cursor: pointer;
            font-weight: 500;
            color: #555;
            margin: 0;
        }

        .permission-desc {
            font-size: 11px;
            color: #999;
            margin-top: 2px;
        }

        /* Custom Permission Creation */
        .custom-permission-form {
            background: white;
            border: 2px dashed #2a5298;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .custom-permission-form h5 {
            color: #2a5298;
            margin-bottom: 15px;
        }

        .add-permission-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr auto;
            gap: 10px;
            align-items: end;
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 20px;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #2a5298;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .tab-btn.active {
            color: #2a5298;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #2a5298;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .users-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .permissions-grid, .category-permissions {
                grid-template-columns: 1fr;
            }
            
            .add-permission-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
        <p style="color: #2a5298; font-weight: 600;">Processing...</p>
    </div>

    <!-- Navbar -->
    <div class="navbar">
        <div class="logo-area">
            <div class="logo">🔧 SAVANT MOTORS</div>
            <div class="garage-name">UGANDA - POS System</div>
        </div>
        <div class="user-menu">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                <div class="user-role"><?php echo strtoupper(htmlspecialchars($_SESSION['role'])); ?></div>
            </div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <a href="dashboard_erp.php" class="menu-item">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="job_cards.php" class="menu-item">
                <i class="fas fa-clipboard-list"></i> Job Cards
            </a>
            <a href="quotations.php" class="menu-item">
                <i class="fas fa-file-invoice"></i> Quotations
            </a>
            <a href="invoices.php" class="menu-item">
                <i class="fas fa-file-invoice-dollar"></i> Invoices
            </a>
            <a href="customers.php" class="menu-item">
                <i class="fas fa-users"></i> Customers
            </a>
            <a href="technicians.php" class="menu-item">
                <i class="fas fa-users-cog"></i> Staff & Technicians
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="users.php" class="menu-item active">
                <i class="fas fa-user-cog"></i> Users
            </a>
        </div>

        <!-- Content Area -->
        <div class="content-area">
            <div class="page-title">
                <span><i class="fas fa-user-cog"></i> User Management</span>
                <div>
                    <button class="btn btn-primary" onclick="openAddUserModal()">
                        <i class="fas fa-user-plus"></i> Add New User
                    </button>
                    <button class="btn btn-warning" onclick="openManagePermissionsModal()" style="margin-left: 10px;">
                        <i class="fas fa-key"></i> Manage Permissions
                    </button>
                </div>
            </div>

            <!-- Alert Messages -->
            <div id="alertMessage" class="alert"></div>

            <!-- Users Grid -->
            <div class="users-grid">
                <?php foreach ($users as $user): 
                    $initials = '';
                    $nameParts = explode(' ', $user['full_name']);
                    foreach ($nameParts as $part) {
                        $initials .= strtoupper(substr($part, 0, 1));
                    }
                    $initials = substr($initials, 0, 2);
                    
                    $status = $user['is_active'] ? 'active' : 'inactive';
                ?>
                <div class="user-card status-<?php echo $status; ?>" id="user-<?php echo $user['id']; ?>">
                    <div class="user-header">
                        <div class="user-avatar">
                            <?php echo $initials; ?>
                        </div>
                        <div class="user-badges">
                            <span class="badge badge-<?php echo $user['role']; ?>">
                                <?php echo strtoupper($user['role']); ?>
                            </span>
                            <span class="badge badge-<?php echo $status; ?>">
                                <?php echo strtoupper($status); ?>
                            </span>
                        </div>
                    </div>

                    <div class="user-info">
                        <div class="info-row">
                            <span class="info-label">Name:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['full_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Username:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['username']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email:</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <?php if ($user['last_login_formatted']): ?>
                        <div class="info-row">
                            <span class="info-label">Last Login:</span>
                            <span class="info-value"><?php echo $user['last_login_formatted']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="user-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $user['jobs_created']; ?></div>
                            <div class="stat-label">Jobs</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $user['quotations_created']; ?></div>
                            <div class="stat-label">Quotes</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $user['invoices_created']; ?></div>
                            <div class="stat-label">Invoices</div>
                        </div>
                    </div>

                    <div class="user-actions">
                        <button class="action-btn btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="action-btn btn-permissions" onclick="managePermissions(<?php echo $user['id']; ?>)">
                            <i class="fas fa-key"></i> Permissions
                        </button>
                        <button class="action-btn btn-reset" onclick="resetPassword(<?php echo $user['id']; ?>)">
                            <i class="fas fa-key"></i> Reset
                        </button>
                        <?php if ($user['is_active']): ?>
                        <button class="action-btn btn-disable" onclick="toggleUserStatus(<?php echo $user['id']; ?>, 'disable')">
                            <i class="fas fa-ban"></i> Disable
                        </button>
                        <?php else: ?>
                        <button class="action-btn btn-enable" onclick="toggleUserStatus(<?php echo $user['id']; ?>, 'enable')">
                            <i class="fas fa-check"></i> Enable
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add New User</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form id="userForm" method="POST" action="save_user.php">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name *</label>
                            <input type="text" class="form-control" name="full_name" id="fullName" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-user-tag"></i> Username *</label>
                                <input type="text" class="form-control" name="username" id="username" required>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email *</label>
                                <input type="email" class="form-control" name="email" id="email" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Role *</label>
                            <select class="form-control" name="role" id="role" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role; ?>"><?php echo ucfirst($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Password Section (for new users) -->
                    <div class="form-section" id="passwordSection">
                        <h4><i class="fas fa-lock"></i> Password</h4>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-key"></i> Password *</label>
                                <input type="password" class="form-control" name="password" id="password" onkeyup="checkPasswordStrength()">
                                <div class="password-strength" style="margin-top: 5px; height: 5px; background: #e9ecef; border-radius: 3px; overflow: hidden;">
                                    <div class="strength-bar" id="strengthBar" style="width: 0; height: 100%; transition: width 0.3s;"></div>
                                </div>
                                <div class="strength-text" id="strengthText" style="font-size: 12px; margin-top: 5px;"></div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-redo-alt"></i> Confirm Password *</label>
                                <input type="password" class="form-control" name="confirm_password" id="confirmPassword" onkeyup="checkPasswordMatch()">
                                <div id="passwordMatch" style="font-size: 12px; margin-top: 5px;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="require_password_change" value="1" checked>
                                <span>Require password change on first login</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" id="saveBtn">
                        <i class="fas fa-save"></i> Save User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- User Permissions Modal -->
    <div id="permissionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> User Permissions</h3>
                <span class="close" onclick="closePermissionsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="permUserId">
                
                <div class="form-section">
                    <h4>User: <span id="permUserName"></span></h4>
                    <p>Role: <strong id="permUserRole"></strong></p>
                </div>

                <div class="tabs">
                    <button class="tab-btn active" onclick="switchPermissionTab('all')">All Permissions</button>
                    <button class="tab-btn" onclick="switchPermissionTab('custom')">Custom Permissions</button>
                </div>

                <!-- All Permissions Tab -->
                <div id="allPermissionsTab" class="tab-content active">
                    <?php foreach ($permissions_by_category as $category => $perms): ?>
                    <div class="permission-category">
                        <div class="category-header" onclick="toggleCategory(this)">
                            <span><i class="fas fa-folder"></i> <?php echo $category; ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="category-permissions">
                            <?php foreach ($perms as $perm): ?>
                            <div class="permission-item">
                                <input type="checkbox" class="perm-checkbox" data-permission-id="<?php echo $perm['id']; ?>" id="perm_<?php echo $perm['id']; ?>">
                                <label for="perm_<?php echo $perm['id']; ?>">
                                    <?php echo htmlspecialchars($perm['permission_name']); ?>
                                    <div class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Custom Permissions Tab -->
                <div id="customPermissionsTab" class="tab-content">
                    <div class="custom-permission-form">
                        <h5><i class="fas fa-plus-circle"></i> Create Custom Permission</h5>
                        <div class="add-permission-row">
                            <input type="text" id="newPermKey" class="form-control" placeholder="Permission Key (e.g., special_access)">
                            <input type="text" id="newPermName" class="form-control" placeholder="Permission Name (e.g., Special Access)">
                            <select id="newPermCategory" class="form-control">
                                <option value="Custom">Custom</option>
                                <option value="General">General</option>
                                <option value="Jobs">Jobs</option>
                                <option value="Sales">Sales</option>
                                <option value="Finance">Finance</option>
                                <option value="Reports">Reports</option>
                                <option value="Admin">Admin</option>
                                <option value="Inventory">Inventory</option>
                                <option value="Tools">Tools</option>
                                <option value="CRM">CRM</option>
                                <option value="HR">HR</option>
                                <option value="Procurement">Procurement</option>
                            </select>
                            <button type="button" class="btn btn-primary" onclick="createCustomPermission()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                        <div class="permission-desc" style="margin-top: 10px;">
                            <i class="fas fa-info-circle"></i> Permission keys should be unique and use underscores (e.g., manage_workshop)
                        </div>
                    </div>
                    
                    <div id="customPermissionsList" style="margin-top: 20px;">
                        <h5><i class="fas fa-list"></i> Existing Custom Permissions</h5>
                        <div id="customPermissionsGrid" class="permissions-grid" style="margin-top: 10px;">
                            <!-- Custom permissions will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePermissionsModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveUserPermissions()">Save Permissions</button>
            </div>
        </div>
    </div>

    <!-- Manage All Permissions Modal -->
    <div id="managePermissionsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Manage All Permissions</h3>
                <span class="close" onclick="closeManagePermissionsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="custom-permission-form">
                    <h5><i class="fas fa-plus-circle"></i> Create New System Permission</h5>
                    <div class="add-permission-row">
                        <input type="text" id="sysPermKey" class="form-control" placeholder="Permission Key (e.g., manage_workshop)">
                        <input type="text" id="sysPermName" class="form-control" placeholder="Permission Name (e.g., Manage Workshop)">
                        <input type="text" id="sysPermDesc" class="form-control" placeholder="Description">
                        <select id="sysPermCategory" class="form-control">
                            <option value="General">General</option>
                            <option value="Jobs">Jobs</option>
                            <option value="Sales">Sales</option>
                            <option value="Finance">Finance</option>
                            <option value="Reports">Reports</option>
                            <option value="Admin">Admin</option>
                            <option value="Inventory">Inventory</option>
                            <option value="Tools">Tools</option>
                            <option value="CRM">CRM</option>
                            <option value="HR">HR</option>
                            <option value="Procurement">Procurement</option>
                            <option value="Custom">Custom</option>
                        </select>
                        <button type="button" class="btn btn-primary" onclick="createSystemPermission()">
                            <i class="fas fa-plus"></i> Create
                        </button>
                    </div>
                </div>
                
                <div id="allSystemPermissions" style="margin-top: 20px;">
                    <h5><i class="fas fa-list"></i> All Permissions</h5>
                    <?php foreach ($permissions_by_category as $category => $perms): ?>
                    <div class="permission-category">
                        <div class="category-header" onclick="toggleCategory(this)">
                            <span><i class="fas fa-folder"></i> <?php echo $category; ?> (<?php echo count($perms); ?>)</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="category-permissions">
                            <?php foreach ($perms as $perm): ?>
                            <div class="permission-item" id="perm-item-<?php echo $perm['id']; ?>">
                                <div style="flex: 1;">
                                    <strong><?php echo htmlspecialchars($perm['permission_name']); ?></strong>
                                    <div class="permission-desc">Key: <?php echo htmlspecialchars($perm['permission_key']); ?></div>
                                    <div class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></div>
                                </div>
                                <button class="btn btn-sm btn-danger" onclick="deletePermission(<?php echo $perm['id']; ?>, '<?php echo addslashes($perm['permission_name']); ?>')" style="padding: 5px 10px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeManagePermissionsModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let currentUserPermissions = [];

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (password.match(/[a-z]+/)) strength += 25;
            if (password.match(/[A-Z]+/)) strength += 25;
            if (password.match(/[0-9]+/)) strength += 15;
            if (password.match(/[$@#&!]+/)) strength += 10;
            
            strengthBar.style.width = strength + '%';
            
            if (strength < 50) {
                strengthBar.style.background = '#dc3545';
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#dc3545';
            } else if (strength < 75) {
                strengthBar.style.background = '#ffc107';
                strengthText.textContent = 'Medium password';
                strengthText.style.color = '#ffc107';
            } else {
                strengthBar.style.background = '#28a745';
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#28a745';
            }
        }

        // Check password match
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (password && confirm) {
                if (password === confirm) {
                    matchDiv.innerHTML = '<span style="color: #28a745;">✓ Passwords match</span>';
                } else {
                    matchDiv.innerHTML = '<span style="color: #dc3545;">✗ Passwords do not match</span>';
                }
            }
        }

        // Toggle permission category
        function toggleCategory(header) {
            const categoryDiv = header.nextElementSibling;
            header.classList.toggle('collapsed');
            categoryDiv.classList.toggle('collapsed');
        }

        // Switch permission tabs
        function switchPermissionTab(tab) {
            const allTab = document.getElementById('allPermissionsTab');
            const customTab = document.getElementById('customPermissionsTab');
            const tabs = document.querySelectorAll('.tab-btn');
            
            tabs.forEach(btn => btn.classList.remove('active'));
            
            if (tab === 'all') {
                allTab.classList.add('active');
                customTab.classList.remove('active');
                document.querySelector('.tab-btn:first-child').classList.add('active');
            } else {
                allTab.classList.remove('active');
                customTab.classList.add('active');
                document.querySelector('.tab-btn:last-child').classList.add('active');
                loadCustomPermissions();
            }
        }

        // Load custom permissions for user
        function loadCustomPermissions() {
            const userId = document.getElementById('permUserId').value;
            fetch(`get_user_permissions.php?id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    const grid = document.getElementById('customPermissionsGrid');
                    if (data.custom_permissions && data.custom_permissions.length > 0) {
                        grid.innerHTML = data.custom_permissions.map(perm => `
                            <div class="permission-item">
                                <input type="checkbox" class="custom-perm-checkbox" data-permission-id="${perm.id}" ${perm.granted ? 'checked' : ''}>
                                <label>
                                    <strong>${escapeHtml(perm.permission_name)}</strong>
                                    <div class="permission-desc">Key: ${escapeHtml(perm.permission_key)}</div>
                                    <div class="permission-desc">${escapeHtml(perm.description || 'No description')}</div>
                                </label>
                            </div>
                        `).join('');
                    } else {
                        grid.innerHTML = '<p style="color: #999; text-align: center;">No custom permissions available. Create one using the form above.</p>';
                    }
                });
        }

        // Create custom permission
        function createCustomPermission() {
            const key = document.getElementById('newPermKey').value.trim();
            const name = document.getElementById('newPermName').value.trim();
            const category = document.getElementById('newPermCategory').value;
            
            if (!key || !name) {
                alert('Please enter both permission key and name');
                return;
            }
            
            document.getElementById('loadingSpinner').style.display = 'flex';
            
            fetch('create_permission.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    permission_key: key,
                    permission_name: name,
                    description: '',
                    category: category
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingSpinner').style.display = 'none';
                if (data.success) {
                    showAlert('Permission created successfully', 'success');
                    document.getElementById('newPermKey').value = '';
                    document.getElementById('newPermName').value = '';
                    loadCustomPermissions();
                    // Also refresh the all permissions view
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Create system permission (global)
        function createSystemPermission() {
            const key = document.getElementById('sysPermKey').value.trim();
            const name = document.getElementById('sysPermName').value.trim();
            const desc = document.getElementById('sysPermDesc').value.trim();
            const category = document.getElementById('sysPermCategory').value;
            
            if (!key || !name) {
                alert('Please enter both permission key and name');
                return;
            }
            
            document.getElementById('loadingSpinner').style.display = 'flex';
            
            fetch('create_permission.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    permission_key: key,
                    permission_name: name,
                    description: desc,
                    category: category
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingSpinner').style.display = 'none';
                if (data.success) {
                    showAlert('Permission created successfully', 'success');
                    document.getElementById('sysPermKey').value = '';
                    document.getElementById('sysPermName').value = '';
                    document.getElementById('sysPermDesc').value = '';
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Delete permission
        function deletePermission(permId, permName) {
            if (confirm(`Are you sure you want to delete permission "${permName}"? This will remove it from all users.`)) {
                document.getElementById('loadingSpinner').style.display = 'flex';
                
                fetch('delete_permission.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ permission_id: permId })
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingSpinner').style.display = 'none';
                    if (data.success) {
                        showAlert('Permission deleted successfully', 'success');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }

        // Open add user modal
        function openAddUserModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('passwordSection').style.display = 'block';
            document.getElementById('userModal').style.display = 'block';
        }

        // Edit user
        function editUser(userId) {
            fetch(`get_user.php?id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit"></i> Edit User';
                    document.getElementById('userId').value = data.id;
                    document.getElementById('fullName').value = data.full_name;
                    document.getElementById('username').value = data.username;
                    document.getElementById('email').value = data.email;
                    document.getElementById('role').value = data.role;
                    
                    document.getElementById('passwordSection').style.display = 'none';
                    document.getElementById('userModal').style.display = 'block';
                });
        }

        // Manage permissions for a specific user
        function managePermissions(userId) {
            currentUserId = userId;
            
            fetch(`get_user_permissions.php?id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('permUserId').value = data.id;
                    document.getElementById('permUserName').textContent = data.full_name;
                    document.getElementById('permUserRole').textContent = data.role;
                    
                    // Store current permissions
                    currentUserPermissions = data.permissions || [];
                    
                    // Check/uncheck all permission checkboxes
                    document.querySelectorAll('.perm-checkbox').forEach(cb => {
                        const permId = parseInt(cb.dataset.permissionId);
                        cb.checked = currentUserPermissions.includes(permId);
                    });
                    
                    document.getElementById('permissionsModal').style.display = 'block';
                });
        }

        // Save user permissions
        function saveUserPermissions() {
            const userId = document.getElementById('permUserId').value;
            const selectedPermissions = [];
            
            // Get all selected permissions
            document.querySelectorAll('.perm-checkbox:checked').forEach(cb => {
                selectedPermissions.push(parseInt(cb.dataset.permissionId));
            });
            
            // Get custom permissions if any
            document.querySelectorAll('.custom-perm-checkbox:checked').forEach(cb => {
                selectedPermissions.push(parseInt(cb.dataset.permissionId));
            });
            
            document.getElementById('loadingSpinner').style.display = 'flex';
            
            fetch('save_user_permissions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: userId,
                    permissions: selectedPermissions
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingSpinner').style.display = 'none';
                if (data.success) {
                    showAlert('Permissions saved successfully', 'success');
                    closePermissionsModal();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }

        // Open manage all permissions modal
        function openManagePermissionsModal() {
            document.getElementById('managePermissionsModal').style.display = 'block';
        }

        function closeManagePermissionsModal() {
            document.getElementById('managePermissionsModal').style.display = 'none';
        }

        // Reset password
        function resetPassword(userId) {
            if (confirm('Are you sure you want to reset this user\'s password? A temporary password will be generated.')) {
                document.getElementById('loadingSpinner').style.display = 'flex';
                
                fetch('reset_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId })
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingSpinner').style.display = 'none';
                    if (data.success) {
                        alert(`Password reset successful!\nTemporary password: ${data.temp_password}\n\nPlease share this with the user.`);
                    } else {
                        alert('Error resetting password');
                    }
                });
            }
        }

        // Toggle user status
        function toggleUserStatus(userId, action) {
            const message = action === 'disable' ? 'disable' : 'enable';
            if (confirm(`Are you sure you want to ${message} this user?`)) {
                document.getElementById('loadingSpinner').style.display = 'flex';
                
                fetch('toggle_user_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId, action: action })
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingSpinner').style.display = 'none';
                    if (data.success) {
                        showAlert(`User ${action}d successfully`, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        alert('Error updating user status');
                    }
                });
            }
        }

        // Form submission
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const userId = document.getElementById('userId').value;
            if (!userId) {
                const password = document.getElementById('password').value;
                const confirm = document.getElementById('confirmPassword').value;
                
                if (!password || !confirm) {
                    alert('Please enter password');
                    return;
                }
                
                if (password !== confirm) {
                    alert('Passwords do not match');
                    return;
                }
                
                if (password.length < 8) {
                    alert('Password must be at least 8 characters long');
                    return;
                }
            }
            
            document.getElementById('loadingSpinner').style.display = 'flex';
            
            const formData = new FormData(this);
            
            fetch('save_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loadingSpinner').style.display = 'none';
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message, 'danger');
                }
            });
        });

        // Show alert
        function showAlert(message, type) {
            const alertDiv = document.getElementById('alertMessage');
            alertDiv.className = 'alert alert-' + type;
            alertDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            alertDiv.style.display = 'flex';
            setTimeout(() => { alertDiv.style.display = 'none'; }, 5000);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // Close modals
        function closeModal() { document.getElementById('userModal').style.display = 'none'; }
        function closePermissionsModal() { document.getElementById('permissionsModal').style.display = 'none'; }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const userModal = document.getElementById('userModal');
            const permModal = document.getElementById('permissionsModal');
            const managePermModal = document.getElementById('managePermissionsModal');
            
            if (event.target == userModal) closeModal();
            if (event.target == permModal) closePermissionsModal();
            if (event.target == managePermModal) closeManagePermissionsModal();
        }
    </script>
</body>
</html>