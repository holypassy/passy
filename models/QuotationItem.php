<?php
require_once __DIR__ . '/../config/database.php';

class QuotationItem {
    private $conn;
    private $table = "quotation_items";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByQuotationId($quotationId) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE quotation_id = :quotation_id 
                  ORDER BY id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':quotation_id' => $quotationId]);
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (quotation_id, description, quantity, unit_price, total_price) 
                  VALUES (:quotation_id, :description, :quantity, :unit_price, :total_price)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':quotation_id' => $data['quotation_id'],
            ':description' => $data['description'],
            ':quantity' => $data['quantity'],
            ':unit_price' => $data['unit_price'],
            ':total_price' => $data['quantity'] * $data['unit_price']
        ]);
    }
    
    public function createMultiple($quotationId, $items) {
        $this->conn->beginTransaction();
        
        try {
            $query = "INSERT INTO {$this->table} 
                      (quotation_id, description, quantity, unit_price, total_price) 
                      VALUES (:quotation_id, :description, :quantity, :unit_price, :total_price)";
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($items as $item) {
                if (!empty($item['description'])) {
                    $quantity = floatval($item['quantity'] ?? 1);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    
                    $stmt->execute([
                        ':quotation_id' => $quotationId,
                        ':description' => $item['description'],
                        ':quantity' => $quantity,
                        ':unit_price' => $unit_price,
                        ':total_price' => $quantity * $unit_price
                    ]);
                }
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }
    
    public function deleteByQuotationId($quotationId) {
        $query = "DELETE FROM {$this->table} WHERE quotation_id = :quotation_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':quotation_id' => $quotationId]);
    }
    
    public function calculateTotal($quotationId) {
        $query = "SELECT COALESCE(SUM(total_price), 0) as total FROM {$this->table} 
                  WHERE quotation_id = :quotation_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':quotation_id' => $quotationId]);
        return $stmt->fetch()['total'];
    }
}
?>