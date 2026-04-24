<?php
abstract class BaseController {
    protected $db;
    protected $user;
    
    public function __construct($db) {
        $this->db = $db;
        $this->initSession();
    }
    
    protected function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    protected function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
    
    protected function getPostData() {
        $input = json_decode(file_get_contents('php://input'), true);
        return $input ?? $_POST;
    }
    
    protected function validateRequired($data, $fields) {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }
        return true;
    }
    
    protected function sanitize($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize'], $input);
        }
        return htmlspecialchars(strip_tags(trim($input)));
    }
    
    protected function isAuthenticated() {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return false;
        }
        return true;
    }
    
    protected function hasRole($roles) {
        if (!isset($_SESSION['role'])) {
            return false;
        }
        
        if (is_array($roles)) {
            return in_array($_SESSION['role'], $roles);
        }
        
        return $_SESSION['role'] === $roles;
    }
    
    protected function requireAuth() {
        if (!$this->isAuthenticated()) {
            $this->jsonResponse(['error' => 'Authentication required'], 401);
            exit();
        }
    }
    
    protected function requireRole($roles) {
        $this->requireAuth();
        if (!$this->hasRole($roles)) {
            $this->jsonResponse(['error' => 'Insufficient permissions'], 403);
            exit();
        }
    }
}
?>