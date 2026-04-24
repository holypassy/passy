<?php
namespace App\Models;

use Core\Model;

class Permission extends Model {
    protected $table = 'permissions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'permission_key', 'permission_name', 'description', 'category', 'is_active'
    ];
    
    public function getByCategory() {
        $stmt = $this->db->query("
            SELECT * FROM permissions 
            WHERE is_active = 1 
            ORDER BY category, permission_name
        ");
        $permissions = $stmt->fetchAll();
        
        $grouped = [];
        foreach ($permissions as $perm) {
            $grouped[$perm['category']][] = $perm;
        }
        
        return $grouped;
    }
    
    public function getByUser($userId) {
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
    
    public function getUserPermissions($userId) {
        $stmt = $this->db->prepare("
            SELECT p.permission_key
            FROM permissions p
            JOIN user_permissions up ON p.id = up.permission_id
            WHERE up.user_id = ? AND up.granted = 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getCustomPermissions() {
        $stmt = $this->db->query("
            SELECT * FROM permissions 
            WHERE category = 'Custom' OR category NOT IN (
                'General', 'Jobs', 'Sales', 'Finance', 'Reports', 
                'Admin', 'Inventory', 'Tools', 'CRM', 'HR', 'Procurement'
            )
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }
    
    public function createPermission($data) {
        return $this->create($data);
    }
    
    public function deletePermission($id) {
        // First delete all user associations
        $stmt = $this->db->prepare("DELETE FROM user_permissions WHERE permission_id = ?");
        $stmt->execute([$id]);
        
        // Then delete the permission
        return $this->delete($id);
    }
    
    public function getDefaultPermissions() {
        return [
            ['view_dashboard', 'View Dashboard', 'Access to main dashboard', 'General'],
            ['create_job_card', 'Create Job Cards', 'Create new job cards', 'Jobs'],
            ['edit_job_card', 'Edit Job Cards', 'Modify existing job cards', 'Jobs'],
            ['delete_job_card', 'Delete Job Cards', 'Remove job cards', 'Jobs'],
            ['view_job_cards', 'View Job Cards', 'View all job cards', 'Jobs'],
            ['create_quotation', 'Create Quotations', 'Generate new quotations', 'Sales'],
            ['edit_quotation', 'Edit Quotations', 'Modify existing quotations', 'Sales'],
            ['delete_quotation', 'Delete Quotations', 'Remove quotations', 'Sales'],
            ['approve_quotation', 'Approve Quotations', 'Approve customer quotations', 'Sales'],
            ['create_invoice', 'Create Invoices', 'Generate invoices', 'Finance'],
            ['edit_invoice', 'Edit Invoices', 'Modify invoices', 'Finance'],
            ['delete_invoice', 'Delete Invoices', 'Remove invoices', 'Finance'],
            ['record_payment', 'Record Payments', 'Record customer payments', 'Finance'],
            ['view_reports', 'View Reports', 'Access to reports section', 'Reports'],
            ['export_data', 'Export Data', 'Export data to CSV/Excel', 'Reports'],
            ['manage_users', 'Manage Users', 'Add/edit/delete users', 'Admin'],
            ['manage_roles', 'Manage Roles', 'Create and edit roles', 'Admin'],
            ['manage_permissions', 'Manage Permissions', 'Create custom permissions', 'Admin'],
            ['manage_inventory', 'Manage Inventory', 'Add/edit inventory items', 'Inventory'],
            ['view_inventory', 'View Inventory', 'View inventory items', 'Inventory'],
            ['manage_customers', 'Manage Customers', 'Add/edit/delete customers', 'CRM'],
            ['view_customers', 'View Customers', 'View customer information', 'CRM'],
            ['manage_technicians', 'Manage Technicians', 'Add/edit/delete technicians', 'HR'],
            ['view_attendance', 'View Attendance', 'View staff attendance', 'HR'],
            ['record_attendance', 'Record Attendance', 'Record staff check-in/out', 'HR'],
            ['manage_suppliers', 'Manage Suppliers', 'Add/edit/delete suppliers', 'Procurement'],
            ['create_purchase_order', 'Create Purchase Orders', 'Create purchase orders', 'Procurement']
        ];
    }
    
    public function initializeDefaultPermissions() {
        $count = $this->db->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
        
        if ($count == 0) {
            $defaults = $this->getDefaultPermissions();
            $stmt = $this->db->prepare("
                INSERT INTO permissions (permission_key, permission_name, description, category) 
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($defaults as $perm) {
                $stmt->execute([$perm[0], $perm[1], $perm[2], $perm[3]]);
            }
        }
    }
}