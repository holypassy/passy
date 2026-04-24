<?php
// manage_permissions.php - Manage System Permissions
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
    
    // Get all permissions grouped by category
    $stmt = $conn->query("
        SELECT * FROM permissions 
        WHERE is_active = 1 
        ORDER BY category, permission_name
    ");
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by category
    $permissions_by_category = [];
    foreach ($permissions as $perm) {
        $category = $perm['category'] ?? 'General';
        $permissions_by_category[$category][] = $perm;
    }
    
    // Get available categories for dropdown
    $categories = array_keys($permissions_by_category);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
    $permissions_by_category = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions | SAVANT MOTORS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: white;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
        }

        .sidebar-header p {
            font-size: 0.7rem;
            opacity: 0.6;
            margin-top: 0.25rem;
        }

        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title {
            padding: 0.5rem 1.5rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.4);
            font-weight: 600;
        }

        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .menu-item:hover, .menu-item.active {
            background: rgba(255,255,255,0.08);
            color: white;
            border-left-color: #10b981;
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

        /* Stats */
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

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.3rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
        }

        .btn-primary { background: #2563eb; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }

        /* Permissions Grid */
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .permission-card {
            background: white;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .card-header .badge {
            background: #e2e8f0;
            padding: 0.2rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
        }

        .card-body {
            padding: 0.75rem;
        }

        .permission-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.2s;
        }

        .permission-item:hover {
            background: #f8fafc;
        }

        .permission-info {
            flex: 1;
        }

        .permission-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .permission-key {
            font-size: 0.7rem;
            color: #64748b;
            font-family: monospace;
            margin-top: 0.2rem;
        }

        .permission-desc {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 0.2rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.3rem;
        }

        .icon-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.3rem;
            cursor: pointer;
            border: none;
            font-size: 0.7rem;
        }

        .icon-btn-edit { background: #dbeafe; color: #2563eb; }
        .icon-btn-delete { background: #fee2e2; color: #ef4444; }

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
            background: #1e3a8a;
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
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.3rem;
            font-size: 0.85rem;
            font-family: inherit;
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
            border-top: 3px solid #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .sidebar { left: -260px; transition: left 0.3s; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; }
            .permissions-grid { grid-template-columns: 1fr; }
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
            <a href="/savant/views/users/index.php" class="menu-item"><span style="margin-right: 8px;">👤</span> User Management</a>
            <a href="manage_permissions.php" class="menu-item active"><span style="margin-right: 8px;">🔑</span> Permissions</a>
            <a href="../reports.php" class="menu-item"><span style="margin-right: 8px;">📈</span> Reports</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item"><span style="margin-right: 8px;">🚪</span> Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>🔑 System Permissions</h1>
                <p>Manage user permissions and access controls</p>
            </div>
            <button class="btn btn-primary" onclick="openAddPermissionModal()">
                ➕ Add New Permission
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($permissions); ?></div>
                <div class="stat-label">Total Permissions</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($permissions_by_category); ?></div>
                <div class="stat-label">Categories</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                    $customCount = 0;
                    foreach ($permissions as $p) {
                        if (($p['category'] ?? 'General') === 'Custom') $customCount++;
                    }
                    echo $customCount;
                    ?>
                </div>
                <div class="stat-label">Custom Permissions</div>
            </div>
        </div>

        <div class="permissions-grid">
            <?php foreach ($permissions_by_category as $category => $perms): ?>
            <div class="permission-card">
                <div class="card-header">
                    <h3>📁 <?php echo htmlspecialchars($category); ?></h3>
                    <span class="badge"><?php echo count($perms); ?> permissions</span>
                </div>
                <div class="card-body">
                    <?php foreach ($perms as $perm): ?>
                    <div class="permission-item" id="perm-<?php echo $perm['id']; ?>">
                        <div class="permission-info">
                            <div class="permission-name"><?php echo htmlspecialchars($perm['permission_name']); ?></div>
                            <div class="permission-key"><?php echo htmlspecialchars($perm['permission_key']); ?></div>
                            <?php if ($perm['description']): ?>
                            <div class="permission-desc"><?php echo htmlspecialchars($perm['description']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="action-buttons">
                            <button class="icon-btn icon-btn-edit" onclick="editPermission(<?php echo $perm['id']; ?>, '<?php echo addslashes($perm['permission_name']); ?>', '<?php echo addslashes($perm['permission_key']); ?>', '<?php echo addslashes($perm['description'] ?? ''); ?>', '<?php echo $perm['category']; ?>')">
                                ✏️
                            </button>
                            <button class="icon-btn icon-btn-delete" onclick="deletePermission(<?php echo $perm['id']; ?>, '<?php echo addslashes($perm['permission_name']); ?>')">
                                🗑️
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Add/Edit Permission Modal -->
    <div id="permissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="permissionModalTitle">➕ Add New Permission</h3>
                <button class="close-btn" onclick="closeModal('permissionModal')" style="background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <form id="permissionForm">
                <div class="modal-body">
                    <input type="hidden" name="permission_id" id="permissionId" value="0">
                    
                    <div class="form-group">
                        <label>Permission Name *</label>
                        <input type="text" name="permission_name" id="permissionName" required placeholder="e.g., Manage Workshop">
                        <small style="color: #64748b;">Display name for the permission</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Permission Key *</label>
                        <input type="text" name="permission_key" id="permissionKey" required placeholder="e.g., manage_workshop">
                        <small style="color: #64748b;">Unique identifier (use underscores, no spaces)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="permissionCategory">
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
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="permissionDesc" rows="3" placeholder="What does this permission allow?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('permissionModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">💾 Save Permission</button>
                </div>
            </form>
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

        function openAddPermissionModal() {
            document.getElementById('permissionModalTitle').innerHTML = '➕ Add New Permission';
            document.getElementById('permissionForm').reset();
            document.getElementById('permissionId').value = '0';
            document.getElementById('permissionModal').classList.add('active');
        }

        function editPermission(id, name, key, desc, category) {
            document.getElementById('permissionModalTitle').innerHTML = '✏️ Edit Permission';
            document.getElementById('permissionId').value = id;
            document.getElementById('permissionName').value = name;
            document.getElementById('permissionKey').value = key;
            document.getElementById('permissionDesc').value = desc;
            document.getElementById('permissionCategory').value = category;
            document.getElementById('permissionModal').classList.add('active');
        }

        function deletePermission(id, name) {
            if (confirm(`Are you sure you want to delete permission "${name}"? This will remove it from all users.`)) {
                showLoading();
                
                fetch('delete_permission.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ permission_id: id })
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showAlert('Permission deleted successfully', 'success');
                        // Remove from UI
                        document.getElementById(`perm-${id}`)?.remove();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert(data.message || 'Error deleting permission', 'danger');
                    }
                })
                .catch(error => {
                    hideLoading();
                    showAlert('Error deleting permission', 'danger');
                });
            }
        }

        // Form submission
        document.getElementById('permissionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const permissionId = document.getElementById('permissionId').value;
            const permissionName = document.getElementById('permissionName').value.trim();
            const permissionKey = document.getElementById('permissionKey').value.trim();
            
            if (!permissionName) {
                alert('Please enter permission name');
                return;
            }
            if (!permissionKey) {
                alert('Please enter permission key');
                return;
            }
            if (!/^[a-z_]+$/.test(permissionKey)) {
                alert('Permission key must contain only lowercase letters and underscores');
                return;
            }
            
            showLoading();
            
            const formData = new FormData(this);
            
            fetch('save_permission.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showAlert(data.message, 'success');
                    closeModal('permissionModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                showAlert('Error saving permission: ' + error, 'danger');
            });
        });

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>