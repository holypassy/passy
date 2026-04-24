<?php
require_once __DIR__ . '/../config/database.php';

class InvoiceItem {
    private $conn;
    private $table = "invoice_items";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByInvoiceId($invoiceId) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE invoice_id = :invoice_id 
                  ORDER BY id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':invoice_id' => $invoiceId]);
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (invoice_id, description, quantity, unit_price, total_price) 
                  VALUES (:invoice_id, :description, :quantity, :unit_price, :total_price)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':invoice_id' => $data['invoice_id'],
            ':description' => $data['description'],
            ':quantity' => $data['quantity'],
            ':unit_price' => $data['unit_price'],
            ':total_price' => $data['quantity'] * $data['unit_price']
        ]);
    }
    
    public function createMultiple($invoiceId, $items) {
        $this->conn->beginTransaction();
        
        try {
            $query = "INSERT INTO {$this->table} 
                      (invoice_id, description, quantity, unit_price, total_price) 
                      VALUES (:invoice_id, :description, :quantity, :unit_price, :total_price)";
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($items as $item) {
                if (!empty($item['description']) && $item['unit_price'] > 0) {
                    $quantity = floatval($item['quantity'] ?? 1);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    
                    $stmt->execute([
                        ':invoice_id' => $invoiceId,
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
    
    public function deleteByInvoiceId($invoiceId) {
        $query = "DELETE FROM {$this->table} WHERE invoice_id = :invoice_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':invoice_id' => $invoiceId]);
    }
    
    public function calculateTotal($invoiceId) {
        $query = "SELECT COALESCE(SUM(total_price), 0) as total FROM {$this->table} 
                  WHERE invoice_id = :invoice_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':invoice_id' => $invoiceId]);
        return $stmt->fetch()['total'];
    }
}
?>