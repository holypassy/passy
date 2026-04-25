<?php
// new_job.php - Create New Job Card with Proper Column Handling
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
    
    // Get existing columns in job_cards table
    $columns = $conn->query("SHOW COLUMNS FROM job_cards")->fetchAll(PDO::FETCH_COLUMN);
    
    // Check and add missing columns if needed
    $columnsToAdd = [
        'priority' => "VARCHAR(20) DEFAULT 'normal'",
        'vehicle_make' => 'VARCHAR(50)',
        'vehicle_model' => 'VARCHAR(100)',
        'vehicle_year' => 'VARCHAR(4)',
        'odometer_reading' => 'VARCHAR(20)',
        'fuel_level' => 'VARCHAR(20)',
        'notes' => 'TEXT',
        'inspection_data' => 'TEXT',
        'work_items' => 'TEXT',
        'brought_by' => 'VARCHAR(100)',
        'terms_accepted' => "TINYINT DEFAULT 0"
    ];
    
    foreach ($columnsToAdd as $column => $definition) {
        if (!in_array($column, $columns)) {
            try {
                $conn->exec("ALTER TABLE job_cards ADD COLUMN {$column} {$definition}");
                echo "<!-- Added column: {$column} -->";
            } catch(PDOException $e) {
                // Column might already exist - ignore
            }
        }
    }
    
    // Refresh columns after additions
    $columns = $conn->query("SHOW COLUMNS FROM job_cards")->fetchAll(PDO::FETCH_COLUMN);
    
    // Get customers for dropdown
    $stmt = $conn->query("
        SELECT id, full_name, telephone, email, address 
        FROM customers 
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
          AND status = 1
        ORDER BY full_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate next job number
    $lastJob = $conn->query("SELECT job_number FROM job_cards ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastJob) {
        $lastNum = intval(substr($lastJob['job_number'], -4));
        $nextNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        $job_number = $nextNum;
    } else {
        $job_number = '001';
    }
    
} catch(PDOException $e) {
    error_log("New Job Error: " . $e->getMessage());
    $customers = [];
    $job_number = '001';
}

