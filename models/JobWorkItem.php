<?php
require_once __DIR__ . '/../config/database.php';

class JobWorkItem {
    private $conn;
    private $table = "job_work_items";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (job_card_id, part_number, description, notes) 
                  VALUES (:job_card_id, :part_number, :description, :notes)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':job_card_id' => $data['job_card_id'],
            ':part_number' => $data['part_number'] ?? null,
            ':description' => $data['description'],
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function getByJobId($jobCardId) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE job_card_id = :job_card_id 
                  ORDER BY id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':job_card_id' => $jobCardId]);
        return $stmt->fetchAll();
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET part_number = :part_number,
                      description = :description,
                      notes = :notes
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':part_number' => $data['part_number'] ?? null,
            ':description' => $data['description'],
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function deleteByJobId($jobCardId) {
        $query = "DELETE FROM {$this->table} WHERE job_card_id = :job_card_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':job_card_id' => $jobCardId]);
    }
}
?>