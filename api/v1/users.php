<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/User.php';
require_once '../../app/models/UserActivity.php';
require_once '../../utils/Auth.php';

use App\Models\User;
use App\Models\UserActivity;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if user has admin permission
$hasAdminPermission = false;
$db = \Core\Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT COUNT(*) as count
    FROM user_permissions up
    JOIN permissions p ON up.permission_id = p.id
    WHERE up.user_id = ? AND p.permission_key = 'manage_users' AND up.granted = 1
");
$stmt->execute([$user['id']]);
$result = $stmt->fetch();
$hasAdminPermission = $result['count'] > 0 || $user['role'] === 'admin';

if (!$hasAdminPermission) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$userModel = new User();
$activityModel = new UserActivity();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $userData = $userModel->getWithStats($_GET['id']);
            if ($userData) {
                unset($userData['password']);
                echo json_encode($userData);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
        } else {
            $page = $_GET['page'] ?? 1;
            $perPage = $_GET['per_page'] ?? 15;
            $users = $userModel->paginate($page, $perPage);
            foreach ($users['data'] as &$u) {
                unset($u['password']);
            }
            echo json_encode($users);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'full_name' => 'required|min:2',
            'username' => 'required|min:3',
            'email' => 'required|email',
            'password' => 'required|min:8'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $userId = $userModel->createUser($data);
        $activityModel->log($user['id'], 'api_user_created', "Created user via API: {$data['username']}");
        
        http_response_code(201);
        echo json_encode(['id' => $userId, 'message' => 'User created successfully']);
        break;
        
    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $userModel->updateUser($_GET['id'], $data);
        $activityModel->log($user['id'], 'api_user_updated', "Updated user via API: ID {$_GET['id']}");
        
        echo json_encode(['message' => 'User updated successfully']);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID required']);
            break;
        }
        
        $userModel->softDelete($_GET['id']);
        $activityModel->log($user['id'], 'api_user_deleted', "Deleted user via API: ID {$_GET['id']}");
        
        echo json_encode(['message' => 'User deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}