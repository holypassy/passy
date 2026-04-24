<?php
class Dashboard {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function getFinancialSummary() {
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
                    COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
                    COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
                    COALESCE(SUM(balance), 0) as total_balance
                  FROM cash_accounts
                  WHERE is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_cash' => 0, 
            'total_bank' => 0, 
            'total_mobile' => 0, 
            'total_balance' => 0
        ];
    }
    
    public function getCashFlow($month = null, $year = null) {
        $month = $month ?? date('m');
        $year = $year ?? date('Y');
        
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expenses,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_cash_flow
                  FROM cash_transactions
                  WHERE MONTH(transaction_date) = :month 
                    AND YEAR(transaction_date) = :year
                    AND status = 'approved'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':month' => $month,
            ':year' => $year
        ]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_income' => 0, 
            'total_expenses' => 0, 
            'net_cash_flow' => 0
        ];
    }
    
    public function getJobStatistics() {
        $query = "SELECT 
                    COUNT(*) as total_jobs,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_jobs,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_jobs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN date_promised < CURDATE() AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_jobs
                  FROM job_cards
                  WHERE deleted_at IS NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'in_progress_jobs' => 0,
            'completed_jobs' => 0,
            'overdue_jobs' => 0
        ];
    }
    
    public function getInventoryStatistics() {
        $query = "SELECT 
                    COUNT(*) as total_products,
                    SUM(current_stock) as total_items,
                    SUM(CASE WHEN current_stock <= reorder_level THEN 1 ELSE 0 END) as low_stock_items,
                    COALESCE(SUM(current_stock * cost_price), 0) as inventory_value
                  FROM inventory
                  WHERE is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_products' => 0,
            'total_items' => 0,
            'low_stock_items' => 0,
            'inventory_value' => 0
        ];
    }
    
    public function getPendingReminders($limit = 5) {
        $query = "SELECT 
                    vpr.*,
                    c.full_name,
                    c.telephone,
                    c.email,
                    jc.job_number
                  FROM vehicle_pickup_reminders vpr
                  LEFT JOIN customers c ON vpr.customer_id = c.id
                  LEFT JOIN job_cards jc ON vpr.job_card_id = jc.id
                  WHERE vpr.status = 'pending' 
                    AND vpr.reminder_date <= CURDATE() 
                    AND vpr.reminder_sent = 0
                  ORDER BY vpr.pickup_date ASC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getRecentTransactions($limit = 5) {
        $query = "SELECT 
                    ct.*,
                    ca.account_name,
                    u.full_name as created_by_name
                  FROM cash_transactions ct
                  LEFT JOIN cash_accounts ca ON ct.account_id = ca.id
                  LEFT JOIN users u ON ct.created_by = u.id
                  ORDER BY ct.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getWeeklyPerformance() {
        $chart_labels = [];
        $chart_revenue = [];
        $chart_expenses = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chart_labels[] = date('D', strtotime($date));
            
            $query = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expenses
                      FROM cash_transactions
                      WHERE DATE(transaction_date) = :date 
                        AND status = 'approved'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':date' => $date]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $chart_revenue[] = $data['income'] ?? 0;
            $chart_expenses[] = $data['expenses'] ?? 0;
        }
        
        return [
            'labels' => $chart_labels,
            'revenue' => $chart_revenue,
            'expenses' => $chart_expenses
        ];
    }
    
    public function getAlerts() {
        $alerts = [];
        
        $jobStats = $this->getJobStatistics();
        if ($jobStats['overdue_jobs'] > 0) {
            $alerts[] = [
                'type' => 'danger', 
                'message' => "{$jobStats['overdue_jobs']} job(s) are overdue"
            ];
        }
        
        $inventoryStats = $this->getInventoryStatistics();
        if ($inventoryStats['low_stock_items'] > 0) {
            $alerts[] = [
                'type' => 'warning', 
                'message' => "{$inventoryStats['low_stock_items']} product(s) low in stock"
            ];
        }
        
        $pendingReminders = $this->getPendingReminders(1);
        if (count($pendingReminders) > 0) {
            $alerts[] = [
                'type' => 'info', 
                'message' => count($pendingReminders) . " vehicle(s) waiting for pickup"
            ];
        }
        
        return $alerts;
    }
    
    public function sendReminder($reminderId) {
        try {
            $query = "SELECT 
                        vpr.*, 
                        c.full_name, 
                        c.telephone, 
                        c.email 
                      FROM vehicle_pickup_reminders vpr
                      LEFT JOIN customers c ON vpr.customer_id = c.id
                      WHERE vpr.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':id' => $reminderId]);
            $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reminder) {
                return ['success' => false, 'message' => 'Reminder not found'];
            }
            
            $message = "Dear " . $reminder['full_name'] . ",\n\n" .
                       "Your vehicle " . $reminder['vehicle_reg'] . " is ready for pickup.\n" .
                       "Pickup Date: " . date('l, F j, Y', strtotime($reminder['pickup_date'])) . "\n\n" .
                       "Thank you for choosing Savant Motors!";
            
            // Log to reminder history
            $historyQuery = "INSERT INTO reminder_history 
                            (reminder_id, reminder_type, sent_to, message, sent_status) 
                            VALUES (:reminder_id, :reminder_type, :sent_to, :message, 'sent')";
            
            $historyStmt = $this->conn->prepare($historyQuery);
            $historyStmt->execute([
                ':reminder_id' => $reminder['id'],
                ':reminder_type' => $reminder['reminder_type'],
                ':sent_to' => $reminder['telephone'],
                ':message' => $message
            ]);
            
            // Update reminder as sent
            $updateQuery = "UPDATE vehicle_pickup_reminders 
                           SET reminder_sent = 1 
                           WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->execute([':id' => $reminder['id']]);
            
            return [
                'success' => true, 
                'message' => "Reminder sent to " . $reminder['full_name']
            ];
            
        } catch (PDOException $e) {
            error_log("Send reminder error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error sending reminder'];
        }
    }
}
?>