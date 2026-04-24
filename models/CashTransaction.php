<?php
namespace App\Models;

use Core\Model;

class CashTransaction extends Model {
    protected $table = 'cash_transactions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'transaction_date', 'transaction_type', 'category', 'account_id',
        'amount', 'reference_no', 'description', 'status', 'created_by'
    ];
    
    public function createTransaction($data) {
        return $this->create($data);
    }
    
    public function getWithDetails($id) {
        $stmt = $this->db->prepare("
            SELECT 
                ct.*,
                ca.account_name,
                ca.account_type,
                u.full_name as created_by_name
            FROM cash_transactions ct
            LEFT JOIN cash_accounts ca ON ct.account_id = ca.id
            LEFT JOIN users u ON ct.created_by = u.id
            WHERE ct.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getTransactions($filters = [], $page = 1, $perPage = 15) {
        $sql = "SELECT 
                    ct.*,
                    ca.account_name,
                    ca.account_type,
                    u.full_name as created_by_name
                FROM cash_transactions ct
                LEFT JOIN cash_accounts ca ON ct.account_id = ca.id
                LEFT JOIN users u ON ct.created_by = u.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['account_id'])) {
            $sql .= " AND ct.account_id = ?";
            $params[] = $filters['account_id'];
        }
        
        if (!empty($filters['transaction_type'])) {
            $sql .= " AND ct.transaction_type = ?";
            $params[] = $filters['transaction_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND ct.transaction_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND ct.transaction_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['category'])) {
            $sql .= " AND ct.category = ?";
            $params[] = $filters['category'];
        }
        
        $sql .= " ORDER BY ct.transaction_date DESC, ct.created_at DESC LIMIT ? OFFSET ?";
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM cash_transactions ct WHERE 1=1";
        $countParams = [];
        
        if (!empty($filters['account_id'])) {
            $countSql .= " AND ct.account_id = ?";
            $countParams[] = $filters['account_id'];
        }
        
        if (!empty($filters['transaction_type'])) {
            $countSql .= " AND ct.transaction_type = ?";
            $countParams[] = $filters['transaction_type'];
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch()['total'];
        
        return [
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage)
        ];
    }
    
    public function getSummary($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as net_cash
                FROM cash_transactions
                WHERE (status = 'approved' OR status IS NULL)";
        $params = [];
        
        if ($startDate) {
            $sql .= " AND transaction_date >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND transaction_date <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    public function getMonthlyTrend($months = 6) {
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(transaction_date, '%Y-%m') as month,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
            FROM cash_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            AND (status = 'approved' OR status IS NULL)
            GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$months]);
        return $stmt->fetchAll();
    }
    
    public function getWeeklyTrend($days = 7) {
        $stmt = $this->db->prepare("
            SELECT 
                DATE(transaction_date) as date,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
            FROM cash_transactions
            WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            AND (status = 'approved' OR status IS NULL)
            GROUP BY DATE(transaction_date)
            ORDER BY date ASC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
    
    public function getByCategory($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    category,
                    transaction_type,
                    COALESCE(SUM(amount), 0) as total_amount
                FROM cash_transactions
                WHERE 1=1";
        $params = [];
        
        if ($startDate) {
            $sql .= " AND transaction_date >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND transaction_date <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY category, transaction_type ORDER BY total_amount DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}