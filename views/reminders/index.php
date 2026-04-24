<?php
// views/reminders/index.php - Main Dashboard View with Full Customer & Staff Integration
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, ensure the table has all required columns
    try {
        // Check if reminder_number column exists, if not add it
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'reminder_number'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN reminder_number VARCHAR(50) AFTER id");
        }
        
        // Check if customer_name column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'customer_name'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN customer_name VARCHAR(255) AFTER customer_id");
        }
        
        // Check if customer_phone column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'customer_phone'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN customer_phone VARCHAR(50) AFTER customer_name");
        }
        
        // Check if customer_email column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'customer_email'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN customer_email VARCHAR(255) AFTER customer_phone");
        }
        
        // Check if reminder_sent column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'reminder_sent'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN reminder_sent BOOLEAN DEFAULT 0");
        }
        
        // Check if reminder_type column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'reminder_type'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN reminder_type VARCHAR(20) DEFAULT 'sms'");
        }
        
        // Check if pickup_location_details column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'pickup_location_details'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN pickup_location_details TEXT AFTER pickup_address");
        }

        // Check if pickup_type column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'pickup_type'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN pickup_type VARCHAR(20) DEFAULT 'workshop'");
        }

        // Check if pickup_address column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'pickup_address'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN pickup_address TEXT");
        }

        // Check if pickup_date column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'pickup_date'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN pickup_date DATE");
        }

        // Check if pickup_time column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'pickup_time'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN pickup_time TIME");
        }

        // Check if reminder_date column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'reminder_date'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN reminder_date DATE");
        }

        // Check if reminder_time column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'reminder_time'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN reminder_time TIME");
        }

        // Check if vehicle_reg column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'vehicle_reg'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN vehicle_reg VARCHAR(20)");
        }

        // Check if vehicle_make column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'vehicle_make'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN vehicle_make VARCHAR(50)");
        }

        // Check if vehicle_model column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'vehicle_model'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN vehicle_model VARCHAR(50)");
        }

        // Check if assigned_to column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'assigned_to'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN assigned_to INT DEFAULT NULL");
        }

        // Check if notes column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'notes'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN notes TEXT");
        }

        // Check if status column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'status'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN status VARCHAR(20) DEFAULT 'scheduled'");
        }

        // Check if created_by column exists
        $checkColumn = $conn->query("SHOW COLUMNS FROM vehicle_pickup_reminders LIKE 'created_by'");
        if ($checkColumn->rowCount() == 0) {
            $conn->exec("ALTER TABLE vehicle_pickup_reminders ADD COLUMN created_by INT DEFAULT NULL");
        }
        
    } catch(PDOException $e) {
        // Table might not exist yet, will be created when adding first reminder
    }

    // Get statistics
    $stats = [
        'total' => 0,
        'pending' => 0,
        'scheduled' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    
    try {
        $statsQuery = $conn->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM vehicle_pickup_reminders
        ");
        $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // Table might not exist yet
    }
    
    // Get filters
    $filters = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'pickup_type' => $_GET['pickup_type'] ?? ''
    ];
    
    // Build query for reminders
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $whereConditions = [];
    $params = [];
    
    if (!empty($filters['search'])) {
        $whereConditions[] = "(customer_name LIKE ? OR vehicle_reg LIKE ? OR reminder_number LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['status'])) {
        $whereConditions[] = "status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['pickup_type'])) {
        $whereConditions[] = "pickup_type = ?";
        $params[] = $filters['pickup_type'];
    }
    
    $whereClause = empty($whereConditions) ? "" : "WHERE " . implode(" AND ", $whereConditions);
    
    // Get total count
    $totalRecords = 0;
    try {
        $countQuery = "SELECT COUNT(*) as total FROM vehicle_pickup_reminders $whereClause";
        $countStmt = $conn->prepare($countQuery);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch(PDOException $e) {
        $totalRecords = 0;
    }
    $lastPage = $totalRecords > 0 ? ceil($totalRecords / $limit) : 1;
    
    // Get reminders (with assigned staff name via subquery — works even if users table varies)
    $remindersData = [];
    try {
        $query = "
            SELECT vpr.*,
                   COALESCE(u.full_name, s.full_name) as assigned_staff_name,
                   COALESCE(u.role, 'Staff') as assigned_staff_role
            FROM vehicle_pickup_reminders vpr
            LEFT JOIN users u ON vpr.assigned_to = u.id
            LEFT JOIN staff s ON vpr.assigned_to = s.id
            $whereClause 
            ORDER BY vpr.pickup_date ASC, vpr.pickup_time ASC 
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        $remindersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        // Fallback without JOIN if tables differ
        try {
            $query = "SELECT * FROM vehicle_pickup_reminders $whereClause ORDER BY pickup_date ASC, pickup_time ASC LIMIT $limit OFFSET $offset";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $remindersData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) {
            $remindersData = [];
        }
    }
    
    $reminders = [
        'data' => $remindersData,
        'total' => $totalRecords,
        'current_page' => $page,
        'last_page' => $lastPage
    ];
    
    // Get all customers for modal — handles status as integer (1) OR string ('active') OR missing
    $customers = [];
    $customerFetchError = null;
    try {
        // Detect what the status column looks like
        $colInfo = $conn->query("SHOW COLUMNS FROM customers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType = $colInfo['Type'] ?? '';

        if (empty($colInfo)) {
            // No status column at all — fetch everyone
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } elseif (stripos($statusType, 'int') !== false || stripos($statusType, 'tinyint') !== false) {
            // Integer status: 1 = active
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                WHERE status = 1 OR status IS NULL
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // String status: 'active' or anything other than 'inactive'/'blocked'/'deleted'
            $customers = $conn->query("
                SELECT id, full_name, telephone, email, address
                FROM customers
                WHERE status NOT IN ('inactive','blocked','deleted','suspended') OR status IS NULL
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch(PDOException $e) {
        $customerFetchError = $e->getMessage();
        $customers = [];
    }
    
    // Get staff for assignment
    $staff = [];
    try {
        $staff = $conn->query("
            SELECT id, full_name, email, role 
            FROM users 
            WHERE status = 'active' OR status IS NULL
            ORDER BY full_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        try {
            $staff = $conn->query("
                SELECT id, full_name, email, 'staff' as role 
                FROM staff 
                WHERE status = 'active' OR status IS NULL
                ORDER BY full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e2) {
            $staff = [];
        }
    }
    
} catch(PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $stats = ['total' => 0, 'pending' => 0, 'scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    $reminders = ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1];
    $customers = [];
    $staff = [];
    $filters = ['search' => '', 'status' => '', 'pickup_type' => ''];
}

// ── AJAX: live customer search ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_customers') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    try {
        $conn2 = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Build a status-agnostic WHERE clause
        $colInfo2    = $conn2->query("SHOW COLUMNS FROM customers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $statusType2 = $colInfo2['Type'] ?? '';
        if (empty($colInfo2)) {
            $statusCond = '1=1';
        } elseif (stripos($statusType2, 'int') !== false) {
            $statusCond = '(status = 1 OR status IS NULL)';
        } else {
            $statusCond = "(status NOT IN ('inactive','blocked','deleted','suspended') OR status IS NULL)";
        }

        $like = '%' . $q . '%';
        $stmt2 = $conn2->prepare("
            SELECT id, full_name, telephone, email, address
            FROM customers
            WHERE $statusCond
              AND (full_name LIKE ? OR telephone LIKE ? OR email LIKE ?)
            ORDER BY full_name
            LIMIT 30
        ");
        $stmt2->execute([$like, $like, $like]);
        echo json_encode(['success' => true, 'customers' => $stmt2->fetchAll(PDO::FETCH_ASSOC)]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'customers' => []]);
    }
    exit;
}

// ── AJAX: live staff search ─────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_staff') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    try {
        $conn3 = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $like = '%' . $q . '%';
        $staffResults = [];
        // Try users table first
        try {
            $s3 = $conn3->prepare("
                SELECT id, full_name, email, role, '' as position
                FROM users
                WHERE (status = 'active' OR status IS NULL)
                  AND (full_name LIKE ? OR email LIKE ? OR role LIKE ?)
                ORDER BY full_name LIMIT 30
            ");
            $s3->execute([$like, $like, $like]);
            $staffResults = $s3->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {}
        // Also try staff table
        try {
            $s3b = $conn3->prepare("
                SELECT id, full_name, email, 'staff' as role, position
                FROM staff
                WHERE (status = 'active' OR status IS NULL)
                  AND (full_name LIKE ? OR email LIKE ? OR position LIKE ?)
                ORDER BY full_name LIMIT 30
            ");
            $s3b->execute([$like, $like, $like]);
            $more = $s3b->fetchAll(PDO::FETCH_ASSOC);
            // Merge, avoiding duplicate IDs
            $existingIds = array_column($staffResults, 'id');
            foreach ($more as $m) {
                if (!in_array($m['id'], $existingIds)) $staffResults[] = $m;
            }
        } catch(PDOException $e2) {}
        echo json_encode(['success' => true, 'staff' => $staffResults]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'staff' => []]);
    }
    exit;
}

// Helper function to format time to 12-hour format
function formatTime12Hour($time) {
    if (empty($time)) return '';
    $timestamp = strtotime($time);
    return date('h:i A', $timestamp);
}

// Helper function to safely get array value
function safeGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Pickup Management | SAVANT MOTORS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fb;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #eff6ff;
            --secondary: #7c3aed;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
        }

        /* Sidebar - Light Blue Theme */
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
            box-shadow: 2px 0 12px rgba(0,0,0,0.08);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(12, 74, 110, 0.15);
            text-align: center;
        }

        .logo-container {
            margin-bottom: 0.75rem;
        }
        
        .sidebar-logo {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border-radius: 12px;
            background: white;
            padding: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 32px;
        }

        .sidebar-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            color: #0369a1;
        }

        .sidebar-header p { 
            font-size: 0.7rem; 
            opacity: 0.7; 
            margin-top: 0.25rem;
            color: #0c4a6e;
        }

        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title {
            padding: 0.5rem 1.5rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #0369a1;
            font-weight: 700;
            opacity: 0.8;
        }

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

        .menu-item:hover, .menu-item.active {
            background: rgba(255,255,255,0.6);
            color: #075985;
            border-left-color: #0284c7;
        }
        
        .menu-item.active {
            background: white;
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 1.5rem;
            min-height: 100vh;
        }

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
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }

        .page-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            transition: all 0.2s;
            border: 1px solid var(--border);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 0.75rem;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: flex-end;
            border: 1px solid var(--border);
        }

        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label {
            display: block;
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }

        .filter-group input, .filter-group select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-family: inherit;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid var(--border);
        }

        .tab-btn {
            padding: 0.75rem 1.25rem;
            background: none;
            border: none;
            font-weight: 600;
            cursor: pointer;
            color: var(--gray);
            transition: all 0.2s;
            font-size: 0.85rem;
        }

        .tab-btn.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            margin-bottom: -2px;
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Table */
        .table-container {
            background: white;
            border-radius: 1rem;
            overflow-x: auto;
            border: 1px solid var(--border);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th {
            background: var(--bg-light);
            padding: 0.9rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray);
            border-bottom: 1px solid var(--border);
        }

        .data-table td {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
        }

        .data-table tr:hover { background: var(--bg-light); }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.6rem;
            border-radius: 2rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-pending { background: #fed7aa; color: #9a3412; }
        .badge-scheduled { background: #dbeafe; color: #1e40af; }
        .badge-in_progress { background: #c7d2fe; color: #3730a3; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .pickup-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 2rem;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .pickup-workshop { background: #e2e8f0; color: #475569; }
        .pickup-home { background: #dbeafe; color: #1e40af; }
        .pickup-office { background: #fed7aa; color: #9a3412; }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 0.3rem;
        }

        .action-btn {
            padding: 0.3rem 0.6rem;
            border-radius: 0.4rem;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border: none;
            background: none;
        }

        .action-btn-view { background: #dbeafe; color: #1e40af; }
        .action-btn-view:hover { background: #1e40af; color: white; }
        .action-btn-edit { background: #dcfce7; color: #166534; }
        .action-btn-edit:hover { background: #166534; color: white; }
        .action-btn-send { background: #fed7aa; color: #9a3412; }
        .action-btn-send:hover { background: #9a3412; color: white; }

        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-secondary {
            background: var(--bg-light);
            border: 1px solid var(--border);
            color: var(--gray);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            cursor: pointer;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }

        .empty-state .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 1rem;
            width: 90%;
            max-width: 650px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border-radius: 1rem 1rem 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            background: white;
            border-radius: 0 0 1rem 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .required {
            color: var(--danger);
            margin-left: 0.25rem;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
            transition: all 0.2s;
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .info-box {
            background: #eff6ff;
            padding: 0.75rem;
            border-radius: 0.5rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .page-link {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: 0.4rem;
            text-decoration: none;
            color: var(--dark);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Toast notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        }

        .toast-success { background: #10b981; }
        .toast-error { background: #ef4444; }
        .toast-info { background: #3b82f6; }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { left: -260px; transition: left 0.3s; }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; gap: 0.75rem; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="sidebar-logo">🚗</div>
            </div>
            <h2>🚗 SAVANT MOTORS</h2>
            <p>Enterprise Resource Planning</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="../dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../customers/index.php" class="menu-item">👥 Customers</a>
            <a href="index.php" class="menu-item active">🚗 Pickup Reminders</a>
            <a href="../job_cards.php" class="menu-item">📋 Job Cards</a>
            <a href="../purchases/index.php" class="menu-item">🛒 Purchases</a>
            <a href="pickup_reminders_with_ai_agent.php" class="menu-item">📦 pickup Agent</a>
            <div style="margin-top: 2rem;">
                <a href="../logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1>🚗 Vehicle Pickup Management</h1>
                <p>Schedule and track vehicle pickups from workshop, home, or office</p>
            </div>
            <button class="btn-primary" onclick="openCreateModal()">
                ➕ New Pickup Reminder
            </button>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card" onclick="filterByStatus('all')">
                <div class="stat-icon" style="background: #dbeafe;">📋</div>
                <div class="stat-value"><?php echo number_format(safeGet($stats, 'total', 0)); ?></div>
                <div class="stat-label">Total Pickups</div>
            </div>
            <div class="stat-card" onclick="filterByStatus('pending')">
                <div class="stat-icon" style="background: #fed7aa;">⏰</div>
                <div class="stat-value"><?php echo number_format((safeGet($stats, 'pending', 0) + safeGet($stats, 'scheduled', 0))); ?></div>
                <div class="stat-label">Pending/Scheduled</div>
            </div>
            <div class="stat-card" onclick="filterByStatus('in_progress')">
                <div class="stat-icon" style="background: #c7d2fe;">🚚</div>
                <div class="stat-value"><?php echo number_format(safeGet($stats, 'in_progress', 0)); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card" onclick="filterByStatus('completed')">
                <div class="stat-icon" style="background: #dcfce7;">✅</div>
                <div class="stat-value"><?php echo number_format(safeGet($stats, 'completed', 0)); ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('list')">📋 List View</button>
            <button class="tab-btn" onclick="switchTab('calendar')">📅 Calendar View</button>
            <button class="tab-btn" onclick="switchTab('map')">🗺️ Map View</button>
        </div>

        <!-- List View Tab -->
        <div id="listTab" class="tab-content active">
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-group">
                    <label>🔍 Search</label>
                    <input type="text" id="searchInput" placeholder="Customer, Vehicle..." value="<?php echo htmlspecialchars(safeGet($filters, 'search', '')); ?>">
                </div>
                <div class="filter-group">
                    <label>📊 Status</label>
                    <select id="statusFilter">
                        <option value="">All</option>
                        <option value="pending" <?php echo safeGet($filters, 'status', '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="scheduled" <?php echo safeGet($filters, 'status', '') == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                        <option value="in_progress" <?php echo safeGet($filters, 'status', '') == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo safeGet($filters, 'status', '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo safeGet($filters, 'status', '') == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>📍 Pickup Type</label>
                    <select id="typeFilter">
                        <option value="">All</option>
                        <option value="workshop" <?php echo safeGet($filters, 'pickup_type', '') == 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                        <option value="home" <?php echo safeGet($filters, 'pickup_type', '') == 'home' ? 'selected' : ''; ?>>Home</option>
                        <option value="office" <?php echo safeGet($filters, 'pickup_type', '') == 'office' ? 'selected' : ''; ?>>Office</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="btn-secondary" onclick="applyFilters()">🔍 Filter</button>
                    <button class="btn-secondary" onclick="resetFilters()">🔄 Reset</button>
                </div>
            </div>

            <!-- Reminders Table -->
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reminder #</th>
                            <th>Customer</th>
                            <th>Vehicle</th>
                            <th>Pickup Date/Time</th>
                            <th>Reminder Date/Time</th>
                            <th>Pickup Type</th>
                            <th>Assigned To</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <?php if (empty($reminders['data'])): ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <div class="empty-icon">📅</div>
                                <p>No pickup reminders found</p>
                                <button class="btn-primary" onclick="openCreateModal()" style="margin-top: 1rem;">
                                    ➕ Create First Reminder
                                </button>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($reminders['data'] as $reminder): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(safeGet($reminder, 'reminder_number', 'N/A')); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars(safeGet($reminder, 'customer_name', 'N/A')); ?>
                                <br><small><?php echo htmlspecialchars(safeGet($reminder, 'customer_phone', '')); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars(safeGet($reminder, 'vehicle_reg', 'N/A')); ?></td>
                            <td>
                                <?php echo !empty($reminder['pickup_date']) ? date('d M Y', strtotime($reminder['pickup_date'])) : 'N/A'; ?>
                                <?php if (!empty($reminder['pickup_time'])): ?>
                                <br><small><?php echo formatTime12Hour($reminder['pickup_time']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo !empty($reminder['reminder_date']) ? date('d M Y', strtotime($reminder['reminder_date'])) : 'N/A'; ?>
                                <?php if (!empty($reminder['reminder_time'])): ?>
                                <br><small><?php echo formatTime12Hour($reminder['reminder_time']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="pickup-badge pickup-<?php echo safeGet($reminder, 'pickup_type', 'workshop'); ?>">
                                    <?php 
                                    $type = safeGet($reminder, 'pickup_type', 'workshop');
                                    $icon = $type == 'workshop' ? '🏢' : ($type == 'home' ? '🏠' : '💼');
                                    echo $icon . ' ' . ucfirst($type);
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($reminder['assigned_staff_name'])): ?>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;color:white;font-size:11px;font-weight:700;flex-shrink:0;">
                                        <?php echo strtoupper(substr($reminder['assigned_staff_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-size:13px;font-weight:600;color:#0f172a;"><?php echo htmlspecialchars($reminder['assigned_staff_name']); ?></div>
                                        <?php if (!empty($reminder['assigned_staff_role'])): ?>
                                        <div style="font-size:11px;color:#64748b;"><?php echo htmlspecialchars($reminder['assigned_staff_role']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">— Unassigned —</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo safeGet($reminder, 'status', 'pending'); ?>">
                                    <?php echo strtoupper(str_replace('_', ' ', safeGet($reminder, 'status', 'pending'))); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="view_reminder.php?id=<?php echo safeGet($reminder, 'id', 0); ?>" class="action-btn action-btn-view" title="View">👁️</a>
                                    <a href="edit_reminder.php?id=<?php echo safeGet($reminder, 'id', 0); ?>" class="action-btn action-btn-edit" title="Edit">✏️</a>
                                    <?php if (!safeGet($reminder, 'reminder_sent', 0) && in_array(safeGet($reminder, 'status', ''), ['pending', 'scheduled'])): ?>
                                    <button onclick="sendReminder(<?php echo safeGet($reminder, 'id', 0); ?>)" class="action-btn action-btn-send" title="Send Reminder">📧</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if (($reminders['last_page'] ?? 1) > 1): ?>
            <div class="pagination">
                <?php for($i = 1; $i <= min(5, $reminders['last_page']); $i++): ?>
                <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($filters)); ?>" class="page-link <?php echo $i == ($reminders['current_page'] ?? 1) ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Calendar View Tab -->
        <div id="calendarTab" class="tab-content">
            <div class="table-container" style="padding: 1.5rem; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 20px;">📅</div>
                <h3>Calendar View</h3>
                <p style="color: var(--gray);">Upcoming pickups are listed in the table above</p>
                <p style="color: var(--gray); font-size: 12px; margin-top: 10px;">
                    Total scheduled: <?php echo safeGet($stats, 'scheduled', 0); ?> | 
                    Pending: <?php echo safeGet($stats, 'pending', 0); ?>
                </p>
            </div>
        </div>

        <!-- Map View Tab -->
        <div id="mapTab" class="tab-content">
            <div class="table-container" style="padding: 1.5rem; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 20px;">🗺️</div>
                <h3>Map View</h3>
                <p style="color: var(--gray);">Pickup locations will appear here</p>
                <p style="color: var(--gray); font-size: 12px; margin-top: 10px;">
                    Home pickups: <?php echo count(array_filter($reminders['data'] ?? [], function($r) { return safeGet($r, 'pickup_type', '') == 'home'; })); ?> |
                    Office pickups: <?php echo count(array_filter($reminders['data'] ?? [], function($r) { return safeGet($r, 'pickup_type', '') == 'office'; })); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Create Reminder Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>➕ Create Pickup Reminder</h3>
                <button class="close-btn" onclick="closeCreateModal()" style="background: none; border: none; color: white; font-size: 1.8rem; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" action="create.php" id="reminderForm">
                <div class="modal-body">
                    <!-- Customer Selection -->
                    <div class="form-group">
                        <label>Select Customer <span class="required">*</span></label>

                        <?php if (!empty($customerFetchError)): ?>
                        <div style="background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:8px;">
                            ⚠️ Could not load customers: <?php echo htmlspecialchars($customerFetchError); ?>
                        </div>
                        <?php endif; ?>

                        <!-- Hidden real input sent to server -->
                        <input type="hidden" name="customer_id" id="customerIdInput" required>

                        <!-- Search box -->
                        <div style="position:relative;">
                            <input type="text" id="customerSearchBox"
                                   placeholder="🔍 Type name or phone to search…"
                                   autocomplete="off"
                                   style="width:100%;padding:0.55rem 0.75rem;border:1.5px solid var(--border);border-radius:0.5rem;font-size:0.85rem;font-family:inherit;"
                                   oninput="searchCustomers(this.value)"
                                   onfocus="if(this.value.length>=0) searchCustomers(this.value)">
                            <div id="customerDropdown"
                                 style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1.5px solid var(--border);
                                        border-top:none;border-radius:0 0 0.5rem 0.5rem;max-height:220px;overflow-y:auto;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                            </div>
                        </div>

                        <!-- Selected customer badge -->
                        <div id="selectedCustomerBadge" style="display:none;margin-top:6px;padding:6px 10px;background:#eff6ff;border-radius:6px;font-size:12px;color:#1e40af;display:flex;align-items:center;gap:8px;">
                            <span id="selectedCustomerName"></span>
                            <button type="button" onclick="clearCustomer()"
                                    style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;line-height:1;">✕</button>
                        </div>

                        <?php if (!empty($customers)): ?>
                        <!-- Fallback static select (hidden, used if JS is off) -->
                        <noscript>
                            <select name="customer_id" required style="width:100%;margin-top:6px;">
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo safeGet($cust,'id',''); ?>">
                                    <?php echo htmlspecialchars(safeGet($cust,'full_name','Unknown')); ?> – <?php echo htmlspecialchars(safeGet($cust,'telephone','N/A')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </noscript>
                        <?php endif; ?>
                    </div>
                    
                    <div id="customerDetails" class="info-box" style="display: none;">
                        📞 <span id="customerPhone"></span> | ✉️ <span id="customerEmail"></span>
                    </div>
                    
                    <!-- Vehicle Information -->
                    <div class="form-group">
                        <label>Vehicle Registration <span class="required">*</span></label>
                        <input type="text" name="vehicle_reg" required placeholder="e.g., UBA 123A">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Vehicle Make</label>
                            <input type="text" name="vehicle_make" placeholder="e.g., Toyota">
                        </div>
                        <div class="form-group">
                            <label>Vehicle Model</label>
                            <input type="text" name="vehicle_model" placeholder="e.g., Corolla">
                        </div>
                    </div>
                    
                    <!-- Pickup Details -->
                    <div class="form-group">
                        <label>Pickup Type <span class="required">*</span></label>
                        <select name="pickup_type" id="pickupTypeSelect" required onchange="toggleAddressFields()">
                            <option value="workshop">🏢 Workshop Pickup</option>
                            <option value="home">🏠 Home Pickup</option>
                            <option value="office">💼 Office Pickup</option>
                        </select>
                    </div>
                    
                    <div id="addressFields" style="display: none;">
                        <div class="form-group">
                            <label>Pickup Address <span class="required">*</span></label>
                            <textarea name="pickup_address" id="pickupAddress" rows="2" placeholder="Enter full address for pickup"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Location Details (Landmarks)</label>
                            <textarea name="pickup_location_details" rows="2" placeholder="Gate color, nearby landmark, special instructions..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Pickup Date & Time (12-hour format) -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Pickup Date <span class="required">*</span></label>
                            <input type="date" name="pickup_date" id="pickupDate" required>
                        </div>
                        <div class="form-group">
                            <label>Pickup Time (12-hour)</label>
                            <div style="display: flex; gap: 5px;">
                                <select name="pickup_time_hour" id="pickupTimeHour" style="width: 33%;">
                                    <option value="">--</option>
                                    <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo sprintf("%02d", $i); ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="pickup_time_minute" id="pickupTimeMinute" style="width: 33%;">
                                    <option value="">--</option>
                                    <option value="00">00</option>
                                    <option value="15">15</option>
                                    <option value="30">30</option>
                                    <option value="45">45</option>
                                </select>
                                <select name="pickup_ampm" id="pickupAmPm" style="width: 34%;">
                                    <option value="AM">AM</option>
                                    <option value="PM" selected>PM</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reminder Date & Time (12-hour format) -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Reminder Date <span class="required">*</span></label>
                            <input type="date" name="reminder_date" id="reminderDate" required>
                        </div>
                        <div class="form-group">
                            <label>Reminder Time (12-hour)</label>
                            <div style="display: flex; gap: 5px;">
                                <select name="reminder_time_hour" id="reminderTimeHour" style="width: 33%;">
                                    <option value="">--</option>
                                    <?php for($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $i == 9 ? 'selected' : ''; ?>><?php echo sprintf("%02d", $i); ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select name="reminder_time_minute" id="reminderTimeMinute" style="width: 33%;">
                                    <option value="">--</option>
                                    <option value="00" selected>00</option>
                                    <option value="15">15</option>
                                    <option value="30">30</option>
                                    <option value="45">45</option>
                                </select>
                                <select name="reminder_ampm" id="reminderAmPm" style="width: 34%;">
                                    <option value="AM" selected>AM</option>
                                    <option value="PM">PM</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Staff Assignment - Searchable -->
                    <div class="form-group">
                        <label>👤 Assign To (Staff)</label>
                        <input type="hidden" name="assigned_to" id="staffIdInput">
                        <div style="position:relative;">
                            <input type="text" id="staffSearchBox"
                                   placeholder="🔍 Search staff by name or role…"
                                   autocomplete="off"
                                   style="width:100%;padding:0.55rem 0.75rem;border:1.5px solid var(--border);border-radius:0.5rem;font-size:0.85rem;font-family:inherit;"
                                   oninput="searchStaff(this.value)"
                                   onfocus="searchStaff(this.value)">
                            <div id="staffDropdown"
                                 style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1.5px solid var(--border);
                                        border-top:none;border-radius:0 0 0.5rem 0.5rem;max-height:220px;overflow-y:auto;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.1);">
                            </div>
                        </div>
                        <!-- Selected staff badge -->
                        <div id="selectedStaffBadge" style="display:none;margin-top:6px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;display:flex;align-items:center;gap:10px;">
                            <div id="staffBadgeAvatar" style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;color:white;font-size:13px;font-weight:700;flex-shrink:0;"></div>
                            <div style="flex:1;">
                                <div id="staffBadgeName" style="font-size:13px;font-weight:700;color:#0f172a;"></div>
                                <div id="staffBadgeRole" style="font-size:11px;color:#64748b;"></div>
                            </div>
                            <button type="button" onclick="clearStaff()"
                                    style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:16px;line-height:1;" title="Remove assignment">✕</button>
                        </div>
                        <div style="margin-top:4px;font-size:11px;color:#94a3b8;">Leave blank to leave unassigned</div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any special instructions or notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" name="add_reminder" class="btn-primary">Create Reminder</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Simple toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        // Display session messages
        <?php if (isset($_SESSION['success'])): ?>
        showToast('<?php echo addslashes($_SESSION['success']); ?>', 'success');
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        showToast('<?php echo addslashes($_SESSION['error']); ?>', 'error');
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.target.classList.add('active');
        }
        
        // Modal functions
        function openCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        // Filter functions
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            
            let url = 'index.php?';
            if (search) url += `search=${encodeURIComponent(search)}&`;
            if (status) url += `status=${encodeURIComponent(status)}&`;
            if (type) url += `pickup_type=${encodeURIComponent(type)}&`;
            
            window.location.href = url;
        }
        
        function resetFilters() {
            window.location.href = 'index.php';
        }
        
        function filterByStatus(status) {
            window.location.href = `index.php?status=${status === 'all' ? '' : status}`;
        }
        
        // Send reminder
        function sendReminder(id) {
            if (confirm('Send reminder notification to customer?')) {
                showToast('Sending reminder...', 'info');
                setTimeout(() => {
                    showToast('Reminder sent successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                }, 1000);
            }
        }
        
        // Toggle address fields
        function toggleAddressFields() {
            const type = document.getElementById('pickupTypeSelect').value;
            const addressFields = document.getElementById('addressFields');
            if (type === 'workshop') {
                addressFields.style.display = 'none';
            } else {
                addressFields.style.display = 'block';
            }
        }
        
        // ── Live customer search ─────────────────────────────────────────
        // Pre-load from PHP for instant first results (avoids round-trip on open)
        const preloadedCustomers = <?php echo json_encode(array_map(function($c) {
            return [
                'id'        => $c['id'],
                'full_name' => $c['full_name'],
                'telephone' => $c['telephone'] ?? '',
                'email'     => $c['email']     ?? '',
                'address'   => $c['address']   ?? '',
            ];
        }, $customers)); ?>;

        let searchTimer = null;
        let currentCustomers = preloadedCustomers;

        function searchCustomers(query) {
            clearTimeout(searchTimer);
            query = query.trim();

            // Filter preloaded list immediately for snappy UX
            const q = query.toLowerCase();
            const local = preloadedCustomers.filter(c =>
                c.full_name.toLowerCase().includes(q) ||
                (c.telephone || '').toLowerCase().includes(q) ||
                (c.email     || '').toLowerCase().includes(q)
            );
            renderCustomerDropdown(local, query);

            // Also hit the server for a fresher / larger result set
            searchTimer = setTimeout(async () => {
                try {
                    const resp = await fetch(`index.php?ajax=search_customers&q=${encodeURIComponent(query)}`);
                    const data = await resp.json();
                    if (data.success && data.customers.length) {
                        currentCustomers = data.customers;
                        renderCustomerDropdown(data.customers, query);
                    }
                } catch(e) { /* silent – local results already shown */ }
            }, 280);
        }

        function renderCustomerDropdown(list, query) {
            const dropdown = document.getElementById('customerDropdown');
            if (!dropdown) return;

            if (!list.length) {
                dropdown.innerHTML = '<div style="padding:10px 14px;color:#64748b;font-size:13px;">No customers found</div>';
                dropdown.style.display = 'block';
                return;
            }

            const hl = (text, q) => {
                if (!q) return text;
                return text.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
                    '<mark style="background:#fef08a;border-radius:2px;">$1</mark>');
            };

            dropdown.innerHTML = list.map(c => `
                <div onclick="selectCustomer(${c.id},'${(c.full_name||'').replace(/'/g,"\\'")}','${(c.telephone||'').replace(/'/g,"\\'")}','${(c.email||'').replace(/'/g,"\\'")}','${(c.address||'').replace(/'/g,"\\'")}')"
                     style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;transition:background 0.15s;"
                     onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                    <div style="font-weight:600;color:#0f172a;">${hl(c.full_name || 'Unknown', query)}</div>
                    <div style="color:#64748b;font-size:11px;margin-top:2px;">
                        📞 ${hl(c.telephone || 'N/A', query)}
                        ${c.email ? ' · ✉️ ' + hl(c.email, query) : ''}
                    </div>
                </div>
            `).join('');
            dropdown.style.display = 'block';
        }

        function selectCustomer(id, name, phone, email, address) {
            document.getElementById('customerIdInput').value   = id;
            document.getElementById('customerSearchBox').value = name + (phone ? ' — ' + phone : '');
            document.getElementById('customerDropdown').style.display = 'none';

            // Show badge
            const badge = document.getElementById('selectedCustomerBadge');
            document.getElementById('selectedCustomerName').textContent = name + (phone ? ' · ' + phone : '');
            if (badge) badge.style.display = 'flex';

            // Populate details panel
            const details = document.getElementById('customerDetails');
            if (details) {
                details.style.display = 'block';
                document.getElementById('customerPhone').textContent = phone || 'N/A';
                document.getElementById('customerEmail').textContent = email || 'N/A';
            }

            // Auto-fill address if pickup is not workshop
            const pickupType = document.getElementById('pickupTypeSelect');
            const pickupAddr = document.getElementById('pickupAddress');
            if (pickupType && pickupType.value !== 'workshop' && address && address !== 'null' && pickupAddr) {
                pickupAddr.value = address;
            }
        }

        function clearCustomer() {
            document.getElementById('customerIdInput').value   = '';
            document.getElementById('customerSearchBox').value = '';
            const badge = document.getElementById('selectedCustomerBadge');
            if (badge) badge.style.display = 'none';
            const details = document.getElementById('customerDetails');
            if (details) details.style.display = 'none';
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const box  = document.getElementById('customerSearchBox');
            const drop = document.getElementById('customerDropdown');
            if (drop && box && !box.contains(e.target) && !drop.contains(e.target)) {
                drop.style.display = 'none';
            }
        });

        // Open dropdown on focus
        document.getElementById('customerSearchBox')?.addEventListener('focus', function() {
            searchCustomers(this.value);
        });
        
        // Set default dates
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        
        const pickupDateInput = document.getElementById('pickupDate');
        const reminderDateInput = document.getElementById('reminderDate');
        
        if (pickupDateInput) pickupDateInput.value = today.toISOString().split('T')[0];
        if (reminderDateInput) reminderDateInput.value = tomorrow.toISOString().split('T')[0];
        
        // Enter key search
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
        
        // Close modal on outside click
        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        }
        
        // ── Live staff search ─────────────────────────────────────────────
        const preloadedStaff = <?php echo json_encode(array_map(function($s) {
            return [
                'id'        => $s['id'],
                'full_name' => $s['full_name'],
                'role'      => $s['role']      ?? '',
                'position'  => $s['position']  ?? '',
                'email'     => $s['email']     ?? '',
            ];
        }, $staff)); ?>;

        let staffSearchTimer = null;

        function searchStaff(query) {
            clearTimeout(staffSearchTimer);
            query = query.trim();
            const q = query.toLowerCase();
            const local = preloadedStaff.filter(s =>
                s.full_name.toLowerCase().includes(q) ||
                (s.role     || '').toLowerCase().includes(q) ||
                (s.position || '').toLowerCase().includes(q) ||
                (s.email    || '').toLowerCase().includes(q)
            );
            renderStaffDropdown(local, query);

            staffSearchTimer = setTimeout(async () => {
                try {
                    const resp = await fetch(`index.php?ajax=search_staff&q=${encodeURIComponent(query)}`);
                    const data = await resp.json();
                    if (data.success && data.staff.length) {
                        renderStaffDropdown(data.staff, query);
                    }
                } catch(e) { /* silent */ }
            }, 280);
        }

        function renderStaffDropdown(list, query) {
            const dropdown = document.getElementById('staffDropdown');
            if (!dropdown) return;

            // Always show unassign option at top
            const unassignHtml = `
                <div onclick="selectStaff('','','','')"
                     style="padding:9px 14px;cursor:pointer;border-bottom:2px solid #f1f5f9;font-size:13px;color:#64748b;font-style:italic;transition:background 0.15s;"
                     onmouseover="this.style.background='#fef9c3'" onmouseout="this.style.background=''">
                    ✕ Leave unassigned
                </div>
            `;

            if (!list.length) {
                dropdown.innerHTML = unassignHtml + '<div style="padding:10px 14px;color:#64748b;font-size:13px;">No staff found</div>';
                dropdown.style.display = 'block';
                return;
            }

            const hl = (text, q) => {
                if (!q || !text) return text || '';
                return text.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
                    '<mark style="background:#fef08a;border-radius:2px;">$1</mark>');
            };

            // Role color mapping
            const roleColor = role => {
                const r = (role || '').toLowerCase();
                if (r.includes('admin'))   return '#7c3aed';
                if (r.includes('manager')) return '#0284c7';
                if (r.includes('tech'))    return '#d97706';
                if (r.includes('driver'))  return '#059669';
                return '#64748b';
            };

            dropdown.innerHTML = unassignHtml + list.map(s => {
                const initials = (s.full_name || '?').split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
                const roleLabel = s.role || s.position || 'Staff';
                const rColor = roleColor(roleLabel);
                return `
                <div onclick="selectStaff(${s.id},'${(s.full_name||'').replace(/'/g,"\\'")}','${(roleLabel).replace(/'/g,"\\'")}','${(s.email||'').replace(/'/g,"\\'")}')"
                     style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;display:flex;align-items:center;gap:10px;transition:background 0.15s;"
                     onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''">
                    <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,${rColor},#0f172a44);display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:700;flex-shrink:0;">
                        ${initials}
                    </div>
                    <div>
                        <div style="font-weight:600;color:#0f172a;">${hl(s.full_name || 'Unknown', query)}</div>
                        <div style="font-size:11px;margin-top:2px;">
                            <span style="background:${rColor}20;color:${rColor};padding:1px 7px;border-radius:20px;font-weight:600;">${hl(roleLabel, query)}</span>
                            ${s.email ? ' <span style="color:#94a3b8;">· ' + hl(s.email, query) + '</span>' : ''}
                        </div>
                    </div>
                </div>`;
            }).join('');
            dropdown.style.display = 'block';
        }

        function selectStaff(id, name, role, email) {
            document.getElementById('staffIdInput').value   = id;
            document.getElementById('staffSearchBox').value = name ? name + (role ? ' — ' + role : '') : '';
            document.getElementById('staffDropdown').style.display = 'none';

            const badge = document.getElementById('selectedStaffBadge');
            if (!id) {
                badge.style.display = 'none';
                return;
            }
            const initials = name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
            document.getElementById('staffBadgeAvatar').textContent = initials;
            document.getElementById('staffBadgeName').textContent   = name;
            document.getElementById('staffBadgeRole').textContent   = role || 'Staff';
            badge.style.display = 'flex';
        }

        function clearStaff() {
            document.getElementById('staffIdInput').value   = '';
            document.getElementById('staffSearchBox').value = '';
            document.getElementById('selectedStaffBadge').style.display = 'none';
        }

        // Close staff dropdown on outside click
        document.addEventListener('click', function(e) {
            const box  = document.getElementById('staffSearchBox');
            const drop = document.getElementById('staffDropdown');
            if (drop && box && !box.contains(e.target) && !drop.contains(e.target)) {
                drop.style.display = 'none';
            }
        });

        // Initialize
        toggleAddressFields();
    </script>
</body>
</html>