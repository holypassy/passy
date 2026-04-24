<?php
namespace App\Controllers;

use Core\Controller;
use Core\Auth;
use App\Models\User;
use App\Models\Permission;
use App\Models\UserPermission;
use App\Models\UserActivity;
use Utils\PasswordGenerator;
use Utils\EmailSender;
use Utils\ActivityLogger;

class UserController extends Controller {
    private $userModel;
    private $permissionModel;
    private $userPermissionModel;
    private $activityModel;
    private $emailSender;
    
    public function __construct() {
        $this->userModel = new User();
        $this->permissionModel = new Permission();
        $this->userPermissionModel = new UserPermission();
        $this->activityModel = new UserActivity();
        $this->emailSender = new EmailSender();
    }
    
    public function index() {
        // Check permission
        if (!$this->hasPermission('manage_users')) {
            $this->redirect('/dashboard');
            return;
        }
        
        $users = $this->userModel->getAllWithStats();
        $permissionsByCategory = $this->permissionModel->getByCategory();
        
        $this->view('users/index', [
            'users' => $users,
            'permissions_by_category' => $permissionsByCategory
        ]);
    }
    
    public function create() {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $roles = ['admin', 'manager', 'cashier', 'technician', 'receptionist'];
        $permissions = $this->permissionModel->getByCategory();
        
        $this->view('users/create', [
            'roles' => $roles,
            'permissions' => $permissions
        ]);
    }
    
    public function store() {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = $this->sanitize($_POST);
        
        $rules = [
            'full_name' => 'required|min:2|max:100',
            'username' => 'required|min:3|max:50|alpha_num',
            'email' => 'required|email',
            'role' => 'required|in:admin,manager,cashier,technician,receptionist',
            'password' => 'required|min:8'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $this->json(['success' => false, 'errors' => $errors], 422);
            return;
        }
        
        // Check if username exists
        $existingUser = $this->userModel->findByUsername($data['username']);
        if ($existingUser) {
            $this->json(['success' => false, 'message' => 'Username already exists'], 422);
            return;
        }
        
        // Check if email exists
        $existingEmail = $this->userModel->findByEmail($data['email']);
        if ($existingEmail) {
            $this->json(['success' => false, 'message' => 'Email already exists'], 422);
            return;
        }
        
        $userId = $this->userModel->createUser([
            'full_name' => $data['full_name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'is_active' => 1
        ]);
        
        // Sync permissions if provided
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $this->userPermissionModel->syncPermissions($userId, $data['permissions']);
        }
        
        // Log activity
        $this->activityModel->log($this->getCurrentUser(), 'user_created', "Created user: {$data['username']}");
        
        // Send welcome email
        $this->emailSender->sendWelcomeEmail($data['email'], $data['full_name'], $data['password']);
        
        $this->json(['success' => true, 'message' => 'User created successfully', 'id' => $userId]);
    }
    
    public function edit($id) {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $user = $this->userModel->find($id);
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }
        
        $roles = ['admin', 'manager', 'cashier', 'technician', 'receptionist'];
        
        $this->view('users/edit', [
            'user' => $user,
            'roles' => $roles
        ]);
    }
    
    public function update($id) {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = $this->sanitize($_POST);
        
        $rules = [
            'full_name' => 'required|min:2|max:100',
            'email' => 'required|email',
            'role' => 'required|in:admin,manager,cashier,technician,receptionist'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $this->json(['success' => false, 'errors' => $errors], 422);
            return;
        }
        
        $updateData = [
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'role' => $data['role']
        ];
        
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                $this->json(['success' => false, 'message' => 'Password must be at least 8 characters'], 422);
                return;
            }
            $updateData['password'] = $data['password'];
        }
        
        $this->userModel->updateUser($id, $updateData);
        
        $this->activityModel->log($this->getCurrentUser(), 'user_updated', "Updated user ID: {$id}");
        
        $this->json(['success' => true, 'message' => 'User updated successfully']);
    }
    
    public function delete($id) {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        if ($id == $this->getCurrentUser()) {
            $this->json(['success' => false, 'message' => 'Cannot delete your own account'], 422);
            return;
        }
        
        $this->userModel->softDelete($id);
        $this->activityModel->log($this->getCurrentUser(), 'user_deleted', "Deleted user ID: {$id}");
        
        $this->json(['success' => true, 'message' => 'User deleted successfully']);
    }
    
    public function getPermissions($id) {
        if (!$this->hasPermission('manage_permissions')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $user = $this->userModel->find($id);
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }
        
        $permissions = $this->userPermissionModel->getUserPermissions($id);
        $userPermissions = $this->userPermissionModel->getUserPermissions($id);
        $grantedPermissions = [];
        
        foreach ($userPermissions as $perm) {
            if ($perm['granted']) {
                $grantedPermissions[] = $perm['id'];
            }
        }
        
        $this->json([
            'success' => true,
            'user' => $user,
            'permissions' => $permissions,
            'granted_permissions' => $grantedPermissions
        ]);
    }
    
    public function updatePermissions($id) {
        if (!$this->hasPermission('manage_permissions')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $permissionIds = $data['permissions'] ?? [];
        
        $this->userPermissionModel->syncPermissions($id, $permissionIds);
        
        $this->activityModel->log($this->getCurrentUser(), 'permissions_updated', "Updated permissions for user ID: {$id}");
        
        $this->json(['success' => true, 'message' => 'Permissions updated successfully']);
    }
    
    public function resetPassword($id) {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $user = $this->userModel->find($id);
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }
        
        $tempPassword = PasswordGenerator::generate(10);
        $this->userModel->changePassword($id, $tempPassword);
        
        // Send email with temporary password
        $this->emailSender->sendPasswordReset($user['email'], $user['full_name'], $tempPassword);
        
        $this->activityModel->log($this->getCurrentUser(), 'password_reset', "Reset password for user: {$user['username']}");
        
        $this->json([
            'success' => true, 
            'message' => 'Password reset successfully',
            'temp_password' => $tempPassword
        ]);
    }
    
    public function toggleStatus($id) {
        if (!$this->hasPermission('manage_users')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        
        $user = $this->userModel->find($id);
        if (!$user) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }
        
        $isActive = ($action === 'enable') ? 1 : 0;
        $this->userModel->update($id, ['is_active' => $isActive]);
        
        $this->activityModel->log($this->getCurrentUser(), 'user_status_changed', "Changed status of user {$user['username']} to " . ($isActive ? 'active' : 'inactive'));
        
        $this->json(['success' => true, 'message' => 'User status updated successfully']);
    }
}