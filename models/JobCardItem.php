<?php
require_once __DIR__ . '/../config/database.php';

class JobCardItem {
    private $conn;
    private $table = "job_card_items";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByJobId($jobCardId) {
        $query = "SELECT 
                    ji.*,
                    i.product_name,
                    i.sku,
                    i.product_code
                  FROM {$this->table} ji
                  LEFT JOIN inventory i ON ji.product_id = i.id
                  WHERE ji.job_card_id = :job_card_id
                  ORDER BY ji.id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':job_card_id' => $jobCardId]);
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (job_card_id, product_id, description, quantity, unit_price, total, notes) 
                  VALUES (:job_card_id, :product_id, :description, :quantity, :unit_price, :total, :notes)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':job_card_id' => $data['job_card_id'],
            ':product_id' => $data['product_id'] ?? null,
            ':description' => $data['description'],
            ':quantity' => $data['quantity'],
            ':unit_price' => $data['unit_price'],
            ':total' => $data['quantity'] * $data['unit_price'],
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET quantity = :quantity,
                      unit_price = :unit_price,
                      total = :total,
                      notes = :notes
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':quantity' => $data['quantity'],
            ':unit_price' => $data['unit_price'],
            ':total' => $data['quantity'] * $data['unit_price'],
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function getTotalByJobId($jobCardId) {
        $query = "SELECT COALESCE(SUM(total), 0) as total FROM {$this->table} WHERE job_card_id = :job_card_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':job_card_id' => $jobCardId]);
        return $stmt->fetch()['total'];
    }
}
?>