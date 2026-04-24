<?php
namespace Utils;

use Core\Database;

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function authenticate() {
        $headers = getallheaders();
        
        if (!isset($headers['Authorization'])) {
            return false;
        }
        
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            WHERE api_token = ? AND is_active = 1
        ");
        $stmt->execute([$token]);
        
        return $stmt->fetch();
    }
    
    public function login($username, $password) {
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            WHERE (username = ? OR email = ?) AND is_active = 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $token = bin2hex(random_bytes(32));
            
            $updateStmt = $this->db->prepare("
                UPDATE users SET api_token = ?, last_login = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$token, $user['id']]);
            
            return ['user' => $user, 'token' => $token];
        }
        
        return false;
    }
    
    public function logout($userId) {
        $stmt = $this->db->prepare("UPDATE users SET api_token = NULL WHERE id = ?");
        return $stmt->execute([$userId]);
    }
    
    public function hasPermission($userId, $permission) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.name = ?
        ");
        $stmt->execute([$userId, $permission]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
}