<?php
// edit_tool.php - Edit Tool Information
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 1;
$tool_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($tool_id <= 0) {
    $_SESSION['error'] = "Invalid tool ID";
    header('Location: tools.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get tool details
    $stmt = $conn->prepare("SELECT * FROM tools WHERE id = ?");
    $stmt->execute([$tool_id]);
    $tool = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tool) {
        $_SESSION['error'] = "Tool not found";
        header('Location: tools.php');
        exit();
    }
    
    // Get categories for dropdown
    $stmt = $conn->query("SELECT DISTINCT category FROM tools WHERE category IS NOT NULL AND category != ''");
    $existingCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $defaultCategories = ['Power Tools', 'Hand Tools', 'Diagnostic', 'Measuring', 'Safety', 'Welding', 'Pneumatic', 'Electrical'];
    $categories = array_unique(array_merge($defaultCategories, $existingCategories));
    sort($categories);
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tool'])) {
        
        $toolName = trim($_POST['tool_name']);
        
        if (empty($toolName)) {
            $error_message = "Tool name is required";
        } else {
            try {
                $stmt = $conn->prepare("
                    UPDATE tools SET 
                        tool_code = :tool_code,
                        tool_name = :tool_name,
                        category = :category,
                        brand = :brand,
                        model = :model,
                        serial_number = :serial_number,
                        location = :location,
                        purchase_date = :purchase_date,
                        purchase_price = :purchase_price,
                        current_value = :current_value,
                        status = :status,
                        condition_rating = :condition_rating,
                        last_calibration_date = :last_calibration_date,
                        next_calibration_date = :next_calibration_date,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    ':tool_code' => $_POST['tool_code'],
                    ':tool_name' => $toolName,
                    ':category' => $_POST['category'] ?? null,
                    ':brand' => $_POST['brand'] ?? null,
                    ':model' => $_POST['model'] ?? null,
                    ':serial_number' => $_POST['serial_number'] ?? null,
                    ':location' => $_POST['location'] ?? 'Store',
                    ':purchase_date' => !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null,
                    ':purchase_price' => !empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0,
                    ':current_value' => !empty($_POST['current_value']) ? floatval($_POST['current_value']) : (!empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0),
                    ':status' => $_POST['status'] ?? 'available',
                    ':condition_rating' => $_POST['condition_rating'] ?? 'good',
                    ':last_calibration_date' => !empty($_POST['last_calibration_date']) ? $_POST['last_calibration_date'] : null,
                    ':next_calibration_date' => !empty($_POST['next_calibration_date']) ? $_POST['next_calibration_date'] : null,
                    ':notes' => $_POST['notes'] ?? null,
                    ':id' => $tool_id
                ]);
                
                $_SESSION['success'] = "Tool updated successfully!";
                header("Location: view_tool.php?id=$tool_id");
                exit();
                
            } catch(PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }
    
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Tool | SAVANT MOTORS</title>
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

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            padding: 1rem 1.5rem;
            color: white;
        }
        .form-header h2 { font-size: 1.1rem; font-weight: 600; }
        .form-body { padding: 1.5rem; }

        .form-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .form-section:last-child { border-bottom: none; }
        .section-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        .required { color: var(--danger); margin-left: 0.25rem; }
        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary-light); }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
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
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        .btn-danger { background: #ef4444; color: white; }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { justify-content: center; }
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
            <a href="dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="tools.php" class="menu-item active">🔧 Tool Management</a>
            <a href="tool_requests.php" class="menu-item">📝 Tool Requests</a>
            <a href="customers.php" class="menu-item">👥 Customers</a>
            <div style="margin-top: 2rem;">
                <a href="logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1>✏️ Edit Tool</h1>
                <p>Update tool information and details</p>
            </div>
            <a href="tools.php" class="btn btn-secondary">← Back to Tools</a>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h2>🔧 Editing: <?php echo htmlspecialchars($tool['tool_name']); ?></h2>
            </div>
            
            <div class="form-body">
                <?php if (isset($error_message)): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-section">
                        <div class="section-title">📋 Basic Information</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tool Code <span class="required">*</span></label>
                                <input type="text" name="tool_code" value="<?php echo htmlspecialchars($tool['tool_code']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Tool Name <span class="required">*</span></label>
                                <input type="text" name="tool_name" value="<?php echo htmlspecialchars($tool['tool_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($tool['category'] == $cat) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Brand</label>
                                <input type="text" name="brand" value="<?php echo htmlspecialchars($tool['brand'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Model</label>
                                <input type="text" name="model" value="<?php echo htmlspecialchars($tool['model'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Serial Number</label>
                                <input type="text" name="serial_number" value="<?php echo htmlspecialchars($tool['serial_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">📍 Location & Status</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Storage Location</label>
                                <select name="location">
                                    <option value="Store" <?php echo ($tool['location'] == 'Store') ? 'selected' : ''; ?>>Main Store</option>
                                    <option value="Workshop" <?php echo ($tool['location'] == 'Workshop') ? 'selected' : ''; ?>>Workshop</option>
                                    <option value="Tool Room" <?php echo ($tool['location'] == 'Tool Room') ? 'selected' : ''; ?>>Tool Room</option>
                                    <option value="Mobile" <?php echo ($tool['location'] == 'Mobile') ? 'selected' : ''; ?>>Mobile Unit</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="available" <?php echo ($tool['status'] == 'available') ? 'selected' : ''; ?>>Available</option>
                                    <option value="taken" <?php echo ($tool['status'] == 'taken') ? 'selected' : ''; ?>>Taken</option>
                                    <option value="maintenance" <?php echo ($tool['status'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="damaged" <?php echo ($tool['status'] == 'damaged') ? 'selected' : ''; ?>>Damaged</option>
                                    <option value="retired" <?php echo ($tool['status'] == 'retired') ? 'selected' : ''; ?>>Retired</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Condition</label>
                                <select name="condition_rating">
                                    <option value="new" <?php echo ($tool['condition_rating'] == 'new') ? 'selected' : ''; ?>>New</option>
                                    <option value="good" <?php echo ($tool['condition_rating'] == 'good') ? 'selected' : ''; ?>>Good</option>
                                    <option value="fair" <?php echo ($tool['condition_rating'] == 'fair') ? 'selected' : ''; ?>>Fair</option>
                                    <option value="poor" <?php echo ($tool['condition_rating'] == 'poor') ? 'selected' : ''; ?>>Poor</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">💰 Financial Information</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Purchase Date</label>
                                <input type="date" name="purchase_date" value="<?php echo $tool['purchase_date']; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Purchase Price (UGX)</label>
                                <input type="number" name="purchase_price" step="1000" value="<?php echo $tool['purchase_price']; ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Current Value (UGX)</label>
                                <input type="number" name="current_value" step="1000" value="<?php echo $tool['current_value']; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">📅 Calibration Schedule</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Last Calibration Date</label>
                                <input type="date" name="last_calibration_date" value="<?php echo $tool['last_calibration_date']; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Next Calibration Date</label>
                                <input type="date" name="next_calibration_date" value="<?php echo $tool['next_calibration_date']; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="section-title">📝 Additional Notes</div>
                        <textarea name="notes" rows="3"><?php echo htmlspecialchars($tool['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <a href="tools.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="update_tool" class="btn btn-primary">💾 Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Auto-calculate current value when purchase price changes
        const purchasePrice = document.querySelector('input[name="purchase_price"]');
        const currentValue = document.querySelector('input[name="current_value"]');
        
        purchasePrice?.addEventListener('change', function() {
            if (!currentValue.value || currentValue.value === '0') {
                currentValue.value = this.value;
            }
        });
        
        // Form submission loading state
        const form = document.querySelector('form');
        const submitBtn = form.querySelector('button[type="submit"]');
        
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Saving...';
        });
    </script>
</body>
</html>