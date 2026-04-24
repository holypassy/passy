<?php
require_once __DIR__ . '/../config/database.php';

class VoucherItem {
    private $conn;
    private $table = "voucher_items";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByVoucherId($voucherId) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE voucher_id = :voucher_id 
                  ORDER BY id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':voucher_id' => $voucherId]);
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (voucher_id, description, amount) 
                  VALUES (:voucher_id, :description, :amount)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':voucher_id' => $data['voucher_id'],
            ':description' => $data['description'],
            ':amount' => $data['amount']
        ]);
    }
    
    public function createMultiple($voucherId, $items) {
        $this->conn->beginTransaction();
        
        try {
            $query = "INSERT INTO {$this->table} 
                      (voucher_id, description, amount) 
                      VALUES (:voucher_id, :description, :amount)";
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($items as $item) {
                if (!empty($item['description']) && $item['amount'] > 0) {
                    $stmt->execute([
                        ':voucher_id' => $voucherId,
                        ':description' => $item['description'],
                        ':amount' => $item['amount']
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
    
    public function deleteByVoucherId($voucherId) {
        $query = "DELETE FROM {$this->table} WHERE voucher_id = :voucher_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':voucher_id' => $voucherId]);
    }
    
    public function getTotal($voucherId) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total FROM {$this->table} 
                  WHERE voucher_id = :voucher_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':voucher_id' => $voucherId]);
        return $stmt->fetch()['total'];
    }
}
?>