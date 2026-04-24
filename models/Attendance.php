<?php
require_once __DIR__ . '/../config/database.php';

class Attendance {
    private $conn;
    private $table = "technician_attendance";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByDate($date, $filters = []) {
        $query = "SELECT 
                    ta.*, 
                    t.full_name, 
                    t.technician_code, 
                    t.department,
                    TIME_FORMAT(ta.check_in_time, '%h:%i %p') as check_in_formatted,
                    TIME_FORMAT(ta.check_out_time, '%h:%i %p') as check_out_formatted
                  FROM {$this->table} ta
                  JOIN technicians t ON ta.technician_id = t.id
                  WHERE ta.attendance_date = :date";
        
        $params = [':date' => $date];
        
        if (!empty($filters['technician_id'])) {
            $query .= " AND ta.technician_id = :technician_id";
            $params[':technician_id'] = $filters['technician_id'];
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND ta.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        $query .= " ORDER BY ta.check_in_time IS NULL, ta.check_in_time ASC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getByTechnician($technicianId, $startDate, $endDate) {
        $query = "SELECT 
                    *,
                    TIME_FORMAT(check_in_time, '%h:%i %p') as check_in_formatted,
                    TIME_FORMAT(check_out_time, '%h:%i %p') as check_out_formatted
                  FROM {$this->table}
                  WHERE technician_id = :technician_id
                    AND attendance_date BETWEEN :start_date AND :end_date
                  ORDER BY attendance_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function checkIn($data) {
        // Check if already checked in today
        $checkQuery = "SELECT id FROM {$this->table} 
                       WHERE technician_id = :technician_id 
                         AND attendance_date = :attendance_date";
        
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->execute([
            ':technician_id' => $data['technician_id'],
            ':attendance_date' => $data['attendance_date']
        ]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Already checked in today'];
        }
        
        $query = "INSERT INTO {$this->table} 
                  (technician_id, attendance_date, check_in_time, status, notes) 
                  VALUES (:technician_id, :attendance_date, :check_in_time, :status, :notes)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':technician_id' => $data['technician_id'],
            ':attendance_date' => $data['attendance_date'],
            ':check_in_time' => $data['check_in_time'],
            ':status' => $data['status'] ?? 'present',
            ':notes' => $data['notes'] ?? null
        ]);
        
        if ($result) {
            return ['success' => true, 'id' => $this->conn->lastInsertId()];
        }
        
        return ['success' => false, 'message' => 'Failed to check in'];
    }
    
    public function checkOut($technicianId, $date, $checkOutTime) {
        $query = "UPDATE {$this->table} 
                  SET check_out_time = :check_out_time,
                      total_hours = TIMESTAMPDIFF(HOUR, check_in_time, :check_out_time)
                  WHERE technician_id = :technician_id 
                    AND attendance_date = :attendance_date
                    AND check_out_time IS NULL";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':technician_id' => $technicianId,
            ':attendance_date' => $date,
            ':check_out_time' => $checkOutTime
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['success' => true];
        }
        
        return ['success' => false, 'message' => 'No active check-in found'];
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }
    
    public function getWeeklySummary($startDate, $endDate) {
        $query = "SELECT 
                    attendance_date,
                    COUNT(DISTINCT technician_id) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_day,
                    SUM(total_hours) as total_hours
                  FROM {$this->table}
                  WHERE attendance_date BETWEEN :start_date AND :end_date
                  GROUP BY attendance_date
                  ORDER BY attendance_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getMonthlyStatistics($startDate, $endDate) {
        $query = "SELECT 
                    COUNT(DISTINCT technician_id) as total_technicians,
                    COUNT(*) as total_attendance_records,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as total_present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as total_late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as total_absent,
                    COALESCE(SUM(total_hours), 0) as total_hours_worked,
                    COALESCE(AVG(total_hours), 0) as avg_daily_hours
                  FROM {$this->table}
                  WHERE attendance_date BETWEEN :start_date AND :end_date";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        
        return $stmt->fetch();
    }
}
?>