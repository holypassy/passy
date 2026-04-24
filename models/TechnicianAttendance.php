<?php
require_once __DIR__ . '/../config/database.php';

class TechnicianAttendance {
    private $conn;
    private $table = "technician_attendance";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByTechnicianId($technicianId, $startDate, $endDate) {
        $query = "SELECT * FROM {$this->table} 
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
    
    public function getMonthlySummary($technicianId, $year, $month) {
        $query = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
                    SUM(total_hours) as total_hours
                  FROM {$this->table}
                  WHERE technician_id = :technician_id
                    AND YEAR(attendance_date) = :year
                    AND MONTH(attendance_date) = :month";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':year' => $year,
            ':month' => $month
        ]);
        
        return $stmt->fetch();
    }
    
    public function recordAttendance($data) {
        $query = "INSERT INTO {$this->table} 
                  (technician_id, attendance_date, check_in_time, check_out_time, 
                   total_hours, status, notes) 
                  VALUES (:technician_id, :attendance_date, :check_in_time, :check_out_time,
                          :total_hours, :status, :notes)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':technician_id' => $data['technician_id'],
            ':attendance_date' => $data['attendance_date'],
            ':check_in_time' => $data['check_in_time'] ?? null,
            ':check_out_time' => $data['check_out_time'] ?? null,
            ':total_hours' => $data['total_hours'] ?? 0,
            ':status' => $data['status'] ?? 'present',
            ':notes' => $data['notes'] ?? null
        ]);
    }
}
?>