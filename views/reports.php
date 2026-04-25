<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$user_full_name = $_SESSION['full_name'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';

// Get date range from URL or use defaults
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$report_type = isset($_GET['type']) ? $_GET['type'] : 'dashboard';

// Validate dates
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Initialize variables with defaults
$job_stats = ['total_jobs' => 0, 'completed_jobs' => 0, 'pending_jobs' => 0, 'in_progress_jobs' => 0, 'overdue_jobs' => 0];
$financial_stats = ['total_revenue' => 0, 'collected' => 0, 'receivables' => 0, 'avg_invoice_value' => 0];
$daily_trends = [];
$table_data = [];
$error = null;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // First, let's check what columns exist in the inventory table
    $stmt = $conn->query("SHOW COLUMNS FROM inventory");
    $inventoryColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // ==================== JOB STATISTICS ====================
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT id) as total_jobs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_jobs,
            SUM(CASE WHEN date_promised < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_jobs
        FROM job_cards 
        WHERE created_at BETWEEN :start_date AND :end_date AND deleted_at IS NULL
    ");
    $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
    $job_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $job_stats;
    
    // ==================== FINANCIAL SUMMARY ====================
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as collected,
            COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN (total_amount - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as receivables,
            COALESCE(AVG(total_amount), 0) as avg_invoice_value
        FROM invoices 
        WHERE created_at BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
    $financial_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $financial_stats;
    
    // ==================== DAILY TRENDS ====================
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as job_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM job_cards
        WHERE created_at BETWEEN :start_date AND :end_date AND deleted_at IS NULL
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
    $daily_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ==================== TABLE DATA BASED ON REPORT TYPE ====================
    switch($report_type) {
        case 'pl':
            $stmt = $conn->prepare("
                SELECT 
                    DATE(created_at) as date,
                    invoice_number as reference,
                    'Invoice' as type,
                    total_amount as amount,
                    payment_status as status
                FROM invoices 
                WHERE created_at BETWEEN :start_date AND :end_date
                UNION ALL
                SELECT 
                    DATE(transaction_date) as date,
                    description as reference,
                    transaction_type as type,
                    amount,
                    status
                FROM cash_transactions 
                WHERE transaction_date BETWEEN :start_date AND :end_date AND status = 'approved'
                ORDER BY date DESC
                LIMIT 100
            ");
            $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
            $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'inventory':
            // Check which columns exist and build query accordingly
            $selectFields = [];
            if (in_array('product_name', $inventoryColumns)) {
                $selectFields[] = 'product_name as name';
            } elseif (in_array('name', $inventoryColumns)) {
                $selectFields[] = 'name';
            } elseif (in_array('item_name', $inventoryColumns)) {
                $selectFields[] = 'item_name as name';
            } else {
                $selectFields[] = "'Unknown' as name";
            }
            
            if (in_array('sku', $inventoryColumns)) {
                $selectFields[] = 'sku';
            } else {
                $selectFields[] = "'N/A' as sku";
            }
            
            if (in_array('quantity', $inventoryColumns)) {
                $selectFields[] = 'quantity as current_stock';
            } elseif (in_array('stock', $inventoryColumns)) {
                $selectFields[] = 'stock as current_stock';
            } else {
                $selectFields[] = '0 as current_stock';
            }
            
            if (in_array('reorder_level', $inventoryColumns)) {
                $selectFields[] = 'reorder_level';
            } else {
                $selectFields[] = '0 as reorder_level';
            }
            
            if (in_array('cost_price', $inventoryColumns)) {
                $selectFields[] = 'cost_price as unit_cost';
            } elseif (in_array('cost', $inventoryColumns)) {
                $selectFields[] = 'cost as unit_cost';
            } else {
                $selectFields[] = '0 as unit_cost';
            }
            
            if (in_array('selling_price', $inventoryColumns)) {
                $selectFields[] = 'selling_price';
            } elseif (in_array('price', $inventoryColumns)) {
                $selectFields[] = 'price as selling_price';
            } else {
                $selectFields[] = '0 as selling_price';
            }
            
            $stmt = $conn->prepare("
                SELECT 
                    " . implode(', ', $selectFields) . ",
                    (CASE 
                        WHEN " . (in_array('cost_price', $inventoryColumns) ? 'cost_price' : (in_array('cost', $inventoryColumns) ? 'cost' : '0')) . " IS NOT NULL 
                        THEN (CASE 
                            WHEN " . (in_array('quantity', $inventoryColumns) ? 'quantity' : (in_array('stock', $inventoryColumns) ? 'stock' : '0')) . " IS NOT NULL
                            THEN " . (in_array('quantity', $inventoryColumns) ? 'quantity' : (in_array('stock', $inventoryColumns) ? 'stock' : '0')) . " * " . (in_array('cost_price', $inventoryColumns) ? 'cost_price' : (in_array('cost', $inventoryColumns) ? 'cost' : '0')) . "
                            ELSE 0
                        END)
                        ELSE 0
                    END) as stock_value
                FROM inventory
                WHERE is_active = 1
                ORDER BY name ASC
            ");
            $stmt->execute();
            $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'expenses':
            $stmt = $conn->prepare("
                SELECT 
                    DATE(transaction_date) as date,
                    description,
                    amount,
                    category,
                    'Cash' as payment_method,
                    'System' as created_by
                FROM cash_transactions 
                WHERE transaction_type = 'expense' 
                    AND transaction_date BETWEEN :start_date AND :end_date
                    AND status = 'approved'
                ORDER BY transaction_date DESC
                LIMIT 100
            ");
            $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
            $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'purchases':
            $stmt = $conn->prepare("
                SELECT 
                    id as po_number,
                    DATE(created_at) as date,
                    'Supplier' as supplier_name,
                    total_amount,
                    payment_status,
                    due_date as payment_due_date
                FROM invoices 
                WHERE created_at BETWEEN :start_date AND :end_date
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
            $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'receivables':
            $stmt = $conn->prepare("
                SELECT 
                    invoice_number,
                    DATE(created_at) as date,
                    customer_name,
                    total_amount,
                    COALESCE(amount_paid, 0) as amount_paid,
                    (total_amount - COALESCE(amount_paid, 0)) as balance_due,
                    due_date,
                    payment_status
                FROM invoices 
                WHERE payment_status != 'paid' 
                    AND created_at BETWEEN :start_date AND :end_date
                ORDER BY due_date ASC
                LIMIT 100
            ");
            $stmt->execute([':start_date' => $start_date . ' 00:00:00', ':end_date' => $end_date . ' 23:59:59']);
            $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        // TOOL INVENTORY REPORT
        case 'tool_inventory':
            // First check if tool_inventory table exists
            try {
                $stmt = $conn->query("SHOW TABLES LIKE 'tool_inventory'");
                if ($stmt->rowCount() > 0) {
                    // Check columns in tool_inventory
                    $stmt = $conn->query("SHOW COLUMNS FROM tool_inventory");
                    $toolColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $selectFields = [];
                    if (in_array('tool_name', $toolColumns)) {
                        $selectFields[] = 'tool_name';
                    } elseif (in_array('name', $toolColumns)) {
                        $selectFields[] = 'name as tool_name';
                    } else {
                        $selectFields[] = "'Unknown Tool' as tool_name";
                    }
                    
                    if (in_array('quantity', $toolColumns)) {
                        $selectFields[] = 'quantity';
                    } else {
                        $selectFields[] = '0 as quantity';
                    }
                    
                    if (in_array('location', $toolColumns)) {
                        $selectFields[] = 'location';
                    } else {
                        $selectFields[] = "'N/A' as location";
                    }
                    
                    if (in_array('condition', $toolColumns)) {
                        $selectFields[] = '`condition`';
                    } else {
                        $selectFields[] = "'Good' as `condition`";
                    }
                    
                    if (in_array('last_checked', $toolColumns)) {
                        $selectFields[] = 'last_checked';
                    } else {
                        $selectFields[] = "CURDATE() as last_checked";
                    }
                    
                    $stmt = $conn->prepare("
                        SELECT " . implode(', ', $selectFields) . "
                        FROM tool_inventory
                        ORDER BY tool_name ASC
                    ");
                    $stmt->execute();
                    $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // Table doesn't exist, show empty state with helpful message
                    $table_data = [];
                    $error = "Tool Inventory table doesn't exist. Please run the SQL to create it.";
                }
            } catch (PDOException $e) {
                $table_data = [];
                $error = "Tool Inventory not set up yet. Please contact administrator.";
            }
            break;

        // CUSTOMERS REPORT
        case 'customers':
            try {
                $stmt = $conn->query("SHOW TABLES LIKE 'customers'");
                if ($stmt->rowCount() > 0) {
                    $stmt = $conn->query("SHOW COLUMNS FROM customers");
                    $customerColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $selectFields = [];
                    if (in_array('name', $customerColumns)) {
                        $selectFields[] = 'name';
                    } elseif (in_array('full_name', $customerColumns)) {
                        $selectFields[] = 'full_name as name';
                    } else {
                        $selectFields[] = "'Unknown' as name";
                    }
                    
                    if (in_array('chassis_no', $customerColumns)) {
                        $selectFields[] = 'chassis_no';
                    } elseif (in_array('chassis_number', $customerColumns)) {
                        $selectFields[] = 'chassis_number as chassis_no';
                    } else {
                        $selectFields[] = "'N/A' as chassis_no";
                    }
                    
                    if (in_array('last_service', $customerColumns)) {
                        $selectFields[] = 'last_service';
                    } else {
                        $selectFields[] = "NULL as last_service";
                    }
                    
                    if (in_array('phone', $customerColumns)) {
                        $selectFields[] = 'phone';
                    } elseif (in_array('phone_number', $customerColumns)) {
                        $selectFields[] = 'phone_number as phone';
                    } else {
                        $selectFields[] = "'—' as phone";
                    }
                    
                    if (in_array('email', $customerColumns)) {
                        $selectFields[] = 'email';
                    } else {
                        $selectFields[] = "'—' as email";
                    }
                    
                    $stmt = $conn->prepare("
                        SELECT " . implode(', ', $selectFields) . "
                        FROM customers
                        ORDER BY name ASC
                    ");
                    $stmt->execute();
                    $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $table_data = [];
                    $error = "Customers table doesn't exist. Please run the SQL to create it.";
                }
            } catch (PDOException $e) {
                $table_data = [];
                $error = "Customers module not set up yet. Please contact administrator.";
            }
            break;
            
        default:
            $table_data = [];
    }
    
} catch(PDOException $e) {
    error_log("Reports Error: " . $e->getMessage());
    $error = "Database connection error: " . $e->getMessage();
}

// Helper function to check if a table exists
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '" . $tableName . "'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics | Savant Motors Uganda</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
            min-height: 100vh;
        }

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray: #64748b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        /* Sidebar - Light Blue */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
            border-right: 1px solid rgba(37, 99, 235, 0.1);
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 28px 24px;
            text-align: center;
            border-bottom: 1px solid rgba(37, 99, 235, 0.2);
        }

        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .logo-icon i {
            font-size: 32px;
            color: white;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            padding: 8px 24px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--gray);
            font-weight: 600;
        }

        .nav-item {
            padding: 12px 24px;
            margin: 4px 12px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 500;
        }

        .nav-item i {
            width: 22px;
            font-size: 18px;
        }

        .nav-item:hover, .nav-item.active {
            background: #dbeafe;
            color: var(--primary);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 28px 32px;
            min-height: 100vh;
        }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-card {
            background: white;
            padding: 8px 20px 8px 16px;
            border-radius: 60px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 700;
            color: var(--dark);
        }

        .user-role {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
        }

        .logout-btn {
            background: #fef2f2;
            color: var(--danger);
            padding: 8px 16px;
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: var(--danger);
            color: white;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .filter-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
        }

        .filter-group input, .filter-group select {
            padding: 12px 16px;
            border: 2px solid var(--border);
            border-radius: 14px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }

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
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid var(--border);
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 20px;
            padding: 22px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .card-icon.blue { background: #dbeafe; color: var(--primary); }
        .card-icon.green { background: #dcfce7; color: var(--success); }
        .card-icon.orange { background: #fed7aa; color: var(--warning); }
        .card-icon.purple { background: #f3e8ff; color: #8b5cf6; }

        .card-info h3 {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 6px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .card-info .value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }

        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 20px;
            padding: 8px;
            margin-bottom: 30px;
            border: 1px solid var(--border);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 22px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray);
            text-decoration: none;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            justify-content: center;
        }

        .tab:hover, .tab.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 24px;
            padding: 22px;
            border: 1px solid var(--border);
        }

        .chart-container {
            height: 280px;
            position: relative;
        }

        /* Table */
        .report-table-container {
            background: white;
            border-radius: 24px;
            padding: 22px;
            border: 1px solid var(--border);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th {
            text-align: left;
            padding: 14px;
            background: var(--light);
            font-size: 12px;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            border-bottom: 2px solid var(--border);
        }

        .report-table td {
            padding: 14px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .amount {
            font-weight: 700;
        }

        .amount-positive {
            color: var(--success);
        }

        .amount-negative {
            color: var(--danger);
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fed7aa; color: #9a3412; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--gray);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .alert-error {
            background: #fee2e2;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .tabs-container {
                flex-direction: column;
            }
            .tab {
                width: 100%;
            }
        }

        @media print {
            .sidebar, .filter-card, .action-buttons, .tabs-container, .table-search {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Light Blue Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <i class="fas fa-chart-bar"></i>
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
        </div>
        <div class="sidebar-menu">
            <div class="nav-section">
                <div class="nav-section-title">MAIN</div>
                <a href="dashboard_erp.php" class="nav-item"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <a href="job_cards.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Job Cards</a>
                <a href="reports.php" class="nav-item active"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
            <div class="nav-section">
                <div class="nav-section-title">SYSTEM</div>
                <div class="nav-item" id="logoutBtn"><i class="fas fa-sign-out-alt"></i> Logout</div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
            </div>
            <div class="user-card">
                <div class="user-avatar"><?php echo strtoupper(substr($user_full_name, 0, 2)); ?></div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                    <div class="user-role"><?php echo strtoupper(htmlspecialchars($user_role)); ?></div>
                </div>
                <div class="logout-btn" id="logoutIcon"><i class="fas fa-sign-out-alt"></i> Logout</div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <div class="filter-title">
                <i class="fas fa-calendar-alt"></i> Filter Report Data
            </div>
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" id="startDate" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" id="endDate" value="<?php echo $end_date; ?>">
                </div>
                <div class="filter-group">
                    <label>Report Type</label>
                    <select id="reportType">
                        <option value="dashboard" <?php echo $report_type == 'dashboard' ? 'selected' : ''; ?>>Dashboard Summary</option>
                        <option value="pl" <?php echo $report_type == 'pl' ? 'selected' : ''; ?>>P&L Statement</option>
                        <option value="inventory" <?php echo $report_type == 'inventory' ? 'selected' : ''; ?>>Parts Inventory</option>
                        <option value="tool_inventory" <?php echo $report_type == 'tool_inventory' ? 'selected' : ''; ?>>Tool Inventory</option>
                        <option value="customers" <?php echo $report_type == 'customers' ? 'selected' : ''; ?>>Customers</option>
                        <option value="expenses" <?php echo $report_type == 'expenses' ? 'selected' : ''; ?>>Expenses Report</option>
                        <option value="purchases" <?php echo $report_type == 'purchases' ? 'selected' : ''; ?>>Purchases Report</option>
                        <option value="receivables" <?php echo $report_type == 'receivables' ? 'selected' : ''; ?>>Receivables Report</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button class="btn btn-primary" onclick="applyFilter()"><i class="fas fa-search"></i> Generate Report</button>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="card-icon blue"><i class="fas fa-chart-line"></i></div>
                <div class="card-info">
                    <h3>Total Revenue</h3>
                    <div class="value">UGX <?php echo number_format($financial_stats['total_revenue']); ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon green"><i class="fas fa-wallet"></i></div>
                <div class="card-info">
                    <h3>Collected</h3>
                    <div class="value">UGX <?php echo number_format($financial_stats['collected']); ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon orange"><i class="fas fa-clock"></i></div>
                <div class="card-info">
                    <h3>Receivables</h3>
                    <div class="value">UGX <?php echo number_format($financial_stats['receivables']); ?></div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon purple"><i class="fas fa-tasks"></i></div>
                <div class="card-info">
                    <h3>Jobs Performance</h3>
                    <div class="value"><?php echo $job_stats['completed_jobs']; ?>/<?php echo $job_stats['total_jobs']; ?></div>
                </div>
            </div>
        </div>

        <!-- Report Tabs -->
        <div class="tabs-container">
            <a href="?type=dashboard&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> Dashboard
            </a>
            <a href="?type=pl&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'pl' ? 'active' : ''; ?>">
                <i class="fas fa-coins"></i> P&L
            </a>
            <a href="?type=inventory&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'inventory' ? 'active' : ''; ?>">
                <i class="fas fa-boxes"></i> Parts
            </a>
            <a href="?type=tool_inventory&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'tool_inventory' ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i> Tools
            </a>
            <a href="?type=customers&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'customers' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Customers
            </a>
            <a href="?type=expenses&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'expenses' ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i> Expenses
            </a>
            <a href="?type=purchases&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'purchases' ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart"></i> Purchases
            </a>
            <a href="?type=receivables&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
               class="tab <?php echo $report_type == 'receivables' ? 'active' : ''; ?>">
                <i class="fas fa-hand-holding-usd"></i> Receivables
            </a>
        </div>

        <!-- Charts (Dashboard Only) -->
        <?php if($report_type == 'dashboard' && !empty($daily_trends)): ?>
        <div class="charts-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Job Trends</h3>
                </div>
                <div class="chart-container">
                    <canvas id="jobTrendsChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-pie"></i> Job Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="jobStatusChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Table -->
        <div class="report-table-container">
            <div class="table-header">
                <h3>
                    <i class="fas fa-list"></i>
                    <?php
                    $report_titles = [
                        'pl' => 'Profit & Loss Statement',
                        'inventory' => 'Parts Inventory Report',
                        'tool_inventory' => 'Tool Inventory Report',
                        'customers' => 'Customer Directory',
                        'expenses' => 'Expenses Report',
                        'purchases' => 'Purchases Report',
                        'receivables' => 'Receivables Report',
                        'dashboard' => 'Transaction Summary'
                    ];
                    echo $report_titles[$report_type] ?? 'Detailed Report';
                    ?>
                </h3>
                <div class="table-search">
                    <input type="text" placeholder="Search records..." id="tableSearch">
                </div>
            </div>

            <?php if(empty($table_data) && $report_type != 'dashboard'): ?>
            <div class="empty-state">
                <i class="fas fa-database"></i>
                <p>No data found for the selected period</p>
                <p style="font-size: 12px; margin-top: 10px;">Try changing the date range or report type</p>
            </div>
            <?php elseif($report_type == 'dashboard' && empty($daily_trends)): ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <p>No transaction data available for this period</p>
                <p style="font-size: 12px; margin-top: 10px;">Try selecting a different date range</p>
            </div>
            <?php else: ?>
            <table class="report-table" id="dataTable">
                <thead>
                    <tr>
                        <?php if($report_type == 'pl'): ?>
                            <th>Date</th><th>Reference</th><th>Type</th><th>Amount</th><th>Status</th>
                        <?php elseif($report_type == 'inventory'): ?>
                            <th>Product Name</th><th>SKU</th><th>Stock</th><th>Reorder Level</th><th>Unit Cost</th><th>Selling Price</th><th>Stock Value</th>
                        <?php elseif($report_type == 'tool_inventory'): ?>
                            <th>Tool Name</th><th>Quantity</th><th>Location</th><th>Condition</th><th>Last Checked</th>
                        <?php elseif($report_type == 'customers'): ?>
                            <th>Customer Name</th><th>Chassis No.</th><th>Last Service</th><th>Phone</th><th>Email</th>
                        <?php elseif($report_type == 'expenses'): ?>
                            <th>Date</th><th>Description</th><th>Category</th><th>Amount</th><th>Payment Method</th>
                        <?php elseif($report_type == 'purchases'): ?>
                            <th>PO Number</th><th>Date</th><th>Supplier</th><th>Amount</th><th>Payment Status</th><th>Due Date</th>
                        <?php elseif($report_type == 'receivables'): ?>
                            <th>Invoice #</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th>
                        <?php else: ?>
                            <th>Date</th><th>Description</th><th>Type</th><th>Amount</th><th>Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if($report_type == 'pl'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['reference'] ?? 'N/A'); ?></td>
                            <td><span class="badge <?php echo ($row['type'] ?? '') == 'income' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst(htmlspecialchars($row['type'] ?? 'N/A')); ?></span></td>
                            <td class="amount <?php echo ($row['type'] ?? '') == 'income' ? 'amount-positive' : 'amount-negative'; ?>">UGX <?php echo number_format($row['amount'] ?? 0); ?></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($row['status'] ?? 'N/A'); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'inventory'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['sku'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($row['current_stock'] ?? 0); ?></td>
                            <td><?php echo number_format($row['reorder_level'] ?? 0); ?></td>
                            <td>UGX <?php echo number_format($row['unit_cost'] ?? 0); ?></td>
                            <td>UGX <?php echo number_format($row['selling_price'] ?? 0); ?></td>
                            <td class="amount">UGX <?php echo number_format($row['stock_value'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'tool_inventory'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['tool_name'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($row['quantity'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></td>
                            <td><span class="badge <?php echo ($row['condition'] ?? 'Good') == 'Good' ? 'badge-success' : 'badge-warning'; ?>"><?php echo htmlspecialchars($row['condition'] ?? 'N/A'); ?></span></td>
                            <td><?php echo htmlspecialchars($row['last_checked'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'customers'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['chassis_no'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['last_service'] ?? 'Never'); ?></td>
                            <td><?php echo htmlspecialchars($row['phone'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['email'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'expenses'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['description'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['category'] ?? 'N/A'); ?></td>
                            <td class="amount amount-negative">UGX <?php echo number_format($row['amount'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($row['payment_method'] ?? 'Cash'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'purchases'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['po_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['supplier_name'] ?? 'N/A'); ?></td>
                            <td class="amount">UGX <?php echo number_format($row['total_amount'] ?? 0); ?></td>
                            <td><span class="badge <?php echo ($row['payment_status'] ?? '') == 'paid' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst(htmlspecialchars($row['payment_status'] ?? 'Pending')); ?></span></td>
                            <td><?php echo htmlspecialchars($row['payment_due_date'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php elseif($report_type == 'receivables'): ?>
                        <?php foreach($table_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['invoice_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['date'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                            <td class="amount">UGX <?php echo number_format($row['total_amount'] ?? 0); ?></td>
                            <td class="amount">UGX <?php echo number_format($row['amount_paid'] ?? 0); ?></td>
                            <td class="amount amount-negative">UGX <?php echo number_format($row['balance_due'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($row['due_date'] ?? 'N/A'); ?></td>
                            <td><span class="badge <?php echo ($row['payment_status'] ?? '') == 'overdue' ? 'badge-danger' : 'badge-warning'; ?>"><?php echo ucfirst(htmlspecialchars($row['payment_status'] ?? 'Pending')); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach($daily_trends as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['date'] ?? 'N/A'); ?></td>
                            <td><?php echo $row['job_count']; ?> jobs created</td>
                            <td><span class="badge badge-info">Jobs</span></td>
                            <td class="amount"><?php echo $row['completed_count']; ?> completed</td>
                            <td><span class="badge badge-success">Active</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Chart initialization (only if data exists)
        <?php if($report_type == 'dashboard' && !empty($daily_trends)): ?>
        // Job Trends Chart
        const dailyLabels = <?php echo json_encode(array_column($daily_trends, 'date')); ?>;
        const dailyJobs = <?php echo json_encode(array_column($daily_trends, 'job_count')); ?>;
        const dailyCompleted = <?php echo json_encode(array_column($daily_trends, 'completed_count')); ?>;
        
        const jobTrendsCtx = document.getElementById('jobTrendsChart').getContext('2d');
        new Chart(jobTrendsCtx, {
            type: 'line',
            data: {
                labels: dailyLabels.map(d => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
                datasets: [
                    {
                        label: 'Total Jobs',
                        data: dailyJobs,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Completed Jobs',
                        data: dailyCompleted,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true } },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}` } }
                },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });

        // Job Status Chart
        const jobStatusCtx = document.getElementById('jobStatusChart').getContext('2d');
        new Chart(jobStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'In Progress', 'Pending'],
                datasets: [{
                    data: [
                        <?php echo $job_stats['completed_jobs'] ?: 0; ?>,
                        <?php echo $job_stats['in_progress_jobs'] ?: 0; ?>,
                        <?php echo $job_stats['pending_jobs'] ?: 0; ?>
                    ],
                    backgroundColor: ['#10b981', '#3b82f6', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '65%'
            }
        });
        <?php endif; ?>

        // Filter functions
        function applyFilter() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            
            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            window.location.href = `reports.php?type=${reportType}&start_date=${startDate}&end_date=${endDate}`;
        }
        
        function exportReport() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            window.location.href = `export_report.php?type=${reportType}&start_date=${startDate}&end_date=${endDate}`;
        }
        
        // Search functionality
        const searchInput = document.getElementById('tableSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                const searchTerm = e.target.value.toLowerCase();
                const table = document.getElementById('dataTable');
                if (!table) return;
                
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        
        // Date validation
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');
        
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', function() {
                if (endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            });
            
            endDateInput.addEventListener('change', function() {
                if (startDateInput.value > this.value) {
                    startDateInput.value = this.value;
                }
            });
        }
        
        // Logout function
        async function logout() {
            try {
                const response = await fetch('/api/auth.php?action=logout', { method: 'POST' });
                const data = await response.json();
                if (data.success) {
                    window.location.href = '/index.php';
                }
            } catch (error) {
                console.error('Logout error:', error);
                window.location.href = '../views/logout.php';
            }
        }
        
        const logoutBtn = document.getElementById('logoutBtn');
        const logoutIcon = document.getElementById('logoutIcon');
        if (logoutBtn) logoutBtn.addEventListener('click', logout);
        if (logoutIcon) logoutIcon.addEventListener('click', logout);
    </script>
</body>
</html>