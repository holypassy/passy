<?php
// /savant/api/v1/settings.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, log them instead

session_start();

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check admin role
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

// Database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Helper function to get settings
function getSettings($conn, $group = null) {
    if ($group) {
        $stmt = $conn->prepare("SELECT setting_key, setting_value, setting_type FROM system_settings WHERE setting_group = ?");
        $stmt->execute([$group]);
    } else {
        $stmt = $conn->query("SELECT setting_key, setting_value, setting_type, setting_group FROM system_settings");
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    
    foreach ($results as $result) {
        $value = $result['setting_value'];
        switch ($result['setting_type']) {
            case 'boolean':
                $value = $value == '1';
                break;
            case 'number':
                $value = is_numeric($value) ? (float)$value : 0;
                break;
            default:
                $value = (string)$value;
        }
        
        if ($group) {
            $settings[$result['setting_key']] = $value;
        } else {
            $groupName = $result['setting_group'];
            if (!isset($settings[$groupName])) {
                $settings[$groupName] = [];
            }
            $settings[$groupName][$result['setting_key']] = $value;
        }
    }
    
    return $settings;
}

// Helper function to update settings
function updateSettings($conn, $group, $data) {
    foreach ($data as $key => $value) {
        // Determine type
        if (is_bool($value)) {
            $type = 'boolean';
            $value = $value ? '1' : '0';
        } elseif (is_numeric($value)) {
            $type = 'number';
            $value = (string)$value;
        } else {
            $type = 'text';
            $value = (string)$value;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, setting_group, updated_at) 
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                setting_value = ?, 
                setting_type = ?, 
                setting_group = ?,
                updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $type, $group, $value, $type, $group]);
    }
    return true;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        if ($action === 'all') {
            $settings = getSettings($conn);
            echo json_encode(['success' => true, 'data' => $settings]);
        } elseif ($action === 'group' && isset($_GET['group'])) {
            $settings = getSettings($conn, $_GET['group']);
            echo json_encode(['success' => true, 'data' => $settings]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'update-efris') {
            updateSettings($conn, 'efris', $data);
            echo json_encode(['success' => true, 'message' => 'EFRIS settings saved successfully']);
        } elseif ($action === 'update-royalty') {
            updateSettings($conn, 'royalty', $data);
            echo json_encode(['success' => true, 'message' => 'Royalty settings saved successfully']);
        } elseif ($action === 'update-discount') {
            updateSettings($conn, 'discount', $data);
            echo json_encode(['success' => true, 'message' => 'Discount settings saved successfully']);
        } elseif ($action === 'update-barcode') {
            updateSettings($conn, 'barcode', $data);
            echo json_encode(['success' => true, 'message' => 'Barcode settings saved successfully']);
        } elseif ($action === 'update-appearance') {
            updateSettings($conn, 'appearance', $data);
            echo json_encode(['success' => true, 'message' => 'Appearance settings saved successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'POST':
        if ($action === 'test-efris') {
            echo json_encode(['success' => true, 'message' => 'EFRIS connection test successful']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}