<?php
// create_tool.php — Add a new tool to inventory
session_start();
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id        = $_SESSION['user_id']   ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role      = $_SESSION['role']      ?? 'user';

// Auto-generate a unique tool code (can be overridden in the form)
$tool_code = 'TL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

$error_message   = null;
$success_message = null;

// ── Single DB connection used throughout ─────────────────────────────────────
$conn = null;
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure tools table exists with every column we need
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tools (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            tool_code            VARCHAR(50)  UNIQUE NOT NULL,
            tool_name            VARCHAR(255) NOT NULL,
            category             VARCHAR(100),
            brand                VARCHAR(100),
            model                VARCHAR(100),
            serial_number        VARCHAR(100),
            location             VARCHAR(255) DEFAULT 'Store',
            purchase_date        DATE,
            purchase_price       DECIMAL(15,2) DEFAULT 0,
            current_value        DECIMAL(15,2) DEFAULT 0,
            quantity             INT           NOT NULL DEFAULT 1,
            status               ENUM('available','taken','maintenance','damaged','retired') DEFAULT 'available',
            condition_rating     ENUM('new','good','fair','poor')                           DEFAULT 'good',
            last_calibration_date DATE,
            next_calibration_date DATE,
            notes                TEXT,
            is_active            TINYINT DEFAULT 1,
            created_by           INT,
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add columns that might be missing on older installs
    $existingCols = $conn->query("SHOW COLUMNS FROM tools")->fetchAll(PDO::FETCH_COLUMN);
    $addCols = [
        'quantity'      => "INT NOT NULL DEFAULT 1",
        'purchase_price'=> "DECIMAL(15,2) DEFAULT 0",
        'current_value' => "DECIMAL(15,2) DEFAULT 0",
    ];
    foreach ($addCols as $col => $def) {
        if (!in_array($col, $existingCols)) {
            $conn->exec("ALTER TABLE tools ADD COLUMN {$col} {$def}");
        }
    }

} catch (PDOException $e) {
    $error_message = "Database connection failed. Please contact your administrator.";
    $conn = null;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_tool']) && $conn) {
    try {
        // Validate required fields
        $toolName = trim($_POST['tool_name'] ?? '');
        if ($toolName === '') {
            throw new Exception("Tool name is required.");
        }

        $qty = intval($_POST['quantity'] ?? 1);
        if ($qty < 1) {
            throw new Exception("Quantity must be at least 1.");
        }

        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
        if ($purchasePrice < 0) {
            throw new Exception("Purchase price cannot be negative.");
        }

        // Resolve tool code — use user input if provided, else auto-generated
        $finalCode = trim($_POST['tool_code'] ?? '') ?: $tool_code;

        // If that code is already taken, append a random suffix
        $dup = $conn->prepare("SELECT COUNT(*) FROM tools WHERE tool_code = ?");
        $dup->execute([$finalCode]);
        if ($dup->fetchColumn() > 0) {
            $finalCode = 'TL-' . date('Y') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        }

        $currentValue = floatval($_POST['current_value'] ?? 0) ?: $purchasePrice;

        $stmt = $conn->prepare("
            INSERT INTO tools (
                tool_code, tool_name, category, brand, model, serial_number,
                location, purchase_date, purchase_price, current_value,
                quantity, condition_rating, status, notes, is_active, created_by
            ) VALUES (
                :tool_code, :tool_name, :category, :brand, :model, :serial_number,
                :location, :purchase_date, :purchase_price, :current_value,
                :quantity, :condition_rating, 'available', :notes, 1, :created_by
            )
        ");

        $stmt->execute([
            ':tool_code'       => $finalCode,
            ':tool_name'       => $toolName,
            ':category'        => $_POST['category']        ?: null,
            ':brand'           => trim($_POST['brand']       ?? '') ?: null,
            ':model'           => trim($_POST['model']       ?? '') ?: null,
            ':serial_number'   => trim($_POST['serial_number'] ?? '') ?: null,
            ':location'        => $_POST['location']        ?? 'Store',
            ':purchase_date'   => $_POST['purchase_date']   ?: null,
            ':purchase_price'  => $purchasePrice,
            ':current_value'   => $currentValue,
            ':quantity'        => $qty,
            ':condition_rating'=> $_POST['condition_rating'] ?? 'good',
            ':notes'           => trim($_POST['notes'] ?? '') ?: null,
            ':created_by'      => $user_id,
        ]);

        $_SESSION['success'] = "✅ Tool <strong>" . htmlspecialchars($toolName) . "</strong> added successfully! Code: <strong>$finalCode</strong>";
        header('Location: index.php');
        exit();

    } catch (PDOException $e) {
        // Duplicate entry check
        if (strpos($e->getMessage(), '1062') !== false) {
            $error_message = "A tool with that code already exists. Please use a different tool code.";
        } else {
            $error_message = "Database error: " . $e->getMessage();
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ── Load categories for dropdown ──────────────────────────────────────────────
$defaultCategories = ['Diagnostic', 'Electrical', 'Hand Tools', 'Measuring', 'Pneumatic', 'Power Tools', 'Safety', 'Welding'];
$categories = $defaultCategories;
if ($conn) {
    try {
        $rows = $conn->query("
            SELECT DISTINCT category FROM tools
            WHERE category IS NOT NULL AND category != ''
            ORDER BY category
        ")->fetchAll(PDO::FETCH_COLUMN);
        $categories = array_unique(array_merge($defaultCategories, $rows));
        sort($categories);
    } catch (PDOException $e) { /* keep defaults */ }
}

// Keep POST values to re-fill the form after a validation error
$old = $_POST;
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
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f9 100%);
            min-height: 100vh;
        }

        :root {
            --primary:       #1e40af;
            --primary-light: #3b82f6;
            --primary-dark:  #1e3a8a;
            --success:       #10b981;
            --danger:        #ef4444;
            --warning:       #f59e0b;
            --border:        #e2e8f0;
            --gray:          #64748b;
            --gray-light:    #94a3b8;
            --dark:          #0f172a;
            --bg-light:      #f8fafc;
        }

        /* ── Sidebar ── */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: 260px; height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            z-index: 1000; overflow-y: auto;
            box-shadow: 2px 0 8px rgba(0,0,0,0.07);
        }
        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.08);
            text-align: center;
        }
        .sidebar-logo {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.6rem; color: white;
            box-shadow: 0 4px 12px rgba(37,99,235,.3);
        }
        .sidebar-header h2 { font-size: 1.1rem; font-weight: 800; color: #0369a1; letter-spacing: -0.3px; }
        .sidebar-header p  { font-size: 0.68rem; color: #0284c7; margin-top: 0.2rem; opacity: .8; }

        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title {
            padding: 0.5rem 1.5rem;
            font-size: 0.65rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px;
            color: #0369a1; opacity: .7;
        }
        .menu-item {
            padding: 0.65rem 1.5rem;
            display: flex; align-items: center; gap: 0.75rem;
            color: #0c4a6e; text-decoration: none;
            font-size: 0.83rem; font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.18s;
        }
        .menu-item i { width: 18px; font-size: 0.95rem; }
        .menu-item:hover,
        .menu-item.active { background: rgba(14,165,233,.18); color: #0284c7; border-left-color: #0284c7; }
        .menu-item.active  { font-weight: 600; }

        /* ── Main ── */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* ── Top bar ── */
        .top-bar {
            background: white; border-radius: 1rem;
            padding: 1rem 1.5rem; margin-bottom: 1.5rem;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1rem;
            border: 1px solid var(--border);
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .page-title h1 {
            font-size: 1.2rem; font-weight: 800; color: var(--dark);
            display: flex; align-items: center; gap: 0.6rem;
        }
        .page-title h1 i { color: var(--primary-light); }
        .page-title p { font-size: 0.73rem; color: var(--gray); margin-top: 0.2rem; }

        /* ── Progress steps ── */
        .steps {
            display: flex; gap: 0; margin-bottom: 1.5rem;
            background: white; border-radius: 1rem; overflow: hidden;
            border: 1px solid var(--border);
        }
        .step {
            flex: 1; padding: 0.9rem 1rem; text-align: center;
            font-size: 0.72rem; font-weight: 600; color: var(--gray);
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            border-right: 1px solid var(--border);
            position: relative;
        }
        .step:last-child { border-right: none; }
        .step.active { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .step.done   { background: #dcfce7; color: #166534; }
        .step-num {
            width: 22px; height: 22px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 700;
            background: rgba(255,255,255,.3);
        }
        .step.active .step-num { background: rgba(255,255,255,.3); }
        .step.done   .step-num { background: #16a34a; color: white; }

        /* ── Form card ── */
        .form-card {
            background: white; border-radius: 1rem;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
            overflow: hidden;
        }

        .form-section {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .form-section:last-of-type { border-bottom: none; }

        .section-header {
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1.2rem;
        }
        .section-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .section-header h3 { font-size: 0.95rem; font-weight: 700; color: var(--dark); }
        .section-header p  { font-size: 0.72rem; color: var(--gray); margin-top: 1px; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        .form-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
        .form-grid.cols-1 { grid-template-columns: 1fr; }
        .col-span-2 { grid-column: span 2; }

        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-group label {
            font-size: 0.68rem; font-weight: 700;
            color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .required { color: var(--danger); margin-left: 2px; }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.6rem 0.8rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--dark);
            background: white;
            transition: border-color .15s, box-shadow .15s;
            width: 100%;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(59,130,246,.12);
        }
        .form-group input.error,
        .form-group select.error { border-color: var(--danger); }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group small { font-size: 0.68rem; color: var(--gray-light); }

        /* Tool code display */
        .code-box {
            display: flex; align-items: center; gap: 0.6rem;
            background: #f1f5f9; border: 1.5px dashed var(--border);
            border-radius: 0.5rem; padding: 0.55rem 0.8rem;
        }
        .code-badge {
            font-family: monospace; font-size: 0.9rem;
            font-weight: 800; color: var(--primary); letter-spacing: 0.5px;
        }
        .code-tag {
            font-size: 0.65rem; background: #dbeafe; color: var(--primary);
            padding: 2px 8px; border-radius: 20px; font-weight: 600;
        }

        /* Qty stepper */
        .qty-stepper {
            display: flex; align-items: center; gap: 0;
            border: 1.5px solid var(--border); border-radius: 0.5rem;
            overflow: hidden; width: fit-content;
        }
        .qty-btn {
            width: 36px; height: 36px;
            background: var(--bg-light); border: none;
            font-size: 1.1rem; font-weight: 700; color: var(--primary);
            cursor: pointer; transition: background .15s;
            display: flex; align-items: center; justify-content: center;
        }
        .qty-btn:hover { background: #dbeafe; }
        .qty-input {
            width: 60px !important; border: none !important;
            border-left: 1px solid var(--border) !important;
            border-right: 1px solid var(--border) !important;
            border-radius: 0 !important; text-align: center;
            font-weight: 700; font-size: 0.95rem;
            box-shadow: none !important;
        }

        /* Condition pills */
        .condition-pills { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .condition-pill {
            flex: 1; min-width: 70px; padding: 0.5rem;
            border: 2px solid var(--border); border-radius: 0.5rem;
            text-align: center; cursor: pointer;
            font-size: 0.75rem; font-weight: 600;
            transition: all .15s; user-select: none;
        }
        .condition-pill input { display: none; }
        .condition-pill:has(input:checked) { border-color: var(--primary-light); background: #eff6ff; color: var(--primary); }
        .condition-pill.new-pill:has(input:checked)  { border-color: #10b981; background: #f0fdf4; color: #059669; }
        .condition-pill.fair-pill:has(input:checked) { border-color: #f59e0b; background: #fffbeb; color: #d97706; }
        .condition-pill.poor-pill:has(input:checked) { border-color: #ef4444; background: #fef2f2; color: #dc2626; }
        .condition-pill .pill-icon { font-size: 1.2rem; display: block; margin-bottom: 2px; }

        /* Location cards */
        .location-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
        .loc-card {
            padding: 0.6rem 0.4rem; border: 2px solid var(--border);
            border-radius: 0.5rem; text-align: center; cursor: pointer;
            font-size: 0.72rem; font-weight: 600; color: var(--gray);
            transition: all .15s; user-select: none;
        }
        .loc-card input { display: none; }
        .loc-card:has(input:checked) { border-color: var(--primary-light); background: #eff6ff; color: var(--primary); }
        .loc-card .loc-icon { font-size: 1.3rem; display: block; margin-bottom: 3px; }

        /* Alerts */
        .alert {
            padding: 0.85rem 1rem; border-radius: 0.6rem;
            margin: 1rem 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem;
            font-size: 0.85rem;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid var(--success); }

        /* Form actions */
        .form-actions {
            padding: 1.2rem 1.5rem;
            background: var(--bg-light);
            border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 1rem;
        }
        .actions-left  { display: flex; gap: 0.75rem; align-items: center; }
        .actions-right { display: flex; gap: 0.75rem; }

        .btn {
            padding: 0.6rem 1.3rem; border-radius: 0.5rem;
            font-weight: 600; font-size: 0.85rem;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; transition: all .18s;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            color: white;
        }
        .btn-primary:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.3); }
        .btn-primary:disabled { opacity: .6; transform: none; cursor: not-allowed; }
        .btn-secondary { background: white; color: var(--gray); border: 1.5px solid var(--border); }
        .btn-secondary:hover { background: var(--bg-light); border-color: var(--gray-light); }
        .btn-success { background: linear-gradient(135deg, #34d399, var(--success)); color: white; }
        .btn-success:hover { filter: brightness(1.08); transform: translateY(-1px); }

        /* Price preview */
        .price-preview {
            background: #f0fdf4; border: 1.5px solid #bbf7d0;
            border-radius: 0.5rem; padding: 0.6rem 1rem;
            font-size: 0.8rem; color: #166534; font-weight: 600;
            display: flex; align-items: center; gap: 0.5rem;
            margin-top: 0.5rem;
        }

        /* Category custom input */
        .category-wrap { position: relative; }
        .category-wrap select { padding-right: 2rem; }
        #customCategoryRow { display: none; margin-top: 0.5rem; }

        @media (max-width: 900px) {
            .form-grid        { grid-template-columns: 1fr; }
            .form-grid.cols-3 { grid-template-columns: 1fr 1fr; }
            .col-span-2       { grid-column: span 1; }
            .location-cards   { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { left: -260px; transition: left .3s; }
            .sidebar.show { left: 0; }
            .main-content { margin-left: 0; }
            .steps { display: none; }
            .form-grid.cols-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ════════════════════════════════ SIDEBAR ════════════════════════════════ -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo"><i class="fas fa-charging-station"></i></div>
        <h2>SAVANT MOTORS</h2>
        <p>Tool Management System</p>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-title">Main</div>
        <a href="../dashboard_erp.php" class="menu-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a href="../job_cards.php"     class="menu-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
        <a href="../technicians.php"   class="menu-item"><i class="fas fa-users-cog"></i> Technicians</a>
        <a href="index.php"            class="menu-item active"><i class="fas fa-tools"></i> Tool Management</a>
        <a href="taken.php"            class="menu-item"><i class="fas fa-hand-holding"></i> Tools Taken</a>
        <a href="../tool_requests/index.php" class="menu-item"><i class="fas fa-clipboard-check"></i> Tool Requests</a>
        <div class="sidebar-title" style="margin-top:1rem;">Account</div>
        <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- ════════════════════════════════ MAIN ════════════════════════════════ -->
<div class="main-content">

    <!-- Top bar -->
    <div class="top-bar">
        <div class="page-title">
            <h1><i class="fas fa-plus-circle"></i> Add New Tool</h1>
            <p>Register a new tool or piece of equipment in the workshop inventory</p>
        </div>
        <div style="display:flex;gap:.6rem;">
            <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Tools</a>
        </div>
    </div>

    <!-- Step indicator -->
    <div class="steps">
        <div class="step active">
            <div class="step-num">1</div> Basic Info
        </div>
        <div class="step active">
            <div class="step-num">2</div> Stock & Location
        </div>
        <div class="step active">
            <div class="step-num">3</div> Financial Details
        </div>
        <div class="step active">
            <div class="step-num">4</div> Save
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($error_message): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <div><?php echo $error_message; ?></div>
    </div>
    <?php endif; ?>
    <?php if ($success_message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <div><?php echo $success_message; ?></div>
    </div>
    <?php endif; ?>

    <form method="POST" id="createToolForm" novalidate>

        <!-- ══ SECTION 1: Basic Information ══════════════════════════════ -->
        <div class="form-card" style="margin-bottom:1.2rem;">
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="fas fa-tag"></i></div>
                    <div>
                        <h3>Basic Information</h3>
                        <p>Core details that identify this tool</p>
                    </div>
                </div>

                <div class="form-grid">
                    <!-- Tool Code (auto) -->
                    <div class="form-group">
                        <label>Tool Code <span style="font-weight:400;text-transform:none;color:var(--gray-light);">(auto-generated)</span></label>
                        <div class="code-box">
                            <span class="code-badge" id="codeDisplay"><?php echo htmlspecialchars($tool_code); ?></span>
                            <span class="code-tag">AUTO</span>
                        </div>
                        <input type="hidden" name="tool_code" id="toolCodeInput" value="<?php echo htmlspecialchars($tool_code); ?>">
                        <small>You can override this below if needed</small>
                        <div style="margin-top:.4rem;display:flex;align-items:center;gap:.5rem;">
                            <input type="checkbox" id="overrideCode" style="width:auto;margin:0;">
                            <label for="overrideCode" style="text-transform:none;letter-spacing:0;font-size:.75rem;font-weight:500;color:var(--gray);">Use custom code</label>
                        </div>
                        <input type="text" id="customCodeInput" name="custom_tool_code"
                               placeholder="e.g., TL-DRILL-001"
                               style="margin-top:.4rem;display:none;"
                               value="<?php echo htmlspecialchars($old['custom_tool_code'] ?? ''); ?>">
                    </div>

                    <!-- Tool Name -->
                    <div class="form-group">
                        <label>Tool Name <span class="required">*</span></label>
                        <input type="text" name="tool_name" id="toolName" required
                               placeholder="e.g., Cordless Drill, Torque Wrench"
                               value="<?php echo htmlspecialchars($old['tool_name'] ?? ''); ?>">
                        <small>Be specific — include key attributes in the name</small>
                    </div>

                    <!-- Category -->
                    <div class="form-group category-wrap">
                        <label>Category</label>
                        <select name="category" id="categorySelect">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"
                                <?php echo (($old['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="__new__">➕ Add new category…</option>
                        </select>
                        <div id="customCategoryRow">
                            <input type="text" name="custom_category" id="customCategory"
                                   placeholder="Type new category name"
                                   value="<?php echo htmlspecialchars($old['custom_category'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Brand -->
                    <div class="form-group">
                        <label>Brand</label>
                        <input type="text" name="brand"
                               placeholder="e.g., Bosch, Makita, Stanley, Snap-on"
                               value="<?php echo htmlspecialchars($old['brand'] ?? ''); ?>">
                    </div>

                    <!-- Model -->
                    <div class="form-group">
                        <label>Model / Part Number</label>
                        <input type="text" name="model"
                               placeholder="e.g., GSB 18V-55, 3/8 Drive"
                               value="<?php echo htmlspecialchars($old['model'] ?? ''); ?>">
                    </div>

                    <!-- Serial -->
                    <div class="form-group">
                        <label>Serial Number</label>
                        <input type="text" name="serial_number"
                               placeholder="Manufacturer serial (optional)"
                               value="<?php echo htmlspecialchars($old['serial_number'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ SECTION 2: Stock, Location & Condition ══════════════════ -->
        <div class="form-card" style="margin-bottom:1.2rem;">
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-boxes"></i></div>
                    <div>
                        <h3>Stock, Location &amp; Condition</h3>
                        <p>How many units and where they're kept</p>
                    </div>
                </div>

                <div class="form-grid">
                    <!-- Quantity stepper -->
                    <div class="form-group">
                        <label>Total Quantity <span class="required">*</span></label>
                        <div class="qty-stepper">
                            <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
                            <input type="number" name="quantity" id="qtyInput"
                                   class="qty-input" min="1" max="9999"
                                   value="<?php echo intval($old['quantity'] ?? 1); ?>">
                            <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
                        </div>
                        <small>Number of identical units being registered</small>
                    </div>

                    <!-- Location -->
                    <div class="form-group">
                        <label>Storage Location</label>
                        <div class="location-cards">
                            <?php
                            $locations = [
                                'Store'     => ['🏪', 'Main Store'],
                                'Workshop'  => ['🔧', 'Workshop'],
                                'Tool Room' => ['🚪', 'Tool Room'],
                                'Mobile'    => ['🚐', 'Mobile Unit'],
                            ];
                            $selectedLoc = $old['location'] ?? 'Store';
                            foreach ($locations as $val => [$icon, $label]):
                            ?>
                            <label class="loc-card">
                                <input type="radio" name="location" value="<?php echo $val; ?>"
                                       <?php echo ($selectedLoc === $val) ? 'checked' : ''; ?>>
                                <span class="loc-icon"><?php echo $icon; ?></span>
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Condition -->
                    <div class="form-group col-span-2">
                        <label>Condition <span class="required">*</span></label>
                        <div class="condition-pills">
                            <?php
                            $conditions = [
                                'new'  => ['new-pill',  '✨', 'New',     'Brand new, never used'],
                                'good' => ['good-pill', '👍', 'Good',    'Used but fully functional'],
                                'fair' => ['fair-pill', '⚠️',  'Fair',    'Working with minor wear'],
                                'poor' => ['poor-pill', '🔴', 'Poor',    'Needs maintenance soon'],
                            ];
                            $selCond = $old['condition_rating'] ?? 'good';
                            foreach ($conditions as $val => [$cls, $icon, $lbl, $tip]):
                            ?>
                            <label class="condition-pill <?php echo $cls; ?>" title="<?php echo $tip; ?>">
                                <input type="radio" name="condition_rating" value="<?php echo $val; ?>"
                                       <?php echo ($selCond === $val) ? 'checked' : ''; ?>>
                                <span class="pill-icon"><?php echo $icon; ?></span>
                                <?php echo $lbl; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ SECTION 3: Financial Details ════════════════════════════ -->
        <div class="form-card" style="margin-bottom:1.2rem;">
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-coins"></i></div>
                    <div>
                        <h3>Financial Details</h3>
                        <p>Purchase cost and current value for inventory tracking</p>
                    </div>
                </div>

                <div class="form-grid cols-3">
                    <!-- Purchase Date -->
                    <div class="form-group">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" id="purchaseDate"
                               value="<?php echo htmlspecialchars($old['purchase_date'] ?? ''); ?>">
                    </div>

                    <!-- Purchase Price -->
                    <div class="form-group">
                        <label>Purchase Price (UGX)</label>
                        <input type="number" name="purchase_price" id="purchasePrice"
                               min="0" step="1000" placeholder="0"
                               value="<?php echo htmlspecialchars($old['purchase_price'] ?? ''); ?>"
                               oninput="updateValuePreview()">
                        <small>Cost per unit</small>
                    </div>

                    <!-- Current Value -->
                    <div class="form-group">
                        <label>Current Value (UGX) <span style="font-weight:400;text-transform:none;color:var(--gray-light);">(optional)</span></label>
                        <input type="number" name="current_value" id="currentValue"
                               min="0" step="1000" placeholder="Leave blank to use purchase price"
                               value="<?php echo htmlspecialchars($old['current_value'] ?? ''); ?>"
                               oninput="updateValuePreview()">
                        <small>Depreciated or market value today</small>
                    </div>
                </div>

                <!-- Inventory value preview -->
                <div class="price-preview" id="pricePreview" style="display:none;">
                    <i class="fas fa-chart-pie"></i>
                    <span id="pricePreviewText"></span>
                </div>
            </div>

            <!-- Notes -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-sticky-note"></i></div>
                    <div>
                        <h3>Notes</h3>
                        <p>Maintenance history, special instructions, accessories included, etc.</p>
                    </div>
                </div>
                <div class="form-group">
                    <textarea name="notes" rows="3"
                              placeholder="e.g., Comes with 3 drill bits. Last serviced Jan 2025. Requires 18V battery (not included)."><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- ══ Form Actions ══════════════════════════════════════════════ -->
        <div class="form-actions">
            <div class="actions-left">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <span style="font-size:.75rem;color:var(--gray);">
                    <i class="fas fa-info-circle"></i> Tool will be set to <strong>Available</strong> status automatically
                </span>
            </div>
            <div class="actions-right">
                <button type="reset" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset
                </button>
                <button type="submit" name="create_tool" id="submitBtn" class="btn btn-primary">
                    <i class="fas fa-save"></i> Add Tool to Inventory
                </button>
            </div>
        </div>

    </form>
</div>

<script>
    // ── Quantity stepper ─────────────────────────────────────────────────────
    function changeQty(delta) {
        const input = document.getElementById('qtyInput');
        const val   = parseInt(input.value) || 1;
        input.value = Math.max(1, val + delta);
        updateValuePreview();
    }

    // ── Tool code override ───────────────────────────────────────────────────
    document.getElementById('overrideCode').addEventListener('change', function () {
        const custom = document.getElementById('customCodeInput');
        const hidden = document.getElementById('toolCodeInput');
        if (this.checked) {
            custom.style.display = 'block';
            custom.focus();
            custom.addEventListener('input', function () {
                hidden.value = this.value.trim() || '<?php echo $tool_code; ?>';
                document.getElementById('codeDisplay').textContent = hidden.value;
            });
        } else {
            custom.style.display = 'none';
            hidden.value = '<?php echo $tool_code; ?>';
            document.getElementById('codeDisplay').textContent = hidden.value;
        }
    });

    // ── Category "Add new" ───────────────────────────────────────────────────
    document.getElementById('categorySelect').addEventListener('change', function () {
        const row = document.getElementById('customCategoryRow');
        if (this.value === '__new__') {
            row.style.display = 'block';
            document.getElementById('customCategory').focus();
            // Swap the select value so we don't submit "__new__"
            this.dataset.prev = this.value;
        } else {
            row.style.display = 'none';
        }
    });

    // ── Price preview ────────────────────────────────────────────────────────
    function updateValuePreview() {
        const price = parseFloat(document.getElementById('purchasePrice').value) || 0;
        const value = parseFloat(document.getElementById('currentValue').value)  || price;
        const qty   = parseInt(document.getElementById('qtyInput').value)        || 1;
        const box   = document.getElementById('pricePreview');
        const txt   = document.getElementById('pricePreviewText');

        if (price > 0 || value > 0) {
            const totalCost = price * qty;
            const totalVal  = value * qty;
            txt.innerHTML =
                `<strong>Total purchase cost:</strong> UGX ${totalCost.toLocaleString()} &nbsp;|&nbsp; ` +
                `<strong>Total current value:</strong> UGX ${totalVal.toLocaleString()} &nbsp;` +
                `<span style="opacity:.7;">(${qty} unit${qty !== 1 ? 's' : ''})</span>`;
            box.style.display = 'flex';
        } else {
            box.style.display = 'none';
        }
    }
    document.getElementById('qtyInput').addEventListener('input', updateValuePreview);

    // ── Form submit guard ────────────────────────────────────────────────────
    const form      = document.getElementById('createToolForm');
    const submitBtn = document.getElementById('submitBtn');

    // Always re-enable on load (guards against browser back-button)
    submitBtn.disabled = false;

    form.addEventListener('submit', function (e) {
        let valid = true;

        // Tool name
        const nameInput = document.getElementById('toolName');
        if (!nameInput.value.trim()) {
            nameInput.classList.add('error');
            nameInput.focus();
            valid = false;
        } else {
            nameInput.classList.remove('error');
        }

        // Quantity
        const qtyInput = document.getElementById('qtyInput');
        if (parseInt(qtyInput.value) < 1 || isNaN(parseInt(qtyInput.value))) {
            qtyInput.classList.add('error');
            valid = false;
        } else {
            qtyInput.classList.remove('error');
        }

        // If custom category is visible, copy its value into select
        const catSelect = document.getElementById('categorySelect');
        if (catSelect.value === '__new__') {
            const customCat = document.getElementById('customCategory').value.trim();
            if (customCat) {
                // Add a temporary option so the value is submitted
                const opt = document.createElement('option');
                opt.value = customCat;
                opt.selected = true;
                catSelect.appendChild(opt);
                catSelect.name = 'category';
            } else {
                catSelect.value = '';
            }
        }

        if (!valid) {
            e.preventDefault();
            const errDiv = document.createElement('div');
            errDiv.className = 'alert alert-error';
            errDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i><div>Please fill in all required fields.</div>';
            const existing = document.querySelector('.alert');
            if (existing) existing.replaceWith(errDiv);
            else form.prepend(errDiv);
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    });

    // ── Reset ────────────────────────────────────────────────────────────────
    function resetForm() {
        document.getElementById('codeDisplay').textContent = '<?php echo $tool_code; ?>';
        document.getElementById('toolCodeInput').value     = '<?php echo $tool_code; ?>';
        document.getElementById('customCodeInput').style.display = 'none';
        document.getElementById('overrideCode').checked    = false;
        document.getElementById('customCategoryRow').style.display = 'none';
        document.getElementById('pricePreview').style.display     = 'none';
        document.getElementById('qtyInput').value = 1;
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Add Tool to Inventory';
    }

    // ── Init ─────────────────────────────────────────────────────────────────
    updateValuePreview();
</script>
</body>
</html>
