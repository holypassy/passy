<?php
// users/index.php - Light Blue Theme Version (with Password Toggle)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all users
    $stmt = $conn->query("
        SELECT u.*, 
               COUNT(DISTINCT jc.id) as jobs_created,
               COUNT(DISTINCT qt.id) as quotations_created,
               COUNT(DISTINCT inv.id) as invoices_created
        FROM users u
        LEFT JOIN job_cards jc ON u.id = jc.created_by
        LEFT JOIN quotations qt ON u.id = qt.created_by
        LEFT JOIN invoices inv ON u.id = inv.created_by
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $users = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | SAVANT MOTORS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e8f0fe 0%, #d4e2f7 100%);
        }

        /* Sidebar - Light Blue Theme */
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
            box-shadow: 2px 0 10px rgba(0,0,0,0.05);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0369a1;
        }

        .sidebar-header p {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 0.25rem;
            color: #0284c7;
        }

        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title {
            padding: 0.5rem 1.5rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #0369a1;
            font-weight: 600;
        }

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

        .menu-item:hover, .menu-item.active {
            background: rgba(14, 165, 233, 0.2);
            color: #0284c7;
            border-left-color: #0284c7;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 1.5rem;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }

        .page-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
        }

        .page-title p {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: #0284c7;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        /* Search Bar */
        .search-bar {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-input {
            flex: 2;
        }

        .search-input input {
            width: 100%;
            padding: 0.6rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .search-input input:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.1);
        }

        .filter-select {
            flex: 1;
        }

        .filter-select select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            background: white;
            transition: all 0.2s;
        }

        .filter-select select:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.1);
        }

        /* Table */
        .table-container {
            background: white;
            border-radius: 0.5rem;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .user-table th {
            background: #f0f9ff;
            padding: 0.9rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: #0369a1;
            border-bottom: 1px solid #e2e8f0;
        }

        .user-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.85rem;
        }

        .user-table tr:hover {
            background: #f0f9ff;
        }

        /* Badges */
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .role-admin { background: #fee2e2; color: #dc2626; }
        .role-manager { background: #fed7aa; color: #ea580c; }
        .role-cashier { background: #dcfce7; color: #16a34a; }
        .role-technician { background: #dbeafe; color: #2563eb; }
        .role-receptionist { background: #f3e8ff; color: #9333ea; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active { background: #dcfce7; color: #16a34a; }
        .status-inactive { background: #fee2e2; color: #dc2626; }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.3rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary { 
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: white; 
        }
        .btn-primary:hover { 
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.3);
        }

        .btn-secondary { 
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .action-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.3rem;
            font-size: 0.7rem;
            cursor: pointer;
            border: none;
            margin: 0 2px;
            transition: all 0.2s;
        }

        .btn-view { background: #dbeafe; color: #2563eb; }
        .btn-view:hover { background: #2563eb; color: white; }
        .btn-edit { background: #dcfce7; color: #16a34a; }
        .btn-edit:hover { background: #16a34a; color: white; }
        .btn-reset { background: #fed7aa; color: #ea580c; }
        .btn-reset:hover { background: #ea580c; color: white; }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: white;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: white;
            border-radius: 0.5rem 0.5rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body { padding: 1.5rem; }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        /* Form */
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #64748b;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.3rem;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.1);
        }

        /* Password Toggle Wrapper */
        .password-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-wrapper input {
            padding-right: 2.5rem;
            width: 100%;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            cursor: pointer;
            font-size: 1.1rem;
            user-select: none;
            background: none;
            border: none;
            padding: 0;
            color: #64748b;
        }
        .toggle-password:hover {
            color: #0284c7;
        }

        /* Alert */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.3rem;
            margin-bottom: 1rem;
            display: none;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 3px solid #10b981;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 3px solid #ef4444;
        }

        /* Loading */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #0284c7;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: white;
        }

        @media (max-width: 768px) {
            .sidebar { left: -260px; transition: left 0.3s; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Enterprise Resource Planning</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item"><span style="margin-right: 8px;">📊</span> Dashboard</a>
            <a href="../job_cards.php" class="menu-item"><span style="margin-right: 8px;">📋</span> Job Cards</a>
            <a href="../customers/index.php" class="menu-item"><span style="margin-right: 8px;">👥</span> Customers</a>
            <a href="index.php" class="menu-item active"><span style="margin-right: 8px;">👤</span> User Management</a>
            <a href="manage_permissions.php" class="menu-item"><span style="margin-right: 8px;">🔑</span> Permissions</a>
            <a href="../reports.php" class="menu-item"><span style="margin-right: 8px;">📈</span> Reports</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item"><span style="margin-right: 8px;">🚪</span> Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>👤 User Management</h1>
                <p>Manage system users, roles, and permissions</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn btn-primary" onclick="openAddModal()">➕ Add New User</button>
                <button class="btn btn-primary" onclick="window.location.href='manage_permissions.php'" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);">
                    🔑 Manage Permissions
                </button>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($users); ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($users, function($u) { return $u['is_active']; })); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($users, function($u) { return $u['role'] === 'admin'; })); ?></div>
                <div class="stat-label">Admins</div>
            </div>
        </div>

        <div class="search-bar">
            <div class="search-input">
                <input type="text" id="searchInput" placeholder="🔍 Search by name, email, or role...">
            </div>
            <div class="filter-select">
                <select id="roleFilter">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="cashier">Cashier</option>
                    <option value="technician">Technician</option>
                    <option value="receptionist">Receptionist</option>
                </select>
            </div>
            <div class="filter-select">
                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="applyFilters()">🔍 Filter</button>
        </div>

        <div class="table-container">
            <table class="user-table" id="usersTable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): 
                        $initials = '';
                        $nameParts = explode(' ', $user['full_name']);
                        foreach ($nameParts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                        $initials = substr($initials, 0, 2);
                        $avatarColors = ['#0284c7', '#7c3aed', '#10b981', '#f59e0b', '#ef4444'];
                        $avatarColor = $avatarColors[$user['id'] % count($avatarColors)];
                    ?>
                    <tr data-role="<?php echo $user['role']; ?>" data-status="<?php echo $user['is_active']; ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <div class="user-avatar" style="background: <?php echo $avatarColor; ?>; display: flex; align-items: center; justify-content: center;">
                                    <?php echo $initials; ?>
                                </div>
                                <div>
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <div style="font-size: 0.7rem; color: #64748b;"><?php echo htmlspecialchars($user['email']); ?></div>
                                    <div style="font-size: 0.65rem; color: #94a3b8;">@<?php echo htmlspecialchars($user['username']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td>
                            <?php echo $user['last_login'] ? date('d M Y H:i', strtotime($user['last_login'])) : 'Never'; ?>
                        </td>
                        <td>
                            <button class="action-btn btn-view" onclick="viewUser(<?php echo $user['id']; ?>)">👁️ View</button>
                            <button class="action-btn btn-edit" onclick="editUser(<?php echo $user['id']; ?>)">✏️ Edit</button>
                            <button class="action-btn btn-reset" onclick="resetPassword(<?php echo $user['id']; ?>)">🔐 Reset</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">➕ Add New User</h3>
                <button class="close-btn" onclick="closeModal('userModal')" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="userId" value="0">
                    
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="fullName" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" id="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" id="role" required>
                            <option value="">Select Role</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="cashier">Cashier</option>
                            <option value="technician">Technician</option>
                            <option value="receptionist">Receptionist</option>
                        </select>
                    </div>
                    
                    <div id="passwordSection">
                        <div class="form-group">
                            <label>Password <span id="passwordRequired">*</span></label>
                            <div class="password-wrapper">
                                <input type="password" name="password" id="password">
                                <button type="button" class="toggle-password" onclick="togglePassword('password')">👁️</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <div class="password-wrapper">
                                <input type="password" name="confirm_password" id="confirmPassword">
                                <button type="button" class="toggle-password" onclick="togglePassword('confirmPassword')">👁️</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('userModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">💾 Save User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View User Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>👤 User Details</h3>
                <button class="close-btn" onclick="closeModal('viewModal')" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div class="modal-body" id="viewContent"></div>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
        <p style="margin-top: 1rem;">Processing...</p>
    </div>

    <script>
        function showLoading() { document.getElementById('loadingSpinner').style.display = 'flex'; }
        function hideLoading() { document.getElementById('loadingSpinner').style.display = 'none'; }

        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-' + type;
            alertDiv.innerHTML = (type === 'success' ? '✅ ' : '❌ ') + message;
            alertDiv.style.display = 'block';
            
            const mainContent = document.querySelector('.main-content');
            mainContent.insertBefore(alertDiv, mainContent.firstChild);
            
            setTimeout(() => alertDiv.remove(), 5000);
        }

        // Password Toggle Function
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field) return;
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            // Optional: change the icon based on state
            const toggleBtn = field.parentElement.querySelector('.toggle-password');
            if (toggleBtn) {
                toggleBtn.textContent = type === 'password' ? '👁️' : '🙈';
            }
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '➕ Add New User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '0';
            document.getElementById('passwordSection').style.display = 'block';
            // Reset password fields to type="password" when reopening modal
            document.getElementById('password').type = 'password';
            document.getElementById('confirmPassword').type = 'password';
            // Reset toggle button icons
            const toggles = document.querySelectorAll('#passwordSection .toggle-password');
            toggles.forEach(btn => btn.textContent = '👁️');
            document.getElementById('userModal').classList.add('active');
        }

        function editUser(id) {
            showLoading();
            
            fetch('get_user.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.error) {
                        showAlert(data.error, 'danger');
                        return;
                    }
                    document.getElementById('modalTitle').innerHTML = '✏️ Edit User';
                    document.getElementById('userId').value = data.id;
                    document.getElementById('fullName').value = data.full_name;
                    document.getElementById('username').value = data.username;
                    document.getElementById('email').value = data.email;
                    document.getElementById('role').value = data.role;
                    document.getElementById('passwordSection').style.display = 'none';
                    document.getElementById('userModal').classList.add('active');
                })
                .catch(error => {
                    hideLoading();
                    showAlert('Error loading user data', 'danger');
                });
        }

        function viewUser(id) {
            showLoading();
            
            fetch('get_user.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.error) {
                        showAlert(data.error, 'danger');
                        return;
                    }
                    
                    const content = `
                        <div style="text-align: center; margin-bottom: 1rem;">
                            <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #0284c7, #0369a1); display: flex; align-items: center; justify-content: center; margin: 0 auto; color: white; font-size: 2rem;">
                                ${data.full_name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2)}
                            </div>
                            <h3 style="margin-top: 1rem;">${escapeHtml(data.full_name)}</h3>
                            <p style="color: #64748b;">@${escapeHtml(data.username)}</p>
                        </div>
                        <div style="border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                            <p><strong>📧 Email:</strong> ${escapeHtml(data.email)}</p>
                            <p><strong>👔 Role:</strong> <span class="role-badge role-${data.role}">${data.role}</span></p>
                            <p><strong>📊 Status:</strong> <span class="status-badge status-${data.is_active ? 'active' : 'inactive'}">${data.is_active ? 'Active' : 'Inactive'}</span></p>
                            <p><strong>🕐 Last Login:</strong> ${data.last_login ? new Date(data.last_login).toLocaleString() : 'Never'}</p>
                            <p><strong>📅 Joined:</strong> ${new Date(data.created_at).toLocaleDateString()}</p>
                        </div>
                    `;
                    document.getElementById('viewContent').innerHTML = content;
                    document.getElementById('viewModal').classList.add('active');
                })
                .catch(error => {
                    hideLoading();
                    showAlert('Error loading user data', 'danger');
                });
        }

        function resetPassword(id) {
            if (confirm('Are you sure you want to reset this user\'s password?')) {
                showLoading();
                fetch('reset_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: id })
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        alert(`Password reset successful!\nTemporary password: ${data.temp_password}`);
                        showAlert('Password reset successfully', 'success');
                    } else {
                        showAlert(data.message || 'Error resetting password', 'danger');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showAlert('Error resetting password', 'danger');
                });
            }
        }

        // Form submission
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const userId = document.getElementById('userId').value;
            const password = document.getElementById('password')?.value || '';
            const confirmPassword = document.getElementById('confirmPassword')?.value || '';
            
            // Validation
            if (userId === '0' || userId === 0) {
                if (!password) {
                    alert('Please enter a password for new users');
                    return;
                }
                if (password !== confirmPassword) {
                    alert('Passwords do not match');
                    return;
                }
                if (password.length < 8) {
                    alert('Password must be at least 8 characters');
                    return;
                }
            } else {
                if (password && password !== confirmPassword) {
                    alert('Passwords do not match');
                    return;
                }
                if (password && password.length < 8) {
                    alert('Password must be at least 8 characters');
                    return;
                }
            }
            
            showLoading();
            
            // Create FormData and send
            const formData = new FormData(this);
            
            fetch('./save_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showAlert(data.message, 'success');
                    closeModal('userModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showAlert('Error saving user: ' + error, 'danger');
            });
        });

        function applyFilters() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const role = document.getElementById('roleFilter').value;
            const status = document.getElementById('statusFilter').value;
            
            const rows = document.querySelectorAll('#usersTable tbody tr');
            
            rows.forEach(row => {
                let show = true;
                const text = row.textContent.toLowerCase();
                const userRole = row.dataset.role;
                const userStatus = row.dataset.status;
                
                if (search && !text.includes(search)) show = false;
                if (role && userRole !== role) show = false;
                if (status && userStatus !== status) show = false;
                
                row.style.display = show ? '' : 'none';
            });
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
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

        // Event listeners
        document.getElementById('searchInput')?.addEventListener('keyup', applyFilters);
        document.getElementById('roleFilter')?.addEventListener('change', applyFilters);
        document.getElementById('statusFilter')?.addEventListener('change', applyFilters);

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>