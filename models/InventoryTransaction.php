<?php
require_once __DIR__ . '/../config/database.php';

class InventoryTransaction {
    private $conn;
    private $table = "inventory_transactions";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (product_id, transaction_type, quantity, unit_price, total_amount, 
                   reference_type, reference_id, notes, created_by) 
                  VALUES (:product_id, :transaction_type, :quantity, :unit_price, :total_amount, 
                          :reference_type, :reference_id, :notes, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':product_id' => $data['product_id'],
            ':transaction_type' => $data['transaction_type'],
            ':quantity' => $data['quantity'],
            ':unit_price' => $data['unit_price'] ?? null,
            ':total_amount' => $data['total_amount'] ?? null,
            ':reference_type' => $data['reference_type'] ?? null,
            ':reference_id' => $data['reference_id'] ?? null,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function getByProductId($productId, $limit = 50) {
        $query = "SELECT 
                    it.*,
                    u.full_name as created_by_name
                  FROM {$this->table} it
                  LEFT JOIN users u ON it.created_by = u.id
                  WHERE it.product_id = :product_id
                  ORDER BY it.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function getMovements($startDate, $endDate, $productId = null) {
        $query = "SELECT 
                    it.*,
                    i.product_name,
                    i.sku,
                    u.full_name as created_by_name
                  FROM {$this->table} it
                  LEFT JOIN inventory i ON it.product_id = i.id
                  LEFT JOIN users u ON it.created_by = u.id
                  WHERE DATE(it.created_at) BETWEEN :start_date AND :end_date";
        
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];
        
        if ($productId) {
            $query .= " AND it.product_id = :product_id";
            $params[':product_id'] = $productId;
        }
        
        $query .= " ORDER BY it.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getSummary($startDate, $endDate) {
        $query = "SELECT 
                    transaction_type,
                    COUNT(*) as transaction_count,
                    SUM(quantity) as total_quantity,
                    COALESCE(SUM(total_amount), 0) as total_amount
                  FROM {$this->table}
                  WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                  GROUP BY transaction_type";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
}
?>