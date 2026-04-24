<?php
require_once __DIR__ . '/../config/database.php';

class LoginAttempt {
    private $conn;
    private $table = "login_attempts";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getRecentAttempts($ip, $minutes = 15) {
        $query = "SELECT COUNT(*) as attempts 
                  FROM {$this->table} 
                  WHERE ip_address = :ip 
                    AND attempt_time > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':ip' => $ip,
            ':minutes' => $minutes
        ]);
        
        $result = $stmt->fetch();
        return $result['attempts'] ?? 0;
    }
    
    public function logAttempt($ip, $username, $success = false) {
        $query = "INSERT INTO {$this->table} 
                  (ip_address, username, success) 
                  VALUES (:ip, :username, :success)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':ip' => $ip,
            ':username' => $username,
            ':success' => $success ? 1 : 0
        ]);
    }
    
    public function clearAttempts($ip) {
        $query = "DELETE FROM {$this->table} 
                  WHERE ip_address = :ip";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':ip' => $ip]);
    }
    
    public function getAttemptHistory($username = null, $limit = 100) {
        $query = "SELECT * FROM {$this->table} ";
        $params = [];
        
        if ($username) {
            $query .= "WHERE username = :username ";
            $params[':username'] = $username;
        }
        
        $query .= "ORDER BY attempt_time DESC LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>