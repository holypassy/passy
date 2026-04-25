<?php
// suppliers.php – Supplier Management (Blue Theme - Table View)
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
    
    // Create suppliers table with all necessary columns
    $conn->exec("
        CREATE TABLE IF NOT EXISTS suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(100),
            phone VARCHAR(20),
            email VARCHAR(100),
            address TEXT,
            tax_id VARCHAR(50),
            payment_terms VARCHAR(50) DEFAULT 'Net 30',
            created_by INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL
        )
    ");
    
    // Check and add missing columns if they don't exist
    $existingColumns = $conn->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredColumns = [
        'payment_terms' => "VARCHAR(50) DEFAULT 'Net 30'",
        'created_by' => "INT",
        'created_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "DATETIME ON UPDATE CURRENT_TIMESTAMP",
        'deleted_at' => "DATETIME NULL"
    ];
    
    foreach ($requiredColumns as $col => $def) {
        if (!in_array($col, $existingColumns)) {
            try {
                $conn->exec("ALTER TABLE suppliers ADD COLUMN {$col} {$def}");
            } catch(PDOException $e) {
                // Column might already exist, ignore
            }
        }
    }
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $where = "WHERE deleted_at IS NULL";
    $params = [];
    if (!empty($search)) {
        $where .= " AND (supplier_name LIKE :search OR contact_person LIKE :search OR phone LIKE :search OR email LIKE :search OR tax_id LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM suppliers $where");
    foreach ($params as $key => $val) $countStmt->bindValue($key, $val);
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($total / $limit);
    
    $sql = "SELECT id, supplier_name, contact_person, phone, email, address, tax_id, payment_terms, created_at FROM suppliers $where ORDER BY supplier_name ASC LIMIT :limit OFFSET :offset";
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $val) $stmt->bindValue($key, $val);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    error_log("Suppliers Error: " . $e->getMessage());
    $suppliers = [];
    $total = 0;
    $totalPages = 0;
    $error_message = "Database error: " . $e->getMessage();
}

