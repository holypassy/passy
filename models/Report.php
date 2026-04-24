<?php
require_once __DIR__ . '/../config/database.php';

class Report {
    private $conn;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getJobStatistics($startDate, $endDate) {
        $query = "SELECT 
                    COUNT(DISTINCT id) as total_jobs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_jobs,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_jobs,
                    COALESCE(SUM(total_amount), 0) as total_value
                  FROM job_cards 
                  WHERE created_at BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetch();
    }
    
    public function getFinancialSummary($startDate, $endDate) {
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expenses,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_profit,
                    COALESCE(SUM(CASE WHEN category = 'Sales' THEN amount ELSE 0 END), 0) as sales_revenue,
                    COALESCE(SUM(CASE WHEN category = 'Service' THEN amount ELSE 0 END), 0) as service_revenue,
                    COUNT(CASE WHEN transaction_type = 'income' THEN 1 END) as transaction_count
                  FROM cash_transactions
                  WHERE transaction_date BETWEEN :start_date AND :end_date
                    AND status = 'approved'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetch();
    }
    
    public function getInvoiceSummary($startDate, $endDate) {
        $query = "SELECT 
                    COALESCE(SUM(total_amount), 0) as total_invoiced,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as collected,
                    COALESCE(SUM(CASE WHEN payment_status != 'paid' THEN (total_amount - COALESCE(amount_paid, 0)) ELSE 0 END), 0) as receivables,
                    COALESCE(AVG(total_amount), 0) as avg_invoice_value,
                    COUNT(*) as invoice_count,
                    COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count
                  FROM invoices 
                  WHERE created_at BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetch();
    }
    
    public function getDailyTrends($startDate, $endDate) {
        $query = "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as job_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    COALESCE(SUM(total_amount), 0) as total_value
                  FROM job_cards
                  WHERE created_at BETWEEN :start_date AND :end_date
                  GROUP BY DATE(created_at)
                  ORDER BY date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getProfitLossStatement($startDate, $endDate) {
        $query = "SELECT 
                    'Revenue' as section,
                    category,
                    COALESCE(SUM(amount), 0) as amount
                  FROM cash_transactions
                  WHERE transaction_type = 'income'
                    AND transaction_date BETWEEN :start_date AND :end_date
                    AND status = 'approved'
                  GROUP BY category
                  
                  UNION ALL
                  
                  SELECT 
                    'Expenses' as section,
                    category,
                    COALESCE(SUM(amount), 0) as amount
                  FROM cash_transactions
                  WHERE transaction_type = 'expense'
                    AND transaction_date BETWEEN :start_date AND :end_date
                    AND status = 'approved'
                  GROUP BY category
                  
                  ORDER BY section, amount DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getInventoryReport() {
        $query = "SELECT 
                    id,
                    product_code as sku,
                    product_name as name,
                    current_stock,
                    reorder_level,
                    cost_price as unit_cost,
                    selling_price,
                    (current_stock * cost_price) as stock_value,
                    CASE 
                        WHEN current_stock <= reorder_level THEN 'Low Stock'
                        WHEN current_stock = 0 THEN 'Out of Stock'
                        ELSE 'In Stock'
                    END as stock_status
                  FROM inventory
                  WHERE is_active = 1
                  ORDER BY name ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function getExpensesReport($startDate, $endDate) {
        $query = "SELECT 
                    DATE(transaction_date) as date,
                    description,
                    amount,
                    category,
                    payment_method,
                    reference_no,
                    u.full_name as created_by_name
                  FROM cash_transactions ct
                  LEFT JOIN users u ON ct.created_by = u.id
                  WHERE ct.transaction_type = 'expense' 
                    AND ct.transaction_date BETWEEN :start_date AND :end_date
                    AND ct.status = 'approved'
                  ORDER BY ct.transaction_date DESC
                  LIMIT 500";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getPurchasesReport($startDate, $endDate) {
        $query = "SELECT 
                    po_number,
                    DATE(created_at) as date,
                    supplier_name,
                    total_amount,
                    payment_status,
                    payment_due_date,
                    created_by
                  FROM purchases 
                  WHERE created_at BETWEEN :start_date AND :end_date
                  ORDER BY created_at DESC
                  LIMIT 500";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getReceivablesReport($startDate, $endDate) {
        $query = "SELECT 
                    invoice_number,
                    DATE(created_at) as date,
                    customer_name,
                    total_amount,
                    COALESCE(amount_paid, 0) as amount_paid,
                    (total_amount - COALESCE(amount_paid, 0)) as balance_due,
                    due_date,
                    payment_status,
                    CASE 
                        WHEN due_date < CURDATE() AND payment_status != 'paid' THEN 'Overdue'
                        WHEN due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND payment_status != 'paid' THEN 'Due Soon'
                        ELSE payment_status
                    END as status_display
                  FROM invoices 
                  WHERE payment_status != 'paid' 
                    AND created_at BETWEEN :start_date AND :end_date
                  ORDER BY due_date ASC
                  LIMIT 500";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getCategoryBreakdown($startDate, $endDate) {
        $query = "SELECT 
                    category,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expenses,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net
                  FROM cash_transactions
                  WHERE transaction_date BETWEEN :start_date AND :end_date
                    AND status = 'approved'
                    AND category IS NOT NULL
                  GROUP BY category
                  ORDER BY net DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getMonthlyComparison($year = null) {
        $year = $year ?? date('Y');
        
        $query = "SELECT 
                    MONTH(transaction_date) as month,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expenses
                  FROM cash_transactions
                  WHERE YEAR(transaction_date) = :year
                    AND status = 'approved'
                  GROUP BY MONTH(transaction_date)
                  ORDER BY month ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':year' => $year]);
        
        return $stmt->fetchAll();
    }
    
    public function getTopCustomers($startDate, $endDate, $limit = 10) {
        $query = "SELECT 
                    c.id,
                    c.full_name,
                    c.telephone,
                    COUNT(jc.id) as job_count,
                    COALESCE(SUM(jc.total_amount), 0) as total_spent
                  FROM customers c
                  LEFT JOIN job_cards jc ON c.id = jc.customer_id
                  WHERE jc.created_at BETWEEN :start_date AND :end_date
                  GROUP BY c.id
                  ORDER BY total_spent DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':start_date', $startDate . ' 00:00:00');
        $stmt->bindValue(':end_date', $endDate . ' 23:59:59');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function getPerformanceMetrics($startDate, $endDate) {
        $query = "SELECT 
                    AVG(TIMESTAMPDIFF(HOUR, created_at, completed_at)) as avg_completion_hours,
                    COUNT(CASE WHEN date_promised >= CURDATE() THEN 1 END) as on_time_delivery,
                    COUNT(CASE WHEN date_promised < CURDATE() AND status != 'completed' THEN 1 END) as overdue_jobs
                  FROM job_cards
                  WHERE created_at BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate . ' 00:00:00',
            ':end_date' => $endDate . ' 23:59:59'
        ]);
        
        return $stmt->fetch();
    }
}
?>