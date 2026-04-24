<?php
namespace Core;

use App\Models\User;
use App\Models\UserActivity;

class Auth {
    private $userModel;
    private $activityModel;
    
    public function __construct() {
        $this->userModel = new User();
        $this->activityModel = new UserActivity();
    }
    
    public function attempt($username, $password, $remember = false) {
        $user = $this->userModel->findByUsername($username);
        
        if ($user && Hash::verify($password, $user['password'])) {
            if ($user['is_active'] != 1) {
                return ['success' => false, 'message' => 'Account is disabled'];
            }
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            
            if ($remember) {
                $token = TokenGenerator::generate();
                setcookie('remember_token', $token, time() + (86400 * 30), '/');
                $this->userModel->update($user['id'], ['remember_token' => $token]);
            }
            
            $this->userModel->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);
            $this->activityModel->log($user['id'], 'login', 'User logged in');
            
            return ['success' => true, 'user' => $user];
        }
        
        return ['success' => false, 'message' => 'Invalid credentials'];
    }
    
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->activityModel->log($_SESSION['user_id'], 'logout', 'User logged out');
        }
        
        session_destroy();
        setcookie('remember_token', '', time() - 3600, '/');
        
        return true;
    }
    
    public function check() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public function user() {
        if ($this->check()) {
            return $this->userModel->find($_SESSION['user_id']);
        }
        return null;
    }
    
    public function id() {
        return $_SESSION['user_id'] ?? null;
    }
    
    public function hasPermission($permission) {
        if (!$this->check()) {
            return false;
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.permission_key = ? AND up.granted = 1
        ");
        $stmt->execute([$this->id(), $permission]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
    
    public function hasRole($role) {
        return $this->check() && $_SESSION['role'] === $role;
    }
}