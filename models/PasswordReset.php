<?php
require_once __DIR__ . '/../config/database.php';

class PasswordReset {
    private $conn;
    private $table = "password_resets";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function createToken($email) {
        // Delete any existing tokens for this email
        $this->deleteByEmail($email);
        
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $query = "INSERT INTO {$this->table} (email, token, expires_at) VALUES (:email, :token, :expires_at)";
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':email' => $email,
            ':token' => $token,
            ':expires_at' => $expiresAt
        ]);
        
        if ($result) {
            return $token;
        }
        
        return false;
    }
    
    public function validateToken($token) {
        $query = "SELECT * FROM {$this->table} WHERE token = :token AND expires_at > NOW()";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':token' => $token]);
        return $stmt->fetch();
    }
    
    public function deleteByEmail($email) {
        $query = "DELETE FROM {$this->table} WHERE email = :email";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':email' => $email]);
    }
    
    public function deleteByToken($token) {
        $query = "DELETE FROM {$this->table} WHERE token = :token";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':token' => $token]);
    }
}
?>