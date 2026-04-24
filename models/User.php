<?php
namespace App\Models;

use Core\Model;
use Core\Hash;

class User extends Model {
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $fillable = [
        'username', 'email', 'password', 'full_name', 'role',
        'is_active', 'last_login', 'remember_token', 'api_token',
        'two_factor_secret', 'two_factor_enabled', 'phone', 'avatar'
    ];
    
    public function createUser($data) {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        return $this->create($data);
    }
    
    public function updateUser($id, $data) {
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        return $this->update($id, $data);
    }
    
    public function findByUsername($username) {
        return $this->findFirst(['username' => $username]);
    }
    
    public function findByEmail($email) {
        return $this->findFirst(['email' => $email]);
    }
    
    public function getWithStats($id) {
        $stmt = $this->db->prepare("
            SELECT 
                u.*,
                COUNT(DISTINCT jc.id) as jobs_created,
                COUNT(DISTINCT qt.id) as quotations_created,
                COUNT(DISTINCT inv.id) as invoices_created,
                COUNT(DISTINCT up.id) as permissions_count
            FROM users u
            LEFT JOIN job_cards jc ON u.id = jc.created_by
            LEFT JOIN quotations qt ON u.id = qt.created_by
            LEFT JOIN invoices inv ON u.id = inv.created_by
            LEFT JOIN user_permissions up ON u.id = up.user_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllWithStats() {
        $stmt = $this->db->query("
            SELECT 
                u.*,
                COUNT(DISTINCT jc.id) as jobs_created,
                COUNT(DISTINCT qt.id) as quotations_created,
                COUNT(DISTINCT inv.id) as invoices_created,
                COUNT(DISTINCT up.id) as permissions_count
            FROM users u
            LEFT JOIN job_cards jc ON u.id = jc.created_by
            LEFT JOIN quotations qt ON u.id = qt.created_by
            LEFT JOIN invoices inv ON u.id = inv.created_by
            LEFT JOIN user_permissions up ON u.id = up.user_id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        return $stmt->fetchAll();
    }
    
    public function getByRole($role) {
        return $this->all(['role' => $role], 'full_name');
    }
    
    public function getActiveUsers() {
        return $this->all(['is_active' => 1], 'full_name');
    }
    
    public function generateApiToken($userId) {
        $token = bin2hex(random_bytes(32));
        $this->update($userId, ['api_token' => $token]);
        return $token;
    }
    
    public function validateApiToken($token) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE api_token = ? AND is_active = 1");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }
    
    public function changePassword($userId, $newPassword) {
        return $this->update($userId, ['password' => Hash::make($newPassword)]);
    }
    
    public function enableTwoFactor($userId, $secret) {
        return $this->update($userId, [
            'two_factor_secret' => $secret,
            'two_factor_enabled' => 1
        ]);
    }
    
    public function disableTwoFactor($userId) {
        return $this->update($userId, [
            'two_factor_secret' => null,
            'two_factor_enabled' => 0
        ]);
    }
}