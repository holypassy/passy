<?php
// /api/dashboard_stats.php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$response = [];

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ==================== JOB CARDS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_jobs,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_jobs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
            SUM(CASE WHEN date_promised < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_jobs
        FROM job_cards WHERE deleted_at IS NULL
    ");
    $response['job_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== QUOTATIONS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_quotations,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_quotations,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_quotations,
            COALESCE(SUM(total_amount), 0) as total_value
        FROM quotations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $response['quote_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== INVOICES ====================
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
    $response['invoice_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== CASH MANAGEMENT ====================
    try {
        $stmt = $conn->query("
            SELECT 
                COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
                COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
                COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
                COALESCE(SUM(balance), 0) as total_balance
            FROM cash_accounts WHERE is_active = 1
        ");
        $response['cash_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $response['cash_stats'] = ['total_cash' => 0, 'total_bank' => 0, 'total_mobile' => 0, 'total_balance' => 0];
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
            $response['cash_flow'] = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $response['cash_flow'] = ['income' => 0, 'expenses' => 0];
        }
    } catch (PDOException $e) {
        $response['cash_flow'] = ['income' => 0, 'expenses' => 0];
    }
    
    // ==================== INVENTORY (FIX COLUMN NAMES) ====================
    // Replace 'current_stock', 'reorder_level', 'cost_price' with the actual column names from your inventory table
    $quantity_column = 'quantity';           // ← Change this to your stock column (e.g., 'current_stock', 'stock', 'qty')
    $reorder_column = 'reorder_level';       // ← Change this to your reorder level column (e.g., 'min_stock', 'reorder_point')
    $cost_column = 'cost_price';             // ← Change this to your cost column (e.g., 'unit_cost', 'purchase_price')
    
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_products,
            SUM({$quantity_column}) as total_items,
            SUM(CASE WHEN {$quantity_column} <= {$reorder_column} THEN 1 ELSE 0 END) as low_stock_items,
            COALESCE(SUM({$quantity_column} * {$cost_column}), 0) as inventory_value
        FROM inventory WHERE is_active = 1
    ");
    $response['inventory_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== TOOL MANAGEMENT ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_tools,
            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_tools,
            SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_tools,
            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_tools,
            COALESCE(SUM(purchase_price), 0) as total_value
        FROM tools WHERE is_active = 1
    ");
    $response['tool_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== TOOL REQUESTS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN urgency = 'emergency' AND status = 'pending' THEN 1 ELSE 0 END) as emergency_pending
        FROM tool_requests
    ");
    $response['tool_request_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== TECHNICIANS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_technicians,
            SUM(CASE WHEN status = 'active' AND is_blocked = 0 THEN 1 ELSE 0 END) as active_technicians,
            SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked_technicians
        FROM technicians
    ");
    $response['technician_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== CUSTOMERS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_customers,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_customers
        FROM customers WHERE status = 1
    ");
    $response['customer_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== PICKUP REMINDERS ====================
    $stmt = $conn->query("
        SELECT 
            COUNT(*) as total_reminders,
            SUM(CASE WHEN reminder_sent = 0 AND reminder_date <= CURDATE() THEN 1 ELSE 0 END) as pending_reminders
        FROM vehicle_pickup_reminders WHERE status = 'pending'
    ");
    $response['reminder_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ==================== ALERTS ====================
    $alerts = [];
    if ($response['job_stats']['overdue_jobs'] > 0) $alerts[] = ['type' => 'danger', 'message' => $response['job_stats']['overdue_jobs'] . " job(s) are overdue"];
    if ($response['tool_request_stats']['emergency_pending'] > 0) $alerts[] = ['type' => 'danger', 'message' => $response['tool_request_stats']['emergency_pending'] . " emergency tool request(s) pending"];
    if ($response['inventory_stats']['low_stock_items'] > 0) $alerts[] = ['type' => 'warning', 'message' => $response['inventory_stats']['low_stock_items'] . " product(s) low in stock"];
    if ($response['tool_request_stats']['pending_requests'] > 0) $alerts[] = ['type' => 'warning', 'message' => $response['tool_request_stats']['pending_requests'] . " tool request(s) awaiting approval"];
    if ($response['quote_stats']['pending_quotations'] > 0) $alerts[] = ['type' => 'info', 'message' => $response['quote_stats']['pending_quotations'] . " quotation(s) pending approval"];
    if ($response['invoice_stats']['unpaid_invoices'] > 0) $alerts[] = ['type' => 'warning', 'message' => $response['invoice_stats']['unpaid_invoices'] . " invoice(s) unpaid"];
    if ($response['reminder_stats']['pending_reminders'] > 0) $alerts[] = ['type' => 'info', 'message' => $response['reminder_stats']['pending_reminders'] . " vehicle(s) waiting for pickup reminder"];
    if ($response['tool_stats']['maintenance_tools'] > 0) $alerts[] = ['type' => 'warning', 'message' => $response['tool_stats']['maintenance_tools'] . " tool(s) need maintenance"];
    $response['alerts'] = $alerts;
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}