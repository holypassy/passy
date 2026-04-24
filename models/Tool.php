<?php
namespace App\Models;

use Core\Model;

class Tool extends Model {
    protected $table = 'tools';
    protected $primaryKey = 'id';
    protected $fillable = [
        'tool_code', 'tool_name', 'category', 'brand', 'model',
        'serial_number', 'location', 'purchase_date', 'purchase_price',
        'current_value', 'status', 'condition_rating', 'last_calibration_date',
        'next_calibration_date', 'notes', 'image_path', 'qr_code_path', 'is_active', 'created_by'
    ];
    
    public function generateToolCode() {
        $prefix = 'TL-';
        $year = date('Y');
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM tools WHERE tool_code LIKE '{$prefix}{$year}%'");
        $count = $stmt->fetch()['count'] + 1;
        return $prefix . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
    
    public function getWithDetails($id) {
        $stmt = $this->db->prepare("
            SELECT 
                t.*,
                COUNT(DISTINCT ta.id) as times_assigned,
                COUNT(DISTINCT CASE WHEN ta.status = 'active' AND ta.actual_return_date IS NULL THEN ta.id END) as current_assignments,
                tech.full_name as current_technician,
                ta.id as current_assignment_id,
                ta.assigned_date,
                ta.expected_return_date,
                tr.id as request_id,
                tr.request_number,
                tr.urgency as request_urgency
            FROM tools t
            LEFT JOIN tool_assignments ta ON t.id = ta.tool_id AND ta.actual_return_date IS NULL
            LEFT JOIN technicians tech ON ta.technician_id = tech.id
            LEFT JOIN tool_requests tr ON tr.id = ta.request_id
            WHERE t.id = ?
            GROUP BY t.id
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllWithStatus() {
        $stmt = $this->db->query("
            SELECT 
                t.*,
                COUNT(DISTINCT ta.id) as times_assigned,
                COUNT(DISTINCT CASE WHEN ta.status = 'active' AND ta.actual_return_date IS NULL THEN ta.id END) as current_assignments,
                tech.full_name as current_technician,
                ta.id as current_assignment_id,
                ta.assigned_date,
                ta.expected_return_date,
                tr.id as request_id,
                tr.request_number
            FROM tools t
            LEFT JOIN tool_assignments ta ON t.id = ta.tool_id AND ta.actual_return_date IS NULL
            LEFT JOIN technicians tech ON ta.technician_id = tech.id
            LEFT JOIN tool_requests tr ON tr.id = ta.request_id
            WHERE t.is_active = 1
            GROUP BY t.id
            ORDER BY FIELD(t.status, 'available', 'taken', 'maintenance'), t.tool_name ASC
        ");
        return $stmt->fetchAll();
    }
    
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN status = 'taken' THEN 1 ELSE 0 END) as taken,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) as damaged,
                SUM(CASE WHEN status = 'retired' THEN 1 ELSE 0 END) as retired,
                COALESCE(SUM(purchase_price), 0) as total_value,
                COALESCE(SUM(current_value), 0) as current_value
            FROM tools
            WHERE is_active = 1
        ");
        return $stmt->fetch();
    }
    
    public function getCategories() {
        $stmt = $this->db->query("
            SELECT DISTINCT category, COUNT(*) as count 
            FROM tools 
            WHERE category IS NOT NULL AND is_active = 1
            GROUP BY category 
            ORDER BY category
        ");
        return $stmt->fetchAll();
    }
    
    public function updateStatus($id, $status) {
        return $this->update($id, ['status' => $status]);
    }
    
    public function getTakenTools() {
        $stmt = $this->db->prepare("
            SELECT 
                t.*,
                tech.full_name as technician_name,
                ta.assigned_date,
                ta.expected_return_date,
                ta.id as assignment_id,
                tr.request_number
            FROM tools t
            JOIN tool_assignments ta ON t.id = ta.tool_id AND ta.actual_return_date IS NULL
            JOIN technicians tech ON ta.technician_id = tech.id
            LEFT JOIN tool_requests tr ON ta.request_id = tr.id
            WHERE t.status = 'taken' AND t.is_active = 1
            ORDER BY ta.expected_return_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getLowStockTools() {
        // For tools that are low in quantity (if you track quantity)
        $stmt = $this->db->prepare("
            SELECT * FROM tools 
            WHERE status != 'damaged' AND status != 'retired'
            AND is_active = 1
            ORDER BY times_assigned ASC
            LIMIT 10
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}