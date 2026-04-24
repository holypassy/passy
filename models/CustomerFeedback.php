<?php
require_once __DIR__ . '/../config/database.php';

class CustomerFeedback {
    private $conn;
    private $table = "customer_feedback";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByCustomerId($customerId, $limit = 10) {
        $query = "SELECT * FROM {$this->table} 
                  WHERE customer_id = :customer_id
                  ORDER BY feedback_date DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (customer_id, feedback_date, rating, feedback_text, category) 
                  VALUES (:customer_id, CURDATE(), :rating, :feedback_text, :category)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':customer_id' => $data['customer_id'],
            ':rating' => $data['rating'],
            ':feedback_text' => $data['feedback_text'],
            ':category' => $data['category'] ?? 'General'
        ]);
    }
    
    public function getStatistics() {
        $query = "SELECT 
                    AVG(rating) as avg_rating,
                    COUNT(*) as total_feedback,
                    SUM(CASE WHEN rating >= 4 THEN 1 ELSE 0 END) as positive_feedback,
                    SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negative_feedback
                  FROM {$this->table}";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function getByRating($rating) {
        $query = "SELECT 
                    cf.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} cf
                  LEFT JOIN customers c ON cf.customer_id = c.id
                  WHERE cf.rating = :rating
                    AND c.status = 1
                  ORDER BY cf.feedback_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':rating' => $rating]);
        
        return $stmt->fetchAll();
    }
}
?>