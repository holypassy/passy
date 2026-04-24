<?php
require_once __DIR__ . '/../config/database.php';

class Quotation {
    private $conn;
    private $table = "quotations";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAvailableForConversion() {
        $query = "SELECT 
                    q.*, 
                    c.full_name as customer_name, 
                    c.telephone, 
                    c.email,
                    c.address
                  FROM {$this->table} q
                  LEFT JOIN customers c ON q.customer_id = c.id
                  WHERE q.status IN ('sent', 'accepted')
                  ORDER BY q.created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getItems($quotationId) {
        $query = "SELECT * FROM quotation_items WHERE quotation_id = :quotation_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':quotation_id' => $quotationId]);
        return $stmt->fetchAll();
    }
    
    public function markAsInvoiced($id) {
        $query = "UPDATE {$this->table} SET status = 'invoiced' WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
}
?>