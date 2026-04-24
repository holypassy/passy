<?php
namespace Utils;

use Core\Database;

class ActivityLogger {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function log($userId, $action, $details = null) {
        $stmt = $this->db->prepare("
            INSERT INTO user_activities (user_id, action, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $userId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    public function getUserActivity($userId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT * FROM user_activities 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
}