<?php
// goods_received.php - Goods Received Notes Management (Fixed)
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
    
    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $conn->beginTransaction();
                
                $grn_number = 'GRN-' . date('Ymd') . '-' . rand(100, 999);
                
                $stmt = $conn->prepare("
                    INSERT INTO goods_received_notes (
                        grn_number, po_id, received_date, received_by, supplier_id,
                        delivery_note_number, invoice_number, status, total_value, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
                ");
                
                $stmt->execute([
                    $grn_number,
                    !empty($_POST['po_id']) ? $_POST['po_id'] : null,
                    date('Y-m-d'),
                    $user_id,
                    $_POST['supplier_id'],
                    $_POST['delivery_note_number'] ?? null,
                    $_POST['invoice_number'] ?? null,
                    $_POST['total_value'] ?? 0,
                    $_POST['notes'] ?? ''
                ]);
                
                $grn_id = $conn->lastInsertId();
                
                // Add received items
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['product_id'])) {
                            $item_stmt = $conn->prepare("
                                INSERT INTO goods_received_items (
                                    grn_id, product_id, ordered_quantity, received_quantity,
                                    unit_price, total_price, condition_status
                                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $item_stmt->execute([
                                $grn_id,
                                $item['product_id'],
                                $item['ordered_quantity'] ?? 0,
                                $item['received_quantity'],
                                $item['unit_price'],
                                $item['received_quantity'] * $item['unit_price'],
                                $item['condition_status'] ?? 'good'
                            ]);
                            
                            // Update inventory stock - using correct column name
                            $update_stmt = $conn->prepare("
                                UPDATE inventory 
                                SET current_stock = current_stock + ?
                                WHERE id = ?
                            ");
                            $update_stmt->execute([$item['received_quantity'], $item['product_id']]);
                        }
                    }
                }
                
                $conn->commit();
                $message = "Goods Received Note $grn_number created successfully";
                $message_type = "success";
                break;
                
            case 'verify':
                $stmt = $conn->prepare("
                    UPDATE goods_received_notes 
                    SET status = 'verified', 
                        verified_by = ?,
                        verified_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$user_id, $_POST['grn_id']]);
                $message = "GRN verified successfully";
                $message_type = "success";
                break;
                
            case 'quality_check':
                $stmt = $conn->prepare("
                    UPDATE goods_received_notes 
                    SET quality_status = ?,
                        quality_checked_by = ?,
                        quality_checked_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['quality_status'], $user_id, $_POST['grn_id']]);
                $message = "Quality check completed";
                $message_type = "success";
                break;
        }
    }
    
    // Fetch all GRNs - FIXED: removed CONCAT for users
    $status_filter = $_GET['status'] ?? 'all';
    $sql = "SELECT grn.*, 
                   po.po_number,
                   s.supplier_name,
                   u.username as receiver_name
            FROM goods_received_notes grn
            LEFT JOIN purchases po ON grn.po_id = po.id
            LEFT JOIN suppliers s ON grn.supplier_id = s.id
            LEFT JOIN users u ON grn.received_by = u.id";
    
    if ($status_filter != 'all') {
        $sql .= " WHERE grn.status = :status ORDER BY grn.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':status' => $status_filter]);
    } else {
        $sql .= " ORDER BY grn.created_at DESC";
        $stmt = $conn->query($sql);
    }
    
    $goods_received = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch pending POs for dropdown - check if purchases table exists
    $pending_pos = [];
    try {
        $po_stmt = $conn->query("
            SELECT po.id, po.po_number, s.supplier_name 
            FROM purchases po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status IN ('approved', 'sent') 
            ORDER BY po.po_number DESC
            LIMIT 50
        ");
        $pending_pos = $po_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist yet
        $pending_pos = [];
    }
    
    // Fetch suppliers
    $suppliers = [];
    try {
        $supplier_stmt = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status = 'active' OR status = 1 ORDER BY supplier_name");
        $suppliers = $supplier_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $suppliers = [];
    }
    
} catch(PDOException $e) {
    $message = "Database error: " . $e->getMessage();
    $message_type = "danger";
    $goods_received = [];
}
?>
<!-- Rest of the HTML remains the same -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goods Received - Savant Motors ERP</title>
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
        }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100%;
            background: linear-gradient(180deg, #ffffff 0%, #ffffff 100%);
            z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 32px 24px; text-align: center; border-bottom: 1px solid var(--border); }
        .logo-icon { width: 150px; height: 100px; margin: 0 auto 16px; overflow: hidden; }
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
            font-weight: 500;
        }
        .logout-item:hover { background: rgba(239,68,68,0.1); }
        
        .main-content { margin-left: 280px; padding: 0 32px 32px 32px; min-height: 100vh; }
        .top-bar {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;
            padding: 20px 0; border-bottom: 1px solid var(--border); margin-bottom: 32px;
        }
        .welcome-section h1 { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, var(--dark), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
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
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        
        .filter-bar {
            background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px;
            display: flex; gap: 16px; flex-wrap: wrap; align-items: center; box-shadow: var(--shadow);
        }
        .filter-btn {
            padding: 8px 20px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500;
            background: var(--light); color: var(--gray);
        }
        .filter-btn.active { background: var(--primary); color: white; }
        
        .grn-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 24px;
        }
        .grn-card {
            background: white; border-radius: 24px; overflow: hidden; border: 1px solid var(--border);
            transition: all 0.2s; box-shadow: var(--shadow);
        }
        .grn-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .card-header {
            padding: 20px 24px; background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        .grn-number { font-weight: 700; font-size: 16px; }
        .card-body { padding: 20px 24px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .info-label { color: var(--gray); font-weight: 500; }
        .info-value { color: var(--dark); font-weight: 600; }
        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-verified { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .quality-good { background: #d1fae5; color: #065f46; }
        .quality-damaged { background: #fee2e2; color: #991b1b; }
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
            background: white; border-radius: 32px; width: 90%; max-width: 900px; max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px 24px; background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white; display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0;
        }
        .close-modal { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--border); }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark); font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 16px;
            font-size: 14px;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--primary);
        }
        
        .items-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .items-table th, .items-table td {
            padding: 12px; text-align: left; border-bottom: 1px solid var(--border);
        }
        .items-table th { background: var(--light); font-weight: 600; font-size: 13px; }
        
        .alert {
            padding: 16px 20px; border-radius: 16px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid var(--danger); }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 0 20px 20px 20px; }
            .grn-grid { grid-template-columns: 1fr; }
        }
        
        .row-2cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <img src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo">
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
            <div class="logo-subtitle">Goods Receiving</div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="purchase_requests.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Purchase Requests</a>
            <a href="purchases/index.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchase Orders</a>
            <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
            <a href="goods_received.php" class="nav-item active"><i class="fas fa-check-double"></i> Goods Received</a>
            <a href="vendor_quotes.php" class="nav-item"><i class="fas fa-file-invoice"></i> Vendor Quotes</a>
            <div class="logout-wrapper">
                <div class="logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="welcome-section">
                <h1>Goods Received Notes</h1>
                <p><i class="fas fa-check-double"></i> Track all incoming goods and quality inspections</p>
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
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-check-double" style="color: var(--success);"></i>
                Goods Received
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> New Goods Receipt
            </button>
        </div>
        
        <div class="filter-bar">
            <a href="?status=all" class="filter-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="filter-btn <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=verified" class="filter-btn <?php echo $status_filter == 'verified' ? 'active' : ''; ?>">Verified</a>
        </div>
        
        <div class="grn-grid">
            <?php if (empty($goods_received)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 24px;">
                    <i class="fas fa-box-open" style="font-size: 64px; color: var(--gray); margin-bottom: 16px;"></i>
                    <p style="color: var(--gray);">No goods received records found</p>
                    <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">Receive First Shipment</button>
                </div>
            <?php else: ?>
                <?php foreach ($goods_received as $grn): ?>
                <div class="grn-card">
                    <div class="card-header">
                        <span class="grn-number"><?php echo htmlspecialchars($grn['grn_number']); ?></span>
                        <span class="status-badge status-<?php echo $grn['status']; ?>">
                            <?php echo ucfirst($grn['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">PO Number:</span>
                            <span class="info-value"><?php echo htmlspecialchars($grn['po_number'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Supplier:</span>
                            <span class="info-value"><?php echo htmlspecialchars($grn['supplier_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Received Date:</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($grn['received_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Delivery Note:</span>
                            <span class="info-value"><?php echo htmlspecialchars($grn['delivery_note_number'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Value:</span>
                            <span class="info-value">UGX <?php echo number_format($grn['total_value'] ?? 0); ?></span>
                        </div>
                        <?php if ($grn['quality_status']): ?>
                        <div class="info-row">
                            <span class="info-label">Quality Status:</span>
                            <span class="quality-badge quality-<?php echo $grn['quality_status']; ?>">
                                <?php echo ucfirst($grn['quality_status']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary" onclick="viewGRN(<?php echo $grn['id']; ?>)">
                            <i class="fas fa-eye"></i> View Items
                        </button>
                        <?php if ($grn['status'] == 'pending'): ?>
                        <button class="btn btn-success" onclick="verifyGRN(<?php echo $grn['id']; ?>)">
                            <i class="fas fa-check-circle"></i> Verify
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-warning" onclick="qualityCheck(<?php echo $grn['id']; ?>)">
                            <i class="fas fa-clipboard-check"></i> Quality Check
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Create GRN Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> New Goods Received Note</h3>
                <button class="close-modal" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" id="createForm">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div class="form-group">
                            <label>Purchase Order *</label>
                            <select name="po_id" id="poSelect" required onchange="loadPOItems()">
                                <option value="">Select PO</option>
                                <?php foreach ($pending_pos as $po): ?>
                                <option value="<?php echo $po['id']; ?>" data-supplier="<?php echo $po['supplier_name']; ?>">
                                    <?php echo htmlspecialchars($po['po_number']); ?> - <?php echo htmlspecialchars($po['supplier_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Supplier</label>
                            <input type="text" id="supplierName" readonly style="background: var(--light);">
                            <input type="hidden" name="supplier_id" id="supplierId">
                        </div>
                    </div>
                    <div class="row-2cols">
                        <div class="form-group">
                            <label>Delivery Note Number</label>
                            <input type="text" name="delivery_note_number">
                        </div>
                        <div class="form-group">
                            <label>Invoice Number</label>
                            <input type="text" name="invoice_number">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any remarks about the delivery..."></textarea>
                    </div>
                    
                    <label style="font-weight: 600; margin: 16px 0 12px; display: block;">Received Items</label>
                    <div id="poItemsContainer"></div>
                    <input type="hidden" name="total_value" id="totalValue">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create GRN</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Items Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> Received Items</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="items-table">
                    <thead>
                        <tr><th>Product</th><th>Ordered</th><th>Received</th><th>Unit Price</th><th>Total</th><th>Condition</th></tr>
                    </thead>
                    <tbody id="viewItemsBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Verify Modal -->
    <div id="verifyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Verify Goods Receipt</h3>
                <button class="close-modal" onclick="closeVerifyModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="verify">
                <input type="hidden" name="grn_id" id="verifyGrnId">
                <div class="modal-body">
                    <p>Confirm that all goods have been received correctly and match the PO?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeVerifyModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Verification</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Quality Check Modal -->
    <div id="qualityModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-clipboard-check"></i> Quality Check</h3>
                <button class="close-modal" onclick="closeQualityModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="quality_check">
                <input type="hidden" name="grn_id" id="qualityGrnId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Quality Status *</label>
                        <select name="quality_status" required>
                            <option value="good">Good - All items acceptable</option>
                            <option value="partial">Partial - Some items damaged/defective</option>
                            <option value="damaged">Damaged - Major quality issues</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeQualityModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning">Submit Quality Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let poItems = [];
        
        async function loadPOItems() {
            const poSelect = document.getElementById('poSelect');
            const poId = poSelect.value;
            const selectedOption = poSelect.options[poSelect.selectedIndex];
            const supplierName = selectedOption.getAttribute('data-supplier');
            
            document.getElementById('supplierName').value = supplierName || '';
            
            if (!poId) return;
            
            try {
                const response = await fetch(`get_po_items.php?po_id=${poId}`);
                const data = await response.json();
                poItems = data.items || [];
                
                const container = document.getElementById('poItemsContainer');
                if (poItems.length === 0) {
                    container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--gray);">No items found for this PO</div>';
                    return;
                }
                
                let html = '<table class="items-table"><thead><tr><th>Product</th><th>Ordered Qty</th><th>Received Qty</th><th>Unit Price</th><th>Condition</th></tr></thead><tbody>';
                poItems.forEach((item, index) => {
                    html += `
                        <tr>
                            <td>${item.product_name}<input type="hidden" name="items[${index}][product_id]" value="${item.product_id}"></td>
                            <td>${item.quantity}<input type="hidden" name="items[${index}][ordered_quantity]" value="${item.quantity}"></td>
                            <td><input type="number" name="items[${index}][received_quantity]" value="${item.quantity}" min="0" max="${item.quantity}" style="width: 80px; padding: 6px; border-radius: 8px; border: 1px solid var(--border);" onchange="updateTotal()"></td>
                            <td><input type="number" name="items[${index}][unit_price]" value="${item.unit_price || 0}" step="0.01" style="width: 100px; padding: 6px; border-radius: 8px; border: 1px solid var(--border);" onchange="updateTotal()"></td>
                            <td><select name="items[${index}][condition_status]" style="padding: 6px; border-radius: 8px; border: 1px solid var(--border);"><option value="good">Good</option><option value="damaged">Damaged</option><option value="expired">Expired</option></select></td>
                        </tr>
                    `;
                });
                html += '</tbody></table>';
                container.innerHTML = html;
                updateTotal();
            } catch(e) {
                console.error('Error loading PO items:', e);
            }
        }
        
        function updateTotal() {
            let total = 0;
            const receivedInputs = document.querySelectorAll('input[name*="[received_quantity]"]');
            const priceInputs = document.querySelectorAll('input[name*="[unit_price]"]');
            
            for (let i = 0; i < receivedInputs.length; i++) {
                const qty = parseFloat(receivedInputs[i].value) || 0;
                const price = parseFloat(priceInputs[i].value) || 0;
                total += qty * price;
            }
            
            document.getElementById('totalValue').value = total;
        }
        
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        async function viewGRN(grnId) {
            try {
                const response = await fetch(`get_grn_items.php?grn_id=${grnId}`);
                const items = await response.json();
                const tbody = document.getElementById('viewItemsBody');
                tbody.innerHTML = '';
                items.forEach(item => {
                    const row = tbody.insertRow();
                    row.insertCell(0).textContent = item.product_name;
                    row.insertCell(1).textContent = item.ordered_quantity;
                    row.insertCell(2).textContent = item.received_quantity;
                    row.insertCell(3).textContent = `UGX ${parseFloat(item.unit_price).toLocaleString()}`;
                    row.insertCell(4).textContent = `UGX ${parseFloat(item.total_price).toLocaleString()}`;
                    row.insertCell(5).innerHTML = `<span class="quality-${item.condition_status}">${item.condition_status}</span>`;
                });
                document.getElementById('viewModal').style.display = 'flex';
            } catch(e) {
                alert('Error loading items');
            }
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function verifyGRN(grnId) {
            document.getElementById('verifyGrnId').value = grnId;
            document.getElementById('verifyModal').style.display = 'flex';
        }
        
        function closeVerifyModal() {
            document.getElementById('verifyModal').style.display = 'none';
        }
        
        function qualityCheck(grnId) {
            document.getElementById('qualityGrnId').value = grnId;
            document.getElementById('qualityModal').style.display = 'flex';
        }
        
        function closeQualityModal() {
            document.getElementById('qualityModal').style.display = 'none';
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