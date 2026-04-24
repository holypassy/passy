<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$page = $_GET['page'] ?? 1;
$status = $_GET['status'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$limit = 15;
$offset = ($page - 1) * $limit;

// Build query
$where = [];
$params = [];

if ($status) {
    $where[] = "p.status = ?";
    $params[] = $status;
}
if ($supplier_id) {
    $where[] = "p.supplier_id = ?";
    $params[] = $supplier_id;
}
if ($date_from) {
    $where[] = "p.purchase_date >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where[] = "p.purchase_date <= ?";
    $params[] = $date_to;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, check what columns are available in inventory
    $invColumns = $conn->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
    
    // Determine the correct column names
    $costColumn = 'unit_cost';
    if (!in_array('unit_cost', $invColumns)) {
        if (in_array('cost_price', $invColumns)) $costColumn = 'cost_price';
        else if (in_array('purchase_price', $invColumns)) $costColumn = 'purchase_price';
        else if (in_array('buying_price', $invColumns)) $costColumn = 'buying_price';
    }
    
    $stockColumn = 'quantity';
    if (!in_array('quantity', $invColumns)) {
        if (in_array('current_stock', $invColumns)) $stockColumn = 'current_stock';
        else if (in_array('stock', $invColumns)) $stockColumn = 'stock';
    }
    
    // Get purchases
    $sql = "SELECT p.*, s.supplier_name, 
                   (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) as item_count
            FROM purchases p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            $whereClause
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM purchases p $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($total / $limit);
    
    // Get suppliers for filter - check for correct status column
    $supplierColumns = $conn->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
    $statusColumn = in_array('is_active', $supplierColumns) ? 'is_active = 1' : (in_array('status', $supplierColumns) ? "status = 'active'" : "1=1");
    
    $suppliers = $conn->query("SELECT id, supplier_name FROM suppliers WHERE $statusColumn ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total_purchases,
            COALESCE(SUM(total_amount), 0) as total_spent,
            COUNT(CASE WHEN status = 'ordered' THEN 1 END) as pending_orders,
            COUNT(CASE WHEN status = 'received' THEN 1 END) as received_orders
        FROM purchases 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
    $purchases = [];
    $suppliers = [];
    $stats = ['total_purchases' => 0, 'total_spent' => 0, 'pending_orders' => 0, 'received_orders' => 0];
    $totalPages = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders | SAVANT MOTORS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 56px;
            height: calc(100% - 56px);
            width: 250px;
            background: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
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
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s;
        }
        .menu-item:hover, .menu-item.active {
            background: #e0e7ff;
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }
        .menu-item i {
            width: 24px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-ordered { background: #fff3e0; color: #f59e0b; }
        .status-received { background: #dcfce7; color: #10b981; }
        .status-cancelled { background: #fee2e2; color: #ef4444; }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            color: white;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
                z-index: 1000;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-custom navbar-dark fixed-top">
        <div class="container-fluid">
            <button class="btn btn-link text-white d-md-none" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand" href="#">
                <i class="fas fa-shopping-cart me-2"></i>
                <strong>Purchase Management</strong>
            </a>
            <div class="dropdown">
                <button class="btn btn-link text-white dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle"></i> <?php echo $_SESSION['full_name'] ?? 'User'; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="../dashboard_erp.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-menu">
            <a href="../dashboard_erp.php" class="menu-item">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="index.php" class="menu-item active">
                <i class="fas fa-shopping-cart"></i> Purchases
            </a>
            <a href="../suppliers.php" class="menu-item">
                <i class="fas fa-truck"></i> Suppliers
            </a>
            <a href="../unified/index.php" class="menu-item">
                <i class="fas fa-boxes"></i> Inventory
            </a>
            <hr class="my-3">
            <a href="create.php" class="menu-item">
                <i class="fas fa-plus-circle"></i> New Purchase Order
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" style="margin-top: 56px;">
        <div class="container-fluid">
            <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="fw-bold mb-2">Purchase Orders</h2>
                    <p class="text-muted">Manage and track all your purchase orders</p>
                </div>
                <a href="create.php" class="btn btn-primary-custom">
                    <i class="fas fa-plus me-2"></i> New Purchase Order
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #dbeafe; color: var(--primary);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h3 class="mb-0"><?php echo number_format($stats['total_purchases'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Total Purchases (30d)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #dcfce7; color: var(--success);">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <h3 class="mb-0">UGX <?php echo number_format(($stats['total_spent'] ?? 0) / 1000000, 1); ?>M</h3>
                        <p class="text-muted mb-0">Total Spent (30d)</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fff3e0; color: var(--warning);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3 class="mb-0"><?php echo number_format($stats['pending_orders'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Pending Orders</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #dcfce7; color: var(--success);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3 class="mb-0"><?php echo number_format($stats['received_orders'] ?? 0); ?></h3>
                        <p class="text-muted mb-0">Received Orders</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <option value="ordered" <?php echo $status == 'ordered' ? 'selected' : ''; ?>>Ordered</option>
                                <option value="received" <?php echo $status == 'received' ? 'selected' : ''; ?>>Received</option>
                                <option value="cancelled" <?php echo $status == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">All Suppliers</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_id == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Purchases Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Date</th>
                                    <th>Supplier</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($purchases)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        <p class="text-muted">No purchase orders found</p>
                                        <a href="create.php" class="btn btn-primary">Create First Order</a>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($purchases as $purchase): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($purchase['po_number']); ?></strong>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($purchase['supplier_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo $purchase['item_count']; ?> items</td>
                                    <td><strong>UGX <?php echo number_format($purchase['total_amount']); ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $purchase['status']; ?>">
                                            <?php echo strtoupper($purchase['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($purchase['status'] == 'ordered'): ?>
                                            <a href="receive.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            <a href="print.php?id=<?php echo $purchase['id']; ?>" class="btn btn-sm btn-outline-info" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&status=<?php echo $status; ?>&supplier_id=<?php echo $supplier_id; ?>">Previous</a>
                            </li>
                            <?php for($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>&supplier_id=<?php echo $supplier_id; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&status=<?php echo $status; ?>&supplier_id=<?php echo $supplier_id; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#sidebarToggle').click(function() {
            $('#sidebar').toggleClass('show');
        });
    </script>
</body>
</html>