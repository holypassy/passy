<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../utils/Auth.php';

use Utils\Auth;

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'logout') {
        session_start();
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out']);
        exit();
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);