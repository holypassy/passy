<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/LoginAttempt.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Validator.php';

class AuthController {
    private $userModel;
    private $loginAttemptModel;
    
    public function __construct() {
        $this->userModel = new User();
        $this->loginAttemptModel = new LoginAttempt();
    }
    
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'username' => 'required',
            'password' => 'required'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $username = trim($input['username']);
        $password = $input['password'];
        $remember = isset($input['remember']) ? filter_var($input['remember'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Rate limiting
        $ip = $_SERVER['REMOTE_ADDR'];
        $attempts = $this->loginAttemptModel->getRecentAttempts($ip);
        
        if ($attempts >= 5) {
            Response::json([
                'success' => false,
                'message' => 'Too many failed attempts. Please try again after 15 minutes.'
            ]);
        }
        
        $user = $this->userModel->authenticate($username, $password);
        
        if (!$user) {
            $this->loginAttemptModel->logAttempt($ip, $username, false);
            Response::json(['success' => false, 'message' => 'Invalid credentials']);
        }
        
        // Check if account is active
        if ($user['is_active'] != 1) {
            $this->loginAttemptModel->logAttempt($ip, $username, false);
            Response::json(['success' => false, 'message' => 'Account deactivated. Contact administrator.']);
        }
        
        // Clear login attempts
        $this->loginAttemptModel->clearAttempts($ip);
        
        // Update last login
        $this->userModel->updateLastLogin($user['id']);
        
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
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
        }
        
        Response::json([
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
        session_destroy();
        
        setcookie('remember_token', '', time() - 3600, '/');
        
        Response::json(['success' => true, 'message' => 'Logged out successfully']);
    }
    
    public function check() {
        session_start();
        
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            Response::json([
                'authenticated' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'],
                    'full_name' => $_SESSION['full_name'],
                    'role' => $_SESSION['role']
                ]
            ]);
        }
        
        Response::json(['authenticated' => false]);
    }
}

$controller = new AuthController();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $controller->login();
        break;
    case 'logout':
        $controller->logout();
        break;
    case 'check':
        $controller->check();
        break;
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>