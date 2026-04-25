<?php
// dashboard_erp.php – Modern ERP Dashboard with Overdue Tool Alerts & Sales Funnel + Attendance + Voice Announcements (DB Settings)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'] ?? 1;
$user_full_name = $_SESSION['full_name'] ?? 'User';

date_default_timezone_set('Africa/Kampala');

// Initialise default values
$job_stats = ['total_jobs' => 0, 'pending_jobs' => 0, 'in_progress_jobs' => 0, 'completed_jobs' => 0, 'overdue_jobs' => 0];
$quote_stats = ['total_quotations' => 0, 'pending_quotations' => 0, 'approved_quotations' => 0, 'total_value' => 0];
$invoice_stats = ['total_invoices' => 0, 'paid_invoices' => 0, 'unpaid_invoices' => 0, 'total_amount' => 0, 'collected_amount' => 0, 'outstanding_amount' => 0];
$cash_stats = ['total_cash' => 0, 'total_bank' => 0, 'total_mobile' => 0, 'total_balance' => 0];
$cash_flow = ['income' => 0, 'expenses' => 0];
$inventory_stats = ['total_products' => 0, 'total_items' => 0, 'low_stock_items' => 0, 'inventory_value' => 0];
$low_stock_items = [];
$tool_stats = ['total_tools' => 0, 'available_tools' => 0, 'assigned_tools' => 0, 'maintenance_tools' => 0, 'total_value' => 0];
$tool_request_stats = ['total_requests' => 0, 'pending_requests' => 0, 'emergency_pending' => 0];
$technician_stats = ['total_technicians' => 0, 'active_technicians' => 0, 'blocked_technicians' => 0];
$customer_stats = ['total_customers' => 0, 'new_customers' => 0];
$reminder_stats = ['total_reminders' => 0, 'pending_reminders' => 0];
$service_stats = ['total_services' => 0, 'avg_price' => 0];
$recent_jobs = [];
$recent_quotes = [];
$recent_invoices = [];
$pending_requests = [];
$overdue_assignments = [];
$current_assignments = [];
$chart_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$chart_revenue = array_fill(0, 7, 0);
$chart_jobs = array_fill(0, 7, 0);
$alerts = [];

// ATTENDANCE VARIABLES
$today_attendance = [];
$absent_staff = [];
$month_stats = ['total_staff_present' => 0, 'total_checkins' => 0, 'completed_checkouts' => 0, 'avg_hours' => 0];
$today_stats = ['checked_in' => 0, 'checked_out' => 0, 'still_working' => 0, 'absent' => 0, 'total_staff' => 0];
$recent_attendance = [];

// Sales Funnel with source filter
$selected_source = $_GET['funnel_source'] ?? 'All';
$funnel_data = [];
$sources = [];

