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
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$product = null;
$error = null;
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($product_id <= 0) {
    $error = "Invalid product ID.";
} else {
    try {
        $stmt = $conn->prepare("
            SELECT * FROM inventory 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $error = "Product not found or has been deactivated.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Details - Savant Motors ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%); }

        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: white;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header { padding: 25px 24px; text-align: center; border-bottom: 1px solid rgba(59,130,246,0.2); }
        .logo-icon { width: 60px; height: 60px; background: linear-gradient(135deg, var(--primary-light), var(--primary)); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; }
        .logo-icon i { font-size: 28px; color: white; }
        .logo-text { font-size: 20px; font-weight: 800; color: white; }

        .sidebar-menu { padding: 20px 0; }
        .menu-item { padding: 12px 24px; display: flex; align-items: center; gap: 12px; color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.3s; border-left: 3px solid transparent; font-size: 14px; font-weight: 500; cursor: pointer; }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(59,130,246,0.2); color: white; border-left-color: var(--primary-light); }

        .main-content { margin-left: 280px; padding: 25px 30px; min-height: 100vh; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-title h1 { font-size: 28px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 12px; }
        .page-title h1 i { color: var(--primary-light); }

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
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: white; color: var(--gray); border: 1px solid var(--border); }
        .btn-warning { background: var(--warning); color: white; }
        .btn-danger { background: var(--danger); color: white; }

        .product-detail-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .product-header {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
            padding: 30px;
            position: relative;
        }
        .product-header h2 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .product-code {
            font-size: 14px;
            opacity: 0.9;
            font-family: monospace;
        }
        .stock-status {
            position: absolute;
            top: 30px;
            right: 30px;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
        }
        .stock-status.available { background: rgba(16,185,129,0.2); color: #10b981; }
        .stock-status.low { background: rgba(245,158,11,0.2); color: #f59e0b; }
        .stock-status.out { background: rgba(239,68,68,0.2); color: #ef4444; }
        .product-body {
            padding: 30px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }
        .info-item {
            border-bottom: 1px dashed var(--border);
            padding-bottom: 12px;
        }
        .info-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .info-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        .price-box {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .price-label {
            font-size: 13px;
            color: var(--gray);
            margin-bottom: 8px;
        }
        .price-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--success);
        }
        .description-box {
            background: var(--light);
            border-radius: 20px;
            padding: 20px;
            margin-top: 20px;
        }
        .description-box h3 {
            font-size: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: flex-end;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }
        .alert-warning {
            background: #fed7aa;
            border-left: 4px solid var(--warning);
            color: #9a3412;
        }
        .empty-state {
            text-align: center;
            padding: 80px;
            color: var(--gray);
        }

        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .main-content { margin-left: 0; padding: 20px; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon"><i class="fas fa-cube"></i></div>
            <div class="logo-text">SAVANT MOTORS</div>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard_erp.php" class="menu-item"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="services_products.php" class="menu-item active"><i class="fas fa-cube"></i> Services & Products</a>
            <a href="inventory.php" class="menu-item"><i class="fas fa-boxes"></i> Inventory</a>
            <div style="margin-top: 30px;"><div class="menu-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div></div>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title"><h1><i class="fas fa-cube"></i> Product Details</h1></div>
            <a href="services_products.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Products</a>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php else: ?>
        <div class="product-detail-card">
            <div class="product-header">
                <h2><?php echo htmlspecialchars($product['product_name']); ?></h2>
                <div class="product-code">SKU: <?php echo htmlspecialchars($product['item_code']); ?></div>
                <?php
                $stock_status = '';
                $stock_text = '';
                if ($product['quantity'] <= 0) {
                    $stock_status = 'out';
                    $stock_text = 'OUT OF STOCK';
                } elseif ($product['quantity'] <= $product['reorder_level']) {
                    $stock_status = 'low';
                    $stock_text = 'LOW STOCK';
                } else {
                    $stock_status = 'available';
                    $stock_text = 'IN STOCK';
                }
                ?>
                <div class="stock-status <?php echo $stock_status; ?>">
                    <i class="fas fa-<?php echo $stock_status == 'out' ? 'times-circle' : ($stock_status == 'low' ? 'exclamation-triangle' : 'check-circle'); ?>"></i>
                    <?php echo $stock_text; ?>
                </div>
            </div>
            <div class="product-body">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Category</div>
                        <div class="info-value"><?php echo htmlspecialchars($product['category'] ?? 'General'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Unit of Measure</div>
                        <div class="info-value"><?php echo htmlspecialchars($product['unit_of_measure'] ?? 'piece'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Current Stock</div>
                        <div class="info-value"><?php echo number_format($product['quantity']); ?> <?php echo htmlspecialchars($product['unit_of_measure'] ?? 'pcs'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Reorder Level</div>
                        <div class="info-value"><?php echo number_format($product['reorder_level']); ?> <?php echo htmlspecialchars($product['unit_of_measure'] ?? 'pcs'); ?></div>
                    </div>
                </div>

                <div class="price-box">
                    <div class="price-label">Selling Price (UGX)</div>
                    <div class="price-value">UGX <?php echo number_format($product['selling_price']); ?></div>
                    <?php if ($product['unit_cost'] > 0): ?>
                    <div style="font-size: 12px; margin-top: 8px;">Cost: UGX <?php echo number_format($product['unit_cost']); ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['description'])): ?>
                <div class="description-box">
                    <h3><i class="fas fa-align-left"></i> Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <a href="services_products.php?edit_product=<?php echo $product['id']; ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit Product</a>
                    <form method="POST" action="services_products.php" style="display: inline;" onsubmit="return confirm('Deactivate this product? It will no longer appear in lists.');">
                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                        <button type="submit" name="delete_product" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Deactivate</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function logout() {
            window.location.href = 'logout.php';
        }
        document.getElementById('logoutBtn')?.addEventListener('click', logout);
    </script>
</body>
</html>