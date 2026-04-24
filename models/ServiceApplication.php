<?php
require_once __DIR__ . '/../config/database.php';

class ServiceApplication {
    private $conn;
    private $table = "service_applications";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll($filters = []) {
        $query = "SELECT 
                    sa.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email,
                    c.address
                  FROM {$this->table} sa
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            $query .= " AND sa.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['customer_id'])) {
            $query .= " AND sa.customer_id = :customer_id";
            $params[':customer_id'] = $filters['customer_id'];
        }
        
        if (!empty($filters['from_date'])) {
            $query .= " AND sa.service_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $query .= " AND sa.service_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }
        
        $query .= " ORDER BY sa.service_date ASC";
        
        if (!empty($filters['limit'])) {
            $query .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT 
                    sa.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email,
                    c.address
                  FROM {$this->table} sa
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE sa.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (customer_id, vehicle_reg, vehicle_make, vehicle_model, 
                   service_name, service_date, service_type, status, 
                   estimated_cost, notes, created_by) 
                  VALUES (:customer_id, :vehicle_reg, :vehicle_make, :vehicle_model, 
                          :service_name, :service_date, :service_type, :status, 
                          :estimated_cost, :notes, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':customer_id' => $data['customer_id'],
            ':vehicle_reg' => $data['vehicle_reg'],
            ':vehicle_make' => $data['vehicle_make'] ?? null,
            ':vehicle_model' => $data['vehicle_model'] ?? null,
            ':service_name' => $data['service_name'],
            ':service_date' => $data['service_date'],
            ':service_type' => $data['service_type'] ?? 'general',
            ':status' => $data['status'] ?? 'scheduled',
            ':estimated_cost' => $data['estimated_cost'] ?? 0,
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET customer_id = :customer_id,
                      vehicle_reg = :vehicle_reg,
                      vehicle_make = :vehicle_make,
                      vehicle_model = :vehicle_model,
                      service_name = :service_name,
                      service_date = :service_date,
                      service_type = :service_type,
                      status = :status,
                      estimated_cost = :estimated_cost,
                      notes = :notes
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':customer_id' => $data['customer_id'],
            ':vehicle_reg' => $data['vehicle_reg'],
            ':vehicle_make' => $data['vehicle_make'] ?? null,
            ':vehicle_model' => $data['vehicle_model'] ?? null,
            ':service_name' => $data['service_name'],
            ':service_date' => $data['service_date'],
            ':service_type' => $data['service_type'] ?? 'general',
            ':status' => $data['status'] ?? 'scheduled',
            ':estimated_cost' => $data['estimated_cost'] ?? 0,
            ':notes' => $data['notes'] ?? null
        ]);
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }
    
    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function getUpcomingServices($days = 30) {
        $query = "SELECT 
                    sa.*,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} sa
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE sa.service_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
                    AND sa.status NOT IN ('completed', 'cancelled')
                  ORDER BY sa.service_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function getStatistics() {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN service_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue
                  FROM {$this->table}";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }
}
?>