// Handle new customer AJAX request
if (isset($_POST['ajax_add_customer'])) {
    header('Content-Type: application/json');
    
    try {
        if (empty($_POST['full_name'])) {
            echo json_encode(['success' => false, 'error' => 'Customer name is required']);
            exit();
        }
        
        $full_name = trim($_POST['full_name']);
        $telephone = !empty($_POST['telephone']) ? trim($_POST['telephone']) : null;
        $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : null;
        
        // Check if customer already exists
        $checkStmt = $conn->prepare("
            SELECT id, full_name, telephone, email, address 
            FROM customers 
            WHERE (full_name = :name OR (telephone IS NOT NULL AND telephone = :phone))
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
            LIMIT 1
        ");
        $checkStmt->execute([':name' => $full_name, ':phone' => $telephone]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            echo json_encode([
                'success' => true, 
                'id' => $existing['id'], 
                'name' => $existing['full_name'],
                'phone' => $existing['telephone'] ?? '',
                'email' => $existing['email'] ?? '',
                'address' => $existing['address'] ?? '',
                'message' => 'Customer already exists'
            ]);
            exit();
        }
        
        // Insert new customer
        $stmt = $conn->prepare("
            INSERT INTO customers (full_name, telephone, email, address, status, created_at) 
            VALUES (:name, :phone, :email, :address, 1, NOW())
        ");
        $stmt->execute([
            ':name' => $full_name,
            ':phone' => $telephone,
            ':email' => $email,
            ':address' => $address
        ]);
        
        $new_id = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true, 
            'id' => $new_id, 
            'name' => $full_name, 
            'phone' => $telephone ?? '', 
            'email' => $email ?? '',
            'address' => $address ?? '',
            'message' => 'Customer added successfully'
        ]);
        exit();
        
    } catch(PDOException $e) {
        error_log("Add Customer Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_job'])) {
    try {
        // Collect inspection data from checkboxes (horizontal layout)
        $inspection_data = [];
        
        // Basic items (dropdown: On, Off, Missing, Present)
        $basic_items = ['wheel_spanner', 'car_jack', 'special_nut', 'reflector', 'engine_check_light', 'radio', 'ac', 'fuel_level_indicator'];
        foreach ($basic_items as $item) {
            $inspection_data[$item] = $_POST['basic_' . $item] ?? '';
        }
        if (!empty($_POST['basic_notes'])) {
            $inspection_data['basic_notes'] = $_POST['basic_notes'];
        }
        
        // Front items (dropdown: Good, Scratched, Dented, Cracked, Missing)
        $front_items = ['front_bumper', 'front_grill', 'headlights', 'fog_lights', 'windshield', 'windshield_wipers'];
        foreach ($front_items as $item) {
            $inspection_data[$item] = $_POST['front_' . $item] ?? '';
        }
        if (!empty($_POST['front_notes'])) {
            $inspection_data['front_notes'] = $_POST['front_notes'];
        }
        
        // Rear items (dropdown: Good, Scratched, Dented, Cracked, Missing)
        $rear_items = ['rear_bumper', 'tail_lights', 'rear_fog_lights', 'rear_windshield', 'rear_wiper', 'boot_lid'];
        foreach ($rear_items as $item) {
            $inspection_data[$item] = $_POST['rear_' . $item] ?? '';
        }
        if (!empty($_POST['rear_notes'])) {
            $inspection_data['rear_notes'] = $_POST['rear_notes'];
        }
        
        // Left side items (dropdown: Good, Scratched, Dented, Cracked, Missing)
        $left_items = ['left_front_door', 'left_rear_door', 'left_mirror', 'left_side_molding', 'left_side_glass'];
        foreach ($left_items as $item) {
            $inspection_data[$item] = $_POST['left_' . $item] ?? '';
        }
        if (!empty($_POST['left_notes'])) {
            $inspection_data['left_notes'] = $_POST['left_notes'];
        }
        
        // Right side items (dropdown: Good, Scratched, Dented, Cracked, Missing)
        $right_items = ['right_front_door', 'right_rear_door', 'right_mirror', 'right_side_molding', 'right_side_glass'];
        foreach ($right_items as $item) {
            $inspection_data[$item] = $_POST['right_' . $item] ?? '';
        }
        if (!empty($_POST['right_notes'])) {
            $inspection_data['right_notes'] = $_POST['right_notes'];
        }
        
        // Top items (dropdown: Good, Scratched, Dented, Cracked, Missing)
        $top_items = ['roof_condition', 'sunroof', 'roof_rails', 'aerial_antenna'];
        foreach ($top_items as $item) {
            $inspection_data[$item] = $_POST['top_' . $item] ?? '';
        }
        if (!empty($_POST['top_notes'])) {
            $inspection_data['top_notes'] = $_POST['top_notes'];
        }
        
        // Fuel level (still a dropdown)
        $inspection_data['fuel_level'] = $_POST['fuel_level_status'] ?? '';
        
        $inspection_json = json_encode($inspection_data);
        
        // Collect work items (only descriptions)
        $work_items = [];
        if (isset($_POST['description']) && is_array($_POST['description'])) {
            for ($i = 0; $i < count($_POST['description']); $i++) {
                if (!empty($_POST['description'][$i])) {
                    $work_items[] = ['description' => $_POST['description'][$i]];
                }
            }
        }
        $work_items_json = json_encode($work_items);
        
        // Build dynamic INSERT query based on existing columns
        $insertFields = [
            'job_number', 'customer_id', 'vehicle_reg', 'date_received', 
            'status', 'created_by'
        ];
        
        $insertValues = [
            $_POST['job_number'],
            $_POST['customer_id'],
            $_POST['vehicle_reg'],
            date('Y-m-d'),
            'pending',
            $user_id
        ];
        
        // Add optional fields if they exist in table
        $optionalFields = [
            'vehicle_model' => $_POST['vehicle_model'] ?? null,
            'odometer_reading' => $_POST['odometer_reading'] ?? null,
            'fuel_level' => $_POST['fuel_level_status'] ?? null,
            'priority' => 'normal',
            'notes' => null,
            'inspection_data' => $inspection_json,
            'work_items' => $work_items_json,
            'brought_by' => $_POST['brought_by'] ?? null,
            'terms_accepted' => isset($_POST['terms_accepted']) ? 1 : 0
        ];
        
        foreach ($optionalFields as $field => $value) {
            if (in_array($field, $columns)) {
                $insertFields[] = $field;
                $insertValues[] = $value;
            }
        }
        
        // Build the INSERT query
        $placeholders = implode(', ', array_fill(0, count($insertValues), '?'));
        $fieldsList = implode(', ', $insertFields);
        
        $sql = "INSERT INTO job_cards ({$fieldsList}) VALUES ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute($insertValues);
        
        if (!$result) {
            throw new Exception("Failed to insert job card");
        }

        $job_card_id = (int)$conn->lastInsertId();
        
        $_SESSION['success'] = "Job card created successfully!";
        if (!empty($_POST['print_after_save']) && $_POST['print_after_save'] == '1') {
            header('Location: print_job.php?id=' . $job_card_id . '&autoprint=1');
        } else {
            header('Location: job_cards.php');
        }
        exit();
        
    } catch(PDOException $e) {
        $error_message = "Error creating job card: " . $e->getMessage();
    } catch(Exception $e) {
        $error_message = "Error creating job card: " . $e->getMessage();
    }
}

$success_message = $_SESSION['success'] ?? null;
$error_message = $_SESSION['error'] ?? $error_message ?? null;
unset($_SESSION['success'], $_SESSION['error']);
$current_date = date('d-m-Y');

// Set page title and subtitle for header
$page_title = 'JOB CARD';
$page_subtitle = 'Job #' . $job_number;
include 'header.php';
?>

<style>
    /* ===== MODERN FONT & STYLES (matching new_quotation.php) ===== */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: linear-gradient(135deg, #e6f0ff 0%, #cce4ff 100%);
        padding: 2rem;
        position: relative;
        font-size: 14px;
        line-height: 1.5;
    }
    
    /* Main container like quotation */
    .job-card {
        max-width: 1200px;
        margin: 0 auto;
        background: white;
        border-radius: 24px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.1);
        overflow: hidden;
        position: relative;
        z-index: 1;
    }
    
    /* Toolbar styling (same as quotation) */
    .toolbar {
        background: linear-gradient(135deg, #2563eb, #1e3a8a);
        padding: 1rem 2rem;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .toolbar button, .toolbar a {
        background: #2c3e50;
        border: none;
        color: white;
        padding: 0.5rem 1.2rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.9rem;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .toolbar button:hover, .toolbar a:hover {
        background: #1e2b38;
        transform: translateY(-1px);
    }
    
    .toolbar .print-btn {
        background: #3b82f6;
        color: white;
    }
    
    .quote-content {
        padding: 2rem;
        position: relative;
    }
    
    /* Alert styling */
    .alert {
        padding: 12px 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success {
        background: #d4edda;
        color: #155724;
        border-left: 4px solid #28a745;
    }
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }
    .alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border-left: 4px solid #17a2b8;
    }
    
    /* Fuel warning */
    .fuel-warning {
        background: #fef9e6;
        border-left: 5px solid #f59e0b;
        padding: 12px 20px;
        margin-bottom: 20px;
        font-size: 13px;
        color: #92400e;
        border-radius: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    /* Section title modern */
    .section-title-modern {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 16px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Customer info area - like quote-to */
    .customer-info-section {
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #3b82f6;
        padding: 1rem;
        margin-bottom: 20px;
    }
    
    .customer-info-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .customer-info-table td {
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        font-size: 14px;
    }
    
    .customer-info-table td:first-child {
        font-weight: 600;
        background: #f1f5f9;
        width: 140px;
    }
    
    .customer-info-table select, 
    .customer-info-table input {
        width: 100%;
        border: none;
        background: transparent;
        font-family: inherit;
        font-size: 14px;
        padding: 6px 4px;
        outline: none;
    }
    
    .customer-info-table select:focus, 
    .customer-info-table input:focus {
        background: #fff;
        border-bottom: 2px solid #3b82f6;
    }
    
    .btn-add-customer {
        background: #2563eb;
        color: white;
        border: none;
        padding: 6px 14px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
        transition: all 0.2s;
        margin-top: 6px;
    }
    
    .btn-add-customer:hover {
        background: #1e40af;
        transform: translateY(-1px);
    }
    
    /* Horizontal customer info display */
    .horizontal-customer-info {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 12px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
    }
    
    .customer-info-block {
        flex: 1;
        min-width: 160px;
    }
    
    .customer-info-block .info-label {
        font-size: 11px;
        color: #64748b;
        margin-bottom: 4px;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .customer-info-block .info-value {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
        word-break: break-word;
    }
    
    /* Two column layout for vehicle details */
    .two-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 15px;
    }
    
    .info-row {
        display: flex;
        margin-bottom: 12px;
        padding-bottom: 6px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .info-label {
        width: 140px;
        font-weight: 600;
        color: #475569;
        font-size: 14px;
    }
    
    .info-value {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .info-value input, .info-value select {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 14px;
        font-family: 'Inter', sans-serif;
        background: #ffffff;
        transition: all 0.2s;
    }
    
    .info-value input:focus, .info-value select:focus {
        outline: none;
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
    }
    
    /* Work items table - like quotation items table */
    .work-table {
        margin-bottom: 20px;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
        font-size: 13px;
    }
    
    .items-table th, .items-table td {
        border: 1px solid #e2e8f0;
        padding: 10px;
        vertical-align: top;
    }
    
    .items-table th {
        background: #3b82f6;
        color: white;
        font-weight: 600;
        text-align: center;
    }
    
    .items-table td textarea {
        width: 100%;
        border: none;
        background: transparent;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        padding: 4px;
        outline: none;
        resize: vertical;
    }
    
    .items-table td textarea:focus {
        background: #eff6ff;
    }
    
    .btn-add-row {
        background: #2563eb;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 12px;
        margin-top: 8px;
        font-weight: 600;
    }
    
    .btn-add-row:hover {
        background: #1e40af;
    }
    
    /* Master toggle button */
    .master-toggle {
        background: #64748b;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 40px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        margin: 0 0 15px 0;
        transition: all 0.2s;
    }
    
    .master-toggle:hover {
        background: #475569;
        transform: translateY(-1px);
    }
    
    /* NEW: Horizontal inspection checklist styles */
    .inspection-section {
        margin-bottom: 24px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        padding: 16px;
        transition: all 0.2s;
    }
    
    .inspection-title {
        font-weight: 700;
        font-size: 16px;
        color: #1e293b;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
        padding: 10px 16px;
        border-radius: 12px;
    }
    
    .inspection-title-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .toggle-inspection {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 14px;
        color: #64748b;
        padding: 4px 12px;
        border-radius: 30px;
        transition: all 0.2s;
    }
    
    .toggle-inspection:hover {
        background: #e2e8f0;
        color: #0ea5e9;
    }
    
    .inspection-content {
        display: block;
    }
    
    /* Horizontal checklist grid */
    .checklist-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 16px 24px;
        margin-bottom: 16px;
    }
    
    .checklist-item {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f1f5f9;
        padding: 6px 14px;
        border-radius: 40px;
        font-size: 13px;
        transition: all 0.1s;
    }
    
    .checklist-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin: 0;
        cursor: pointer;
        accent-color: #2563eb;
    }
    
    .checklist-item label {
        cursor: pointer;
        font-weight: 500;
        color: #1e293b;
    }
    
    /* Condition dropdown inside checklist items */
    .condition-select {
        padding: 3px 8px;
        border-radius: 20px;
        border: 1px solid #cbd5e1;
        font-size: 12px;
        font-family: 'Inter', sans-serif;
        background: white;
        color: #1e293b;
        cursor: pointer;
        outline: none;
        transition: all 0.15s;
        font-weight: 600;
    }
    .condition-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 2px rgba(37,99,235,0.12);
    }
    .condition-select option[value="On"]        { color: #16a34a; }
    .condition-select option[value="Off"]       { color: #dc2626; }
    .condition-select option[value="Missing"]   { color: #ea580c; }
    .condition-select option[value="Present"]   { color: #2563eb; }
    .condition-select option[value="Good"]      { color: #16a34a; }
    .condition-select option[value="Scratched"] { color: #d97706; }
    .condition-select option[value="Dented"]    { color: #ea580c; }
    .condition-select option[value="Cracked"]   { color: #dc2626; }
    
    /* Color-coded selected state */
    .condition-select.val-good      { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
    .condition-select.val-scratched { border-color: #d97706; color: #d97706; background: #fffbeb; }
    .condition-select.val-dented    { border-color: #ea580c; color: #ea580c; background: #fff7ed; }
    .condition-select.val-cracked   { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
    .condition-select.val-missing   { border-color: #ea580c; color: #ea580c; background: #fff7ed; }
    .condition-select.val-on        { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
    .condition-select.val-off       { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
    .condition-select.val-present   { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
    
    .inspection-notes {
        margin-top: 12px;
        width: 100%;
    }
    
    .inspection-notes textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        font-family: 'Inter', sans-serif;
        font-size: 12px;
        resize: vertical;
        background: #fefce8;
    }
    
    /* Terms section */
    .terms-section {
        background: #eff6ff;
        border-left: 4px solid #2563eb;
        padding: 1rem;
        margin: 1.5rem 0;
        border-radius: 8px;
    }
    
    .terms-header {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 12px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .terms-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px 25px;
    }
    
    .term-item {
        display: flex;
        gap: 10px;
        font-size: 12px;
        line-height: 1.4;
        color: #475569;
        padding: 6px 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    
    .term-number {
        font-weight: 800;
        color: #f59e0b;
        min-width: 24px;
        font-size: 12px;
    }
    
    .term-text {
        flex: 1;
    }
    
    .term-text strong {
        color: #1e293b;
    }
    
    .authorization-text {
        margin-top: 15px;
        padding-top: 12px;
        border-top: 1px solid #e2e8f0;
        font-size: 12px;
        font-weight: 500;
        color: #1e293b;
        text-align: center;
        background: white;
        padding: 12px;
        border-radius: 12px;
    }
    
    .terms-accept {
        margin-top: 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        background: #fef9e6;
        border-radius: 12px;
        border-left: 4px solid #f59e0b;
    }
    
    .terms-accept input {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .terms-accept label {
        font-size: 13px;
        font-weight: 500;
        color: #92400e;
        cursor: pointer;
    }
    
    /* Signature section */
    .signature-section {
        padding: 20px 0;
        background: white;
        display: flex;
        justify-content: space-between;
        gap: 30px;
        border-top: 1px solid #e2e8f0;
    }
    
    .signature-box {
        flex: 1;
        text-align: center;
    }
    
    .signature-line {
        border-top: 1px solid #4b5563;
        margin-top: 20px;
        padding-top: 8px;
        font-size: 11px;
        color: #6b7280;
    }
    
    .signature-box input {
        border: none;
        border-bottom: 1px solid #cbd5e1;
        background: transparent;
        text-align: center;
        width: 80%;
        padding: 6px;
        margin-top: 4px;
        font-size: 13px;
        font-family: 'Inter', sans-serif;
    }
    
    .signature-box input:focus {
        outline: none;
        border-bottom-color: #2563eb;
    }
    
    /* Footer */
    .footer {
        text-align: center;
        padding: 1rem;
        font-size: 12px;
        color: white;
        background: #1e3a8a;
        margin-top: 20px;
    }
    
    /* Action buttons */
    .action-buttons {
        padding: 20px 0 0 0;
        background: white;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 15px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    
    .btn {
        padding: 10px 24px;
        border-radius: 40px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: none;
        transition: all 0.2s;
    }
    
    .btn-primary {
        background: #2563eb;
        color: white;
    }
    
    .btn-primary:hover {
        background: #1e40af;
        transform: translateY(-2px);
    }
    
    .btn-secondary {
        background: #64748b;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #475569;
        transform: translateY(-2px);
    }
    
    /* Modal styling */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
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
        border-radius: 24px;
        width: 90%;
        max-width: 500px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    }
    
    .modal-header {
        background: linear-gradient(135deg, #2563eb, #1e3a8a);
        color: white;
        padding: 18px 24px;
        border-radius: 24px 24px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .close-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 40px;
        color: white;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .close-btn:hover {
        background: rgba(255,255,255,0.3);
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .form-group {
        margin-bottom: 16px;
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
    .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: 'Inter', sans-serif;
    }
    
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    /* Print styles - compact and clean */
    @media print {
        @page {
            size: A4;
            margin: 0.4cm;
        }
        body {
            padding: 0;
            margin: 0;
            background: white;
            font-family: 'Inter', 'Segoe UI', sans-serif !important;
            font-size: 10pt !important;
            line-height: 1.2;
        }
        .no-print {
            display: none !important;
        }
        .job-card {
            margin: 0;
            padding: 0;
            box-shadow: none;
            border: none;
            max-width: 100%;
        }
        .fuel-warning {
            margin: 3px 6px !important;
            padding: 4px 8px !important;
            font-size: 7.5pt !important;
        }
        .section-title-modern {
            font-size: 9pt !important;
            margin: 5px 0 3px !important;
            padding-bottom: 2px !important;
            border-bottom: 1px solid #ccc !important;
        }
        .horizontal-customer-info {
            display: flex !important;
            flex-wrap: wrap;
            gap: 4px !important;
            padding: 3px 5px !important;
            margin-bottom: 4px !important;
        }
        .customer-info-block {
            flex: 1;
            min-width: 90px;
        }
        .info-label {
            font-size: 6.5pt !important;
            font-weight: bold;
            margin-bottom: 1px;
        }
        .info-value {
            font-size: 8pt !important;
        }
        .two-columns {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 8px !important;
            margin-bottom: 4px !important;
        }
        .info-row {
            margin-bottom: 4px !important;
            padding-bottom: 2px !important;
            border-bottom: none !important;
            display: flex !important;
            align-items: baseline !important;
        }
        .info-label {
            width: 100px !important;
            font-size: 7pt !important;
        }
        .info-value input, 
        .info-value select {
            padding: 1px 3px !important;
            font-size: 8pt !important;
            border: 1px solid #ccc !important;
            background: transparent !important;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
        }
        .items-table th, 
        .items-table td {
            border: 1px solid #aaa;
            padding: 3px !important;
            font-size: 8pt !important;
        }
        .items-table th {
            background: #f1f5f9;
        }
        .items-table textarea {
            font-size: 8pt !important;
            padding: 2px !important;
            border: none !important;
            background: transparent !important;
            resize: none;
            overflow: visible;
            min-height: 30px;
        }
        .inspection-section {
            margin-bottom: 5px !important;
            page-break-inside: avoid;
            border: 1px solid #ddd !important;
            padding: 6px !important;
        }
        .inspection-title {
            font-size: 8.5pt !important;
            padding: 3px 6px !important;
            margin-bottom: 3px !important;
            background: #f1f5f9 !important;
            border-radius: 4px;
        }
        .checklist-grid {
            display: flex !important;
            flex-wrap: wrap;
            gap: 6px 10px !important;
            margin-bottom: 6px;
        }
        .checklist-item {
            padding: 2px 8px !important;
            gap: 4px;
            font-size: 7pt;
            background: #f9fafb;
        }
        .condition-select {
            font-size: 7pt !important;
            padding: 1px 4px !important;
            border-radius: 4px !important;
        }
        .inspection-notes textarea {
            font-size: 7pt !important;
            padding: 3px !important;
            border: 1px solid #ccc !important;
        }
        .terms-section {
            margin-top: 5px !important;
            page-break-inside: avoid;
        }
        .terms-header {
            font-size: 9pt !important;
            margin-bottom: 4px !important;
            padding-left: 5px !important;
        }
        .terms-grid {
            display: block !important;
            columns: 2;
            column-gap: 15px;
            margin-bottom: 4px;
        }
        .term-item {
            display: flex;
            gap: 4px;
            font-size: 6.5pt !important;
            line-height: 1.2;
            margin-bottom: 3px;
            break-inside: avoid;
        }
        .term-number {
            min-width: 18px;
            font-weight: bold;
        }
        .authorization-text {
            font-size: 7pt !important;
            margin: 4px 0 !important;
            padding: 3px !important;
            text-align: center;
        }
        .signature-section {
            display: flex !important;
            gap: 15px !important;
            margin-top: 6px !important;
            padding-top: 4px !important;
            border-top: 1px solid #ccc;
        }
        .signature-line {
            margin-top: 15px;
            border-top: 1px solid #333;
            padding-top: 3px;
            font-size: 6.5pt;
        }
        .signature-box input {
            border: none !important;
            border-bottom: 1px solid #999 !important;
            background: transparent !important;
            text-align: center;
            width: 80%;
            font-size: 7.5pt;
        }
        .footer {
            font-size: 5.5pt !important;
            padding: 4px !important;
            margin-top: 5px !important;
            background: #1e3a8a !important;
            color: white !important;
            text-align: center;
        }
        #basicContent, #frontContent, #rearContent, #leftContent, #rightContent, #topContent, #fuelContent {
            display: block !important;
        }
    }
    
    @media (max-width: 768px) {
        body {
            padding: 1rem;
        }
        .two-columns {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .checklist-grid {
            gap: 10px;
        }
        .signature-section {
            flex-direction: column;
            gap: 20px;
        }
        .terms-grid {
            grid-template-columns: 1fr;
        }
        .info-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }
        .info-label {
            width: 100%;
        }
        .info-value {
            width: 100%;
        }
        .horizontal-customer-info {
            flex-direction: column;
        }
        .action-buttons {
            flex-direction: column;
        }
        .action-buttons .btn {
            justify-content: center;
        }
    }
</style>

<!-- Toolbar -->
<div class="toolbar no-print">
    <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print Job Card</button>
    <a href="job_cards.php" class="reset-btn" style="background:#2c3e50;"><i class="fas fa-list"></i> Back to List</a>
</div>

<div class="job-card">
    <form method="POST" id="jobCardForm">
        <div class="quote-content">
            <div class="fuel-warning">
                <i class="fas fa-gas-pump"></i>
                <span>Please make sure that your Vehicle has a minimum of a quarter tank of Fuel, otherwise it will affect the smooth running of repairs.</span>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
            <div class="alert alert-danger no-print"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <!-- Customer Information -->
            <div class="customer-info-section">
                <div class="section-title-modern">
                    <i class="fas fa-user"></i> CUSTOMER INFORMATION
                </div>
                
                <table class="customer-info-table">
                    <tr>
                        <td>Name of vehicle Owner:</td>
                        <td colspan="3">
                            <select name="customer_id" id="customerSelect" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>"><?php echo htmlspecialchars($cust['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn-add-customer" onclick="openCustomerModal()">
                                <i class="fas fa-plus-circle"></i> New Customer
                            </button>
                        </td>
                    </tr>
                </table>

                <!-- Horizontal Customer Info Display -->
                <div class="horizontal-customer-info" id="customerInfoDisplay">
                    <div class="customer-info-block">
                        <div class="info-label"><i class="fas fa-user"></i> Customer Name</div>
                        <div class="info-value" id="displayCustomerName">-</div>
                    </div>
                    <div class="customer-info-block">
                        <div class="info-label"><i class="fas fa-phone"></i> Telephone</div>
                        <div class="info-value blue-text" id="displayCustomerPhone">-</div>
                    </div>
                    <div class="customer-info-block">
                        <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="info-value blue-text" id="displayCustomerEmail">-</div>
                    </div>
                    <div class="customer-info-block">
                        <div class="info-label"><i class="fas fa-map-marker-alt"></i> Address</div>
                        <div class="info-value" id="displayCustomerAddress">-</div>
                    </div>
                </div>

                <input type="hidden" id="customerNameHidden" value="">
                <input type="hidden" id="customerPhoneHidden" value="">
                <input type="hidden" id="customerEmailHidden" value="">
                <input type="hidden" id="customerAddressHidden" value="">

                <!-- Date Field -->
                <div class="info-row">
                    <div class="info-label">Date:</div>
                    <div class="info-value">
                        <input type="text" value="<?php echo $current_date; ?>" readonly style="background:#f9fafb;">
                    </div>
                </div>

                <div class="two-columns">
                    <div class="info-row">
                        <div class="info-label">Model:</div>
                        <div class="info-value">
                            <input type="text" name="vehicle_model" id="vehicleModel" placeholder="e.g., E CLASS">
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Chassis No.:</div>
                        <div class="info-value">
                            <input type="text" name="chassis_no" id="chassisNo" placeholder="e.g., NDB2120542A054402">
                        </div>
                    </div>
                </div>

                <div class="two-columns">
                    <div class="info-row">
                        <div class="info-label">Reg. No.:</div>
                        <div class="info-value">
                            <input type="text" name="vehicle_reg" id="vehicleReg" placeholder="e.g., 1VB 415Z">
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">ODO Reading:</div>
                        <div class="info-value">
                            <input type="text" name="odometer_reading" id="odometerReading" placeholder="e.g., 13,219 km">
                        </div>
                    </div>
                </div>

                <div class="info-row">
                    <div class="info-label">Received by:</div>
                    <div class="info-value">
                        <input type="text" value="<?php echo htmlspecialchars($user_full_name); ?>" readonly style="background:#f9fafb;">
                    </div>
                </div>
            </div>

            <!-- Work to be Done -->
            <div class="work-table">
                <div class="section-title-modern">
                    <i class="fas fa-tools"></i> WORK TO BE DONE
                </div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">No.</th>
                            <th>Description / Customer Complaint</th>
                        </tr>
                    </thead>
                    <tbody id="work-items">
                        <tr>
                            <td class="row-number">1</td>
                            <td><textarea name="description[]" rows="2" placeholder="Describe work to be done..."></textarea></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="text-align:right; border:none;">
                                <button type="button" class="btn-add-row" onclick="addWorkItem()"><i class="fas fa-plus"></i> Add Item</button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Security Notice -->
            <div class="notes-section">
                <div class="notes-box">
                    <i class="fas fa-shield-alt"></i>
                    <span>Savant Motors will not be responsible for loss of any personal items left in the vehicle. <strong>PLEASE REMOVE ALL PERSONAL EFFECTS FROM THE VEHICLE</strong></span>
                </div>
            </div>

            <!-- Master Toggle Button -->
            <div class="no-print" style="margin: 0 0 15px 0;">
                <button type="button" class="master-toggle" id="masterToggleBtn">
                    <i class="fas fa-eye-slash"></i> Hide All Inspections
                </button>
            </div>

            <!-- INSPECTION SECTIONS - HORIZONTAL CHECKBOXES (all checked by default) -->
            <div id="inspectionSections">
                <!-- BASIC INSPECTION -->
                <div class="inspection-section" id="basicSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
            <i class="fas fa-clipboard-check"></i> VEHICLE INCOMING INSPECTION (Basic Items)
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('basic')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="basicContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="basic_wheel_spanner">Wheel Spanner</label>
                                <select name="basic_wheel_spanner" id="basic_wheel_spanner" class="condition-select">
                                    <option value="">—</option>
                                    <option value="Present">Present</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_car_jack">Car Jack</label>
                                <select name="basic_car_jack" id="basic_car_jack" class="condition-select">
                                    <option value="">—</option>
                                    <option value="Present">Present</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_special_nut">Special Nut</label>
                                <select name="basic_special_nut" id="basic_special_nut" class="condition-select">
                                    <option value="">—</option>
                                    <option value="Present">Present</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_reflector">Reflector</label>
                                <select name="basic_reflector" id="basic_reflector" class="condition-select">
                                    <option value="">—</option>
                                    <option value="Present">Present</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_engine_check_light">Engine Check Light</label>
                                <select name="basic_engine_check_light" id="basic_engine_check_light" class="condition-select">
                                    <option value="">—</option>
                                    <option value="On">On</option>
                                    <option value="Off">Off</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_radio">Radio</label>
                                <select name="basic_radio" id="basic_radio" class="condition-select">
                                    <option value="">—</option>
                                    <option value="On">On</option>
                                    <option value="Off">Off</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_ac">AC</label>
                                <select name="basic_ac" id="basic_ac" class="condition-select">
                                    <option value="">—</option>
                                    <option value="On">On</option>
                                    <option value="Off">Off</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="basic_fuel_level_indicator">Fuel Level Indicator</label>
                                <select name="basic_fuel_level_indicator" id="basic_fuel_level_indicator" class="condition-select">
                                    <option value="">—</option>
                                    <option value="On">On</option>
                                    <option value="Off">Off</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="basic_notes" rows="2" placeholder="Additional notes for Basic Inspection..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- FRONT INSPECTION -->
                <div class="inspection-section" id="frontSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-car"></i> FRONT INSPECTION
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('front')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="frontContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="front_front_bumper">Front Bumper</label>
                                <select name="front_front_bumper" id="front_front_bumper" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="front_front_grill">Front Grill</label>
                                <select name="front_front_grill" id="front_front_grill" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="front_headlights">Headlights</label>
                                <select name="front_headlights" id="front_headlights" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="front_fog_lights">Fog Lights</label>
                                <select name="front_fog_lights" id="front_fog_lights" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="front_windshield">Windshield</label>
                                <select name="front_windshield" id="front_windshield" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="front_windshield_wipers">Windshield Wipers</label>
                                <select name="front_windshield_wipers" id="front_windshield_wipers" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="front_notes" rows="2" placeholder="Additional notes for Front Inspection..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- REAR INSPECTION -->
                <div class="inspection-section" id="rearSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-car-rear"></i> REAR INSPECTION
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('rear')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="rearContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="rear_rear_bumper">Rear Bumper</label>
                                <select name="rear_rear_bumper" id="rear_rear_bumper" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="rear_tail_lights">Tail Lights</label>
                                <select name="rear_tail_lights" id="rear_tail_lights" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="rear_rear_fog_lights">Rear Fog Lights</label>
                                <select name="rear_rear_fog_lights" id="rear_rear_fog_lights" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="rear_rear_windshield">Rear Windshield</label>
                                <select name="rear_rear_windshield" id="rear_rear_windshield" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="rear_rear_wiper">Rear Wiper</label>
                                <select name="rear_rear_wiper" id="rear_rear_wiper" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="rear_boot_lid">Boot Lid / Trunk</label>
                                <select name="rear_boot_lid" id="rear_boot_lid" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="rear_notes" rows="2" placeholder="Additional notes for Rear Inspection..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- LEFT SIDE INSPECTION -->
                <div class="inspection-section" id="leftSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-arrow-left"></i> LEFT SIDE INSPECTION
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('left')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="leftContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="left_left_front_door">Front Door</label>
                                <select name="left_left_front_door" id="left_left_front_door" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="left_left_rear_door">Rear Door</label>
                                <select name="left_left_rear_door" id="left_left_rear_door" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="left_left_mirror">Side Mirror</label>
                                <select name="left_left_mirror" id="left_left_mirror" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="left_left_side_molding">Side Molding</label>
                                <select name="left_left_side_molding" id="left_left_side_molding" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="left_left_side_glass">Side Glass</label>
                                <select name="left_left_side_glass" id="left_left_side_glass" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="left_notes" rows="2" placeholder="Additional notes for Left Side..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDE INSPECTION -->
                <div class="inspection-section" id="rightSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-arrow-right"></i> RIGHT SIDE INSPECTION
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('right')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="rightContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="right_right_front_door">Front Door</label>
                                <select name="right_right_front_door" id="right_right_front_door" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="right_right_rear_door">Rear Door</label>
                                <select name="right_right_rear_door" id="right_right_rear_door" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="right_right_mirror">Side Mirror</label>
                                <select name="right_right_mirror" id="right_right_mirror" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="right_right_side_molding">Side Molding</label>
                                <select name="right_right_side_molding" id="right_right_side_molding" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="right_right_side_glass">Side Glass</label>
                                <select name="right_right_side_glass" id="right_right_side_glass" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="right_notes" rows="2" placeholder="Additional notes for Right Side..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- TOP VIEW INSPECTION -->
                <div class="inspection-section" id="topSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-arrow-up"></i> TOP VIEW INSPECTION
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('top')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="topContent" style="display: block;">
                        <div class="checklist-grid">
                            <div class="checklist-item">
                                <label for="top_roof_condition">Roof Condition</label>
                                <select name="top_roof_condition" id="top_roof_condition" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Cracked">Cracked</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="top_sunroof">Sunroof / Moonroof</label>
                                <select name="top_sunroof" id="top_sunroof" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Cracked">Cracked</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="top_roof_rails">Roof Rails</label>
                                <select name="top_roof_rails" id="top_roof_rails" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Scratched">Scratched</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                            <div class="checklist-item">
                                <label for="top_aerial_antenna">Aerial / Antenna</label>
                                <select name="top_aerial_antenna" id="top_aerial_antenna" class="condition-select" onchange="applyConditionColor(this)">
                                    <option value="">—</option>
                                    <option value="Good">Good</option>
                                    <option value="Dented">Dented</option>
                                    <option value="Missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div class="inspection-notes">
                            <textarea name="top_notes" rows="2" placeholder="Additional notes for Top View..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- FUEL LEVEL (still dropdown) -->
                <div class="inspection-section" id="fuelSection">
                    <div class="inspection-title">
                        <div class="inspection-title-left">
                            <i class="fas fa-gas-pump"></i> FUEL LEVEL
                        </div>
                        <button type="button" class="toggle-inspection no-print" onclick="toggleInspection('fuel')">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="inspection-content" id="fuelContent" style="display: block;">
                        <div style="padding: 8px 0;">
                            <select name="fuel_level_status" required style="padding: 8px 12px; border-radius: 40px; border: 1px solid #ccc;">
                                <option value="">Select Fuel Level</option>
                                <option value="Reserve">⛽ Reserve</option>
                                <option value="Quarter">⛽ Quarter</option>
                                <option value="Half">⛽ Half</option>
                                <option value="Three Quarter">⛽ Three Quarter</option>
                                <option value="Full">⛽ Full</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TERMS AND CONDITIONS -->
            <div class="terms-section">
                <div class="terms-header">
                    <i class="fas fa-file-contract"></i> TERMS AND CONDITIONS
                </div>
                <div class="terms-grid">
                    <div class="term-item"><div class="term-number">1.</div><div class="term-text"><strong>UNSPECIFIED WORK:</strong> Only repairs set out overleaf will be carried out. Any other defects discovered will be drawn to your attention.</div></div>
                    <div class="term-item"><div class="term-number">2.</div><div class="term-text"><strong>REPAIR ESTIMATES:</strong> All estimates are based on prevailing labour rate and parts/material prices at the time repairs are carried out.</div></div>
                    <div class="term-item"><div class="term-number">3.</div><div class="term-text"><strong>STORAGE CHARGES:</strong> Storage charges of UGX 10,000 per day apply from 3 days after completion notification.</div></div>
                    <div class="term-item"><div class="term-number">4.</div><div class="term-text"><strong>UNCOLLECTED GOODS:</strong> All items accepted fall under the UNCOLLECTED GOODS ACT (1952).</div></div>
                    <div class="term-item"><div class="term-number">5.</div><div class="term-text"><strong>GUARANTEE:</strong> The Company accepts no liability for fault or defective workmanship once the vehicle has been taken away.</div></div>
                    <div class="term-item"><div class="term-number">6.</div><div class="term-text"><strong>UNSERVICEABLE PARTS:</strong> Unserviceable parts will be disposed of unless claimed within hours of completion.</div></div>
                    <div class="term-item"><div class="term-number">7.</div><div class="term-text"><strong>QUERIES:</strong> Queries on invoices will not be entertained if not received within 24 hours after invoice issue date.</div></div>
                    <div class="term-item"><div class="term-number">8.</div><div class="term-text"><strong>PENALTY FOR LATE PAYMENT:</strong> A penalty of 5% per month applies on outstanding amounts after thirty days from invoice date.</div></div>
                    <div class="term-item"><div class="term-number">9.</div><div class="term-text"><strong>PICKUP/DELIVERY:</strong> Please note that the workshop cannot assume responsibility for any accidents or incidents that may occur during the pickup or delivery process.</div></div>
                </div>
                <div class="authorization-text">
                    <i class="fas fa-check-circle"></i> I authorize the above repair work and confirm the vehicle incoming inspection & security check are agreed to your terms and conditions.
                </div>
                <div class="terms-accept no-print">
                    <input type="checkbox" name="terms_accepted" id="termsAccepted" required>
                    <label for="termsAccepted">I have read and agree to the Terms and Conditions above *</label>
                </div>
            </div>

            <!-- SIGNATURE SECTION -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line">_________________________</div>
                    <div>Brought by (Print Name): <input type="text" name="brought_by" id="broughtBy" placeholder="Print Name"></div>
                </div>
                <div class="signature-box">
                    <div class="signature-line">_________________________</div>
                    <div>Signed:</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <i class="fas fa-charging-station"></i> Savant Motors - Quality Service You Can Trust | Since 2018
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons no-print">
                <a href="job_cards.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                <input type="hidden" name="job_number" value="<?php echo $job_number; ?>">
                <input type="hidden" name="print_after_save" id="printAfterSave" value="0">
                <button type="submit" name="create_job" class="btn btn-primary"
                        onclick="document.getElementById('printAfterSave').value='1';"
                        style="background:linear-gradient(135deg,#10b981,#059669);">
                    <i class="fas fa-print"></i> Save &amp; Print
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Add Customer Modal -->
<div id="addCustomerModal" class="modal no-print">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
            <button class="close-btn" onclick="closeCustomerModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" id="new_customer_name" placeholder="Enter customer name">
            </div>
            <div class="form-group">
                <label>Telephone</label>
                <input type="text" id="new_customer_phone" placeholder="Enter phone number">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="new_customer_email" placeholder="Enter email address">
            </div>
            <div class="form-group">
                <label>Address</label>
                <textarea id="new_customer_address" rows="2" placeholder="Enter address"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeCustomerModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveNewCustomer()">Save Customer</button>
        </div>
    </div>
</div>

<script>
    const customerSelect = document.getElementById('customerSelect');
    const customers = <?php echo json_encode(array_map(function($c) {
        return ['id' => $c['id'], 'name' => $c['full_name'], 'phone' => $c['telephone'] ?? '', 'email' => $c['email'] ?? '', 'address' => $c['address'] ?? ''];
    }, $customers)); ?>;

    function updateCustomerDisplay(customer) {
        if (customer) {
            document.getElementById('displayCustomerName').textContent = customer.name;
            document.getElementById('displayCustomerPhone').textContent = customer.phone || '-';
            document.getElementById('displayCustomerEmail').textContent = customer.email || '-';
            document.getElementById('displayCustomerAddress').textContent = customer.address || '-';
            
            document.getElementById('customerNameHidden').value = customer.name;
            document.getElementById('customerPhoneHidden').value = customer.phone || '';
            document.getElementById('customerEmailHidden').value = customer.email || '';
            document.getElementById('customerAddressHidden').value = customer.address || '';
        } else {
            document.getElementById('displayCustomerName').textContent = '-';
            document.getElementById('displayCustomerPhone').textContent = '-';
            document.getElementById('displayCustomerEmail').textContent = '-';
            document.getElementById('displayCustomerAddress').textContent = '-';
        }
    }

    customerSelect?.addEventListener('change', function() {
        const customer = customers.find(c => c.id == this.value);
        updateCustomerDisplay(customer);
    });

    function openCustomerModal() {
        document.getElementById('addCustomerModal').classList.add('active');
        document.getElementById('new_customer_name').value = '';
        document.getElementById('new_customer_phone').value = '';
        document.getElementById('new_customer_email').value = '';
        document.getElementById('new_customer_address').value = '';
    }

    function closeCustomerModal() {
        document.getElementById('addCustomerModal').classList.remove('active');
    }

    async function saveNewCustomer() {
        const name = document.getElementById('new_customer_name').value.trim();
        if (!name) {
            alert('Please enter customer name');
            return;
        }

        const phone = document.getElementById('new_customer_phone').value;
        const email = document.getElementById('new_customer_email').value;
        const address = document.getElementById('new_customer_address').value;

        const saveBtn = document.querySelector('#addCustomerModal .btn-primary');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled = true;

        try {
            const formData = new URLSearchParams();
            formData.append('ajax_add_customer', '1');
            formData.append('full_name', name);
            formData.append('telephone', phone);
            formData.append('email', email);
            formData.append('address', address);

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
            
            if (data.success) {
                const newOption = document.createElement('option');
                newOption.value = data.id;
                newOption.textContent = `${data.name}${data.phone ? ' - ' + data.phone : ''}`;
                customerSelect.appendChild(newOption);
                customerSelect.value = data.id;
                
                updateCustomerDisplay({
                    name: data.name,
                    phone: data.phone,
                    email: data.email,
                    address: data.address
                });
                
                closeCustomerModal();
                
                if (data.message === 'Customer already exists') {
                    alert('Customer already exists. Using existing customer.');
                } else {
                    alert('Customer added successfully!');
                }
            } else {
                alert('Error adding customer: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
            alert('Error adding customer. Please try again.');
        }
    }

    let itemCount = 1;
    function addWorkItem() {
        itemCount++;
        const tbody = document.getElementById('work-items');
        const row = document.createElement('tr');
        row.innerHTML = `<td class="row-number">${itemCount}</td><td><textarea name="description[]" rows="2" placeholder="Describe work to be done..."></textarea></td>`;
        tbody.appendChild(row);
    }

    // Individual inspection toggle function
    function toggleInspection(section) {
        const content = document.getElementById(section + 'Content');
        const btnIcon = document.querySelector(`#${section}Section .toggle-inspection i`);
        if (content && btnIcon) {
            if (content.style.display === 'none') {
                content.style.display = 'block';
                btnIcon.className = 'fas fa-eye-slash';
            } else {
                content.style.display = 'none';
                btnIcon.className = 'fas fa-eye';
            }
        }
        updateMasterToggleState();
    }

    // Master toggle: show/hide all inspection contents
    const masterBtn = document.getElementById('masterToggleBtn');
    function toggleAllInspections() {
        const sections = ['basic', 'front', 'rear', 'left', 'right', 'top', 'fuel'];
        let anyVisible = false;
        sections.forEach(section => {
            const content = document.getElementById(section + 'Content');
            if (content && content.style.display !== 'none') {
                anyVisible = true;
            }
        });
        sections.forEach(section => {
            const content = document.getElementById(section + 'Content');
            const btnIcon = document.querySelector(`#${section}Section .toggle-inspection i`);
            if (content) {
                if (anyVisible) {
                    content.style.display = 'none';
                    if (btnIcon) btnIcon.className = 'fas fa-eye';
                } else {
                    content.style.display = 'block';
                    if (btnIcon) btnIcon.className = 'fas fa-eye-slash';
                }
            }
        });
        updateMasterToggleState();
    }

    function updateMasterToggleState() {
        const sections = ['basic', 'front', 'rear', 'left', 'right', 'top', 'fuel'];
        let anyVisible = false;
        sections.forEach(section => {
            const content = document.getElementById(section + 'Content');
            if (content && content.style.display !== 'none') {
                anyVisible = true;
            }
        });
        if (masterBtn) {
            if (anyVisible) {
                masterBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide All Inspections';
            } else {
                masterBtn.innerHTML = '<i class="fas fa-eye"></i> Show All Inspections';
            }
        }
    }

    if (masterBtn) {
        masterBtn.addEventListener('click', toggleAllInspections);
    }

    // Condition color coding for inspection dropdowns
    function applyConditionColor(sel) {
        const map = {
            'good': 'val-good', 'scratched': 'val-scratched',
            'dented': 'val-dented', 'cracked': 'val-cracked',
            'missing': 'val-missing', 'on': 'val-on',
            'off': 'val-off', 'present': 'val-present'
        };
        sel.className = sel.className.replace(/\bval-\S+/g, '').trim();
        const v = (sel.value || '').toLowerCase();
        if (map[v]) sel.classList.add(map[v]);
    }
    // Apply on page load for any pre-selected values
    document.querySelectorAll('.condition-select').forEach(applyConditionColor);

    window.onclick = function(e) {
        const modal = document.getElementById('addCustomerModal');
        if (e.target === modal) {
            closeCustomerModal();
        }
    }

    window.onload = function() {
        if (customerSelect && customerSelect.value) {
            const selected = customers.find(c => c.id == customerSelect.value);
            if (selected) updateCustomerDisplay(selected);
        }
        updateMasterToggleState();
    }
</script>