<?php
require_once __DIR__ . '/../config/database.php';

class CustomerInteraction {
    private $conn;
    private $table = "customer_interactions";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByCustomerId($customerId, $limit = 20) {
        $query = "SELECT 
                    ci.*,
                    u.full_name as created_by_name
                  FROM {$this->table} ci
                  LEFT JOIN users u ON ci.created_by = u.id
                  WHERE ci.customer_id = :customer_id
                  ORDER BY ci.interaction_date DESC, ci.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (customer_id, interaction_date, interaction_type, summary, notes, follow_up_date, created_by) 
                  VALUES (:customer_id, :interaction_date, :interaction_type, :summary, :notes, :follow_up_date, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':customer_id' => $data['customer_id'],
            ':interaction_date' => $data['interaction_date'],
            ':interaction_type' => $data['interaction_type'],
            ':summary' => $data['summary'],
            ':notes' => $data['notes'] ?? null,
            ':follow_up_date' => $data['follow_up_date'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function getUpcomingFollowUps($days = 7) {
        $query = "SELECT 
                    ci.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} ci
                  LEFT JOIN customers c ON ci.customer_id = c.id
                  WHERE ci.follow_up_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
                    AND ci.follow_up_date IS NOT NULL
                    AND c.status = 1
                  ORDER BY ci.follow_up_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>