<?php
namespace App\Models;

use Core\Model;

class SettingsModel extends Model {
    protected $table = 'system_settings';
    protected $primaryKey = 'id';
    protected $fillable = ['setting_key', 'setting_value', 'setting_group', 'setting_type', 'is_encrypted'];
    
    public function get($key, $default = null) {
        $stmt = $this->db->prepare("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return $default;
        }
        
        return $this->castValue($result['setting_value'], $result['setting_type']);
    }
    
    public function getGroup($group) {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value, setting_type FROM system_settings WHERE setting_group = ?");
        $stmt->execute([$group]);
        $results = $stmt->fetchAll();
        
        $settings = [];
        foreach ($results as $result) {
            $settings[$result['setting_key']] = $this->castValue($result['setting_value'], $result['setting_type']);
        }
        
        return $settings;
    }
    
    public function getAll() {
        $stmt = $this->db->query("SELECT setting_key, setting_value, setting_type, setting_group FROM system_settings");
        $results = $stmt->fetchAll();
        
        $settings = [];
        foreach ($results as $result) {
            $group = $result['setting_group'];
            if (!isset($settings[$group])) {
                $settings[$group] = [];
            }
            $settings[$group][$result['setting_key']] = $this->castValue($result['setting_value'], $result['setting_type']);
        }
        
        return $settings;
    }
    
    public function set($key, $value, $group = null, $type = null) {
        // Auto-detect type if not provided
        if (!$type) {
            $type = $this->detectType($value);
        }
        
        $value = $this->uncastValue($value, $type);
        
        // Check if key exists
        $stmt = $this->db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Get old value for history
            $oldStmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $oldStmt->execute([$key]);
            $oldValue = $oldStmt->fetchColumn();
            
            $stmt = $this->db->prepare("UPDATE system_settings SET setting_value = ?, setting_type = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$value, $type, $key]);
            
            // Log to history
            $this->logHistory($key, $oldValue, $value);
        } else {
            $stmt = $this->db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, setting_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$key, $value, $group, $type]);
        }
        
        return true;
    }
    
    public function setGroup($group, $settings) {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $group);
        }
        return true;
    }
    
    private function castValue($value, $type) {
        switch ($type) {
            case 'boolean':
                return (bool)$value;
            case 'number':
                return is_numeric($value) ? (float)$value : 0;
            case 'json':
                return json_decode($value, true);
            case 'array':
                return explode(',', $value);
            default:
                return $value;
        }
    }
    
    private function uncastValue($value, $type) {
        switch ($type) {
            case 'boolean':
                return $value ? '1' : '0';
            case 'json':
                return json_encode($value);
            case 'array':
                return is_array($value) ? implode(',', $value) : $value;
            default:
                return (string)$value;
        }
    }
    
    private function detectType($value) {
        if (is_bool($value)) return 'boolean';
        if (is_numeric($value)) return 'number';
        if (is_array($value)) {
            return isset($value[0]) ? 'array' : 'json';
        }
        if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $value)) return 'color';
        return 'text';
    }
    
    private function logHistory($key, $oldValue, $newValue) {
        $stmt = $this->db->prepare("
            INSERT INTO settings_history (setting_key, old_value, new_value, changed_by) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$key, $oldValue, $newValue, $_SESSION['user_id'] ?? null]);
    }
    
    public function getHistory($key = null, $limit = 50) {
        if ($key) {
            $stmt = $this->db->prepare("
                SELECT h.*, u.full_name as changed_by_name
                FROM settings_history h
                LEFT JOIN users u ON h.changed_by = u.id
                WHERE h.setting_key = ?
                ORDER BY h.changed_at DESC
                LIMIT ?
            ");
            $stmt->execute([$key, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT h.*, u.full_name as changed_by_name
                FROM settings_history h
                LEFT JOIN users u ON h.changed_by = u.id
                ORDER BY h.changed_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll();
    }
}