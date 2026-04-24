<?php
require_once __DIR__ . '/../config/database.php';

class DailyReport {
    private $conn;
    private $table = "technician_daily_reports";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByDate($date, $filters = []) {
        $query = "SELECT 
                    tdr.*, 
                    t.full_name, 
                    t.technician_code
                  FROM {$this->table} tdr
                  JOIN technicians t ON tdr.technician_id = t.id
                  WHERE tdr.report_date = :date";
        
        $params = [':date' => $date];
        
        if (!empty($filters['technician_id'])) {
            $query .= " AND tdr.technician_id = :technician_id";
            $params[':technician_id'] = $filters['technician_id'];
        }
        
        $query .= " ORDER BY tdr.submitted_at DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getByTechnician($technicianId, $startDate, $endDate) {
        $query = "SELECT * FROM {$this->table}
                  WHERE technician_id = :technician_id
                    AND report_date BETWEEN :start_date AND :end_date
                  ORDER BY report_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        // Check if report already exists for today
        $checkQuery = "SELECT id FROM {$this->table} 
                       WHERE technician_id = :technician_id 
                         AND report_date = :report_date";
        
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->execute([
            ':technician_id' => $data['technician_id'],
            ':report_date' => $data['report_date']
        ]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Report already submitted for today'];
        }
        
        $query = "INSERT INTO {$this->table} 
                  (technician_id, report_date, jobs_assigned, jobs_completed, 
                   tasks_completed, tools_used, challenges, next_day_plan, notes) 
                  VALUES (:technician_id, :report_date, :jobs_assigned, :jobs_completed, 
                          :tasks_completed, :tools_used, :challenges, :next_day_plan, :notes)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':technician_id' => $data['technician_id'],
            ':report_date' => $data['report_date'],
            ':jobs_assigned' => $data['jobs_assigned'] ?? 0,
            ':jobs_completed' => $data['jobs_completed'] ?? 0,
            ':tasks_completed' => $data['tasks_completed'] ?? null,
            ':tools_used' => $data['tools_used'] ?? null,
            ':challenges' => $data['challenges'] ?? null,
            ':next_day_plan' => $data['next_day_plan'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);
        
        if ($result) {
            return ['success' => true, 'id' => $this->conn->lastInsertId()];
        }
        
        return ['success' => false, 'message' => 'Failed to submit report'];
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET report_status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }
    
    public function findById($id) {
        $query = "SELECT 
                    tdr.*, 
                    t.full_name, 
                    t.technician_code,
                    t.department
                  FROM {$this->table} tdr
                  JOIN technicians t ON tdr.technician_id = t.id
                  WHERE tdr.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
}
?>