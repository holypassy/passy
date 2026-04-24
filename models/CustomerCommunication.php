<?php
require_once __DIR__ . '/../config/database.php';

class CustomerCommunication {
    private $conn;
    private $table = "customer_communications";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByCustomerId($customerId, $limit = 20) {
        $query = "SELECT 
                    cc.*,
                    u.full_name as assigned_to_name,
                    c.full_name as created_by_name
                  FROM {$this->table} cc
                  LEFT JOIN users u ON cc.assigned_to = u.id
                  LEFT JOIN users c ON cc.created_by = c.id
                  WHERE cc.customer_id = :customer_id
                  ORDER BY cc.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (customer_id, comm_type, subject, message, priority, assigned_to, created_by) 
                  VALUES (:customer_id, :comm_type, :subject, :message, :priority, :assigned_to, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':customer_id' => $data['customer_id'],
            ':comm_type' => $data['comm_type'],
            ':subject' => $data['subject'],
            ':message' => $data['message'],
            ':priority' => $data['priority'] ?? 'medium',
            ':assigned_to' => $data['assigned_to'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET comm_status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }
    
    public function getOpenIssues() {
        $query = "SELECT 
                    cc.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} cc
                  LEFT JOIN customers c ON cc.customer_id = c.id
                  WHERE cc.comm_status != 'closed'
                    AND c.status = 1
                  ORDER BY 
                    CASE cc.priority
                        WHEN 'high' THEN 1
                        WHEN 'medium' THEN 2
                        WHEN 'low' THEN 3
                    END,
                    cc.created_at ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>