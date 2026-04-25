<?php
// purchase_requests.php - Purchase Requests Management (Fixed)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';

date_default_timezone_set('Africa/Kampala');

$message = '';
$message_type = '';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle Create/Update/Delete
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $stmt = $conn->prepare("
                    INSERT INTO purchase_requests (
                        request_number, request_date, requested_by, department,
                        priority, required_by_date, status, notes, created_by
                    ) VALUES (
                        :request_number, :request_date, :requested_by, :department,
                        :priority, :required_by_date, 'pending', :notes, :created_by
                    )
                ");
                
                $request_number = 'PR-' . date('Ymd') . '-' . rand(100, 999);
                
                $stmt->execute([
                    ':request_number' => $request_number,
                    ':request_date' => date('Y-m-d'),
                    ':requested_by' => $_POST['requested_by'],
                    ':department' => $_POST['department'],
                    ':priority' => $_POST['priority'],
                    ':required_by_date' => $_POST['required_by_date'],
                    ':notes' => $_POST['notes'] ?? '',
                    ':created_by' => $user_id
                ]);
                
                $request_id = $conn->lastInsertId();
                
                // Add items
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['product_name'])) {
                            $item_stmt = $conn->prepare("
                                INSERT INTO purchase_request_items (
                                    request_id, product_name, quantity, unit,
                                    estimated_price, notes
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $item_stmt->execute([
                                $request_id,
                                $item['product_name'],
                                $item['quantity'],
                                $item['unit'] ?? 'pcs',
                                $item['estimated_price'] ?? 0,
                                $item['notes'] ?? ''
                            ]);
                        }
                    }
                }
                
                $message = "Purchase request $request_number created successfully";
                $message_type = "success";
                break;
                
            case 'approve':
                $stmt = $conn->prepare("
                    UPDATE purchase_requests 
                    SET status = 'approved', 
                        approved_by = :approved_by,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $_POST['request_id'],
                    ':approved_by' => $user_id
                ]);
                $message = "Request approved successfully";
                $message_type = "success";
                break;
                
            case 'reject':
                $stmt = $conn->prepare("
                    UPDATE purchase_requests 
                    SET status = 'rejected',
                        rejection_reason = :reason,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $_POST['request_id'],
                    ':reason' => $_POST['reason'] ?? ''
                ]);
                $message = "Request rejected";
                $message_type = "warning";
                break;
        }
    }
    
    // Fetch all purchase requests - FIXED: removed CONCAT for users
    $status_filter = $_GET['status'] ?? 'all';
    $sql = "SELECT pr.*, 
                   u.username as requester_name
            FROM purchase_requests pr
            LEFT JOIN users u ON pr.created_by = u.id";
    
    if ($status_filter != 'all') {
        $sql .= " WHERE pr.status = :status ORDER BY pr.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':status' => $status_filter]);
    } else {
        $sql .= " ORDER BY pr.created_at DESC";
        $stmt = $conn->query($sql);
    }
    
    $purchase_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch request items for detailed view
    $request_items = [];
    if (isset($_GET['view_id'])) {
        $item_stmt = $conn->prepare("
            SELECT * FROM purchase_request_items 
            WHERE request_id = :request_id
            ORDER BY id
        ");
        $item_stmt->execute([':request_id' => $_GET['view_id']]);
        $request_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Fetch departments from the table or use defaults
    $dept_stmt = $conn->query("SELECT DISTINCT department FROM purchase_requests WHERE department IS NOT NULL AND department != ''");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($departments)) {
        $departments = ['Workshop', 'Parts', 'Administration', 'Sales', 'Service'];
    }
    
} catch(PDOException $e) {
    $message = "Database error: " . $e->getMessage();
    $message_type = "danger";
    $purchase_requests = [];
}
?>
<!-- Rest of the HTML remains the same as previous version -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Requests - Savant Motors ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #c5d5f0 0%, #a8bbdf 100%);
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
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100%;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 32px 24px; text-align: center; border-bottom: 1px solid var(--border); }
        .logo-icon { width: 150px; height: 100px; background: transparent; margin: 0 auto 16px; overflow: hidden; }
        .logo-icon img { max-width: 180px; max-height: 150px; object-fit: contain; }
        .logo-text { font-size: 24px; font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .logo-subtitle { font-size: 11px; color: var(--gray); margin-top: 6px; }
        .sidebar-menu { padding: 16px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.2s;
            font-weight: 500;
            font-size: 14px;
        }
        .nav-item i { width: 22px; font-size: 16px; }
        .nav-item:hover, .nav-item.active { background: rgba(0, 71, 171, 0.08); color: var(--primary); }
        .logout-wrapper { margin-top: 40px; padding: 12px 24px; border-top: 1px solid var(--border); }
        .logout-item {
            padding: 10px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--danger);
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        .logout-item:hover { background: rgba(239,68,68,0.1); }
        
        .main-content { margin-left: 280px; padding: 0 32px 32px 32px; min-height: 100vh; }
        .top-bar {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;
            padding: 20px 0; border-bottom: 1px solid var(--border); margin-bottom: 32px;
        }
        .welcome-section h1 { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, var(--dark), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .welcome-section p { color: var(--gray); font-size: 14px; margin-top: 6px; }
        .user-card {
            background: white; padding: 8px 20px 8px 16px; border-radius: 60px; display: flex; align-items: center;
            gap: 16px; box-shadow: var(--shadow-md); border: 1px solid var(--border);
        }
        .user-avatar {
            width: 44px; height: 44px; background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 40px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; color: white;
        }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .page-title { font-size: 24px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 12px; }
        .btn {
            padding: 10px 24px; border-radius: 40px; font-weight: 600; cursor: pointer; transition: all 0.2s;
            border: none; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: var(--gray); color: white; }
        
        .filter-bar {
            background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px;
            display: flex; gap: 16px; flex-wrap: wrap; align-items: center; box-shadow: var(--shadow);
        }
        .filter-btn {
            padding: 8px 20px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500;
            background: var(--light); color: var(--gray); transition: all 0.2s;
        }
        .filter-btn.active { background: var(--primary); color: white; }
        .filter-btn:hover { transform: translateY(-1px); }
        
        .requests-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px;
        }
        .request-card {
            background: white; border-radius: 24px; overflow: hidden; border: 1px solid var(--border);
            transition: all 0.2s; box-shadow: var(--shadow);
        }
        .request-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .card-header {
            padding: 20px 24px; background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white; display: flex; justify-content: space-between; align-items: center;
        }
        .request-number { font-weight: 700; font-size: 16px; }
        .priority-badge {
            padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .priority-high { background: #dc2626; color: white; }
        .priority-medium { background: #f59e0b; color: white; }
        .priority-low { background: #10b981; color: white; }
        .card-body { padding: 20px 24px; }
        .request-info { margin-bottom: 16px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .info-label { color: var(--gray); font-weight: 500; }
        .info-value { color: var(--dark); font-weight: 600; }
        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-converted { background: #dbeafe; color: #1e40af; }
        .card-footer {
            padding: 16px 24px; background: var(--light); border-top: 1px solid var(--border);
            display: flex; gap: 12px; flex-wrap: wrap;
        }
        
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(6px);
            align-items: center; justify-content: center; z-index: 1100;
        }
        .modal-content {
            background: white; border-radius: 32px; width: 90%; max-width: 800px; max-height: 90vh;
            overflow-y: auto; animation: fadeInUp 0.3s ease;
        }
        .modal-header {
            padding: 20px 24px; background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0;
        }
        .modal-header h3 { margin: 0; }
        .close-modal { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--border); }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark); font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 16px;
            font-size: 14px; transition: all 0.2s; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30,64,175,0.1);
        }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .items-table th, .items-table td {
            padding: 12px; text-align: left; border-bottom: 1px solid var(--border);
        }
        .items-table th { background: var(--light); font-weight: 600; font-size: 13px; }
        .btn-add-item { margin-top: 12px; }
        
        .alert {
            padding: 16px 20px; border-radius: 16px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid var(--danger); }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid var(--warning); }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 0 20px 20px 20px; }
            .requests-grid { grid-template-columns: 1fr; }
        }
        
        .item-row { margin-bottom: 16px; padding: 16px; background: var(--light); border-radius: 16px; }
        .item-row .row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: center; }
        .remove-item { color: var(--danger); cursor: pointer; background: none; border: none; font-size: 18px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <img src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-car\' style=\'font-size:32px; color:var(--primary);\'></i>';">
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
            <div class="logo-subtitle">Procurement Management</div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="purchase_requests.php" class="nav-item active"><i class="fas fa-clipboard-list"></i> Purchase Requests</a>
            <a href="purchases/index.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchase Orders</a>
            <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
            <a href="goods_received.php" class="nav-item"><i class="fas fa-check-double"></i> Goods Received</a>
            <a href="vendor_quotes.php" class="nav-item"><i class="fas fa-file-invoice"></i> Vendor Quotes</a>
            <div class="logout-wrapper">
                <div class="logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="welcome-section">
                <h1>Purchase Requests</h1>
                <p><i class="fas fa-clipboard-list"></i> Manage and track all purchase requisitions</p>
            </div>
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user_full_name, 0, 2)); ?></div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                    <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : ($message_type == 'danger' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-clipboard-list" style="color: var(--primary);"></i>
                Purchase Requests
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> New Purchase Request
            </button>
        </div>
        
        <div class="filter-bar">
            <a href="?status=all" class="filter-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="filter-btn <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=approved" class="filter-btn <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">Approved</a>
            <a href="?status=rejected" class="filter-btn <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
            <a href="?status=converted" class="filter-btn <?php echo $status_filter == 'converted' ? 'active' : ''; ?>">Converted to PO</a>
        </div>
        
        <div class="requests-grid">
            <?php if (empty($purchase_requests)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 24px;">
                    <i class="fas fa-inbox" style="font-size: 64px; color: var(--gray); margin-bottom: 16px;"></i>
                    <p style="color: var(--gray);">No purchase requests found</p>
                    <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">Create First Request</button>
                </div>
            <?php else: ?>
                <?php foreach ($purchase_requests as $request): ?>
                <div class="request-card">
                    <div class="card-header">
                        <span class="request-number"><?php echo htmlspecialchars($request['request_number']); ?></span>
                        <span class="priority-badge priority-<?php echo $request['priority']; ?>">
                            <?php echo ucfirst($request['priority']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="request-info">
                            <div class="info-row">
                                <span class="info-label">Request Date:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($request['request_date'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Requested By:</span>
                                <span class="info-value"><?php echo htmlspecialchars($request['requester_name'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Department:</span>
                                <span class="info-value"><?php echo htmlspecialchars($request['department'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Required By:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($request['required_by_date'])); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status:</span>
                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </div>
                            <?php if ($request['notes']): ?>
                            <div class="info-row">
                                <span class="info-label">Notes:</span>
                                <span class="info-value"><?php echo htmlspecialchars(substr($request['notes'], 0, 50)); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" onclick="viewRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-eye"></i> View Items
                        </button>
                        <?php if ($request['status'] == 'pending' && ($user_role == 'admin' || $user_role == 'procurement_manager')): ?>
                        <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <?php endif; ?>
                        <?php if ($request['status'] == 'approved'): ?>
                        <button class="btn btn-primary" onclick="convertToPO(<?php echo $request['id']; ?>)">
                            <i class="fas fa-file-invoice"></i> Convert to PO
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Create Request Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> New Purchase Request</h3>
                <button class="close-modal" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" id="createForm">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Requested By *</label>
                        <input type="text" name="requested_by" required value="<?php echo htmlspecialchars($user_full_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority *</label>
                        <select name="priority" required>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Required By Date *</label>
                        <input type="date" name="required_by_date" required>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3" placeholder="Any additional information..."></textarea>
                    </div>
                    
                    <label style="font-weight: 600; margin-bottom: 12px; display: block;">Request Items</label>
                    <div id="itemsContainer"></div>
                    <button type="button" class="btn btn-secondary btn-add-item" onclick="addItemRow()">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Items Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> Request Items</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="items-table">
                    <thead>
                        <tr><th>Product</th><th>Quantity</th><th>Unit</th><th>Est. Price</th><th>Notes</th></tr>
                    </thead>
                    <tbody id="viewItemsBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check"></i> Approve Request</h3>
                <button class="close-modal" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approveRequestId">
                <div class="modal-body">
                    <p>Are you sure you want to approve this purchase request?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times"></i> Reject Request</h3>
                <button class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Rejection</label>
                        <textarea name="reason" rows="3" required placeholder="Please provide a reason..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Convert to PO Modal -->
    <div id="convertModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Convert to Purchase Order</h3>
                <button class="close-modal" onclick="closeConvertModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="convert_to_po">
                <input type="hidden" name="request_id" id="convertRequestId">
                <div class="modal-body">
                    <p>This will convert the request to a Purchase Order. The request will be marked as "Converted".</p>
                    <div class="form-group">
                        <label>Purchase Order Number (Auto-generated)</label>
                        <input type="text" id="poNumber" name="po_id" readonly style="background: var(--light);">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeConvertModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Convert to PO</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let itemCount = 0;
        
        function addItemRow() {
            const container = document.getElementById('itemsContainer');
            const div = document.createElement('div');
            div.className = 'item-row';
            div.id = `item_${itemCount}`;
            div.innerHTML = `
                <div class="row">
                    <input type="text" name="items[${itemCount}][product_name]" placeholder="Product Name" required style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                    <input type="number" name="items[${itemCount}][quantity]" placeholder="Qty" required style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                    <select name="items[${itemCount}][unit]" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                        <option value="pcs">pcs</option><option value="kg">kg</option><option value="liters">liters</option><option value="boxes">boxes</option><option value="sets">sets</option>
                    </select>
                    <input type="number" name="items[${itemCount}][estimated_price]" placeholder="Est. Price" step="0.01" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                    <button type="button" class="remove-item" onclick="this.closest('.item-row').remove()"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.appendChild(div);
            itemCount++;
        }
        
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
            document.getElementById('itemsContainer').innerHTML = '';
            itemCount = 0;
            addItemRow();
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        async function viewRequest(requestId) {
            try {
                const response = await fetch(`get_request_items.php?request_id=${requestId}`);
                const items = await response.json();
                const tbody = document.getElementById('viewItemsBody');
                tbody.innerHTML = '';
                items.forEach(item => {
                    const row = tbody.insertRow();
                    row.insertCell(0).textContent = item.product_name;
                    row.insertCell(1).textContent = item.quantity;
                    row.insertCell(2).textContent = item.unit;
                    row.insertCell(3).textContent = item.estimated_price ? `UGX ${parseFloat(item.estimated_price).toLocaleString()}` : '-';
                    row.insertCell(4).textContent = item.notes || '-';
                });
                document.getElementById('viewModal').style.display = 'flex';
            } catch(e) {
                alert('Error loading items');
            }
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function approveRequest(requestId) {
            document.getElementById('approveRequestId').value = requestId;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }
        
        function rejectRequest(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        function convertToPO(requestId) {
            document.getElementById('convertRequestId').value = requestId;
            document.getElementById('poNumber').value = 'PO-' + new Date().toISOString().slice(0,10).replace(/-/g,'') + '-' + Math.floor(Math.random()*1000);
            document.getElementById('convertModal').style.display = 'flex';
        }
        
        function closeConvertModal() {
            document.getElementById('convertModal').style.display = 'none';
        }
        
        document.getElementById('logoutBtn')?.addEventListener('click', () => {
            window.location.href = 'index.php';
        });
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>