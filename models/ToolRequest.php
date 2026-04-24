<?php
namespace App\Models;

use Core\Model;

class ToolRequest extends Model {
    protected $table = 'tool_requests';
    protected $primaryKey = 'id';
    protected $fillable = [
        'request_number', 'technician_id', 'number_plate', 'reason',
        'instructions', 'urgency', 'expected_duration_days', 'requested_by',
        'approved_by', 'approved_at', 'rejection_reason', 'status'
    ];
    
    public function generateRequestNumber() {
        $prefix = 'TR-' . date('Ymd');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tool_requests WHERE request_number LIKE ?");
        $stmt->execute([$prefix . '%']);
        $count = $stmt->fetchColumn() + 1;
        return $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
    
    public function createWithTools($requestData, $tools) {
        try {
            $this->db->beginTransaction();
            
            $requestData['request_number'] = $this->generateRequestNumber();
            $requestId = $this->create($requestData);
            
            $stmt = $this->db->prepare("
                INSERT INTO request_tools (request_id, tool_id, tool_name, quantity)
                VALUES (?, ?, ?, ?)
            ");
            
            foreach ($tools as $tool) {
                $stmt->execute([
                    $requestId,
                    $tool['tool_id'] ?? null,
                    $tool['tool_name'] ?? null,
                    $tool['quantity'] ?? 1
                ]);
            }
            
            $this->db->commit();
            return $requestId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getWithDetails($id) {
        $stmt = $this->db->prepare("
            SELECT 
                tr.*,
                t.full_name as technician_name,
                t.technician_code,
                u.full_name as requested_by_name,
                au.full_name as approved_by_name,
                GROUP_CONCAT(
                    CONCAT(
                        COALESCE(tl.tool_code, 'NEW:'),
                        ' ',
                        COALESCE(tl.tool_name, rt.tool_name),
                        ' (x', rt.quantity, ')'
                    ) SEPARATOR ',\n'
                ) as tools_list
            FROM tool_requests tr
            LEFT JOIN technicians t ON tr.technician_id = t.id
            LEFT JOIN users u ON tr.requested_by = u.id
            LEFT JOIN users au ON tr.approved_by = au.id
            LEFT JOIN request_tools rt ON tr.id = rt.request_id
            LEFT JOIN tools tl ON rt.tool_id = tl.id
            WHERE tr.id = ?
            GROUP BY tr.id
        ");
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        
        if ($request) {
            $request['tools'] = $this->getTools($id);
        }
        
        return $request;
    }
    
    public function getTools($requestId) {
        $stmt = $this->db->prepare("
            SELECT rt.*, tl.tool_code, tl.tool_name as existing_tool_name
            FROM request_tools rt
            LEFT JOIN tools tl ON rt.tool_id = tl.id
            WHERE rt.request_id = ?
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetchAll();
    }
    
    public function getPendingRequests() {
        $stmt = $this->db->prepare("
            SELECT 
                tr.*,
                t.full_name as technician_name,
                GROUP_CONCAT(
                    CONCAT(
                        COALESCE(tl.tool_code, 'NEW:'),
                        ' ',
                        COALESCE(tl.tool_name, rt.tool_name),
                        ' (x', rt.quantity, ')'
                    ) SEPARATOR '; '
                ) as tools_summary
            FROM tool_requests tr
            LEFT JOIN technicians t ON tr.technician_id = t.id
            LEFT JOIN request_tools rt ON tr.id = rt.request_id
            LEFT JOIN tools tl ON rt.tool_id = tl.id
            WHERE tr.status = 'pending'
            GROUP BY tr.id
            ORDER BY FIELD(tr.urgency, 'emergency', 'high', 'medium', 'low'), tr.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getByTechnician($technicianId) {
        $stmt = $this->db->prepare("
            SELECT * FROM tool_requests 
            WHERE technician_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$technicianId]);
        return $stmt->fetchAll();
    }
    
    public function getRecentRequests($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                tr.*,
                t.full_name as technician_name,
                u.full_name as requested_by_name
            FROM tool_requests tr
            LEFT JOIN technicians t ON tr.technician_id = t.id
            LEFT JOIN users u ON tr.requested_by = u.id
            ORDER BY tr.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function approve($id, $approvedBy) {
        return $this->update($id, [
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function reject($id, $reason, $approvedBy) {
        return $this->update($id, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) as fulfilled,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN urgency = 'emergency' AND status = 'pending' THEN 1 ELSE 0 END) as emergency_pending
            FROM tool_requests
        ");
        return $stmt->fetch();
    }
}