// AJAX handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        if ($_POST['ajax_action'] === 'add_supplier') {
            $stmt = $conn->prepare("
                INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, tax_id, payment_terms, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['supplier_name'],
                $_POST['contact_person'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['email'] ?? null,
                $_POST['address'] ?? null,
                $_POST['tax_id'] ?? null,
                $_POST['payment_terms'] ?? 'Net 30',
                $user_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Supplier added successfully']);
            exit();
        }
        
        if ($_POST['ajax_action'] === 'edit_supplier') {
            $stmt = $conn->prepare("
                UPDATE suppliers SET supplier_name = ?, contact_person = ?, phone = ?, email = ?, address = ?, tax_id = ?, payment_terms = ?
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([
                $_POST['supplier_name'],
                $_POST['contact_person'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['email'] ?? null,
                $_POST['address'] ?? null,
                $_POST['tax_id'] ?? null,
                $_POST['payment_terms'] ?? 'Net 30',
                $_POST['id']
            ]);
            echo json_encode(['success' => true, 'message' => 'Supplier updated successfully']);
            exit();
        }
        
        if ($_POST['ajax_action'] === 'delete_supplier') {
            $stmt = $conn->prepare("UPDATE suppliers SET deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
            exit();
        }
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

if (isset($_GET['get_supplier'])) {
    header('Content-Type: application/json');
    try {
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $conn->prepare("SELECT id, supplier_name, contact_person, phone, email, address, tax_id, payment_terms FROM suppliers WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$_GET['get_supplier']]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($supplier ?: null);
    } catch(PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Blue Theme - Professional Corporate Style */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #e6f0fa 0%, #c5d9f0 100%);
            padding: 2rem;
            position: relative;
            min-height: 100vh;
        }
        .watermark {
            position: fixed;
            bottom: 20px;
            right: 20px;
            opacity: 0.08;
            pointer-events: none;
            z-index: 1000;
            font-size: 48px;
            font-weight: 800;
            color: #1e40af;
            transform: rotate(-15deg);
            white-space: nowrap;
        }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .watermark { opacity: 0.1; }
            .toolbar { display: none; }
            .container { box-shadow: none; border-radius: 0; }
            .btn-actions, .quick-action-card { display: none; }
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 28px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            z-index: 1;
        }
        .toolbar {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 1rem 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .toolbar button, .toolbar a {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }
        .toolbar button:hover, .toolbar a:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }
        .toolbar .print-btn {
            background: #ffffff;
            color: #1e3c72;
        }
        .toolbar .print-btn:hover {
            background: #e6f0fa;
        }
        .quote-content {
            padding: 2rem;
        }
        .header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .logo {
            flex-shrink: 0;
            width: 100px;
        }
        .logo img {
            max-width: 80px;
            height: auto;
        }
        .company {
            flex-grow: 1;
            text-align: center;
        }
        .company h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1e3c72;
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .company-address {
            font-size: 0.8rem;
            color: #5e6f8d;
            margin-top: 5px;
        }
        .right-text {
            width: 100px;
            text-align: right;
            font-size: 18px;
            font-weight: 800;
            color: #2a5298;
            letter-spacing: 1px;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .alert-danger { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title h1 i { color: #2a5298; }
        .page-title p {
            color: #64748b;
            font-size: 14px;
            margin-top: 5px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2a5298, #1e3c72);
            color: white;
            box-shadow: 0 4px 12px rgba(42, 82, 152, 0.3);
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 20px rgba(42, 82, 152, 0.4);
        }
        .btn-secondary { 
            background: white; 
            color: #64748b; 
            border: 1px solid #e2e8f0; 
        }
        .btn-secondary:hover { 
            border-color: #2a5298; 
            color: #2a5298; 
            transform: translateY(-1px);
        }
        .btn-danger { 
            background: #fee2e2; 
            color: #ef4444; 
        }
        .btn-danger:hover { 
            background: #ef4444; 
            color: white; 
            transform: translateY(-1px);
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 11px;
        }
        .search-bar { 
            display: flex; 
            gap: 10px; 
            margin-bottom: 25px; 
        }
        .search-bar input { 
            flex: 1; 
            padding: 12px 16px; 
            border: 2px solid #e2e8f0; 
            border-radius: 40px; 
            font-size: 14px;
            transition: all 0.3s;
        }
        .search-bar input:focus {
            outline: none;
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.1);
        }
        .search-bar button { 
            background: #2a5298; 
            color: white; 
            border: none; 
            padding: 0 24px; 
            border-radius: 40px; 
            cursor: pointer;
            transition: all 0.3s;
        }
        .search-bar button:hover {
            background: #1e3c72;
            transform: translateY(-1px);
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 14px 16px;
            background: #f8fafc;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        tr:hover {
            background: #fafbff;
        }
        .supplier-name-cell {
            font-weight: 700;
            color: #2a5298;
        }
        .payment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #dbeafe;
            color: #1e40af;
        }
        .address-cell {
            max-width: 250px;
            white-space: normal;
            word-wrap: break-word;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .action-btn.edit { background: #dbeafe; color: #1e40af; }
        .action-btn.edit:hover { background: #2a5298; color: white; }
        .action-btn.delete { background: #fee2e2; color: #dc2626; }
        .action-btn.delete:hover { background: #dc2626; color: white; }
        
        .checkbox-col {
            width: 40px;
            text-align: center;
        }
        .checkbox-col input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .bulk-actions {
            background: white;
            border-radius: 16px;
            padding: 12px 20px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 15px;
            border: 1px solid #e2e8f0;
        }
        .bulk-actions.show {
            display: flex;
        }
        .selected-count {
            font-size: 13px;
            font-weight: 600;
            color: #2a5298;
        }
        
        .modal { 
            display: none; 
            position: fixed; 
            top: 0; 
            left: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.6); 
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
            border-radius: 32px; 
            width: 90%; 
            max-width: 600px; 
            max-height: 85vh; 
            overflow-y: auto; 
        }
        .modal-header { 
            background: linear-gradient(135deg, #2a5298, #1e3c72); 
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
            transition: all 0.3s;
        }
        .close-btn:hover {
            background: rgba(255,255,255,0.3);
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
            color: #64748b; 
            margin-bottom: 6px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
        }
        .form-group input, 
        .form-group select, 
        .form-group textarea { 
            width: 100%; 
            padding: 12px 14px; 
            border: 2px solid #e2e8f0; 
            border-radius: 16px; 
            font-size: 14px; 
            transition: all 0.3s;
        }
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.1);
        }
        .modal-footer { 
            padding: 20px 25px; 
            border-top: 1px solid #e2e8f0; 
            display: flex; 
            justify-content: flex-end; 
            gap: 12px; 
        }
        .pagination { 
            display: flex; 
            justify-content: center; 
            gap: 8px; 
            margin-top: 25px; 
        }
        .pagination a, .pagination span { 
            padding: 8px 14px; 
            background: white; 
            border: 1px solid #e2e8f0; 
            border-radius: 40px; 
            text-decoration: none; 
            color: #64748b; 
            transition: all 0.3s;
        }
        .pagination a:hover {
            background: #2a5298;
            color: white;
            border-color: #2a5298;
        }
        .pagination a.active { 
            background: #2a5298; 
            color: white; 
            border-color: #2a5298; 
        }
        .empty-state { 
            text-align: center; 
            padding: 60px; 
            color: #64748b; 
        }
        .empty-state i { 
            font-size: 64px; 
            margin-bottom: 20px; 
            opacity: 0.5; 
        }
        footer {
            text-align: center;
            padding: 1rem;
            font-size: 12px;
            color: #64748b;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-top: 1px solid #e2e8f0;
            margin-top: 20px;
        }
        @media (max-width: 768px) {
            body { padding: 1rem; }
            .search-bar { flex-direction: column; }
            .search-bar button { padding: 12px; }
            th, td { padding: 10px 12px; }
            .action-buttons { flex-direction: column; }
            .action-btn { justify-content: center; }
            .address-cell { max-width: 150px; }
        }
    </style>
</head>
<body>
    <div class="watermark">SAVANT MOTORS</div>

    <div class="container">
        <div class="toolbar">
            <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="dashboard_erp.php"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="unified/index.php"><i class="fas fa-boxes"></i> Inventory</a>
            <a href="purchases/index.php"><i class="fas fa-shopping-cart"></i> Purchases</a>
        </div>

        <div class="quote-content">
            <div class="header-wrapper">
                <div class="logo">
                    <img src="images/logo.jpeg" alt="Savant Motors Logo" onerror="this.style.display='none'">
                </div>
                <div class="company">
                    <h1>SAVANT MOTORS UGANDA</h1>
                    <div class="company-address">
                        <i class="fas fa-map-marker-alt"></i> Bugolobi, Bunyonyi Drive, Kampala, Uganda
                    </div>
                </div>
                <div class="right-text">SUPPLIERS</div>
            </div>

            <div class="top-bar">
                <div class="page-title">
                    <h1><i class="fas fa-truck"></i> Supplier Management</h1>
                    <p>Manage your suppliers, vendors, and their contact information</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus-circle"></i> Add Supplier
                    </button>
                    <button class="btn btn-secondary" onclick="exportToExcel()" style="margin-left: 10px;">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>

            <div class="search-bar">
                <form method="GET" style="flex: 1; display: flex; gap: 10px;">
                    <input type="text" name="search" placeholder="Search by name, contact person, phone, email, tax ID or address..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search)): ?>
                    <a href="suppliers.php" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if ($success = $_SESSION['success'] ?? null): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if ($error = $_SESSION['error'] ?? null): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Bulk Actions Bar -->
            <div class="bulk-actions" id="bulkActions">
                <i class="fas fa-check-circle" style="color: #2a5298;"></i>
                <span class="selected-count" id="selectedCount">0</span> supplier(s) selected
                <button class="btn btn-sm btn-danger" onclick="bulkDelete()"><i class="fas fa-trash-alt"></i> Delete Selected</button>
                <button class="btn btn-sm btn-secondary" onclick="clearSelection()"><i class="fas fa-times"></i> Clear</button>
            </div>

            <!-- Suppliers Table -->
            <div class="table-container">
                <table id="suppliersTable">
                    <thead>
                        <tr>
                            <th class="checkbox-col"><input type="checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>Tax ID / VAT</th>
                            <th>Payment Terms</th>
                            <th>Actions</th>
                        </thead>
                        <tbody id="suppliersTableBody">
                            <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-truck"></i>
                                    <h3>No Suppliers Found</h3>
                                    <p>Click "Add Supplier" to create your first supplier.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                            <tr data-id="<?php echo $supplier['id']; ?>" 
                                data-name="<?php echo strtolower($supplier['supplier_name']); ?>"
                                data-contact="<?php echo strtolower($supplier['contact_person'] ?? ''); ?>"
                                data-phone="<?php echo $supplier['phone'] ?? ''; ?>">
                                <td class="checkbox-col"><input type="checkbox" class="supplier-checkbox" value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['id']); ?></td>
                                <td class="supplier-name-cell">
                                    <i class="fas fa-building"></i> <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['contact_person'])): ?>
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($supplier['contact_person']); ?>
                                    <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['phone'])): ?>
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($supplier['phone']); ?>
                                    <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['email'])): ?>
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($supplier['email']); ?>
                                    <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="address-cell">
                                    <?php if (!empty($supplier['address'])): ?>
                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($supplier['address']); ?>
                                    <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($supplier['tax_id'])): ?>
                                    <?php echo htmlspecialchars($supplier['tax_id']); ?>
                                    <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="payment-badge">
                                        <?php echo htmlspecialchars($supplier['payment_terms'] ?? 'Net 30'); ?>
                                    </span>
                                </td>
                                <td class="action-buttons">
                                    <button class="action-btn edit" onclick="editSupplier(<?php echo $supplier['id']; ?>)">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete" onclick="deleteSupplier(<?php echo $supplier['id']; ?>)">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo $search ? '&search='.urlencode($search) : ''; ?>"><i class="fas fa-angle-double-left"></i></a>
                    <a href="?page=<?php echo $page-1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"><i class="fas fa-angle-left"></i></a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"><i class="fas fa-angle-right"></i></a>
                    <a href="?page=<?php echo $totalPages; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>"><i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 15px; text-align: right; font-size: 12px; color: #64748b;">
                    <i class="fas fa-list"></i> Showing <?php echo count($suppliers); ?> of <?php echo $total; ?> suppliers
                </div>
            </div>
        </div>
        <footer>
            &copy; <?php echo date('Y'); ?> Savant Motors Uganda - Professional Supplier Management
        </footer>
    </div>

    <div id="supplierModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Supplier</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <form id="supplierForm">
                <input type="hidden" name="id" id="supplierId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Supplier Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="supplier_name" id="supplierName" required placeholder="Enter supplier name">
                    </div>
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" name="contact_person" id="contactPerson" placeholder="Contact person name">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" id="phone" placeholder="Phone number">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="email" placeholder="Email address">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" id="address" rows="3" placeholder="Full address - Street, City, Country"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tax ID / VAT Number</label>
                        <input type="text" name="tax_id" id="taxId" placeholder="Tax identification number">
                    </div>
                    <div class="form-group">
                        <label>Payment Terms</label>
                        <select name="payment_terms" id="paymentTerms">
                            <option value="Net 30">Net 30 Days</option>
                            <option value="Net 15">Net 15 Days</option>
                            <option value="Net 7">Net 7 Days</option>
                            <option value="Due on receipt">Due on receipt</option>
                            <option value="Cash on Delivery">Cash on Delivery</option>
                            <option value="Prepaid">Prepaid</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentPage = 1;
        
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Supplier';
            document.getElementById('supplierForm').reset();
            document.getElementById('supplierId').value = '';
            document.getElementById('submitBtn').innerHTML = 'Save Supplier';
            document.getElementById('supplierModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('supplierModal').classList.remove('active');
        }
        
        function editSupplier(id) {
            fetch(`suppliers.php?get_supplier=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data && !data.error) {
                        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Supplier';
                        document.getElementById('supplierId').value = data.id;
                        document.getElementById('supplierName').value = data.supplier_name || '';
                        document.getElementById('contactPerson').value = data.contact_person || '';
                        document.getElementById('phone').value = data.phone || '';
                        document.getElementById('email').value = data.email || '';
                        document.getElementById('address').value = data.address || '';
                        document.getElementById('taxId').value = data.tax_id || '';
                        document.getElementById('paymentTerms').value = data.payment_terms || 'Net 30';
                        document.getElementById('submitBtn').innerHTML = 'Update Supplier';
                        document.getElementById('supplierModal').classList.add('active');
                    } else {
                        alert('Could not load supplier data');
                    }
                })
                .catch(err => console.error(err));
        }
        
        function deleteSupplier(id) {
            if (confirm('Are you sure you want to delete this supplier?')) {
                const formData = new FormData();
                formData.append('ajax_action', 'delete_supplier');
                formData.append('id', id);
                fetch('suppliers.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => { 
                        if (data.success) location.reload(); 
                        else alert('Error: ' + (data.error || 'Unknown error')); 
                    })
                    .catch(err => console.error(err));
            }
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.supplier-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateBulkActions();
        }
        
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.supplier-checkbox:checked');
            const count = checkboxes.length;
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
            document.querySelectorAll('.supplier-checkbox').forEach(cb => cb.checked = false);
            updateBulkActions();
        }
        
        function bulkDelete() {
            const checkboxes = document.querySelectorAll('.supplier-checkbox:checked');
            if (checkboxes.length === 0) return;
            
            if (confirm(`Are you sure you want to delete ${checkboxes.length} supplier(s)?`)) {
                const ids = Array.from(checkboxes).map(cb => cb.value);
                fetch('suppliers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        ajax_action: 'bulk_delete',
                        ids: ids 
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('Error: ' + (data.error || 'Unknown error'));
                })
                .catch(err => console.error(err));
            }
        }
        
        function exportToExcel() {
            const rows = document.querySelectorAll('#suppliersTableBody tr');
            let csv = [];
            const headers = ['Supplier Name', 'Contact Person', 'Phone', 'Email', 'Address', 'Tax ID', 'Payment Terms'];
            csv.push(headers.join(','));
            
            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const cells = row.querySelectorAll('td');
                const rowData = [];
                cells.forEach((cell, index) => {
                    if (index !== 0) {
                        let text = cell.innerText.trim().replace(/,/g, ';');
                        rowData.push('"' + text + '"');
                    }
                });
                if (rowData.length > 0) csv.push(rowData.join(','));
            });
            
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `suppliers_${new Date().toISOString().slice(0,10)}.csv`;
            a.click();
            window.URL.revokeObjectURL(url);
        }
        
        document.getElementById('supplierForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const isEdit = document.getElementById('supplierId').value !== '';
            formData.append('ajax_action', isEdit ? 'edit_supplier' : 'add_supplier');
            fetch('suppliers.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => { 
                    if (data.success) location.reload(); 
                    else alert('Error: ' + (data.error || 'Unknown error')); 
                })
                .catch(err => console.error(err));
        });
        
        // Add change event listeners for checkboxes
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('supplier-checkbox')) {
                updateBulkActions();
            }
        });
        
        window.onclick = function(e) { 
            if (e.target.classList.contains('modal')) closeModal(); 
        };
        
        // Initialize
        updateBulkActions();
    </script>
</body>
</html>