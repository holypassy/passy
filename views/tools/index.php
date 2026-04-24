<?php
// tools.php - Complete Working Version
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

    // ── Ensure quantity & purchase_price columns exist ─────────────────
    $toolCols = $conn->query("SHOW COLUMNS FROM tools")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('quantity', $toolCols)) {
        $conn->exec("ALTER TABLE tools ADD COLUMN quantity INT NOT NULL DEFAULT 1");
    }
    if (!in_array('purchase_price', $toolCols)) {
        $conn->exec("ALTER TABLE tools ADD COLUMN purchase_price DECIMAL(15,2) DEFAULT 0");
    }

    // Simple query to get all tools
    $stmt = $conn->query("
        SELECT * FROM tools 
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY id DESC
    ");
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics — quantity-aware
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(COALESCE(quantity, 1)) as total_quantity,
            SUM(CASE WHEN status = 'available' THEN COALESCE(quantity, 1) ELSE 0 END) as available,
            SUM(CASE WHEN status = 'taken'     THEN 1 ELSE 0 END) as taken,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
            SUM(CASE WHEN (quantity IS NULL OR quantity = 0) AND status = 'available' THEN 1 ELSE 0 END) as out_of_stock,
            COALESCE(SUM(COALESCE(quantity,1) * COALESCE(purchase_price,0)), 0) as total_value
        FROM tools
        WHERE is_active = 1 OR is_active IS NULL
    ")->fetch(PDO::FETCH_ASSOC);
    
    // Get categories
    $categories = $conn->query("SELECT DISTINCT category FROM tools WHERE category IS NOT NULL AND category != ''")->fetchAll(PDO::FETCH_COLUMN);
    
} catch(PDOException $e) {
    $tools = [];
    $stats = ['total' => 0, 'available' => 0, 'taken' => 0, 'maintenance' => 0];
    $categories = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tool Management | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%);
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        /* Sidebar */
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
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
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
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.2rem;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .stat-info { flex: 1; }
        .stat-value { font-size: 1.6rem; font-weight: 800; color: var(--dark); line-height: 1; }
        .stat-label { font-size: 0.68rem; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .stat-sub { font-size: 0.7rem; color: var(--gray); margin-top: 2px; }
        .stat-card.danger .stat-value { color: var(--danger); }
        .stat-card.success .stat-value { color: var(--success); }
        .stat-card.warning .stat-value { color: var(--warning); }

        /* Zero stock warning row */
        tr.zero-stock { background: #fef2f2 !important; }
        tr.zero-stock:hover { background: #fee2e2 !important; }
        .qty-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .qty-ok    { background: #dcfce7; color: #166534; }
        .qty-low   { background: #fef3c7; color: #92400e; }
        .qty-zero  { background: #fee2e2; color: #991b1b; }

        /* Table */
        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 1rem; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        tr:hover { background: #f8fafc; }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-available { background: #dcfce7; color: #166534; }
        .status-taken { background: #dbeafe; color: #1e40af; }
        .status-maintenance { background: #fed7aa; color: #9a3412; }

        /* Buttons */
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .action-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.4rem;
            font-size: 0.7rem;
            cursor: pointer;
            border: none;
            margin: 0 2px;
        }
        .btn-view { background: #dbeafe; color: #2563eb; }
        .btn-edit { background: #dcfce7; color: #16a34a; }

        .empty-state { text-align: center; padding: 3rem; color: var(--gray); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Management System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="/savant/views/dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="/savant/views/job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="/savant/views/technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="/savant/views/tools.php" class="menu-item active">🔧 Tool Management</a>
            <a href="purchase_assets.php" class="menu-item">🛒 Purchase Assets</a>
            <a href="/savant/views/tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
            <a href="/savant/views/tools/tools_with_ai_agent.php" class="menu-item">👥 Tools AI agent</a>
            <div style="margin-top: 2rem;">
                <a href="taken.php" class="btn btn-primary" style="background: #f59e0b;"><i class="fas fa-hand-holding"></i> Tools Taken (<?php echo $stats['taken']; ?>)</a>
                <a href="/views/logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>🔧 Tool Management</h1>
                <p>Manage workshop tools and equipment</p>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <a href="purchase_assets.php" class="btn btn-primary" style="background:linear-gradient(135deg,#059669,#047857);">
                    🛒 Purchase Assets
                </a>
                <a href="add_tool.php" class="btn btn-primary">➕ Add New Tool</a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="fas fa-tools"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <div class="stat-label">Tool Types</div>
                    <div class="stat-sub"><?php echo number_format($stats['total_quantity'] ?? 0); ?> total units</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['available'] ?? 0); ?></div>
                    <div class="stat-label">Units Available</div>
                    <div class="stat-sub">Ready to assign</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hand-holding"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['taken'] ?? 0); ?></div>
                    <div class="stat-label">Currently Taken</div>
                    <div class="stat-sub"><a href="taken.php" style="color:inherit;">View taken tools →</a></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fed7aa;color:#c2410c;"><i class="fas fa-wrench"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['maintenance'] ?? 0); ?></div>
                    <div class="stat-label">Maintenance</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon" style="background:#fee2e2;color:#dc2626;"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?php echo number_format($stats['out_of_stock'] ?? 0); ?></div>
                    <div class="stat-label">Out of Stock</div>
                    <div class="stat-sub">Quantity = 0</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-coins"></i></div>
                <div class="stat-info">
                    <div class="stat-value" style="font-size:1.15rem;">UGX <?php echo number_format($stats['total_value'] ?? 0); ?></div>
                    <div class="stat-label">Total Inventory Value</div>
                </div>
            </div>
        </div>

        <!-- Tools Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Tool Code</th>
                        <th>Tool Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Location</th>
                        <th>Qty Available</th>
                        <th>Unit Value</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                <tbody>
                    <?php if (empty($tools)): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fas fa-tools"></i>
                            <h3>No Tools Found</h3>
                            <p>Click "Add New Tool" to get started</p>
                            <a href="add_tool.php" class="btn btn-primary" style="margin-top: 1rem;">➕ Add First Tool</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($tools as $tool):
                        $qty = (int)($tool['quantity'] ?? 1);
                        $isZero = ($qty <= 0);
                        $qtyClass = $isZero ? 'qty-zero' : ($qty <= 2 ? 'qty-low' : 'qty-ok');
                    ?>
                    <tr class="<?php echo $isZero ? 'zero-stock' : ''; ?>">
                        <td><strong><?php echo htmlspecialchars($tool['tool_code']); ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($tool['tool_name']); ?>
                            <?php if ($isZero): ?>
                            <br><small style="color:#dc2626;font-weight:600;"><i class="fas fa-exclamation-circle"></i> Out of stock – cannot be taken</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($tool['category'] ?? 'General'); ?></td>
                        <td><?php echo htmlspecialchars($tool['brand'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($tool['location'] ?? 'Store'); ?></td>
                        <td>
                            <span class="qty-badge <?php echo $qtyClass; ?>">
                                <i class="fas fa-<?php echo $isZero ? 'times-circle' : ($qty <= 2 ? 'exclamation-circle' : 'check-circle'); ?>"></i>
                                <?php echo $qty; ?> unit<?php echo $qty != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($tool['purchase_price']) && $tool['purchase_price'] > 0): ?>
                            <small>UGX <?php echo number_format($tool['purchase_price']); ?></small>
                            <?php else: ?>
                            <small style="color:#94a3b8;">—</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $tool['status'] ?? 'available'; ?>">
                                <?php echo strtoupper($tool['status'] ?? 'AVAILABLE'); ?>
                            </span>
                        </td>
                        <td>
                            <a href="view_tool.php?id=<?php echo $tool['id']; ?>" class="action-btn btn-view">👁️ View</a>
                            <a href="edit_tool.php?id=<?php echo $tool['id']; ?>" class="action-btn btn-edit">✏️ Edit</a>
                            <a href="purchase_assets.php?tool_id=<?php echo $tool['id']; ?>" class="action-btn" style="background:#dcfce7;color:#166534;">🛒 Buy More</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>