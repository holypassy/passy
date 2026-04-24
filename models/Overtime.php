<?php
require_once __DIR__ . '/../config/database.php';

class Overtime {
    private $conn;
    private $table = "technician_overtime";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll($filters = []) {
        $query = "SELECT 
                    o.*, 
                    t.full_name, 
                    t.technician_code,
                    j.job_number
                  FROM {$this->table} o
                  JOIN technicians t ON o.technician_id = t.id
                  LEFT JOIN job_cards j ON o.job_id = j.id
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            $query .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['technician_id'])) {
            $query .= " AND o.technician_id = :technician_id";
            $params[':technician_id'] = $filters['technician_id'];
        }
        
        if (!empty($filters['from_date'])) {
            $query .= " AND o.overtime_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $query .= " AND o.overtime_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }
        
        $query .= " ORDER BY o.requested_at DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT 
                    o.*, 
                    t.full_name, 
                    t.technician_code,
                    t.phone,
                    t.email
                  FROM {$this->table} o
                  JOIN technicians t ON o.technician_id = t.id
                  WHERE o.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (technician_id, overtime_date, hours_requested, reason, job_id, requested_by) 
                  VALUES (:technician_id, :overtime_date, :hours_requested, :reason, :job_id, :requested_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':technician_id' => $data['technician_id'],
            ':overtime_date' => $data['overtime_date'],
            ':hours_requested' => $data['hours_requested'],
            ':reason' => $data['reason'],
            ':job_id' => $data['job_id'] ?? null,
            ':requested_by' => $data['requested_by']
        ]);
    }
    
    public function approve($id, $approvedBy) {
        $query = "UPDATE {$this->table} 
                  SET status = 'approved', 
                      approved_by = :approved_by,
                      approved_at = NOW() 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':approved_by' => $approvedBy
        ]);
    }
    
    public function reject($id, $rejectedBy, $reason = null) {
        $query = "UPDATE {$this->table} 
                  SET status = 'rejected', 
                      approved_by = :approved_by,
                      approved_at = NOW(),
                      rejection_reason = :reason
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':approved_by' => $rejectedBy,
            ':reason' => $reason
        ]);
    }
    
    public function getPendingCount() {
        $query = "SELECT COUNT(*) as count FROM {$this->table} WHERE status = 'pending'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch()['count'];
    }
    
    public function getStatistics($startDate, $endDate) {
        $query = "SELECT 
                    SUM(CASE WHEN status = 'approved' THEN hours_requested ELSE 0 END) as total_approved_hours,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_requests,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_requests,
                    COUNT(*) as total_requests
                  FROM {$this->table}
                  WHERE overtime_date BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetch();
    }
}
?>