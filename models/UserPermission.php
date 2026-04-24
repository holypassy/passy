<?php
namespace App\Models;

use Core\Model;

class UserPermission extends Model {
    protected $table = 'user_permissions';
    protected $fillable = ['user_id', 'permission_id', 'granted', 'granted_by'];
    
    public function syncPermissions($userId, $permissionIds) {
        // Remove all existing permissions
        $stmt = $this->db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Add new permissions
        if (!empty($permissionIds)) {
            $stmt = $this->db->prepare("
                INSERT INTO user_permissions (user_id, permission_id, granted, granted_by) 
                VALUES (?, ?, 1, ?)
            ");
            
            foreach ($permissionIds as $permId) {
                $stmt->execute([$userId, $permId, $_SESSION['user_id'] ?? 1]);
            }
        }
        
        return true;
    }
    
    public function getUserPermissions($userId) {
        $stmt = $this->db->prepare("
            SELECT p.*, up.granted
            FROM permissions p
            LEFT JOIN user_permissions up ON p.id = up.permission_id AND up.user_id = ?
            WHERE p.is_active = 1
            ORDER BY p.category, p.permission_name
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function hasPermission($userId, $permissionKey) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.permission_key = ? AND up.granted = 1
        ");
        $stmt->execute([$userId, $permissionKey]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
}