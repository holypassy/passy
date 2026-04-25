<?php
// vendor_quotes.php - Vendor Quotes Management (Fully Fixed)
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
                $stmt = $conn->prepare("
                    INSERT INTO vendor_quotes (
                        quote_number, vendor_id, request_for_quote_id, quote_date, 
                        valid_until, delivery_terms, payment_terms, total_amount,
                        currency, status, notes, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
                ");
                
                $quote_number = 'VQ-' . date('Ymd') . '-' . rand(100, 999);
                
                $stmt->execute([
                    $quote_number,
                    $_POST['vendor_id'],
                    !empty($_POST['rfq_id']) ? $_POST['rfq_id'] : null,
                    $_POST['quote_date'],
                    $_POST['valid_until'],
                    $_POST['delivery_terms'] ?? null,
                    $_POST['payment_terms'] ?? null,
                    $_POST['total_amount'],
                    $_POST['currency'] ?? 'UGX',
                    $_POST['notes'] ?? '',
                    $user_id
                ]);
                
                $quote_id = $conn->lastInsertId();
                
                // Add quote items
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['product_name'])) {
                            $item_stmt = $conn->prepare("
                                INSERT INTO vendor_quote_items (
                                    quote_id, product_name, description, quantity, unit,
                                    unit_price, total_price, tax_rate, tax_amount
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $total_price = $item['quantity'] * $item['unit_price'];
                            $tax_amount = $total_price * (($item['tax_rate'] ?? 0) / 100);
                            $item_stmt->execute([
                                $quote_id,
                                $item['product_name'],
                                $item['description'] ?? '',
                                $item['quantity'],
                                $item['unit'] ?? 'pcs',
                                $item['unit_price'],
                                $total_price,
                                $item['tax_rate'] ?? 0,
                                $tax_amount
                            ]);
                        }
                    }
                }
                
                $message = "Vendor quote $quote_number created successfully";
                $message_type = "success";
                break;
                
            case 'approve':
                $stmt = $conn->prepare("
                    UPDATE vendor_quotes 
                    SET status = 'approved',
                        approved_by = ?,
                        approved_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$user_id, $_POST['quote_id']]);
                $message = "Quote approved successfully";
                $message_type = "success";
                break;
                
            case 'reject':
                $stmt = $conn->prepare("
                    UPDATE vendor_quotes 
                    SET status = 'rejected',
                        rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$_POST['reason'], $_POST['quote_id']]);
                $message = "Quote rejected";
                $message_type = "warning";
                break;
        }
    }
    
    // Fetch all vendor quotes - FIXED: using correct column name 'supplier_name'
    $status_filter = $_GET['status'] ?? 'all';
    $sql = "SELECT vq.*, 
                   s.supplier_name, 
                   s.contact_person, 
                   s.email,
                   u.username as creator_name
            FROM vendor_quotes vq
            LEFT JOIN suppliers s ON vq.vendor_id = s.id
            LEFT JOIN users u ON vq.created_by = u.id";
    
    if ($status_filter != 'all') {
        $sql .= " WHERE vq.status = :status ORDER BY vq.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':status' => $status_filter]);
    } else {
        $sql .= " ORDER BY vq.created_at DESC";
        $stmt = $conn->query($sql);
    }
    
    $vendor_quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch vendors for dropdown - using correct column name
    $vendors = [];
    try {
        $vendor_stmt = $conn->query("SELECT id, supplier_name FROM suppliers WHERE status = 'active' OR status = 1 ORDER BY supplier_name");
        $vendors = $vendor_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $vendors = [];
    }
    
} catch(PDOException $e) {
    $message = "Database error: " . $e->getMessage();
    $message_type = "danger";
    $vendor_quotes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Quotes - Savant Motors ERP</title>
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
        .btn-danger { background: var(--danger); color: white; }
        
        .filter-bar {
            background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px;
            display: flex; gap: 16px; flex-wrap: wrap; align-items: center; box-shadow: var(--shadow);
        }
        .filter-btn {
            padding: 8px 20px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500;
            background: var(--light); color: var(--gray);
        }
        .filter-btn.active { background: var(--primary); color: white; }
        
        .quotes-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 24px;
        }
        .quote-card {
            background: white; border-radius: 24px; overflow: hidden; border: 1px solid var(--border);
            transition: all 0.2s; box-shadow: var(--shadow);
        }
        .quote-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .card-header {
            padding: 20px 24px; background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        .quote-number { font-weight: 700; font-size: 16px; }
        .card-body { padding: 20px 24px; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .info-label { color: var(--gray); font-weight: 500; }
        .info-value { color: var(--dark); font-weight: 600; }
        .amount { font-size: 20px; font-weight: 800; color: var(--primary); }
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
            background: white; border-radius: 32px; width: 90%; max-width: 1000px; max-height: 90vh;
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
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid var(--warning); }
        
        .row-2cols { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .row-3cols { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 0 20px 20px 20px; }
            .quotes-grid { grid-template-columns: 1fr; }
            .row-2cols, .row-3cols { grid-template-columns: 1fr; }
        }
        
        .item-row { margin-bottom: 16px; padding: 16px; background: var(--light); border-radius: 16px; }
        .item-row .row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: center; }
        .remove-item { color: var(--danger); cursor: pointer; background: none; border: none; font-size: 18px; }
        
        @media (max-width: 768px) {
            .item-row .row { grid-template-columns: 1fr; gap: 8px; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <img src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-car\' style=\'font-size:32px; color:var(--primary);\'></i>';">
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
            <div class="logo-subtitle">Vendor Quotes</div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
            <a href="purchase_requests.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Purchase Requests</a>
            <a href="purchases/index.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchase Orders</a>
            <a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a>
            <a href="goods_received.php" class="nav-item"><i class="fas fa-check-double"></i> Goods Received</a>
            <a href="vendor_quotes.php" class="nav-item active"><i class="fas fa-file-invoice"></i> Vendor Quotes</a>
            <div class="logout-wrapper">
                <div class="logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-bar">
            <div class="welcome-section">
                <h1>Vendor Quotes</h1>
                <p><i class="fas fa-file-invoice"></i> Manage and compare supplier quotations</p>
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
                <i class="fas fa-file-invoice" style="color: var(--primary);"></i>
                Vendor Quotes
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> New Vendor Quote
            </button>
        </div>
        
        <div class="filter-bar">
            <a href="?status=all" class="filter-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="filter-btn <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=approved" class="filter-btn <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">Approved</a>
            <a href="?status=rejected" class="filter-btn <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
        </div>
        
        <div class="quotes-grid">
            <?php if (empty($vendor_quotes)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 24px;">
                    <i class="fas fa-file-invoice" style="font-size: 64px; color: var(--gray); margin-bottom: 16px;"></i>
                    <p style="color: var(--gray);">No vendor quotes found</p>
                    <button class="btn btn-primary" onclick="openCreateModal()" style="margin-top: 16px;">Create First Quote</button>
                </div>
            <?php else: ?>
                <?php foreach ($vendor_quotes as $quote): ?>
                <div class="quote-card">
                    <div class="card-header">
                        <span class="quote-number"><?php echo htmlspecialchars($quote['quote_number']); ?></span>
                        <span class="status-badge status-<?php echo $quote['status']; ?>">
                            <?php echo ucfirst($quote['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Vendor:</span>
                            <span class="info-value"><?php echo htmlspecialchars($quote['supplier_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Quote Date:</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($quote['quote_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Valid Until:</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($quote['valid_until'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Total Amount:</span>
                            <span class="amount"><?php echo htmlspecialchars($quote['currency'] ?? 'UGX'); ?> <?php echo number_format($quote['total_amount'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Payment Terms:</span>
                            <span class="info-value"><?php echo htmlspecialchars($quote['payment_terms'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary" onclick="viewQuote(<?php echo $quote['id']; ?>)">
                            <i class="fas fa-eye"></i> View Items
                        </button>
                        <?php if ($quote['status'] == 'pending' && ($user_role == 'admin' || $user_role == 'procurement_manager')): ?>
                        <button class="btn btn-success" onclick="approveQuote(<?php echo $quote['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-danger" onclick="rejectQuote(<?php echo $quote['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Create Quote Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus"></i> New Vendor Quote</h3>
                <button class="close-modal" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST" id="createForm">
                <input type="hidden" name="action" value="create">
                <div class="modal-body">
                    <div class="row-2cols">
                        <div class="form-group">
                            <label>Vendor *</label>
                            <select name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>"><?php echo htmlspecialchars($vendor['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>RFQ Reference (Optional)</label>
                            <select name="rfq_id">
                                <option value="">None</option>
                            </select>
                        </div>
                    </div>
                    <div class="row-3cols">
                        <div class="form-group">
                            <label>Quote Date *</label>
                            <input type="date" name="quote_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Valid Until *</label>
                            <input type="date" name="valid_until" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <option value="UGX">UGX - Ugandan Shilling</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                            </select>
                        </div>
                    </div>
                    <div class="row-2cols">
                        <div class="form-group">
                            <label>Delivery Terms</label>
                            <input type="text" name="delivery_terms" placeholder="e.g., FOB, CIF, EXW">
                        </div>
                        <div class="form-group">
                            <label>Payment Terms</label>
                            <input type="text" name="payment_terms" placeholder="e.g., Net 30, 50% deposit">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any additional information..."></textarea>
                    </div>
                    
                    <label style="font-weight: 600; margin: 16px 0 12px; display: block;">Quote Items</label>
                    <div id="itemsContainer"></div>
                    <button type="button" class="btn btn-secondary btn-add-item" onclick="addItemRow()">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    <input type="hidden" name="total_amount" id="totalAmount">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Quote</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Items Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> Quote Items</h3>
                <button class="close-modal" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <table class="items-table">
                    <thead>
                        <tr><th>Product</th><th>Description</th><th>Quantity</th><th>Unit</th><th>Unit Price</th><th>Total</th></tr>
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
                <h3><i class="fas fa-check"></i> Approve Quote</h3>
                <button class="close-modal" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="quote_id" id="approveQuoteId">
                <div class="modal-body">
                    <p>Are you sure you want to approve this vendor quote?</p>
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
                <h3><i class="fas fa-times"></i> Reject Quote</h3>
                <button class="close-modal" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="quote_id" id="rejectQuoteId">
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
                    <input type="text" name="items[${itemCount}][description]" placeholder="Description" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                    <input type="number" name="items[${itemCount}][quantity]" placeholder="Qty" required style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);" onchange="updateTotal()">
                    <select name="items[${itemCount}][unit]" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);">
                        <option value="pcs">pcs</option><option value="kg">kg</option><option value="liters">liters</option><option value="boxes">boxes</option><option value="sets">sets</option>
                    </select>
                    <input type="number" name="items[${itemCount}][unit_price]" placeholder="Unit Price" step="0.01" required style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);" onchange="updateTotal()">
                    <select name="items[${itemCount}][tax_rate]" style="padding: 8px; border-radius: 8px; border: 1px solid var(--border);" onchange="updateTotal()">
                        <option value="0">0% Tax</option><option value="18">18% VAT</option>
                    </select>
                    <button type="button" class="remove-item" onclick="this.closest('.item-row').remove(); updateTotal();"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.appendChild(div);
            itemCount++;
        }
        
        function updateTotal() {
            let total = 0;
            const rows = document.querySelectorAll('.item-row');
            rows.forEach(row => {
                const qty = parseFloat(row.querySelector('input[name*="[quantity]"]')?.value) || 0;
                const price = parseFloat(row.querySelector('input[name*="[unit_price]"]')?.value) || 0;
                const taxRate = parseFloat(row.querySelector('select[name*="[tax_rate]"]')?.value) || 0;
                const subtotal = qty * price;
                const tax = subtotal * (taxRate / 100);
                total += subtotal + tax;
            });
            document.getElementById('totalAmount').value = total.toFixed(2);
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
        
        async function viewQuote(quoteId) {
            try {
                const response = await fetch(`get_quote_items.php?quote_id=${quoteId}`);
                const items = await response.json();
                const tbody = document.getElementById('viewItemsBody');
                tbody.innerHTML = '';
                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No items found</td></tr>';
                } else {
                    items.forEach(item => {
                        const row = tbody.insertRow();
                        row.insertCell(0).textContent = item.product_name;
                        row.insertCell(1).textContent = item.description || '-';
                        row.insertCell(2).textContent = item.quantity;
                        row.insertCell(3).textContent = item.unit;
                        row.insertCell(4).textContent = parseFloat(item.unit_price).toLocaleString();
                        row.insertCell(5).textContent = parseFloat(item.total_price).toLocaleString();
                    });
                }
                document.getElementById('viewModal').style.display = 'flex';
            } catch(e) {
                console.error('Error loading items:', e);
                alert('Error loading items');
            }
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function approveQuote(quoteId) {
            document.getElementById('approveQuoteId').value = quoteId;
            document.getElementById('approveModal').style.display = 'flex';
        }
        
        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }
        
        function rejectQuote(quoteId) {
            document.getElementById('rejectQuoteId').value = quoteId;
            document.getElementById('rejectModal').style.display = 'flex';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }
        
        document.getElementById('logoutBtn')?.addEventListener('click', () => {
            window.location.href = 'index.php';
        });
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // Initialize with one item row
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('createModal').style.display === 'flex') {
                addItemRow();
            }
        });
    </script>
</body>
</html>