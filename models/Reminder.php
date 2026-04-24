<?php
require_once __DIR__ . '/../config/database.php';

class Reminder {
    private $conn;
    private $table = "vehicle_pickup_reminders";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getPending($limit = 10) {
        $query = "SELECT vpr.*, c.full_name, c.telephone, c.email, jc.job_number
                  FROM {$this->table} vpr
                  LEFT JOIN customers c ON vpr.customer_id = c.id
                  LEFT JOIN job_cards jc ON vpr.job_card_id = jc.id
                  WHERE vpr.status = 'pending' 
                    AND vpr.reminder_date <= CURDATE() 
                    AND vpr.reminder_sent = 0
                  ORDER BY vpr.pickup_date ASC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function getAll($page = 1, $limit = 20, $status = '') {
        $offset = ($page - 1) * $limit;
        
        $query = "SELECT vpr.*, c.full_name, c.telephone, jc.job_number
                  FROM {$this->table} vpr
                  LEFT JOIN customers c ON vpr.customer_id = c.id
                  LEFT JOIN job_cards jc ON vpr.job_card_id = jc.id
                  WHERE 1=1 ";
        $params = [];
        
        if (!empty($status)) {
            $query .= "AND vpr.status = :status ";
            $params[':status'] = $status;
        }
        
        $query .= "ORDER BY vpr.pickup_date ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (customer_id, job_card_id, vehicle_reg, vehicle_make, vehicle_model, 
                   pickup_date, pickup_time, reminder_date, reminder_type, notes, created_by) 
                  VALUES (:customer_id, :job_card_id, :vehicle_reg, :vehicle_make, :vehicle_model, 
                          :pickup_date, :pickup_time, :reminder_date, :reminder_type, :notes, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':customer_id' => $data['customer_id'],
            ':job_card_id' => $data['job_card_id'] ?? null,
            ':vehicle_reg' => $data['vehicle_reg'],
            ':vehicle_make' => $data['vehicle_make'] ?? null,
            ':vehicle_model' => $data['vehicle_model'] ?? null,
            ':pickup_date' => $data['pickup_date'],
            ':pickup_time' => $data['pickup_time'] ?? null,
            ':reminder_date' => $data['reminder_date'],
            ':reminder_type' => $data['reminder_type'] ?? 'sms',
            ':notes' => $data['notes'] ?? null,
            ':created_by' => $data['created_by']
        ]);
    }
    
    public function markAsSent($id) {
        $query = "UPDATE {$this->table} SET reminder_sent = 1 WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }
    
    public function logHistory($reminderId, $type, $sentTo, $message) {
        $query = "INSERT INTO reminder_history 
                  (reminder_id, reminder_type, sent_to, message, sent_status) 
                  VALUES (:reminder_id, :reminder_type, :sent_to, :message, 'sent')";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':reminder_id' => $reminderId,
            ':reminder_type' => $type,
            ':sent_to' => $sentTo,
            ':message' => $message
        ]);
    }
    
    public function getReminderWithCustomer($id) {
        $query = "SELECT vpr.*, c.full_name, c.telephone, c.email 
                  FROM {$this->table} vpr
                  LEFT JOIN customers c ON vpr.customer_id = c.id
                  WHERE vpr.id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function sendReminder($id) {
        $reminder = $this->getReminderWithCustomer($id);
        
        if (!$reminder) {
            return ['success' => false, 'message' => 'Reminder not found'];
        }
        
        $message = "Dear " . $reminder['full_name'] . ",\n\n" .
                   "Your vehicle " . $reminder['vehicle_reg'] . " is ready for pickup.\n" .
                   "Pickup Date: " . date('l, F j, Y', strtotime($reminder['pickup_date'])) . "\n\n" .
                   "Thank you for choosing Savant Motors!";
        
        // Log to history
        $this->logHistory($reminder['id'], $reminder['reminder_type'], $reminder['telephone'], $message);
        
        // Mark as sent
        $this->markAsSent($reminder['id']);
        
        return [
            'success' => true,
            'message' => "Reminder sent to " . $reminder['full_name'],
            'data' => [
                'customer' => $reminder['full_name'],
                'telephone' => $reminder['telephone'],
                'message' => $message
            ]
        ];
    }
}
?>