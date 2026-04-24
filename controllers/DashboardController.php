<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Customer.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/JobCard.php';
require_once __DIR__ . '/../models/CashTransaction.php';
require_once __DIR__ . '/../models/Reminder.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';

class DashboardController {
    private $userModel;
    private $customerModel;
    private $inventoryModel;
    private $jobCardModel;
    private $cashTransactionModel;
    private $reminderModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->userModel = new User();
        $this->customerModel = new Customer();
        $this->inventoryModel = new Inventory();
        $this->jobCardModel = new JobCard();
        $this->cashTransactionModel = new CashTransaction();
        $this->reminderModel = new Reminder();
    }
    
    public function getStats() {
        AuthMiddleware::authenticate();
        
        $stats = [
            'financial' => $this->getFinancialSummary(),
            'cash_flow' => $this->getCashFlow(),
            'jobs' => $this->jobCardModel->getStatistics(),
            'inventory' => $this->inventoryModel->getSummary(),
            'reminders' => $this->reminderModel->getPending(5),
            'transactions' => $this->cashTransactionModel->getRecent(5),
            'weekly_performance' => $this->getWeeklyPerformance(),
            'alerts' => $this->getAlerts(),
            'user' => AuthMiddleware::getCurrentUser()
        ];
        
        $this->jsonResponse(['success' => true, 'data' => $stats]);
    }
    
    private function getFinancialSummary() {
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN account_type = 'cash' THEN balance ELSE 0 END), 0) as total_cash,
                    COALESCE(SUM(CASE WHEN account_type = 'bank' THEN balance ELSE 0 END), 0) as total_bank,
                    COALESCE(SUM(CASE WHEN account_type = 'mobile_money' THEN balance ELSE 0 END), 0) as total_mobile,
                    COALESCE(SUM(balance), 0) as total_balance
                  FROM cash_accounts
                  WHERE is_active = 1";
        
        $database = Database::getInstance();
        $conn = $database->getConnection();
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    private function getCashFlow() {
        $month = date('m');
        $year = date('Y');
        
        $query = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expenses,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_cash_flow
                  FROM cash_transactions
                  WHERE MONTH(transaction_date) = :month 
                    AND YEAR(transaction_date) = :year
                    AND status = 'approved'";
        
        $database = Database::getInstance();
        $conn = $database->getConnection();
        $stmt = $conn->prepare($query);
        $stmt->execute([':month' => $month, ':year' => $year]);
        
        return $stmt->fetch();
    }
    
    private function getWeeklyPerformance() {
        $labels = [];
        $revenue = [];
        $expenses = [];
        
        $database = Database::getInstance();
        $conn = $database->getConnection();
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('D', strtotime($date));
            
            $query = "SELECT 
                        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expenses
                      FROM cash_transactions
                      WHERE DATE(transaction_date) = :date AND status = 'approved'";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([':date' => $date]);
            $data = $stmt->fetch();
            
            $revenue[] = $data['income'] ?? 0;
            $expenses[] = $data['expenses'] ?? 0;
        }
        
        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'expenses' => $expenses
        ];
    }
    
    private function getAlerts() {
        $alerts = [];
        
        $jobStats = $this->jobCardModel->getStatistics();
        if ($jobStats['overdue_jobs'] > 0) {
            $alerts[] = [
                'type' => 'danger',
                'message' => "{$jobStats['overdue_jobs']} job(s) are overdue"
            ];
        }
        
        $inventoryStats = $this->inventoryModel->getSummary();
        if ($inventoryStats['low_stock_items'] > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "{$inventoryStats['low_stock_items']} product(s) low in stock"
            ];
        }
        
        $pendingReminders = $this->reminderModel->getPending(1);
        if (count($pendingReminders) > 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => count($pendingReminders) . " vehicle(s) waiting for pickup"
            ];
        }
        
        return $alerts;
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }
}
?>