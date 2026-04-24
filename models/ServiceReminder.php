<?php
require_once __DIR__ . '/../config/database.php';

class ServiceReminder {
    private $conn;
    private $table = "service_reminders";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll($filters = []) {
        $query = "SELECT 
                    r.*,
                    sa.vehicle_reg,
                    sa.service_name,
                    sa.service_date,
                    c.id as customer_id,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} r
                  JOIN service_applications sa ON r.service_application_id = sa.id
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['sent'])) {
            $query .= " AND r.sent = :sent";
            $params[':sent'] = $filters['sent'];
        }
        
        if (!empty($filters['from_date'])) {
            $query .= " AND r.reminder_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $query .= " AND r.reminder_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }
        
        $query .= " ORDER BY r.reminder_date ASC";
        
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
    
    public function getTodayReminders() {
        $query = "SELECT 
                    r.*,
                    sa.vehicle_reg,
                    sa.service_name,
                    sa.service_date,
                    c.id as customer_id,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} r
                  JOIN service_applications sa ON r.service_application_id = sa.id
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE r.reminder_date = CURDATE()
                    AND r.sent = 0
                  ORDER BY r.created_at ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getPendingReminders() {
        $query = "SELECT 
                    r.*,
                    sa.vehicle_reg,
                    sa.service_name,
                    sa.service_date,
                    c.id as customer_id,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} r
                  JOIN service_applications sa ON r.service_application_id = sa.id
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE r.sent = 0
                    AND r.reminder_date <= CURDATE()
                  ORDER BY r.reminder_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT 
                    r.*,
                    sa.vehicle_reg,
                    sa.service_name,
                    sa.service_date,
                    c.id as customer_id,
                    c.full_name as customer_name,
                    c.telephone,
                    c.email
                  FROM {$this->table} r
                  JOIN service_applications sa ON r.service_application_id = sa.id
                  LEFT JOIN customers c ON sa.customer_id = c.id
                  WHERE r.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (service_application_id, reminder_date, reminder_type, message, 
                   reminder_days_before, status, created_by) 
                  VALUES (:service_application_id, :reminder_date, :reminder_type, :message, 
                          :reminder_days_before, :status, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':service_application_id' => $data['service_application_id'],
            ':reminder_date' => $data['reminder_date'],
            ':reminder_type' => $data['reminder_type'] ?? 'both',
            ':message' => $data['message'] ?? null,
            ':reminder_days_before' => $data['reminder_days_before'] ?? 3,
            ':status' => $data['status'] ?? 'pending',
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function createFromService($serviceId, $reminderDays = 3) {
        // Get service details
        $serviceApp = new ServiceApplication();
        $service = $serviceApp->findById($serviceId);
        
        if (!$service) {
            return false;
        }
        
        $reminderDate = date('Y-m-d', strtotime($service['service_date'] . " -{$reminderDays} days"));
        
        // Check if reminder already exists
        $checkQuery = "SELECT id FROM {$this->table} 
                       WHERE service_application_id = :service_id 
                         AND reminder_date = :reminder_date";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->execute([
            ':service_id' => $serviceId,
            ':reminder_date' => $reminderDate
        ]);
        
        if ($checkStmt->fetch()) {
            return false;
        }
        
        $message = "Dear " . $service['customer_name'] . ",\n\n" .
                   "Your vehicle " . $service['vehicle_reg'] . " is due for service on " . 
                   date('d M Y', strtotime($service['service_date'])) . ".\n\n" .
                   "Service: " . $service['service_name'] . "\n\n" .
                   "Please contact us to schedule your appointment.\n\n" .
                   "Thank you for choosing SAVANT MOTORS!";
        
        $query = "INSERT INTO {$this->table} 
                  (service_application_id, reminder_date, reminder_type, message, 
                   reminder_days_before, status) 
                  VALUES (:service_id, :reminder_date, 'both', :message, :reminder_days_before, 'pending')";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':service_id' => $serviceId,
            ':reminder_date' => $reminderDate,
            ':message' => $message,
            ':reminder_days_before' => $reminderDays
        ]);
    }
    
    public function markAsSent($id) {
        $query = "UPDATE {$this->table} 
                  SET sent = 1, sent_date = NOW() 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
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
    
    public function getStatistics() {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sent = 1 THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN sent = 0 AND reminder_date < CURDATE() THEN 1 ELSE 0 END) as missed,
                    SUM(CASE WHEN sent = 0 AND reminder_date >= CURDATE() THEN 1 ELSE 0 END) as pending
                  FROM {$this->table}";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }
}
?>