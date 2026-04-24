<?php
namespace App\Models;

use Core\Model;

class ToolAssignment extends Model {
    protected $table = 'tool_assignments';
    protected $fillable = [
        'tool_id', 'request_id', 'technician_id', 'assigned_date',
        'expected_return_date', 'condition_on_assign', 'notes', 'created_by'
    ];
    
    public function assignTool($toolId, $technicianId, $requestId = null, $expectedReturnDate = null) {
        try {
            $this->db->beginTransaction();
            
            // Create assignment record
            $assignmentId = $this->create([
                'tool_id' => $toolId,
                'request_id' => $requestId,
                'technician_id' => $technicianId,
                'expected_return_date' => $expectedReturnDate ?? date('Y-m-d', strtotime('+7 days')),
                'assigned_date' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            // Update tool status to 'taken'
            $toolModel = new Tool();
            $toolModel->updateStatus($toolId, 'taken');
            
            // If from a request, update request status
            if ($requestId) {
                $requestModel = new ToolRequest();
                $requestModel->update($requestId, ['status' => 'fulfilled']);
            }
            
            $this->db->commit();
            return $assignmentId;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function returnTool($assignmentId, $conditionOnReturn = null, $notes = null) {
        try {
            $this->db->beginTransaction();
            
            // Get assignment details
            $assignment = $this->find($assignmentId);
            if (!$assignment) {
                throw new \Exception('Assignment not found');
            }
            
            // Update assignment
            $this->update($assignmentId, [
                'actual_return_date' => date('Y-m-d H:i:s'),
                'condition_on_return' => $conditionOnReturn,
                'status' => 'returned'
            ]);
            
            // Update tool status back to 'available'
            $toolModel = new Tool();
            $toolModel->updateStatus($assignment['tool_id'], 'available');
            
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getActiveAssignments() {
        $stmt = $this->db->prepare("
            SELECT 
                ta.*,
                t.tool_name,
                t.tool_code,
                tech.full_name as technician_name,
                tr.request_number
            FROM tool_assignments ta
            JOIN tools t ON ta.tool_id = t.id
            JOIN technicians tech ON ta.technician_id = tech.id
            LEFT JOIN tool_requests tr ON ta.request_id = tr.id
            WHERE ta.actual_return_date IS NULL
            ORDER BY ta.expected_return_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getOverdueAssignments() {
        $stmt = $this->db->prepare("
            SELECT 
                ta.*,
                t.tool_name,
                t.tool_code,
                tech.full_name as technician_name,
                DATEDIFF(NOW(), ta.expected_return_date) as days_overdue
            FROM tool_assignments ta
            JOIN tools t ON ta.tool_id = t.id
            JOIN technicians tech ON ta.technician_id = tech.id
            WHERE ta.actual_return_date IS NULL 
            AND ta.expected_return_date < CURDATE()
            ORDER BY ta.expected_return_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getTechnicianAssignments($technicianId) {
        $stmt = $this->db->prepare("
            SELECT 
                ta.*,
                t.tool_name,
                t.tool_code
            FROM tool_assignments ta
            JOIN tools t ON ta.tool_id = t.id
            WHERE ta.technician_id = ?
            ORDER BY ta.assigned_date DESC
        ");
        $stmt->execute([$technicianId]);
        return $stmt->fetchAll();
    }
}