// Voice setting from database (default enabled)
$voice_enabled = true;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ============================================
    // FETCH VOICE SETTING FROM DATABASE
    // ============================================
    $saved_announcements = [];
    $saved_accent        = 'neutral';
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS user_voice_settings (
                user_id      INT PRIMARY KEY,
                voice_enabled TINYINT(1) NOT NULL DEFAULT 1,
                accent       VARCHAR(30) NOT NULL DEFAULT 'neutral',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        try { $conn->exec("ALTER TABLE user_voice_settings ADD COLUMN accent VARCHAR(30) NOT NULL DEFAULT 'neutral'"); } catch(PDOException $e2) {}

        $stmt = $conn->prepare("SELECT voice_enabled, accent FROM user_voice_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $voiceSetting = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($voiceSetting) {
            $voice_enabled = (bool)$voiceSetting['voice_enabled'];
            $saved_accent  = $voiceSetting['accent'] ?? 'neutral';
        } else {
            $stmt = $conn->prepare("INSERT INTO user_voice_settings (user_id, voice_enabled, accent) VALUES (?, 1, 'neutral')");
            $stmt->execute([$user_id]);
            $voice_enabled = true;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS voice_announcements (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                user_id          INT NOT NULL,
                title            VARCHAR(120) NOT NULL,
                message          TEXT NOT NULL,
                is_enabled       TINYINT(1) NOT NULL DEFAULT 1,
                interval_minutes INT NOT NULL DEFAULT 0,
                repeat_count     INT NOT NULL DEFAULT 1,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        // Add new columns if upgrading from older schema
        foreach (['interval_minutes INT NOT NULL DEFAULT 0', 'repeat_count INT NOT NULL DEFAULT 1'] as $colDef) {
            $colName = explode(' ', $colDef)[0];
            try { $conn->exec("ALTER TABLE voice_announcements ADD COLUMN $colDef"); } catch(PDOException $e2) {}
        }
        $stmt = $conn->prepare("SELECT * FROM voice_announcements WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $saved_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Voice settings error: " . $e->getMessage());
        $voice_enabled = true;
    }

    // Helper functions
    function getInventoryColumns($conn) {
        try {
            $stmt = $conn->query("SHOW COLUMNS FROM inventory");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stockCol = null;
            $reorderCol = null;
            $costCol = null;
            foreach (['current_stock', 'quantity', 'stock', 'stock_quantity', 'qty'] as $c) {
                if (in_array($c, $columns)) { $stockCol = $c; break; }
            }
            foreach (['reorder_level', 'min_stock', 'reorder_point', 'alert_level', 'min_qty'] as $c) {
                if (in_array($c, $columns)) { $reorderCol = $c; break; }
            }
            foreach (['cost_price', 'unit_cost', 'price', 'purchase_price'] as $c) {
                if (in_array($c, $columns)) { $costCol = $c; break; }
            }
            return ['stock' => $stockCol, 'reorder' => $reorderCol, 'cost' => $costCol];
        } catch (PDOException $e) {
            return ['stock' => null, 'reorder' => null, 'cost' => null];
        }
    }

    // Job cards
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_jobs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
            SUM(CASE WHEN date_promised < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_jobs
        FROM job_cards WHERE deleted_at IS NULL
    ");
    $job_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $job_stats;

    $stmt = $conn->query("
        SELECT jc.*, c.full_name as customer_name 
        FROM job_cards jc LEFT JOIN customers c ON jc.customer_id = c.id
        WHERE jc.deleted_at IS NULL ORDER BY jc.created_at DESC LIMIT 5
    ");
    $recent_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Quotations
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_quotations,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_quotations,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_quotations,
            COALESCE(SUM(total_amount), 0) as total_value
        FROM quotations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $quote_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $quote_stats;

    $stmt = $conn->query("
        SELECT q.*, c.full_name as customer_name 
        FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id
        ORDER BY q.created_at DESC LIMIT 5
    ");
    $recent_quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Invoices
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_invoices,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
            SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_invoices,
            COALESCE(SUM(total_amount), 0) as total_amount,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as collected_amount,
            COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN (total_amount - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as outstanding_amount
        FROM invoices WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $invoice_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $invoice_stats;

    $stmt = $conn->query("
        SELECT i.*, c.full_name as customer_name 
        FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id
        ORDER BY i.created_at DESC LIMIT 5
    ");
    $recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cash Management
    try {
        $stmt = $conn->query("
            SELECT 
                COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
                COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
                COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
                COALESCE(SUM(balance), 0) as total_balance
            FROM cash_accounts WHERE is_active = 1
        ");
        $cash_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $cash_stats;
    } catch (PDOException $e) {
        error_log("Cash accounts query failed: " . $e->getMessage());
    }

    try {
        $colCheck = $conn->query("SHOW COLUMNS FROM cash_transactions LIKE 'amount'");
        if ($colCheck->rowCount() > 0) {
            $stmt = $conn->query("
                SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expenses
                FROM cash_transactions WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'approved'
            ");
            $cash_flow = $stmt->fetch(PDO::FETCH_ASSOC) ?: $cash_flow;
        }
    } catch (PDOException $e) {
        error_log("Cash flow query failed: " . $e->getMessage());
    }

    // Inventory
    $invCols = getInventoryColumns($conn);
    if ($invCols['stock'] && $invCols['reorder'] && $invCols['cost']) {
        $stockCol = $invCols['stock'];
        $reorderCol = $invCols['reorder'];
        $costCol = $invCols['cost'];
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_products,
                SUM({$stockCol}) as total_items,
                SUM(CASE WHEN {$stockCol} <= {$reorderCol} THEN 1 ELSE 0 END) as low_stock_items,
                COALESCE(SUM({$stockCol} * {$costCol}), 0) as inventory_value
            FROM inventory WHERE is_active = 1
        ");
        $inventory_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $inventory_stats;

        $stmt = $conn->query("
            SELECT product_name, sku, {$stockCol} as current_stock, {$reorderCol} as reorder_level
            FROM inventory WHERE is_active = 1 AND {$stockCol} <= {$reorderCol} ORDER BY {$stockCol} ASC LIMIT 5
        ");
        $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Tools
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_tools,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_tools,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_tools,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_tools,
            COALESCE(SUM(purchase_price), 0) as total_value
        FROM tools WHERE is_active = 1
    ");
    $tool_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tool_stats;

    // Get CURRENTLY ASSIGNED TOOLS
    $stmt = $conn->query("
        SELECT 
            ta.id as assignment_id,
            ta.assigned_date,
            ta.expected_return_date,
            t.tool_code,
            t.tool_name,
            t.category,
            tech.full_name as technician_name,
            tech.technician_code,
            DATEDIFF(NOW(), ta.assigned_date) as days_assigned,
            CASE 
                WHEN ta.expected_return_date < CURDATE() AND ta.actual_return_date IS NULL THEN 'Overdue'
                ELSE 'Active'
            END as assignment_status
        FROM tool_assignments ta
        INNER JOIN tools t ON ta.tool_id = t.id
        INNER JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.actual_return_date IS NULL
        ORDER BY 
            CASE WHEN ta.expected_return_date < CURDATE() THEN 1 ELSE 0 END DESC,
            ta.assigned_date DESC
        LIMIT 10
    ");
    $current_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tool requests
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN urgency = 'emergency' AND status = 'pending' THEN 1 ELSE 0 END) as emergency_pending
        FROM tool_requests
    ");
    $tool_request_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tool_request_stats;

    $stmt = $conn->query("
        SELECT tr.*, t.full_name as technician_name, tl.tool_name
        FROM tool_requests tr
        JOIN technicians t ON tr.technician_id = t.id
        LEFT JOIN tools tl ON tr.tool_id = tl.id
        WHERE tr.status = 'pending'
        ORDER BY CASE tr.urgency WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END, tr.created_at ASC
        LIMIT 5
    ");
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Overdue tool assignments
    try {
        $conn->query("SELECT 1 FROM tool_assignments LIMIT 1");
        $overdue_query = "
            SELECT ta.id AS assignment_id,
                   ta.assigned_date,
                   t.full_name AS technician_name,
                   tl.tool_name,
                   tl.tool_code,
                   DATEDIFF(NOW(), ta.assigned_date) AS days_overdue
            FROM tool_assignments ta
            JOIN technicians t ON ta.technician_id = t.id
            JOIN tools tl ON ta.tool_id = tl.id
            WHERE ta.actual_return_date IS NULL
              AND ta.status = 'assigned'
              AND ta.expected_return_date < CURDATE()
        ";
        if ($user_role == 'technician') {
            $stmt = $conn->prepare("SELECT id FROM technicians WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $tech_id = $stmt->fetchColumn();
            $overdue_query .= " AND ta.technician_id = ?";
            $stmt_overdue = $conn->prepare($overdue_query);
            $stmt_overdue->execute([$tech_id]);
        } else {
            $stmt_overdue = $conn->prepare($overdue_query);
            $stmt_overdue->execute();
        }
        $overdue_assignments = $stmt_overdue->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("tool_assignments table missing: " . $e->getMessage());
    }

    // Technicians
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_technicians,
            SUM(CASE WHEN status = 'active' AND is_blocked = 0 THEN 1 ELSE 0 END) as active_technicians,
            SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked_technicians
        FROM technicians WHERE deleted_at IS NULL
    ");
    $technician_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $technician_stats;

    // Customers
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_customers,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_customers
        FROM customers WHERE status = 1
    ");
    $customer_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $customer_stats;

    // Pickup reminders
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_reminders,
            SUM(CASE WHEN reminder_sent = 0 AND reminder_date <= CURDATE() THEN 1 ELSE 0 END) as pending_reminders
        FROM vehicle_pickup_reminders WHERE status = 'pending'
    ");
    $reminder_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $reminder_stats;

    // Services
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_services,
            COALESCE(AVG(standard_price), 0) as avg_price
        FROM services WHERE is_active = 1
    ");
    $service_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $service_stats;

    // Weekly performance
    $chart_labels = [];
    $chart_revenue = [];
    $chart_jobs = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_labels[] = date('D', strtotime($date));

        $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM invoices WHERE DATE(created_at) = ?");
        $stmt->execute([$date]);
        $chart_revenue[] = $stmt->fetch(PDO::FETCH_ASSOC)['revenue'] ?? 0;

        $stmt = $conn->prepare("SELECT COUNT(*) as jobs FROM job_cards WHERE DATE(date_completed) = ? AND status = 'completed'");
        $stmt->execute([$date]);
        $chart_jobs[] = $stmt->fetch(PDO::FETCH_ASSOC)['jobs'] ?? 0;
    }

    // ============================================
    // ATTENDANCE SECTION
    // ============================================
    $today = date('Y-m-d');
    
    $all_staff_count = 0;
    try {
        $staffCountStmt = $conn->query("
            SELECT COUNT(*) as total 
            FROM staff 
            WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
              AND status = 'active' 
              AND is_blocked = 0
        ");
        $staff_result = $staffCountStmt->fetch(PDO::FETCH_ASSOC);
        $all_staff_count = $staff_result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Staff count error: " . $e->getMessage());
    }
    
    try {
        $techCountStmt = $conn->query("
            SELECT COUNT(*) as total 
            FROM technicians 
            WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') 
              AND status = 'active' 
              AND is_blocked = 0
        ");
        $tech_result = $techCountStmt->fetch(PDO::FETCH_ASSOC);
        $all_staff_count += $tech_result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Tech count error: " . $e->getMessage());
    }
    
    $staff_attendance = [];
    try {
        $attendanceStmt = $conn->prepare("
            SELECT 
                sa.*,
                s.full_name,
                s.staff_code,
                s.position,
                s.department,
                'staff' as type
            FROM staff_attendance sa
            LEFT JOIN staff s ON sa.staff_id = s.id
            WHERE sa.attendance_date = :today
            ORDER BY sa.check_in_time DESC
        ");
        $attendanceStmt->execute([':today' => $today]);
        $staff_attendance = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Staff attendance error: " . $e->getMessage());
    }
    
    $tech_attendance = [];
    try {
        $techAttendanceStmt = $conn->prepare("
            SELECT 
                sa.*,
                t.full_name,
                t.technician_code as staff_code,
                t.specialization as position,
                'Technician' as department,
                'technician' as type
            FROM staff_attendance sa
            LEFT JOIN technicians t ON sa.staff_id = t.id
            WHERE sa.attendance_date = :today AND sa.staff_id IN (SELECT id FROM technicians)
            ORDER BY sa.check_in_time DESC
        ");
        $techAttendanceStmt->execute([':today' => $today]);
        $tech_attendance = $techAttendanceStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Tech attendance error: " . $e->getMessage());
    }
    
    $today_attendance = array_merge($staff_attendance, $tech_attendance);
    
    $absent_staff = [];
    try {
        $absentStaffStmt = $conn->prepare("
            SELECT s.id, s.full_name, s.staff_code, s.position, s.department, 'staff' as type
            FROM staff s
            WHERE (s.deleted_at IS NULL OR s.deleted_at = '0000-00-00 00:00:00')
              AND s.status = 'active'
              AND s.is_blocked = 0
              AND s.id NOT IN (
                  SELECT staff_id FROM staff_attendance 
                  WHERE attendance_date = :today
              )
            ORDER BY s.full_name
            LIMIT 10
        ");
        $absentStaffStmt->execute([':today' => $today]);
        $absent_staff = $absentStaffStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Absent staff error: " . $e->getMessage());
    }
    
    $absent_techs = [];
    try {
        $absentTechStmt = $conn->prepare("
            SELECT t.id, t.full_name, t.technician_code as staff_code, t.specialization as position, 'Technician' as department, 'technician' as type
            FROM technicians t
            WHERE (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')
              AND t.status = 'active'
              AND t.is_blocked = 0
              AND t.id NOT IN (
                  SELECT staff_id FROM staff_attendance 
                  WHERE attendance_date = :today
              )
            ORDER BY t.full_name
            LIMIT 10
        ");
        $absentTechStmt->execute([':today' => $today]);
        $absent_techs = $absentTechStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Absent techs error: " . $e->getMessage());
    }
    
    $absent_staff = array_merge($absent_staff, $absent_techs);
    
    $month_stats = ['total_staff_present' => 0, 'total_checkins' => 0, 'completed_checkouts' => 0, 'avg_hours' => 0];
    try {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        
        $monthStatsStmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT staff_id) as total_staff_present,
                COUNT(*) as total_checkins,
                COUNT(CASE WHEN check_out_time IS NOT NULL THEN 1 END) as completed_checkouts,
                COALESCE(AVG(TIMESTAMPDIFF(HOUR, check_in_time, COALESCE(check_out_time, NOW()))), 0) as avg_hours
            FROM staff_attendance
            WHERE attendance_date BETWEEN :start AND :end
        ");
        $monthStatsStmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
        $month_stats = $monthStatsStmt->fetch(PDO::FETCH_ASSOC) ?: $month_stats;
    } catch (PDOException $e) {
        error_log("Month stats error: " . $e->getMessage());
    }
    
    $today_stats = [
        'checked_in' => count($today_attendance),
        'checked_out' => count(array_filter($today_attendance, function($a) { return !is_null($a['check_out_time']); })),
        'still_working' => count(array_filter($today_attendance, function($a) { return is_null($a['check_out_time']); })),
        'absent' => count($absent_staff),
        'total_staff' => $all_staff_count
    ];
    
    $recent_attendance = [];
    try {
        $recentAttStmt = $conn->prepare("
            SELECT 
                attendance_date,
                COUNT(*) as checkins
            FROM staff_attendance
            WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY attendance_date
            ORDER BY attendance_date DESC
            LIMIT 5
        ");
        $recentAttStmt->execute();
        $recent_attendance = $recentAttStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent attendance error: " . $e->getMessage());
    }

    // ============================================
    // SALES FUNNEL WITH CUSTOMER SOURCE
    // ============================================
    try {
        $colCheck = $conn->query("SHOW COLUMNS FROM customers LIKE 'source'");
        if ($colCheck->rowCount() > 0) {
            $stmt = $conn->query("SELECT DISTINCT source FROM customers WHERE source IS NOT NULL AND source != '' ORDER BY source");
            $sources = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        error_log("Source column check failed: " . $e->getMessage());
    }
    if (empty($sources)) {
        $sources = ['Walk-in', 'Referral', 'Social Media', 'Online Ad', 'Existing Customer'];
    }

    if ($selected_source === 'All') {
        $stmt = $conn->query("
            SELECT 
                COUNT(DISTINCT c.id) as total_customers,
                COUNT(DISTINCT jc.customer_id) as job_card_customers,
                COUNT(DISTINCT q.customer_id) as quotation_customers,
                COUNT(DISTINCT i.customer_id) as invoice_customers
            FROM customers c
            LEFT JOIN job_cards jc ON c.id = jc.customer_id AND jc.deleted_at IS NULL
            LEFT JOIN quotations q ON c.id = q.customer_id AND q.status = 'approved'
            LEFT JOIN invoices i ON c.id = i.customer_id AND i.payment_status = 'paid'
            WHERE c.status = 1
        ");
        $funnel_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(DISTINCT c.id) as total_customers,
                COUNT(DISTINCT jc.customer_id) as job_card_customers,
                COUNT(DISTINCT q.customer_id) as quotation_customers,
                COUNT(DISTINCT i.customer_id) as invoice_customers
            FROM customers c
            LEFT JOIN job_cards jc ON c.id = jc.customer_id AND jc.deleted_at IS NULL
            LEFT JOIN quotations q ON c.id = q.customer_id AND q.status = 'approved'
            LEFT JOIN invoices i ON c.id = i.customer_id AND i.payment_status = 'paid'
            WHERE c.status = 1 AND c.source = :source
        ");
        $stmt->execute([':source' => $selected_source]);
        $funnel_data = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Alerts
    if ($job_stats['overdue_jobs'] > 0) $alerts[] = ['type' => 'danger', 'message' => "{$job_stats['overdue_jobs']} job(s) are overdue"];
    if ($tool_request_stats['emergency_pending'] > 0) $alerts[] = ['type' => 'danger', 'message' => "{$tool_request_stats['emergency_pending']} emergency tool request(s) pending"];
    if ($inventory_stats['low_stock_items'] > 0) $alerts[] = ['type' => 'warning', 'message' => "{$inventory_stats['low_stock_items']} product(s) low in stock"];
    if ($tool_request_stats['pending_requests'] > 0) $alerts[] = ['type' => 'warning', 'message' => "{$tool_request_stats['pending_requests']} tool request(s) awaiting approval"];
    if ($quote_stats['pending_quotations'] > 0) $alerts[] = ['type' => 'info', 'message' => "{$quote_stats['pending_quotations']} quotation(s) pending approval"];
    if ($invoice_stats['unpaid_invoices'] > 0) $alerts[] = ['type' => 'warning', 'message' => "{$invoice_stats['unpaid_invoices']} invoice(s) unpaid"];
    if ($reminder_stats['pending_reminders'] > 0) $alerts[] = ['type' => 'info', 'message' => "{$reminder_stats['pending_reminders']} vehicle(s) waiting for pickup reminder"];
    if ($tool_stats['maintenance_tools'] > 0) $alerts[] = ['type' => 'warning', 'message' => "{$tool_stats['maintenance_tools']} tool(s) need maintenance"];
    if (count($overdue_assignments) > 0) $alerts[] = ['type' => 'danger', 'message' => count($overdue_assignments) . " tool(s) overdue – return required"];

} catch(PDOException $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $alerts[] = ['type' => 'danger', 'message' => 'Database error: ' . $e->getMessage()];
}

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle return tool action
    if (isset($_POST['action']) && $_POST['action'] === 'return_tool') {
        header('Content-Type: application/json');
        $assignment_id = (int)$_POST['assignment_id'];
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("
                UPDATE tool_assignments 
                SET actual_return_date = NOW(), 
                    status = 'returned', 
                    is_overdue = 0 
                WHERE id = ? AND actual_return_date IS NULL
            ");
            $stmt->execute([$assignment_id]);
            if ($stmt->rowCount() > 0) {
                $stmt2 = $conn->prepare("
                    UPDATE tools t
                    JOIN tool_assignments ta ON t.id = ta.tool_id
                    SET t.status = 'available'
                    WHERE ta.id = ? AND t.status = 'assigned'
                ");
                $stmt2->execute([$assignment_id]);
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Tool returned successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Tool already returned or not found']);
            }
        } catch(Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // Handle update voice setting action
    if (isset($_POST['action']) && $_POST['action'] === 'update_voice_setting') {
        header('Content-Type: application/json');
        $voice_enabled_val = isset($_POST['voice_enabled']) ? (int)$_POST['voice_enabled'] : 1;
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS user_voice_settings (user_id INT PRIMARY KEY, voice_enabled TINYINT(1) NOT NULL DEFAULT 1, accent VARCHAR(30) NOT NULL DEFAULT 'neutral', FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $stmt = $conn->prepare("INSERT INTO user_voice_settings (user_id, voice_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE voice_enabled = ?");
            $stmt->execute([$user_id, $voice_enabled_val, $voice_enabled_val]);
            echo json_encode(['success' => true, 'voice_enabled' => (bool)$voice_enabled_val]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // Update accent
    if (isset($_POST['action']) && $_POST['action'] === 'update_accent') {
        header('Content-Type: application/json');
        $accent = trim($_POST['accent'] ?? 'neutral');
        $allowed = ['neutral','lunyankole','ugandan','slow'];
        if (!in_array($accent, $allowed)) $accent = 'neutral';
        try {
            $stmt = $conn->prepare("INSERT INTO user_voice_settings (user_id, voice_enabled, accent) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE accent = ?");
            $stmt->execute([$user_id, $accent, $accent]);
            echo json_encode(['success' => true, 'accent' => $accent]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // Save announcement
    if (isset($_POST['action']) && $_POST['action'] === 'save_announcement') {
        header('Content-Type: application/json');
        $title            = trim($_POST['title']            ?? '');
        $message          = trim($_POST['message']          ?? '');
        $interval_minutes = max(0, (int)($_POST['interval_minutes'] ?? 0));
        $repeat_count     = max(1, (int)($_POST['repeat_count']     ?? 1));
        if (!$title || !$message) { echo json_encode(['success' => false, 'message' => 'Title and message required']); exit; }
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS voice_announcements (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(120) NOT NULL, message TEXT NOT NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 1, interval_minutes INT NOT NULL DEFAULT 0, repeat_count INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            foreach (['interval_minutes INT NOT NULL DEFAULT 0', 'repeat_count INT NOT NULL DEFAULT 1'] as $colDef) {
                try { $conn->exec("ALTER TABLE voice_announcements ADD COLUMN $colDef"); } catch(PDOException $e2) {}
            }
            $stmt = $conn->prepare("INSERT INTO voice_announcements (user_id, title, message, is_enabled, interval_minutes, repeat_count) VALUES (?,?,?,1,?,?)");
            $stmt->execute([$user_id, $title, $message, $interval_minutes, $repeat_count]);
            $id = $conn->lastInsertId();
            echo json_encode(['success' => true, 'id' => $id, 'title' => $title, 'message' => $message, 'is_enabled' => 1, 'interval_minutes' => $interval_minutes, 'repeat_count' => $repeat_count]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // Toggle announcement enabled/disabled
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_announcement') {
        header('Content-Type: application/json');
        $ann_id  = (int)($_POST['ann_id'] ?? 0);
        $enabled = (int)($_POST['is_enabled'] ?? 0);
        try {
            $stmt = $conn->prepare("UPDATE voice_announcements SET is_enabled = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$enabled, $ann_id, $user_id]);
            echo json_encode(['success' => true, 'is_enabled' => $enabled]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }

    // Delete announcement
    if (isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
        header('Content-Type: application/json');
        $ann_id = (int)($_POST['ann_id'] ?? 0);
        try {
            $stmt = $conn->prepare("DELETE FROM voice_announcements WHERE id = ? AND user_id = ?");
            $stmt->execute([$ann_id, $user_id]);
            echo json_encode(['success' => true]);
        } catch(Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
        exit;
    }
} // end if POST
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAVANT MOTORS | Premium ERP Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* [The entire CSS remains exactly as in the previous version – unchanged] */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Calibri', 'Segoe UI', 'Inter', sans-serif; background: linear-gradient(135deg, #eef2f9 0%, #dfe6f0 100%); }
        :root { --primary: #0a3d7d; --primary-dark: #062a54; --primary-light: #2c6fab; --primary-glow: rgba(10,61,125,0.15); --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --info: #3b82f6; --dark: #0f172a; --gray: #475569; --light: #f8fafc; --border: #e2e8f0; --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05); --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1); --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1); --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1); --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); --jobcard-color: #3B82F6; --quotation-color: #8B5CF6; --invoice-color: #10B981; --receipt-color: #F59E0B; --attendance-color: #06B6D4; --procurement-color: #8B5CF6; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100%; background: linear-gradient(180deg, #ffffff 0%, #fefefe 100%); color: white; z-index: 1000; overflow-y: auto; box-shadow: 2px 0 12px rgba(0,0,0,0.04); }
        .sidebar-header { padding: 32px 24px; text-align: center; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .logo-icon { width: 150px; height: 100px; background: transparent; border-radius: 0; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; overflow: hidden; }
        .logo-icon img { max-width: 180px; max-height: 150px; object-fit: contain; }
        .logo-text { font-size: 24px; font-weight: 800; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .logo-subtitle { font-size: 12px; color: var(--gray); margin-top: 8px; letter-spacing: 0.5px; }
        .sidebar-menu { padding: 16px 0; }
        .nav-section { margin-bottom: 8px; }
        .nav-section-title { padding: 10px 24px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--primary); font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
        .nav-section-title i.toggle-icon { transition: transform 0.2s; }
        .nav-section-title.collapsed i.toggle-icon { transform: rotate(-90deg); }
        .nav-submenu { max-height: 500px; overflow: hidden; transition: max-height 0.3s ease; }
        .nav-submenu.collapsed { max-height: 0; }
        .nav-item { padding: 10px 24px 10px 44px; margin: 2px 0; border-radius: 12px; display: flex; align-items: center; gap: 14px; color: var(--gray); text-decoration: none; transition: var(--transition); font-weight: 500; font-size: 14px; }
        .nav-item i { width: 22px; font-size: 16px; }
        .nav-item:hover, .nav-item.active { background: rgba(10,61,125,0.08); color: var(--primary); }
        .nav-badge { margin-left: auto; background: var(--primary-light); color: white; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .logout-wrapper { margin-top: 40px; padding: 12px 24px; border-top: 1px solid var(--border); }
        .logout-item { padding: 10px 16px; border-radius: 12px; display: flex; align-items: center; gap: 14px; color: var(--danger); cursor: pointer; transition: var(--transition); font-weight: 500; }
        .logout-item:hover { background: rgba(239,68,68,0.1); }
        .main-content { margin-left: 280px; padding: 0 32px 32px 32px; min-height: 100vh; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; padding: 0 0 20px 0; border-bottom: 1px solid var(--border); margin-bottom: 32px; }
        .welcome-section h1 { font-size: 28px; font-weight: 800; background: linear-gradient(135deg, var(--dark), var(--primary-dark)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .welcome-section p { color: var(--gray); font-size: 14px; margin-top: 6px; display: flex; align-items: center; gap: 12px; }
        .date-badge { background: white; padding: 4px 12px; border-radius: 30px; font-size: 12px; font-weight: 500; box-shadow: var(--shadow-sm); border: 1px solid var(--border); }
        .user-card { background: white; padding: 8px 20px 8px 16px; border-radius: 60px; display: flex; align-items: center; gap: 16px; box-shadow: var(--shadow-md); border: 1px solid var(--border); transition: var(--transition); }
        .user-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); border-color: var(--primary); }
        .user-avatar { width: 44px; height: 44px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 40px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; color: white; }
        .user-details { text-align: right; }
        .user-name { font-weight: 700; color: var(--dark); font-size: 14px; }
        .user-role { font-size: 11px; color: var(--gray); text-transform: uppercase; }
        .logout-icon { background: #fef2f2; color: var(--danger); padding: 8px 12px; border-radius: 40px; transition: var(--transition); cursor: pointer; }
        .logout-icon:hover { background: var(--danger); color: white; transform: scale(1.05); }
        .alerts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .alert-card { background: white; border-radius: 20px; padding: 16px 20px; display: flex; align-items: center; gap: 16px; border-left: 4px solid; box-shadow: var(--shadow); transition: var(--transition); animation: slideInLeft 0.4s ease-out; }
        @keyframes slideInLeft { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        .alert-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
        .alert-icon { width: 48px; height: 48px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .alert-icon.danger { background: #fee2e2; color: var(--danger); }
        .alert-icon.warning { background: #fed7aa; color: var(--warning); }
        .alert-icon.info { background: #dbeafe; color: var(--info); }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 700; margin-bottom: 4px; color: var(--dark); }
        .alert-message { font-size: 13px; color: var(--gray); }
        .attendance-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .attendance-stat { text-align: center; padding: 15px; background: var(--light); border-radius: 20px; transition: var(--transition); }
        .attendance-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
        .attendance-stat .number { font-size: 28px; font-weight: 800; color: var(--attendance-color); }
        .attendance-stat .label { font-size: 11px; color: var(--gray); margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .attendance-list { margin-top: 15px; }
        .attendance-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .attendance-item:last-child { border-bottom: none; }
        .staff-info h4 { font-size: 14px; font-weight: 600; color: var(--dark); }
        .staff-info p { font-size: 11px; color: var(--gray); margin-top: 2px; }
        .check-time { font-size: 12px; font-weight: 600; }
        .check-time.present { color: var(--success); }
        .check-time.working { color: var(--warning); }
        .absent-staff { color: var(--danger); }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 40px; font-size: 11px; font-weight: 600; }
        .status-present { background: #dcfce7; color: #166534; }
        .status-working { background: #fed7aa; color: #9a3412; }
        .status-absent { background: #fee2e2; color: #991b1b; }
        .alerts-section { background: white; border-radius: 28px; padding: 24px; margin-bottom: 30px; border-left: 6px solid var(--danger); box-shadow: var(--shadow-sm); }
        .tool-overdue { border-left-color: var(--danger); background: #fee2e2; }
        .tool-return-btn { background: var(--danger); color: white; border: none; padding: 6px 14px; border-radius: 40px; font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition); margin-left: auto; }
        .tool-return-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
        .voice-switch { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; font-weight: 500; user-select: none; }
        .voice-switch input { display: none; }
        .voice-switch .slider { position: relative; width: 48px; height: 24px; background-color: #cbd5e1; border-radius: 34px; transition: 0.2s; }
        .voice-switch .slider:before { content: ""; position: absolute; width: 20px; height: 20px; left: 2px; bottom: 2px; background-color: white; border-radius: 50%; transition: 0.2s; }
        .voice-switch input:checked + .slider { background-color: var(--primary); }
        .voice-switch input:checked + .slider:before { transform: translateX(24px); }
        .voice-switch .switch-label { color: var(--gray); transition: color 0.2s; }
        .voice-switch input:checked ~ .switch-label { color: var(--primary); }
        .tools-taken-list { margin-top: 10px; }
        .tools-taken-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .tools-taken-item:last-child { border-bottom: none; }
        .tool-info h4 { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        .tool-info p { font-size: 11px; color: var(--gray); margin-top: 2px; }
        .tool-status { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
        .tool-status.active { background: #dbeafe; color: #1e40af; }
        .tool-status.overdue { background: #fee2e2; color: #dc2626; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin: 32px 0 20px; }
        .section-title { font-size: 20px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .section-title i { font-size: 22px; }
        .view-all-link { color: var(--primary); text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 30px; background: rgba(10,61,125,0.08); transition: var(--transition); }
        .view-all-link:hover { background: var(--primary); color: white; transform: translateX(4px); }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 32px; }
        .stat-card { background: white; border-radius: 24px; padding: 20px; transition: var(--transition); border: 1px solid var(--border); cursor: pointer; position: relative; overflow: hidden; }
        .stat-card::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: linear-gradient(90deg, var(--primary), var(--primary-dark)); transform: scaleX(0); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-xl); }
        .stat-card:hover::after { transform: scaleX(1); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .stat-title { font-size: 13px; font-weight: 600; color: var(--gray); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 22px; transition: var(--transition); }
        .stat-card:hover .stat-icon { transform: scale(1.1) rotate(3deg); }
        .stat-value { font-size: 32px; font-weight: 800; color: var(--dark); margin-bottom: 8px; }
        .stat-trend { font-size: 12px; color: var(--gray); display: flex; align-items: center; gap: 6px; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 28px; margin-bottom: 32px; }
        .glass-card { background: white; border-radius: 28px; overflow: hidden; border: 1px solid var(--border); transition: var(--transition); }
        .glass-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-xl); }
        .card-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: white; }
        .card-header h3 { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .card-header h3 i { font-size: 20px; }
        .card-content { padding: 20px 24px; }
        .activity-item { display: flex; align-items: center; gap: 16px; padding: 14px 0; border-bottom: 1px solid var(--border); transition: var(--transition); cursor: pointer; }
        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { transform: translateX(4px); background: rgba(10,61,125,0.02); }
        .activity-icon { width: 48px; height: 48px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .activity-icon.blue { background: #dbeafe; color: var(--jobcard-color); }
        .activity-icon.green { background: #dcfce7; color: var(--invoice-color); }
        .activity-icon.orange { background: #fed7aa; color: var(--receipt-color); }
        .activity-icon.purple { background: #ede9fe; color: var(--quotation-color); }
        .activity-icon.cyan { background: #cffafe; color: var(--attendance-color); }
        .activity-details { flex: 1; }
        .activity-title { font-weight: 600; color: var(--dark); margin-bottom: 4px; }
        .activity-subtitle { font-size: 12px; color: var(--gray); }
        .activity-badge { font-size: 11px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
        .badge-emergency { background: #fee2e2; color: var(--danger); }
        .badge-urgent { background: #fed7aa; color: var(--warning); }
        .badge-normal { background: #dbeafe; color: var(--primary); }
        .chart-container { height: 280px; position: relative; }
        .quick-actions-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-top: 24px; }
        .quick-action-card { background: white; border-radius: 20px; padding: 20px 16px; text-align: center; text-decoration: none; transition: var(--transition); border: 1px solid var(--border); position: relative; overflow: hidden; }
        .quick-action-card::before { content: ''; position: absolute; bottom: 0; left: 0; width: 100%; height: 3px; background: linear-gradient(90deg, var(--primary), var(--primary-dark)); transform: scaleX(0); transition: transform 0.2s; }
        .quick-action-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-xl); }
        .quick-action-card:hover::before { transform: scaleX(1); }
        .quick-action-icon { width: 56px; height: 56px; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; transition: var(--transition); }
        .quick-action-card:hover .quick-action-icon { transform: scale(1.1) rotate(6deg); }
        .quick-action-icon i { font-size: 24px; color: white; }
        .quick-action-title { font-weight: 600; color: var(--dark); font-size: 13px; }
        .funnel-stats { margin-top: 20px; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .funnel-stage { flex: 1; text-align: center; background: #f8fafc; border-radius: 16px; padding: 12px 8px; transition: var(--transition); }
        .funnel-stage:hover { background: white; box-shadow: var(--shadow); transform: translateY(-2px); }
        .stage-label { font-weight: 600; color: var(--gray); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stage-count { font-size: 28px; font-weight: 800; color: var(--dark); margin: 8px 0 4px; }
        .stage-percent { font-size: 14px; font-weight: 600; }
        .stage-drop { font-size: 12px; margin-top: 6px; font-weight: 500; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(6px); align-items: center; justify-content: center; z-index: 1100; }
        .modal-content { background: white; border-radius: 32px; width: 90%; max-width: 500px; overflow: hidden; animation: fadeInUp 0.3s ease; box-shadow: var(--shadow-lg); }
        .modal-header { padding: 20px 24px; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); color: white; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; }
        .close-modal { background: none; border: none; color: white; font-size: 26px; cursor: pointer; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--border); }
        .btn { padding: 10px 24px; border-radius: 40px; font-weight: 600; cursor: pointer; transition: var(--transition); border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .footer { margin-top: 48px; padding: 24px; text-align: center; border-top: 1px solid var(--border); color: var(--gray); font-size: 13px; }
        .ann-panel { background: white; border-radius: 28px; padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); box-shadow: var(--shadow); }
        .ann-panel-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .ann-panel-title { font-size: 18px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 10px; }
        .ann-form { display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px; align-items: start; margin-bottom: 20px; }
        .ann-form input, .ann-form textarea { padding: 10px 14px; border: 2px solid var(--border); border-radius: 12px; font-size: 13px; font-family: inherit; resize: none; }
        .ann-form input:focus, .ann-form textarea:focus { outline: none; border-color: var(--primary); }
        .ann-form button { padding: 10px 20px; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: var(--transition); }
        .ann-form button:hover { background: var(--primary-dark); }
        .ann-list { display: flex; flex-direction: column; gap: 10px; }
        .ann-item { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 16px; background: var(--light); border: 1px solid var(--border); transition: var(--transition); }
        .ann-item.disabled { opacity: 0.5; }
        .ann-item-body { flex: 1; }
        .ann-item-title { font-weight: 700; font-size: 14px; color: var(--dark); }
        .ann-item-msg { font-size: 13px; color: var(--gray); margin-top: 3px; }
        .ann-play-btn { background: #dbeafe; color: var(--primary); border: none; padding: 7px 14px; border-radius: 40px; font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .ann-play-btn:hover { background: var(--primary); color: white; }
        .ann-del-btn { background: #fee2e2; color: var(--danger); border: none; padding: 7px 12px; border-radius: 40px; font-size: 12px; cursor: pointer; transition: var(--transition); }
        .ann-del-btn:hover { background: var(--danger); color: white; }
        .accent-select { padding: 8px 14px; border: 2px solid var(--border); border-radius: 12px; font-size: 13px; font-family: inherit; background: white; cursor: pointer; }
        .accent-select:focus { outline: none; border-color: var(--primary); }
        .ann-empty { text-align: center; padding: 30px; color: var(--gray); font-size: 14px; }
        @media (max-width: 768px) { .ann-form { grid-template-columns: 1fr; } }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .quick-actions-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { left: -280px; } .main-content { margin-left: 0; padding: 0 20px 20px 20px; } .stats-grid { grid-template-columns: 1fr; } .attendance-summary { grid-template-columns: repeat(2, 1fr); } .quick-actions-grid { grid-template-columns: repeat(2, 1fr); } .top-bar { flex-direction: column; align-items: flex-start; } .funnel-stats { flex-direction: column; gap: 12px; } }
    </style>
</head>
<body>
    <!-- Sidebar (unchanged) -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon"><img src="/savant/views/images/logo.jpeg" alt="Savant Motors Logo" onerror="this.onerror=null; this.parentElement.innerHTML='<i class=\'fas fa-car\' style=\'font-size:32px; color:var(--primary);\'></i>';"></div>
            <div class="logo-subtitle">Enterprise Resource Planning</div>
        </div>
        <div class="sidebar-menu">
            <div class="nav-section"><div class="nav-section-title" data-section="workflow">WORKFLOW <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="workflow"><a href="dashboard_erp.php" class="nav-item active"><i class="fas fa-chart-pie"></i> Dashboard</a><a href="job_cards.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Job Cards <span class="nav-badge"><?php echo $job_stats['pending_jobs']; ?></span></a><a href="quotations.php" class="nav-item"><i class="fas fa-file-invoice"></i> Quotations <span class="nav-badge"><?php echo $quote_stats['pending_quotations']; ?></span></a><a href="invoices.php" class="nav-item"><i class="fas fa-file-invoice-dollar"></i> Invoices <span class="nav-badge"><?php echo $invoice_stats['unpaid_invoices']; ?></span></a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="procurement">PROCUREMENT <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="procurement"><a href="purchases/index.php" class="nav-item"><i class="fas fa-shopping-cart"></i> Purchase Orders</a><a href="suppliers.php" class="nav-item"><i class="fas fa-truck"></i> Suppliers</a><a href="purchase_requests.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Purchase Requests</a><a href="goods_received.php" class="nav-item"><i class="fas fa-check-double"></i> Goods Received</a><a href="vendor_quotes.php" class="nav-item"><i class="fas fa-file-invoice"></i> Vendor Quotes</a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="inventory">INVENTORY <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="inventory"><a href="../views/unified/index.php" class="nav-item"><i class="fas fa-boxes"></i> Inventory <span class="nav-badge"><?php echo $inventory_stats['low_stock_items']; ?></span></a><a href="../views/tools/index.php" class="nav-item"><i class="fas fa-tools"></i> Tool Management <span class="nav-badge"><?php echo $tool_stats['maintenance_tools']; ?></span></a><a href="../views/tool_requests/index.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Tool Requests <span class="nav-badge"><?php echo $tool_request_stats['pending_requests']; ?></span></a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="people">PEOPLE <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="people"><a href="technicians.php" class="nav-item"><i class="fas fa-users-cog"></i> Technicians <span class="nav-badge"><?php echo $technician_stats['total_technicians']; ?></span></a><a href="attendance.php" class="nav-item"><i class="fas fa-user-tie"></i> Staff Management</a><a href="customers/index.php" class="nav-item"><i class="fas fa-users"></i> Customers <span class="nav-badge"><?php echo $customer_stats['new_customers']; ?></span></a><a href="reminders/index.php" class="nav-item"><i class="fas fa-car"></i> Pickup Reminders <span class="nav-badge"><?php echo $reminder_stats['pending_reminders']; ?></span></a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="attendance">ATTENDANCE <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="attendance"><a href="attendance.php" class="nav-item"><i class="fas fa-clock"></i> Attendance <span class="nav-badge"><?php echo $today_stats['checked_in']; ?>/<?php echo $today_stats['total_staff']; ?></span></a><a href="attendance_report.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a><a href="attendance.php?action=checkin" class="nav-item"><i class="fas fa-sign-in-alt"></i> Check In/Out</a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="finance">FINANCE <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="finance"><a href="cash/index.php" class="nav-item"><i class="fas fa-money-bill-wave"></i> Cash Management</a><a href="ledger/index.php" class="nav-item"><i class="fas fa-book"></i> General Ledger</a><a href="accounting/sales_ledger.php" class="nav-item"><i class="fas fa-chart-bar"></i> accounting</a></div></div>
            <div class="nav-section"><div class="nav-section-title" data-section="system">SYSTEM <i class="fas fa-chevron-down toggle-icon"></i></div><div class="nav-submenu" data-submenu="system"><a href="users/index.php" class="nav-item"><i class="fas fa-users"></i> User Management</a><a href="settings/index.php" class="nav-item"><i class="fas fa-cog"></i> Settings</a><a href="reports.php" class="nav-item"><i class="fas fa-chart-bar"></i> Reports</a></div></div>
            <div class="logout-wrapper"><div class="logout-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div></div>
        </div>
    </div>

    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar"><div class="welcome-section"><h1>Make It Last, <?php echo htmlspecialchars(explode(' ', $user_full_name)[0]); ?>!</h1><p><i class="fas fa-calendar-alt"></i> <span id="currentDate"></span> <span class="date-badge"><i class="fas fa-chart-line"></i> Live Dashboard</span></p></div><div class="user-card"><div class="user-avatar"><?php echo strtoupper(substr($user_full_name, 0, 2)); ?></div><div class="user-details"><div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div><div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div></div><div class="logout-icon" id="logoutIcon"><i class="fas fa-sign-out-alt"></i></div></div></div>

        <!-- Alerts -->
        <div id="alertsContainer" class="alerts-grid"><?php if (empty($alerts)): ?><div class="alert-card"><div class="alert-icon info"><i class="fas fa-check-circle"></i></div><div class="alert-content"><div class="alert-title">All Good</div><div class="alert-message">No active alerts. System is running smoothly.</div></div><i class="fas fa-chevron-right" style="color: var(--gray); font-size: 14px;"></i></div><?php else: foreach ($alerts as $alert): ?><div class="alert-card"><div class="alert-icon <?php echo $alert['type']; ?>"><i class="fas fa-<?php echo $alert['type'] == 'danger' ? 'exclamation-triangle' : ($alert['type'] == 'warning' ? 'bell' : 'info-circle'); ?>"></i></div><div class="alert-content"><div class="alert-title"><?php echo $alert['type'] == 'danger' ? 'Critical' : ($alert['type'] == 'warning' ? 'Warning' : 'Info'); ?></div><div class="alert-message"><?php echo $alert['message']; ?></div></div><i class="fas fa-chevron-right" style="color: var(--gray); font-size: 14px;"></i></div><?php endforeach; endif; ?></div>

        <!-- Overdue Tools Section -->
        <?php if (!empty($overdue_assignments)): ?>
        <div class="alerts-section" style="margin-bottom: 30px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <h2 style="display: flex; align-items: center; gap: 10px; color: var(--danger); margin: 0;">
                    <i class="fas fa-tools"></i> Overdue Tools (Return Required)
                </h2>
            </div>
            <?php foreach ($overdue_assignments as $overdue): ?>
            <div class="alert-card tool-overdue" id="overdue-<?php echo $overdue['assignment_id']; ?>" style="margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center;"><div class="alert-content"><div class="alert-title"><?php echo htmlspecialchars($overdue['tool_name']); ?> (<?php echo $overdue['tool_code']; ?>)</div><div class="alert-message">Assigned to <?php echo htmlspecialchars($overdue['technician_name']); ?> on <?php echo date('d M Y', strtotime($overdue['assigned_date'])); ?> – <?php echo $overdue['days_overdue']; ?> day(s) overdue</div></div><button class="tool-return-btn" onclick="returnTool(<?php echo $overdue['assignment_id']; ?>, <?php echo json_encode($overdue['tool_name']); ?>, <?php echo json_encode($overdue['technician_name']); ?>)"><i class="fas fa-undo-alt"></i> Return</button></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tools Taken Section -->
        <?php if (!empty($current_assignments)): ?>
        <div class="glass-card" style="margin-bottom: 30px;"><div class="card-header"><h3><i class="fas fa-hand-holding" style="color: var(--success);"></i> Tools Currently Taken</h3><a href="tools.php?status=assigned" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a></div><div class="card-content"><div class="tools-taken-list"><?php foreach (array_slice($current_assignments, 0, 5) as $assignment): ?><div class="tools-taken-item"><div class="tool-info"><h4><?php echo htmlspecialchars($assignment['tool_name']); ?> (<?php echo $assignment['tool_code']; ?>)</h4><p><i class="fas fa-user"></i> <?php echo htmlspecialchars($assignment['technician_name']); ?> • Taken: <?php echo date('d M Y', strtotime($assignment['assigned_date'])); ?></p><p><i class="fas fa-calendar"></i> Expected Return: <?php echo date('d M Y', strtotime($assignment['expected_return_date'])); ?> • <?php echo $assignment['days_assigned']; ?> days</p></div><div><span class="tool-status <?php echo $assignment['assignment_status'] == 'Overdue' ? 'overdue' : 'active'; ?>"><?php echo $assignment['assignment_status']; ?></span></div></div><?php endforeach; ?></div><?php if (count($current_assignments) > 5): ?><div style="text-align: center; margin-top: 15px;"><a href="tools.php?status=assigned" class="view-all-link">+<?php echo count($current_assignments) - 5; ?> more tools taken</a></div><?php endif; ?></div></div>
        <?php endif; ?>

        <!-- ATTENDANCE SECTION (unchanged) -->
        <div class="glass-card" style="margin-bottom: 30px;"><div class="card-header"><h3><i class="fas fa-clock" style="color: var(--attendance-color);"></i> Today's Attendance</h3><a href="attendance.php" class="view-all-link">View Full Attendance <i class="fas fa-arrow-right"></i></a></div><div class="card-content"><div class="attendance-summary"><div class="attendance-stat"><div class="number"><?php echo $today_stats['checked_in']; ?>/<?php echo $today_stats['total_staff']; ?></div><div class="label">Checked In Today</div></div><div class="attendance-stat"><div class="number"><?php echo $today_stats['still_working']; ?></div><div class="label">Still Working</div></div><div class="attendance-stat"><div class="number"><?php echo $today_stats['absent']; ?></div><div class="label">Absent Today</div></div><div class="attendance-stat"><div class="number"><?php echo $month_stats['total_checkins'] ?? 0; ?></div><div class="label">This Month</div></div></div><div class="dashboard-grid" style="margin-bottom: 0; gap: 20px;"><div class="attendance-list"><h4 style="font-size: 13px; margin-bottom: 10px; color: var(--gray);"><i class="fas fa-user-check" style="color: var(--success);"></i> Present Today</h4><?php if (empty($today_attendance)): ?><div class="empty-state" style="text-align: center; padding: 30px; color: var(--gray);"><i class="fas fa-user-clock" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i><p>No check-ins recorded yet today</p><a href="attendance.php" class="view-all-link" style="display: inline-block; margin-top: 10px;">Check In Staff</a></div><?php else: ?><?php foreach (array_slice($today_attendance, 0, 5) as $attendance): ?><div class="attendance-item"><div class="staff-info"><h4><?php echo htmlspecialchars($attendance['full_name']); ?></h4><p><?php echo htmlspecialchars($attendance['position'] ?? 'Staff'); ?> • <?php echo htmlspecialchars($attendance['department']); ?></p></div><div class="check-time <?php echo $attendance['check_out_time'] ? 'present' : 'working'; ?>"><?php if ($attendance['check_out_time']): ?><span class="status-badge status-present">Checked Out <?php echo date('h:i A', strtotime($attendance['check_out_time'])); ?></span><?php else: ?><span class="status-badge status-working">Working (In: <?php echo date('h:i A', strtotime($attendance['check_in_time'])); ?>)</span><?php endif; ?></div></div><?php endforeach; ?><?php if (count($today_attendance) > 5): ?><div style="text-align: center; margin-top: 10px;"><a href="attendance.php" class="view-all-link">+<?php echo count($today_attendance) - 5; ?> more</a></div><?php endif; ?><?php endif; ?></div><?php if (!empty($absent_staff)): ?><div class="attendance-list"><h4 style="font-size: 13px; margin-bottom: 10px; color: var(--gray);"><i class="fas fa-user-slash" style="color: var(--danger);"></i> Absent Today</h4><?php foreach (array_slice($absent_staff, 0, 5) as $absent): ?><div class="attendance-item"><div class="staff-info"><h4><?php echo htmlspecialchars($absent['full_name']); ?></h4><p><?php echo htmlspecialchars($absent['position'] ?? 'Staff'); ?> • <?php echo htmlspecialchars($absent['department']); ?></p></div><div class="check-time absent-staff"><span class="status-badge status-absent"><i class="fas fa-clock"></i> Not Checked In</span></div></div><?php endforeach; ?><?php if (count($absent_staff) > 5): ?><div style="text-align: center; margin-top: 10px;"><a href="attendance.php?filter=absent" class="view-all-link">+<?php echo count($absent_staff) - 5; ?> more</a></div><?php endif; ?></div><?php endif; ?></div><div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;"><a href="attendance.php" class="view-all-link" style="background: var(--attendance-color); color: white;"><i class="fas fa-sign-in-alt"></i> Check In / Check Out</a><a href="attendance_report.php" class="view-all-link"><i class="fas fa-chart-bar"></i> View Attendance Report</a><a href="attendance.php?action=manual" class="view-all-link"><i class="fas fa-edit"></i> Manual Entry</a></div></div></div>

        <!-- Job Cards Overview -->
        <div class="section-header"><div class="section-title"><i class="fas fa-clipboard-list" style="color: var(--jobcard-color);"></i> Job Cards Overview</div><a href="job_cards.php" class="view-all-link">View All <i class="fas fa-arrow-right"></i></a></div>
        <div class="stats-grid"><div class="stat-card"><div class="stat-header"><span class="stat-title">Total Jobs</span><div class="stat-icon" style="background:#dbeafe;color:var(--jobcard-color);"><i class="fas fa-clipboard-list"></i></div></div><div class="stat-value"><?php echo number_format($job_stats['total_jobs']); ?></div><div class="stat-trend">All job cards</div></div><div class="stat-card"><div class="stat-header"><span class="stat-title">Active Jobs</span><div class="stat-icon" style="background:#fed7aa;color:var(--warning);"><i class="fas fa-clock"></i></div></div><div class="stat-value"><?php echo number_format($job_stats['pending_jobs'] + $job_stats['in_progress_jobs']); ?></div><div class="stat-trend"><?php echo $job_stats['pending_jobs']; ?> pending · <?php echo $job_stats['in_progress_jobs']; ?> in progress</div></div><div class="stat-card"><div class="stat-header"><span class="stat-title">Completed</span><div class="stat-icon" style="background:#dcfce7;color:var(--success);"><i class="fas fa-check-circle"></i></div></div><div class="stat-value"><?php echo number_format($job_stats['completed_jobs']); ?></div><div class="stat-trend">Successfully finished</div></div><div class="stat-card"><div class="stat-header"><span class="stat-title">Overdue</span><div class="stat-icon" style="background:#fee2e2;color:var(--danger);"><i class="fas fa-exclamation-triangle"></i></div></div><div class="stat-value"><?php echo number_format($job_stats['overdue_jobs']); ?></div><div class="stat-trend">Requires attention</div></div></div>

        <!-- Quotations & Invoices Summary -->
        <div class="dashboard-grid"><div class="glass-card"><div class="card-header"><h3><i class="fas fa-file-invoice" style="color: var(--quotation-color);"></i> Quotations</h3><a href="quotations.php" class="view-all-link">View All</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Total</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($quote_stats['total_quotations']); ?></div></div><div><span class="stat-title">Pending</span><div class="stat-value" style="font-size:24px;color:var(--warning);"><?php echo number_format($quote_stats['pending_quotations']); ?></div></div><div><span class="stat-title">Approved</span><div class="stat-value" style="font-size:24px;color:var(--success);"><?php echo number_format($quote_stats['approved_quotations']); ?></div></div></div><div class="stat-trend">Total Value: UGX <span><?php echo number_format($quote_stats['total_value']); ?></span></div></div></div><div class="glass-card"><div class="card-header"><h3><i class="fas fa-file-invoice-dollar" style="color: var(--invoice-color);"></i> Invoices</h3><a href="invoices.php" class="view-all-link">View All</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Total</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($invoice_stats['total_invoices']); ?></div></div><div><span class="stat-title">Paid</span><div class="stat-value" style="font-size:24px;color:var(--success);"><?php echo number_format($invoice_stats['paid_invoices']); ?></div></div><div><span class="stat-title">Unpaid</span><div class="stat-value" style="font-size:24px;color:var(--danger);"><?php echo number_format($invoice_stats['unpaid_invoices']); ?></div></div></div><div class="stat-trend">Collected: UGX <span><?php echo number_format($invoice_stats['collected_amount']); ?></span> | Outstanding: UGX <span><?php echo number_format($invoice_stats['outstanding_amount']); ?></span></div></div></div></div>

        <!-- Recent Activity (unchanged) -->
        <div class="dashboard-grid"><div class="glass-card"><div class="card-header"><h3><i class="fas fa-clock" style="color: var(--jobcard-color);"></i> Recent Job Cards</h3><a href="job_cards.php" class="view-all-link">View All</a></div><div class="card-content"><?php foreach ($recent_jobs as $job): ?><div class="activity-item"><div class="activity-icon blue"><i class="fas fa-car"></i></div><div class="activity-details"><div class="activity-title"><?php echo htmlspecialchars($job['job_number']); ?> - <?php echo htmlspecialchars($job['customer_name'] ?? 'N/A'); ?></div><div class="activity-subtitle">Vehicle: <?php echo htmlspecialchars($job['vehicle_reg'] ?? 'N/A'); ?> · Received: <?php echo date('d M Y', strtotime($job['date_received'])); ?></div></div><div class="activity-badge" style="background:<?php echo $job['status'] == 'completed' ? '#dcfce7' : '#fed7aa'; ?>;color:<?php echo $job['status'] == 'completed' ? '#166534' : '#9a3412'; ?>;"><?php echo ucfirst(str_replace('_', ' ', $job['status'])); ?></div></div><?php endforeach; ?></div></div><div class="glass-card"><div class="card-header"><h3><i class="fas fa-tools" style="color: var(--quotation-color);"></i> Pending Tool Requests</h3><a href="tool_requests/index.php" class="view-all-link">View All</a></div><div class="card-content"><?php if (empty($pending_requests)): ?><div style="text-align:center;padding:40px;color:var(--gray);"><i class="fas fa-check-circle" style="font-size:48px;margin-bottom:12px;display:block;color:var(--success);"></i><p>No pending requests</p></div><?php else: foreach ($pending_requests as $req): ?><div class="activity-item"><div class="activity-icon <?php echo $req['urgency'] == 'emergency' ? 'orange' : 'blue'; ?>"><i class="fas fa-tools"></i></div><div class="activity-details"><div class="activity-title"><?php echo htmlspecialchars($req['request_number']); ?> - <?php echo htmlspecialchars($req['technician_name']); ?></div><div class="activity-subtitle">Tool: <?php echo htmlspecialchars($req['tool_name'] ?? $req['tool_name_requested']); ?> · Qty: <?php echo $req['quantity']; ?></div></div><div class="activity-badge badge-<?php echo $req['urgency']; ?>"><?php echo strtoupper($req['urgency']); ?></div></div><?php endforeach; endif; ?></div></div></div>

        <!-- Inventory & Tools Summary -->
        <div class="dashboard-grid"><div class="glass-card"><div class="card-header"><h3><i class="fas fa-boxes"></i> Inventory Summary</h3><a href="unified/index.php" class="view-all-link">Manage</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Products</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($inventory_stats['total_products']); ?></div></div><div><span class="stat-title">Total Items</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($inventory_stats['total_items']); ?></div></div><div><span class="stat-title">Low Stock</span><div class="stat-value" style="font-size:24px;color:var(--danger);"><?php echo number_format($inventory_stats['low_stock_items']); ?></div></div></div><div class="stat-trend">Value: UGX <span><?php echo number_format($inventory_stats['inventory_value']); ?></span></div><?php if (!empty($low_stock_items)): ?><div style="margin-top:15px;"><div class="stat-title">Low Stock Items</div><?php foreach ($low_stock_items as $item): ?><div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);"><span><?php echo htmlspecialchars($item['product_name']); ?></span><span style="color:var(--danger);"><?php echo $item['current_stock']; ?> left (Min: <?php echo $item['reorder_level']; ?>)</span></div><?php endforeach; ?></div><?php endif; ?></div></div><div class="glass-card"><div class="card-header"><h3><i class="fas fa-tools"></i> Tools Summary</h3><a href="tools/index.php" class="view-all-link">Manage</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Total Tools</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($tool_stats['total_tools']); ?></div></div><div><span class="stat-title">Available</span><div class="stat-value" style="font-size:24px;color:var(--success);"><?php echo number_format($tool_stats['available_tools']); ?></div></div><div><span class="stat-title">Assigned</span><div class="stat-value" style="font-size:24px;color:var(--warning);"><?php echo number_format($tool_stats['assigned_tools']); ?></div></div></div><div class="stat-trend">Value: UGX <span><?php echo number_format($tool_stats['total_value']); ?></span> | Maintenance: <span><?php echo $tool_stats['maintenance_tools']; ?></span></div></div></div></div>

        <!-- Technicians Card -->
        <div class="glass-card"><div class="card-header"><h3><i class="fas fa-users-cog"></i> Technicians</h3><a href="technicians.php" class="view-all-link">View All</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Total</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($technician_stats['total_technicians']); ?></div></div><div><span class="stat-title">Active</span><div class="stat-value" style="font-size:24px;color:var(--success);"><?php echo number_format($technician_stats['active_technicians']); ?></div></div><div><span class="stat-title">Blocked</span><div class="stat-value" style="font-size:24px;color:var(--danger);"><?php echo number_format($technician_stats['blocked_technicians']); ?></div></div></div><div class="stat-trend">Tool Requests Pending: <span><?php echo $tool_request_stats['pending_requests']; ?></span></div></div></div>

        <!-- Performance Chart -->
        <div class="glass-card"><div class="card-header"><h3><i class="fas fa-chart-line"></i> Weekly Performance</h3><div><button class="view-all-link" onclick="toggleChart('revenue')" id="btnRevenue">Revenue</button><button class="view-all-link" onclick="toggleChart('jobs')" id="btnJobs">Jobs</button></div></div><div class="card-content"><div class="chart-container"><canvas id="performanceChart"></canvas></div></div></div>

        <!-- Sales Funnel -->
        <div class="glass-card"><div class="card-header"><h3><i class="fas fa-chart-simple"></i> Sales Funnel</h3><div><select id="funnelSourceSelect" class="view-all-link" style="background:var(--primary); color:white; border:none; padding:6px 12px; border-radius:30px;"><option value="All" <?= $selected_source == 'All' ? 'selected' : '' ?>>All Sources</option><?php foreach ($sources as $src): ?><option value="<?= htmlspecialchars($src) ?>" <?= $selected_source == $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option><?php endforeach; ?></select></div></div><div class="card-content"><div class="chart-container" style="height: 320px; position: relative;"><canvas id="funnelCanvas" width="800" height="320" style="width:100%; height:100%;"></canvas></div><div class="funnel-stats" style="margin-top: 20px;"><?php $funnel_stages = ['Total Customers' => $funnel_data['total_customers'] ?? 0, 'Job Cards' => $funnel_data['job_card_customers'] ?? 0, 'Approved Quotations' => $funnel_data['quotation_customers'] ?? 0, 'Paid Invoices' => $funnel_data['invoice_customers'] ?? 0]; $max_stage = max($funnel_stages) ?: 1; $prev_count = null; foreach ($funnel_stages as $label => $count): $percent = round(($count / $max_stage) * 100); $color = match($label) { 'Total Customers' => '#3B82F6', 'Job Cards' => '#8B5CF6', 'Approved Quotations' => '#10B981', 'Paid Invoices' => '#F59E0B', default => '#64748b' }; ?><div class="funnel-stage"><div class="stage-label"><?= htmlspecialchars($label) ?></div><div class="stage-count" style="color: <?= $color ?>;"><?= number_format($count) ?></div><div class="stage-percent" style="color: <?= $color ?>;"><?= $percent ?>% of top</div><?php if ($prev_count !== null && $prev_count > 0): ?><div class="stage-drop" style="color:<?= ($count/$prev_count) < 0.8 ? 'var(--danger)' : 'var(--success)' ?>;"><?= round(($count/$prev_count)*100) ?>% conversion</div><?php endif; ?></div><?php $prev_count = $count; endforeach; ?></div></div></div>

        <!-- CRM & Reminders -->
        <div class="dashboard-grid"><div class="glass-card"><div class="card-header"><h3><i class="fas fa-users"></i> Customer Summary</h3><a href="customers/index.php" class="view-all-link">View All</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Total Customers</span><div class="stat-value" style="font-size:24px;"><?php echo number_format($customer_stats['total_customers']); ?></div></div><div><span class="stat-title">New (30d)</span><div class="stat-value" style="font-size:24px;color:var(--success);"><?php echo number_format($customer_stats['new_customers']); ?></div></div></div></div></div><div class="glass-card"><div class="card-header"><h3><i class="fas fa-bell"></i> Pickup Reminders</h3><a href="reminders/index.php" class="view-all-link">Manage</a></div><div class="card-content"><div style="display:flex;justify-content:space-between;margin-bottom:16px;"><div><span class="stat-title">Pending</span><div class="stat-value" style="font-size:24px;color:var(--warning);"><?php echo number_format($reminder_stats['pending_reminders']); ?></div></div></div><div class="stat-trend">Total: <span><?php echo $reminder_stats['total_reminders']; ?></span></div></div></div></div>

        <!-- Quick Actions -->
        <div class="section-header"><div class="section-title"><i class="fas fa-bolt"></i> Quick Actions</div></div>
        <div class="quick-actions-grid"><a href="new_job.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--jobcard-color);"><i class="fas fa-clipboard-list"></i></div><div class="quick-action-title">New Job Card</div></a><a href="new_quotation.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--quotation-color);"><i class="fas fa-file-invoice"></i></div><div class="quick-action-title">New Quotation</div></a><a href="new_invoice.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--invoice-color);"><i class="fas fa-file-invoice-dollar"></i></div><div class="quick-action-title">New Invoice</div></a><a href="accounting/receipt.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--receipt-color);"><i class="fas fa-receipt"></i></div><div class="quick-action-title">Receipt</div></a><a href="attendance.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--attendance-color);"><i class="fas fa-clock"></i></div><div class="quick-action-title">Check In/Out</div></a><a href="tool_requests/index.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-tools"></i></div><div class="quick-action-title">Request Tool</div></a><a href="cash/index.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-money-bill-wave"></i></div><div class="quick-action-title">Record Payment</div></a><a href="unified/index.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-boxes"></i></div><div class="quick-action-title">Add Product</div></a><a href="technicians.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-user-plus"></i></div><div class="quick-action-title">Add Technician</div></a><a href="customers/index.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-user-plus"></i></div><div class="quick-action-title">Add Customer</div></a><a href="add_staff.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--primary);"><i class="fas fa-user-tie"></i></div><div class="quick-action-title">Add Staff</div></a><a href="attendance_report.php" class="quick-action-card"><div class="quick-action-icon" style="background: var(--attendance-color);"><i class="fas fa-chart-bar"></i></div><div class="quick-action-title">Attendance Report</div></a></div>

        <!-- Voice Announcements Panel -->
        <div class="ann-panel">
            <div class="ann-panel-header">
                <div class="ann-panel-title"><i class="fas fa-bullhorn" style="color:var(--primary);"></i> Voice Announcements</div>
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--gray);">
                        <i class="fas fa-microphone-alt"></i> Accent:
                        <select id="accentSelect" class="accent-select" onchange="saveAccent(this.value)">
                            <option value="neutral"    <?= $saved_accent=='neutral'    ? 'selected':'' ?>>Neutral English</option>
                            <option value="lunyankole" <?= $saved_accent=='lunyankole' ? 'selected':'' ?>>Lunyankole / Runyankore</option>
                            <option value="ugandan"    <?= $saved_accent=='ugandan'    ? 'selected':'' ?>>Ugandan English</option>
                            <option value="slow"       <?= $saved_accent=='slow'       ? 'selected':'' ?>>Slow &amp; Clear</option>
                        </select>
                    </div>
                    <label class="voice-switch">
                        <input type="checkbox" id="voiceToggleCheckbox" <?= $voice_enabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                        <span class="switch-label"><i class="fas fa-volume-up"></i> Voice On/Off</span>
                    </label>
                </div>
            </div>

            <!-- Add Announcement Form -->
            <div class="ann-form">
                <input  type="text"   id="annTitle"   placeholder="Short title (e.g. Break Time)" maxlength="120">
                <textarea id="annMessage" rows="2"    placeholder="Type the announcement text to be spoken…"></textarea>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <div style="display:flex;flex-direction:column;gap:3px;min-width:140px;">
                        <label style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">🔁 Repeat every</label>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <input type="number" id="annInterval" min="0" max="1440" value="0" style="width:70px;padding:6px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;">
                            <span style="font-size:12px;color:var(--gray);">min (0 = once)</span>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:3px;min-width:120px;">
                        <label style="font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;">🔢 Times to play</label>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <input type="number" id="annRepeat" min="1" max="100" value="1" style="width:70px;padding:6px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;">
                            <span style="font-size:12px;color:var(--gray);">times</span>
                        </div>
                    </div>
                </div>
                <button onclick="saveAnnouncement()"><i class="fas fa-save"></i> Save</button>
            </div>

            <!-- Play Any Text Now -->
            <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:14px;padding:14px 16px;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:8px;"><i class="fas fa-play-circle"></i> Play Anything Now</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <textarea id="quickPlayText" rows="2" placeholder="Type anything to speak it aloud immediately…" style="flex:1;min-width:200px;padding:8px 12px;border:1.5px solid #bae6fd;border-radius:10px;font-size:13px;font-family:inherit;resize:vertical;"></textarea>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <div style="display:flex;gap:6px;align-items:center;">
                            <input type="number" id="quickRepeat" min="1" max="20" value="1" style="width:55px;padding:6px 8px;border:1.5px solid #bae6fd;border-radius:8px;font-size:13px;" title="Times to play">
                            <span style="font-size:11px;color:#0369a1;">×</span>
                            <input type="number" id="quickInterval" min="0" max="60" value="0" style="width:55px;padding:6px 8px;border:1.5px solid #bae6fd;border-radius:8px;font-size:13px;" title="Interval between repeats (seconds)">
                            <span style="font-size:11px;color:#0369a1;">sec gap</span>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button onclick="quickPlay()" style="background:#0ea5e9;color:white;border:none;padding:8px 16px;border-radius:40px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;"><i class="fas fa-play"></i> Play</button>
                            <button onclick="stopAll()" style="background:#fee2e2;color:#dc2626;border:none;padding:8px 14px;border-radius:40px;font-size:13px;font-weight:700;cursor:pointer;"><i class="fas fa-stop"></i> Stop</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Saved Announcements -->
            <div class="ann-list" id="annList">
                <?php if (empty($saved_announcements)): ?>
                <div class="ann-empty" id="annEmpty"><i class="fas fa-volume-mute" style="font-size:32px;display:block;margin-bottom:8px;"></i>No announcements saved yet. Add one above.</div>
                <?php else: foreach ($saved_announcements as $ann): ?>
                <div class="ann-item <?= $ann['is_enabled'] ? '' : 'disabled' ?>" id="ann-<?= $ann['id'] ?>">
                    <label class="voice-switch" style="flex-shrink:0;" title="Enable / disable this announcement">
                        <input type="checkbox" <?= $ann['is_enabled'] ? 'checked' : '' ?> onchange="toggleAnnouncement(<?= $ann['id'] ?>, this.checked)">
                        <span class="slider"></span>
                    </label>
                    <div class="ann-item-body" style="flex:1;">
                        <div class="ann-item-title"><?= htmlspecialchars($ann['title']) ?></div>
                        <div class="ann-item-msg"><?= htmlspecialchars($ann['message']) ?></div>
                        <div style="font-size:11px;color:var(--gray);margin-top:4px;">
                            <?php if ($ann['interval_minutes'] > 0): ?>
                                🔁 Every <?= $ann['interval_minutes'] ?> min &nbsp;·&nbsp;
                            <?php endif; ?>
                            🔢 <?= $ann['repeat_count'] ?> time<?= $ann['repeat_count'] != 1 ? 's' : '' ?>
                        </div>
                    </div>
                    <button class="ann-play-btn" onclick="playAnnouncementWithSettings(<?= json_encode($ann['message']) ?>, <?= (int)$ann['repeat_count'] ?>, <?= (int)$ann['interval_minutes'] * 60 ?>)" title="Play now"><i class="fas fa-play"></i> Play</button>
                    <button class="ann-del-btn" onclick="deleteAnnouncement(<?= $ann['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="footer"><p>© <?php echo date('Y'); ?> SAVANT MOTORS UGANDA. All rights reserved. | Complete ERP System</p></div>
    </div>

    <!-- Return Modal -->
    <div id="returnModal" class="modal"><div class="modal-content"><div class="modal-header"><h3><i class="fas fa-undo-alt"></i> Confirm Tool Return</h3><button class="close-modal" onclick="closeReturnModal()">&times;</button></div><div class="modal-body"><p>Are you sure you want to mark this tool as returned?</p><p><strong id="toolNameModal"></strong><br><strong id="technicianNameModal"></strong></p></div><div class="modal-footer"><button class="btn btn-secondary" onclick="closeReturnModal()">Cancel</button><button class="btn btn-primary" id="confirmReturnBtn">Confirm Return</button></div></div></div>

    <script>
        // Sidebar expand/collapse functionality
        document.querySelectorAll('.nav-section-title').forEach(title => {
            const section = title.dataset.section;
            const submenu = document.querySelector(`.nav-submenu[data-submenu="${section}"]`);
            const isCollapsed = localStorage.getItem(`sidebar_${section}`) === 'collapsed';
            if (isCollapsed) { title.classList.add('collapsed'); submenu.classList.add('collapsed'); }
            title.addEventListener('click', () => { title.classList.toggle('collapsed'); submenu.classList.toggle('collapsed'); localStorage.setItem(`sidebar_${section}`, title.classList.contains('collapsed') ? 'collapsed' : 'expanded'); });
        });

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) { if (m === '&') return '&amp;'; if (m === '<') return '&lt;'; if (m === '>') return '&gt;'; return m; });
        }

        const chartLabels = <?php echo json_encode($chart_labels); ?>;
        const chartRevenue = <?php echo json_encode($chart_revenue); ?>;
        const chartJobs = <?php echo json_encode($chart_jobs); ?>;
        let chart = null;
        let currentChartView = 'revenue';

        function initChart() {
            const canvas = document.getElementById('performanceChart');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const data = currentChartView === 'revenue' ? chartRevenue : chartJobs;
            const label = currentChartView === 'revenue' ? 'Revenue (UGX)' : 'Jobs Completed';
            const color = currentChartView === 'revenue' ? '#0a3d7d' : '#8B5CF6';
            if (chart) chart.destroy();
            chart = new Chart(ctx, {
                type: 'line',
                data: { labels: chartLabels, datasets: [{ label, data, borderColor: color, backgroundColor: color + '20', borderWidth: 3, pointBackgroundColor: color, pointBorderColor: 'white', pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 8, tension: 0.4, fill: true }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15 } }, tooltip: { callbacks: { label: ctx => currentChartView === 'revenue' ? ctx.dataset.label + ': UGX ' + ctx.raw.toLocaleString() : ctx.dataset.label + ': ' + ctx.raw } } }, scales: { y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { callback: value => currentChartView === 'revenue' ? 'UGX ' + (value / 1000).toFixed(0) + 'K' : value } }, x: { grid: { display: false } } } }
            });
        }

        function toggleChart(view) {
            currentChartView = view;
            const btnRevenue = document.getElementById('btnRevenue');
            const btnJobs = document.getElementById('btnJobs');
            if (view === 'revenue') { btnRevenue.style.background = 'var(--primary)'; btnRevenue.style.color = 'white'; btnJobs.style.background = ''; btnJobs.style.color = ''; }
            else { btnJobs.style.background = 'var(--primary)'; btnJobs.style.color = 'white'; btnRevenue.style.background = ''; btnRevenue.style.color = ''; }
            initChart();
        }

        function drawFunnel(stageNames, stageCounts, maxCount) {
            const canvas = document.getElementById('funnelCanvas');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const w = canvas.clientWidth;
            const h = canvas.clientHeight;
            canvas.width = w;
            canvas.height = h;
            const stages = stageNames.length;
            const segmentHeight = h / stages;
            const maxWidth = w * 0.7;
            const minWidth = w * 0.2;
            ctx.clearRect(0, 0, w, h);
            for (let i = 0; i < stages; i++) {
                const ratio = maxCount > 0 ? stageCounts[i] / maxCount : 0;
                const width = minWidth + (maxWidth - minWidth) * ratio;
                const x = (w - width) / 2;
                const y = i * segmentHeight;
                const color = `hsl(${210 - i * 15}, 70%, 55%)`;
                ctx.fillStyle = color;
                ctx.shadowBlur = 4;
                ctx.shadowColor = 'rgba(0,0,0,0.1)';
                ctx.fillRect(x, y + 5, width, segmentHeight - 10);
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 14px "Inter", sans-serif';
                ctx.shadowBlur = 0;
                ctx.fillText(stageNames[i], x + 15, y + segmentHeight / 2 + 5);
                ctx.font = 'bold 18px "Inter", sans-serif';
                ctx.fillStyle = '#fff';
                ctx.fillText(stageCounts[i].toLocaleString(), x + width - 50, y + segmentHeight / 2 + 5);
            }
        }

        const funnelStageNames = <?php echo json_encode(array_keys($funnel_stages)); ?>;
        const funnelStageCounts = <?php echo json_encode(array_values($funnel_stages)); ?>;
        const funnelMax = Math.max(...funnelStageCounts);
        drawFunnel(funnelStageNames, funnelStageCounts, funnelMax);

        document.getElementById('funnelSourceSelect')?.addEventListener('change', function() { window.location.href = window.location.pathname + '?funnel_source=' + encodeURIComponent(this.value); });
        window.addEventListener('resize', () => drawFunnel(funnelStageNames, funnelStageCounts, funnelMax));

        function updateDateTime() { const now = new Date(); document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }); }
        async function logout() { try { await fetch('/api/auth.php?action=logout', { method: 'POST' }); } catch(e) {} window.location.href = '/savant/views/logout.php'; }

        // ============================================
        // VOICE ANNOUNCEMENTS
        // ============================================
        let voiceEnabled = <?php echo $voice_enabled ? 'true' : 'false'; ?>;
        let overdueCount = <?php echo count($overdue_assignments); ?>;

        // Scheduler state
        let _scheduleTimers  = []; // setInterval IDs for auto-repeat
        let _pendingTimeouts = []; // setTimeout IDs for within-announcement spacing

        const accentProfiles = {
            neutral:    { rate: 0.90, pitch: 1.10, langPref: ['en-US','en-GB','en'] },
            lunyankole: { rate: 0.76, pitch: 0.92, langPref: ['en-UG','en-ZA','en-KE','en-NG','en'] },
            ugandan:    { rate: 0.82, pitch: 0.96, langPref: ['en-UG','en-KE','en-NG','en-ZA','en'] },
            slow:       { rate: 0.68, pitch: 1.00, langPref: ['en-US','en-GB','en'] }
        };
        let currentAccent = '<?php echo $saved_accent; ?>';

        function getAccentProfile() { return accentProfiles[currentAccent] || accentProfiles.neutral; }

        function pickVoice(langPrefs) {
            const voices = window.speechSynthesis.getVoices();
            if (!voices.length) return null;
            for (const pref of langPrefs) {
                const v = voices.find(v => v.lang === pref) || voices.find(v => v.lang.startsWith(pref.split('-')[0]));
                if (v) return v;
            }
            return voices[0];
        }

        function speakOnce(text) {
            return new Promise(resolve => {
                if (!('speechSynthesis' in window)) { resolve(); return; }
                window.speechSynthesis.cancel();
                const utterance = new SpeechSynthesisUtterance(text);
                const profile   = getAccentProfile();
                utterance.rate   = profile.rate;
                utterance.pitch  = profile.pitch;
                utterance.volume = 1;
                utterance.onend  = resolve;
                utterance.onerror = resolve;
                const trySet = () => { const v = pickVoice(profile.langPref); if (v) utterance.voice = v; window.speechSynthesis.speak(utterance); };
                const voices = window.speechSynthesis.getVoices();
                voices.length ? trySet() : (window.speechSynthesis.onvoiceschanged = trySet);
            });
        }

        // Speak text N times, with gapSeconds between each play
        async function speakRepeated(text, times, gapSeconds) {
            if (!voiceEnabled) return;
            for (let i = 0; i < times; i++) {
                await speakOnce(text);
                if (i < times - 1 && gapSeconds > 0) {
                    await new Promise(r => { const t = setTimeout(r, gapSeconds * 1000); _pendingTimeouts.push(t); });
                }
            }
        }

        // Legacy single-speak (used by return-tool success etc)
        function speakText(text) {
            if (!voiceEnabled) return;
            speakOnce(text);
        }

        function stopAll() {
            window.speechSynthesis?.cancel();
            _scheduleTimers.forEach(clearInterval);
            _pendingTimeouts.forEach(clearTimeout);
            _scheduleTimers  = [];
            _pendingTimeouts = [];
            showToast('🔇 Stopped all announcements', 'info');
        }

        // Play an announcement with repeat + interval settings
        function playAnnouncementWithSettings(message, repeatCount, intervalSeconds) {
            if (!voiceEnabled) { showToast('Voice is disabled. Enable it first.', 'warning'); return; }
            stopAll(); // stop anything already running
            showToast(`🔊 Playing (${repeatCount}×)…`, 'info');

            if (intervalSeconds > 0 && repeatCount > 1) {
                // Play immediately, then every intervalSeconds
                let played = 0;
                const run = () => {
                    if (played >= repeatCount) { stopAll(); return; }
                    speakRepeated(message, 1, 0);
                    played++;
                };
                run();
                const id = setInterval(run, intervalSeconds * 1000);
                _scheduleTimers.push(id);
            } else {
                // Just play N times back-to-back (2 sec gap)
                speakRepeated(message, repeatCount, 2);
            }
        }

        // Legacy wrapper used by old play button
        function playAnnouncement(message) {
            playAnnouncementWithSettings(message, 1, 0);
        }

        // Quick play (Play Anything Now box)
        function quickPlay() {
            const text     = document.getElementById('quickPlayText').value.trim();
            const times    = Math.max(1, parseInt(document.getElementById('quickRepeat').value)  || 1);
            const gap      = Math.max(0, parseInt(document.getElementById('quickInterval').value) || 0);
            if (!text) { showToast('Enter something to play', 'warning'); return; }
            if (!voiceEnabled) { showToast('Voice is disabled. Enable it first.', 'warning'); return; }
            stopAll();
            showToast(`🔊 Playing ${times}× (${gap}s gap)`, 'info');
            speakRepeated(text, times, gap);
        }

        async function saveAccent(accent) {
            currentAccent = accent;
            try {
                await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=update_accent&accent=${encodeURIComponent(accent)}` });
                if (voiceEnabled) speakText('Accent updated.');
                showToast(`Accent set: ${accent}`, 'info');
            } catch(e) { console.error(e); }
        }

        async function saveAnnouncement() {
            const title    = document.getElementById('annTitle').value.trim();
            const message  = document.getElementById('annMessage').value.trim();
            const interval = Math.max(0, parseInt(document.getElementById('annInterval').value) || 0);
            const repeat   = Math.max(1, parseInt(document.getElementById('annRepeat').value)   || 1);
            if (!title || !message) { showToast('Enter a title and message', 'error'); return; }
            try {
                const resp = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=save_announcement&title=${encodeURIComponent(title)}&message=${encodeURIComponent(message)}&interval_minutes=${interval}&repeat_count=${repeat}`
                });
                const data = await resp.json();
                if (data.success) {
                    document.getElementById('annTitle').value   = '';
                    document.getElementById('annMessage').value = '';
                    document.getElementById('annInterval').value = '0';
                    document.getElementById('annRepeat').value   = '1';
                    addAnnouncementToDOM(data.id, data.title, data.message, 1, interval, repeat);
                    showToast('Announcement saved!', 'success');
                } else { showToast(data.message || 'Failed to save', 'error'); }
            } catch(e) { showToast('Network error', 'error'); }
        }

        function addAnnouncementToDOM(id, title, message, isEnabled, intervalMin, repeatCount) {
            const list  = document.getElementById('annList');
            const empty = document.getElementById('annEmpty');
            if (empty) empty.remove();

            const container = document.createElement('div');
            container.className = `ann-item ${isEnabled ? '' : 'disabled'}`;
            container.id = `ann-${id}`;

            // Toggle switch
            const switchLabel = document.createElement('label');
            switchLabel.className = 'voice-switch';
            switchLabel.style.flexShrink = '0';
            const switchInput = document.createElement('input');
            switchInput.type    = 'checkbox';
            switchInput.checked = !!isEnabled;
            switchInput.onchange = function() { toggleAnnouncement(id, this.checked); };
            const sliderSpan = document.createElement('span');
            sliderSpan.className = 'slider';
            switchLabel.appendChild(switchInput);
            switchLabel.appendChild(sliderSpan);
            container.appendChild(switchLabel);

            // Body
            const bodyDiv  = document.createElement('div');
            bodyDiv.className = 'ann-item-body';
            bodyDiv.style.flex = '1';
            const titleDiv = document.createElement('div'); titleDiv.className = 'ann-item-title'; titleDiv.textContent = title;
            const msgDiv   = document.createElement('div'); msgDiv.className   = 'ann-item-msg';   msgDiv.textContent   = message;
            const metaDiv  = document.createElement('div');
            metaDiv.style.cssText = 'font-size:11px;color:var(--gray);margin-top:4px;';
            metaDiv.textContent = (intervalMin > 0 ? `🔁 Every ${intervalMin} min · ` : '') + `🔢 ${repeatCount} time${repeatCount != 1 ? 's' : ''}`;
            bodyDiv.appendChild(titleDiv); bodyDiv.appendChild(msgDiv); bodyDiv.appendChild(metaDiv);
            container.appendChild(bodyDiv);

            // Play button
            const playBtn = document.createElement('button');
            playBtn.className = 'ann-play-btn';
            playBtn.title     = 'Play now';
            playBtn.innerHTML = '<i class="fas fa-play"></i> Play';
            playBtn.onclick   = function() { playAnnouncementWithSettings(message, repeatCount, intervalMin * 60); };
            container.appendChild(playBtn);

            // Delete button
            const delBtn = document.createElement('button');
            delBtn.className = 'ann-del-btn';
            delBtn.title     = 'Delete';
            delBtn.innerHTML = '<i class="fas fa-trash"></i>';
            delBtn.onclick   = function() { deleteAnnouncement(id); };
            container.appendChild(delBtn);

            list.prepend(container);
        }

        async function toggleAnnouncement(id, checked) {
            const enabled = checked ? 1 : 0;
            const item = document.getElementById(`ann-${id}`);
            if (item) item.classList.toggle('disabled', !checked);
            try {
                await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=toggle_announcement&ann_id=${id}&is_enabled=${enabled}` });
                showToast(enabled ? 'Announcement enabled' : 'Announcement disabled', 'info');
                if (enabled && voiceEnabled) {
                    const msg = item?.querySelector('.ann-item-msg')?.textContent;
                    if (msg) speakText(msg);
                }
            } catch(e) { showToast('Network error', 'error'); }
        }

        async function deleteAnnouncement(id) {
            if (!confirm('Delete this announcement?')) return;
            try {
                await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=delete_announcement&ann_id=${id}` });
                const item = document.getElementById(`ann-${id}`);
                if (item) item.remove();
                const list = document.getElementById('annList');
                if (!list.querySelector('.ann-item')) {
                    list.innerHTML = '<div class="ann-empty" id="annEmpty"><i class="fas fa-volume-mute" style="font-size:32px;display:block;margin-bottom:8px;"></i>No announcements saved yet.</div>';
                }
                showToast('Announcement deleted', 'info');
            } catch(e) { showToast('Network error', 'error'); }
        }

        async function toggleVoiceAnnouncements() {
            const newState = !voiceEnabled;
            const checkbox = document.getElementById('voiceToggleCheckbox');
            voiceEnabled = newState;
            if (checkbox) checkbox.checked = voiceEnabled;
            if (!voiceEnabled) stopAll();
            try {
                const response = await fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=update_voice_setting&voice_enabled=${voiceEnabled ? 1 : 0}` });
                const data = await response.json();
                if (!data.success) throw new Error('Save failed');
                showToast(`Voice announcements ${voiceEnabled ? 'enabled' : 'disabled'}`, 'info');
            } catch (err) {
                voiceEnabled = !newState;
                if (checkbox) checkbox.checked = voiceEnabled;
                showToast('Failed to save voice preference', 'error');
            }
        }

        let currentAssignmentId = null, currentToolName = null, currentTechnicianName = null;
        function returnTool(assignmentId, toolName, technicianName) {
            currentAssignmentId = assignmentId;
            currentToolName = toolName;
            currentTechnicianName = technicianName;
            document.getElementById('toolNameModal').innerText = toolName;
            document.getElementById('technicianNameModal').innerText = 'Assigned to: ' + technicianName;
            document.getElementById('returnModal').style.display = 'flex';
        }
        function closeReturnModal() { document.getElementById('returnModal').style.display = 'none'; currentAssignmentId = null; currentToolName = null; currentTechnicianName = null; }
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.innerText = message;
            toast.style.position = 'fixed';
            toast.style.bottom = '20px';
            toast.style.right = '20px';
            toast.style.background = type === 'error' ? '#ef4444' : (type === 'warning' ? '#f59e0b' : '#10b981');
            toast.style.color = 'white';
            toast.style.padding = '12px 20px';
            toast.style.borderRadius = '40px';
            toast.style.zIndex = '1200';
            toast.style.fontSize = '14px';
            toast.style.fontWeight = '500';
            toast.style.boxShadow = 'var(--shadow-md)';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        document.getElementById('confirmReturnBtn')?.addEventListener('click', function() {
            if (!currentAssignmentId) return;
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=return_tool&assignment_id=${currentAssignmentId}` })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const overdueDiv = document.getElementById(`overdue-${currentAssignmentId}`);
                        if (overdueDiv) overdueDiv.remove();
                        if (voiceEnabled) speakText(`${currentToolName} assigned to ${currentTechnicianName} has been returned successfully. Thank you.`);
                        showToast(`${currentToolName} returned successfully`, 'success');
                        const remaining = document.querySelectorAll('[id^="overdue-"]').length;
                        if (remaining === 0) {
                            const section = document.querySelector('.alerts-section:has(h2)');
                            if (section && section.innerText.includes('Overdue Tools')) { section.style.display = 'none'; if (voiceEnabled) speakText('All overdue tools have been returned. Great job!'); }
                        }
                        overdueCount = remaining;
                    } else { showToast('Error: ' + data.message, 'error'); }
                    closeReturnModal();
                })
                .catch(err => { showToast('Network error', 'error'); closeReturnModal(); });
        });

        document.getElementById('logoutBtn')?.addEventListener('click', logout);
        document.getElementById('logoutIcon')?.addEventListener('click', logout);
        updateDateTime();
        setInterval(updateDateTime, 1000);
        initChart();

        const voiceCheckbox = document.getElementById('voiceToggleCheckbox');
        if (voiceCheckbox) { voiceCheckbox.checked = voiceEnabled; voiceCheckbox.addEventListener('change', toggleVoiceAnnouncements); }

        // Track whether user is actively typing in any input/textarea
        let _userIsTyping = false;
        let _typingTimer  = null;
        document.addEventListener('focusin', e => {
            if (e.target.matches('input, textarea, select')) _userIsTyping = true;
        });
        document.addEventListener('focusout', e => {
            if (e.target.matches('input, textarea, select')) {
                // Give a short grace period so a blur+focus between fields doesn't trigger a reload
                clearTimeout(_typingTimer);
                _typingTimer = setTimeout(() => {
                    // Only clear typing flag if no text field still has content being edited
                    const active = document.activeElement;
                    if (!active || !active.matches('input, textarea, select')) {
                        _userIsTyping = false;
                    }
                }, 3000);
            }
        });
        document.addEventListener('input', e => {
            if (e.target.matches('input, textarea')) {
                _userIsTyping = true;
                clearTimeout(_typingTimer);
                // Keep typing flag alive as long as any field has unsaved content
                _typingTimer = setTimeout(() => {
                    const hasContent = [...document.querySelectorAll('input[type="text"], input[type="number"], textarea')]
                        .some(el => el.value.trim() !== '' && el.id !== 'quickRepeat' && el.id !== 'quickInterval' && el.id !== 'annInterval' && el.id !== 'annRepeat');
                    _userIsTyping = hasContent;
                }, 500);
            }
        });

        // Auto-refresh every 60 seconds — skips if:
        // • speech is playing or a scheduled repeat is running
        // • user is focused on / typing in any input or textarea
        setInterval(() => {
            const speechActive    = window.speechSynthesis?.speaking || window.speechSynthesis?.pending;
            const schedulerActive = _scheduleTimers.length > 0 || _pendingTimeouts.length > 0;
            const focused         = document.activeElement && document.activeElement.matches('input, textarea, select');
            if (!speechActive && !schedulerActive && !_userIsTyping && !focused) {
                location.reload();
            }
        }, 60000);
    </script>
</body>
</html>