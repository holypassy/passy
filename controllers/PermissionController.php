<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Permission;
use App\Models\UserActivity;

class PermissionController extends Controller {
    private $permissionModel;
    private $activityModel;
    
    public function __construct() {
        $this->permissionModel = new Permission();
        $this->activityModel = new UserActivity();
    }
    
    public function index() {
        if (!$this->hasPermission('manage_permissions')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $permissions = $this->permissionModel->getByCategory();
        
        $this->view('permissions/index', [
            'permissions' => $permissions
        ]);
    }
    
    public function store() {
        if (!$this->hasPermission('manage_permissions')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $rules = [
            'permission_key' => 'required|min:3|max:100',
            'permission_name' => 'required|min:3|max:100'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $this->json(['success' => false, 'errors' => $errors], 422);
            return;
        }
        
        // Check if permission key exists
        $existing = $this->permissionModel->findFirst(['permission_key' => $data['permission_key']]);
        if ($existing) {
            $this->json(['success' => false, 'message' => 'Permission key already exists'], 422);
            return;
        }
        
        $permissionId = $this->permissionModel->createPermission([
            'permission_key' => $data['permission_key'],
            'permission_name' => $data['permission_name'],
            'description' => $data['description'] ?? '',
            'category' => $data['category'] ?? 'Custom'
        ]);
        
        $this->activityModel->log($this->getCurrentUser(), 'permission_created', "Created permission: {$data['permission_key']}");
        
        $this->json(['success' => true, 'message' => 'Permission created successfully', 'id' => $permissionId]);
    }
    
    public function delete($id) {
        if (!$this->hasPermission('manage_permissions')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $permission = $this->permissionModel->find($id);
        if (!$permission) {
            $this->json(['error' => 'Permission not found'], 404);
            return;
        }
        
        $this->permissionModel->deletePermission($id);
        
        $this->activityModel->log($this->getCurrentUser(), 'permission_deleted', "Deleted permission: {$permission['permission_key']}");
        
        $this->json(['success' => true, 'message' => 'Permission deleted successfully']);
    }
}