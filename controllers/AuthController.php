<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/LoginAttempt.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';

class AuthController {
    private $userModel;
    private $loginAttemptModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->userModel = new User();
        $this->loginAttemptModel = new LoginAttempt();
    }
    
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || empty($input['username']) || empty($input['password'])) {
            $this->jsonResponse(['success' => false, 'message' => 'Username and password required']);
        }
        
        $username = trim($input['username']);
        $password = $input['password'];
        $remember = isset($input['remember']) ? filter_var($input['remember'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Rate limiting
        $ip = $_SERVER['REMOTE_ADDR'];
        $attempts = $this->loginAttemptModel->getRecentAttempts($ip);
        
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Too many failed attempts. Please try again after ' . LOGIN_TIMEOUT_MINUTES . ' minutes.'
            ]);
        }
        
        // Authenticate user
        $user = $this->userModel->authenticate($username, $password);
        
        if (!$user) {
            $this->loginAttemptModel->logAttempt($ip, $username, false);
            $this->jsonResponse(['success' => false, 'message' => 'Invalid credentials']);
        }
        
        // Check if account is active
        if ($user['is_active'] != 1) {
            $this->loginAttemptModel->logAttempt($ip, $username, false);
            $this->jsonResponse(['success' => false, 'message' => 'Account deactivated']);
        }
        
        // Clear login attempts
        $this->loginAttemptModel->clearAttempts($ip);
        
        // Start session
        session_start();
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        // Handle remember me
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $hashedToken = password_hash($token, PASSWORD_DEFAULT);
            $this->userModel->updateRememberToken($user['id'], $hashedToken);
            
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
        }
        
        $this->jsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => '/dashboard_erp.php',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ]);
    }
    
    public function logout() {
        session_start();
        
        // Clear remember me token if exists
        if (isset($_SESSION['user_id'])) {
            $this->userModel->updateRememberToken($_SESSION['user_id'], null);
        }
        
        setcookie('remember_token', '', time() - 3600, '/');
        session_destroy();
        
        $this->jsonResponse(['success' => true, 'message' => 'Logged out successfully']);
    }
    
    public function check() {
        session_start();
        
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            $this->jsonResponse([
                'authenticated' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'full_name' => $_SESSION['full_name'],
                    'role' => $_SESSION['role']
                ]
            ]);
        }
        
        // Check remember me cookie
        if (isset($_COOKIE['remember_token'])) {
            $token = $_COOKIE['remember_token'];
            $user = $this->userModel->findByRememberToken($token);
            
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                
                $this->jsonResponse([
                    'authenticated' => true,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'role' => $user['role']
                    ]
                ]);
            }
        }
        
        $this->jsonResponse(['authenticated' => false]);
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }
}
?>