<?php
// add_tool.php - Fixed with auto-column addition
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$tool_code = 'TL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
$error_message = null;
$success_message = null;

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $error_message = "Database connection failed: " . $e->getMessage();
    $conn = null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tool'])) {
    if (!$conn) {
        $error_message = "Database connection is not available. Cannot add tool.";
    } else {
        try {
            // 1. Create table if not exists
            $conn->exec("
                CREATE TABLE IF NOT EXISTS tools (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tool_code VARCHAR(50) UNIQUE NOT NULL,
                    tool_name VARCHAR(255) NOT NULL,
                    category VARCHAR(100),
                    brand VARCHAR(100),
                    model VARCHAR(100),
                    serial_number VARCHAR(100),
                    location VARCHAR(255),
                    purchase_date DATE,
                    purchase_price DECIMAL(15,2),
                    current_value DECIMAL(15,2),
                    quantity INT NOT NULL DEFAULT 1,
                    status ENUM('available','taken','maintenance','damaged','retired') DEFAULT 'available',
                    condition_rating ENUM('new','good','fair','poor') DEFAULT 'good',
                    notes TEXT,
                    is_active TINYINT DEFAULT 1,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");

            // 2. Add any missing columns
            $existingCols = $conn->query("SHOW COLUMNS FROM tools")->fetchAll(PDO::FETCH_COLUMN);
            $missingCols = [
                'serial_number' => "VARCHAR(100)",
                'location' => "VARCHAR(255)",
                'purchase_date' => "DATE",
                'purchase_price' => "DECIMAL(15,2)",
                'current_value' => "DECIMAL(15,2)",
                'quantity' => "INT NOT NULL DEFAULT 1",
                'status' => "ENUM('available','taken','maintenance','damaged','retired') DEFAULT 'available'",
                'condition_rating' => "ENUM('new','good','fair','poor') DEFAULT 'good'",
                'notes' => "TEXT",
                'is_active' => "TINYINT DEFAULT 1",
                'created_by' => "INT",
                'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
            ];
            foreach ($missingCols as $col => $def) {
                if (!in_array($col, $existingCols)) {
                    $conn->exec("ALTER TABLE tools ADD COLUMN $col $def");
                }
            }

            // Validate inputs
            $toolName = trim($_POST['tool_name'] ?? '');
            if (empty($toolName)) throw new Exception("Tool name is required.");
            $qty = intval($_POST['quantity'] ?? 1);
            if ($qty < 1) throw new Exception("Quantity must be at least 1.");

            $finalToolCode = trim($_POST['tool_code'] ?? '') ?: $tool_code;
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tools WHERE tool_code = ?");
            $checkStmt->execute([$finalToolCode]);
            if ($checkStmt->fetchColumn() > 0) {
                $finalToolCode = $tool_code . '-' . rand(100, 999);
            }

            $purchasePrice = !empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
            $currentValue  = !empty($_POST['current_value'])  ? floatval($_POST['current_value'])  : $purchasePrice;

            $stmt = $conn->prepare("
                INSERT INTO tools (
                    tool_code, tool_name, category, brand, model, serial_number,
                    location, purchase_date, purchase_price, current_value,
                    condition_rating, notes, quantity, status, is_active, created_by
                ) VALUES (
                    :tool_code, :tool_name, :category, :brand, :model, :serial_number,
                    :location, :purchase_date, :purchase_price, :current_value,
                    :condition_rating, :notes, :quantity, 'available', 1, :created_by
                )
            ");
            $stmt->execute([
                ':tool_code'       => $finalToolCode,
                ':tool_name'       => $toolName,
                ':category'        => $_POST['category']        ?? null,
                ':brand'           => $_POST['brand']           ?? null,
                ':model'           => $_POST['model']           ?? null,
                ':serial_number'   => $_POST['serial_number']   ?? null,
                ':location'        => $_POST['location']        ?? 'Store',
                ':purchase_date'   => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null,
                ':purchase_price'  => $purchasePrice,
                ':current_value'   => $currentValue,
                ':condition_rating'=> $_POST['condition_rating'] ?? 'good',
                ':notes'           => $_POST['notes']            ?? null,
                ':quantity'        => $qty,
                ':created_by'      => $user_id,
            ]);

            $_SESSION['success'] = "Tool \"$toolName\" added successfully! Code: $finalToolCode";
            header('Location: ../tools/index.php');
            exit();

        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}

// Load categories (same as before – unchanged)
$categories = ['Diagnostic', 'Electrical', 'Hand Tools', 'Measuring', 'Pneumatic', 'Power Tools', 'Safety', 'Welding'];
if ($conn) {
    try {
        $tableExists = $conn->query("SHOW TABLES LIKE 'tools'")->rowCount() > 0;
        if ($tableExists) {
            $rows = $conn->query("SELECT DISTINCT category FROM tools WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
            $categories = array_unique(array_merge($categories, $rows));
            sort($categories);
        }
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Tool | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Your existing CSS – unchanged, keep it as is */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%); min-height: 100vh; }
        :root { --primary: #1e40af; --primary-light: #3b82f6; --success: #10b981; --danger: #ef4444; --border: #e2e8f0; --gray: #64748b; --dark: #0f172a; }
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100%; background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%); color: #0c4a6e; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item { padding: 0.7rem 1.5rem; display: flex; align-items: center; gap: 0.75rem; color: #0c4a6e; text-decoration: none; transition: all 0.2s; border-left: 3px solid transparent; font-size: 0.85rem; font-weight: 500; }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }
        .top-bar { background: white; border-radius: 1rem; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border); }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }
        .form-card { background: white; border-radius: 1rem; border: 1px solid var(--border); overflow: hidden; }
        .form-header { background: linear-gradient(135deg, var(--primary-light), var(--primary)); padding: 1rem 1.5rem; color: white; }
        .form-header h2 { font-size: 1.1rem; font-weight: 600; }
        .form-body { padding: 1.5rem; }
        .form-section { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border); }
        .form-section:last-child { border-bottom: none; }
        .section-title { font-size: 0.9rem; font-weight: 700; color: var(--dark); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.7rem; font-weight: 700; color: var(--gray); margin-bottom: 0.25rem; text-transform: uppercase; }
        .required { color: var(--danger); margin-left: 0.25rem; }
        input, select, textarea { width: 100%; padding: 0.6rem 0.75rem; border: 1.5px solid var(--border); border-radius: 0.5rem; font-size: 0.85rem; font-family: inherit; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary-light); }
        .tool-code-display { background: #f1f5f9; padding: 0.6rem 0.75rem; border-radius: 0.5rem; font-family: monospace; font-weight: 600; color: var(--primary); }
        .alert { padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }
        .form-actions { display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
        .btn { padding: 0.6rem 1.2rem; border-radius: 0.5rem; font-weight: 600; font-size: 0.85rem; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        @media (max-width: 768px) { .sidebar { left: -260px; } .main-content { margin-left: 0; padding: 1rem; } .form-row { grid-template-columns: 1fr; } .form-actions { flex-direction: column; } .btn { justify-content: center; } }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header"><h2>🔧 SAVANT MOTORS</h2><p>Tool Management System</p></div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="../tools/index.php" class="menu-item active">🔧 Tool Management</a>
            <a href="../tool_requests/index.php" class="menu-item">📝 Tool Requests</a>
            <a href="../customers/index.php" class="menu-item">👥 Customers</a>
            <div style="margin-top: 2rem;"><a href="../logout.php" class="menu-item">🚪 Logout</a></div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title"><h1>➕ Add New Tool</h1><p>Register a new tool in the inventory system</p></div>
            <a href="../tools/index.php" class="btn btn-secondary">← Back to Tools</a>
        </div>

        <div class="form-card">
            <div class="form-header"><h2>🔧 Tool Information</h2></div>
            <div class="form-body">
                <?php if ($error_message): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-section">
                        <div class="section-title">📋 Basic Information</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tool Code</label>
                                <div class="tool-code-display">🔖 <?php echo $tool_code; ?></div>
                                <input type="hidden" name="tool_code" value="<?php echo $tool_code; ?>">
                                <small>Auto-generated</small>
                            </div>
                            <div class="form-group">
                                <label>Tool Name <span class="required">*</span></label>
                                <input type="text" name="tool_name" required placeholder="e.g., Cordless Drill">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Total Quantity <span class="required">*</span></label>
                                <input type="number" name="quantity" min="1" value="1" required>
                                <small>Number of units</small>
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Brand</label><input type="text" name="brand" placeholder="e.g., Bosch"></div>
                            <div class="form-group"><label>Model</label><input type="text" name="model" placeholder="e.g., GSB 18V-55"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Serial Number</label><input type="text" name="serial_number" placeholder="Unique serial number"></div>
                            <div class="form-group"><label>Storage Location</label>
                                <select name="location">
                                    <option value="Store">Main Store</option>
                                    <option value="Workshop">Workshop</option>
                                    <option value="Tool Room">Tool Room</option>
                                    <option value="Mobile">Mobile Unit</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="section-title">💰 Financial & Condition</div>
                        <div class="form-row">
                            <div class="form-group"><label>Purchase Date</label><input type="date" name="purchase_date"></div>
                            <div class="form-group"><label>Purchase Price (UGX)</label><input type="number" name="purchase_price" step="1000" placeholder="0"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Condition</label>
                                <select name="condition_rating">
                                    <option value="new">New</option>
                                    <option value="good">Good</option>
                                    <option value="fair">Fair</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Current Value (UGX)</label><input type="number" name="current_value" step="1000" placeholder="Leave same as purchase price"></div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="section-title">📝 Additional Notes</div>
                        <textarea name="notes" rows="3" placeholder="Any additional notes..."></textarea>
                    </div>
                    <div class="form-actions">
                        <a href="../tools/index.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="add_tool" class="btn btn-primary">💾 Add Tool</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>