<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Permission.php';
require_once '../../app/models/UserPermission.php';
require_once '../../utils/Auth.php';

use App\Models\Permission;
use App\Models\UserPermission;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check permission
$hasPermission = false;
$db = \Core\Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM user_permissions up
    JOIN permissions p ON up.permission_id = p.id
    WHERE up.user_id = ? AND p.permission_key = 'manage_permissions' AND up.granted = 1
");
$stmt->execute([$user['id']]);
$result = $stmt->fetch();
$hasPermission = $result['count'] > 0 || $user['role'] === 'admin';

if (!$hasPermission) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$permissionModel = new Permission();

switch ($method) {
    case 'GET':
        if (isset($_GET['user_id'])) {
            $userPermModel = new UserPermission();
            $permissions = $userPermModel->getUserPermissions($_GET['user_id']);
            echo json_encode($permissions);
        } else {
            $permissions = $permissionModel->getByCategory();
            echo json_encode($permissions);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'permission_key' => 'required|min:3',
            'permission_name' => 'required|min:3'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $permId = $permissionModel->createPermission($data);
        echo json_encode(['id' => $permId, 'message' => 'Permission created successfully']);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Permission ID required']);
            break;
        }
        
        $permissionModel->deletePermission($_GET['id']);
        echo json_encode(['message' => 'Permission deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}