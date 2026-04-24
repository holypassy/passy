<?php
class AuthMiddleware {
    public static function authenticate() {
        session_start();
        
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Authentication required',
                'redirect' => '/login'
            ]);
            exit();
        }
        
        return $_SESSION;
    }
    
    public static function requireRole($roles) {
        $session = self::authenticate();
        
        if (is_string($roles)) {
            $roles = [$roles];
        }
        
        if (!in_array($session['role'], $roles)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Insufficient permissions'
            ]);
            exit();
        }
        
        return $session;
    }
    
    public static function getCurrentUser() {
        session_start();
        
        if (isset($_SESSION['user_id'])) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ];
        }
        
        return null;
    }
}
?>