<?php
require_once __DIR__ . '/../config/database.php';

class JobInspection {
    private $conn;
    private $table = "job_inspections";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (job_card_id, section, item_name, status, notes) 
                  VALUES (:job_card_id, :section, :item_name, :status, :notes)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':job_card_id' => $data['job_card_id'],
            ':section' => $data['section'],
            ':item_name' => $data['item_name'],
            ':status' => $data['status'],
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function getByJobId($jobCardId) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE job_card_id = :job_card_id 
                  ORDER BY section, id ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':job_card_id' => $jobCardId]);
        return $stmt->fetchAll();
    }
    
    public function getBySection($jobCardId, $section) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE job_card_id = :job_card_id AND section = :section";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':job_card_id' => $jobCardId,
            ':section' => $section
        ]);
        return $stmt->fetchAll();
    }
    
    public function deleteByJobId($jobCardId) {
        $query = "DELETE FROM {$this->table} WHERE job_card_id = :job_card_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':job_card_id' => $jobCardId]);
    }
}
?>