<?php
require_once __DIR__ . '/../config/database.php';

class ToolMaintenance {
    private $conn;
    private $table = "tool_maintenance";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByToolId($toolId, $limit = 20) {
        $query = "SELECT 
                    tm.*,
                    u.full_name as performed_by_name
                  FROM {$this->table} tm
                  LEFT JOIN users u ON tm.performed_by = u.id
                  WHERE tm.tool_id = :tool_id
                  ORDER BY tm.maintenance_date DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':tool_id', $toolId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (tool_id, maintenance_date, maintenance_type, description, cost, 
                   performed_by, next_maintenance_date, notes) 
                  VALUES (:tool_id, :maintenance_date, :maintenance_type, :description, :cost, 
                          :performed_by, :next_maintenance_date, :notes)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':tool_id' => $data['tool_id'],
            ':maintenance_date' => $data['maintenance_date'],
            ':maintenance_type' => $data['maintenance_type'],
            ':description' => $data['description'],
            ':cost' => $data['cost'] ?? 0,
            ':performed_by' => $data['performed_by'],
            ':next_maintenance_date' => $data['next_maintenance_date'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);
        
        if ($result) {
            // Update tool maintenance dates
            $updateTool = "UPDATE tools 
                          SET last_maintenance_date = :maintenance_date,
                              next_maintenance_date = :next_maintenance_date,
                              status = CASE 
                                  WHEN status = 'maintenance' THEN 'available'
                                  ELSE status
                              END
                          WHERE id = :tool_id";
            
            $updateStmt = $this->conn->prepare($updateTool);
            $updateStmt->execute([
                ':maintenance_date' => $data['maintenance_date'],
                ':next_maintenance_date' => $data['next_maintenance_date'],
                ':tool_id' => $data['tool_id']
            ]);
            
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    public function getStatistics() {
        $query = "SELECT 
                    COUNT(*) as tools_in_maintenance,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), last_maintenance_date) > 90 THEN 1 ELSE 0 END) as overdue_maintenance,
                    SUM(CASE WHEN next_maintenance_date <= CURDATE() THEN 1 ELSE 0 END) as maintenance_due
                  FROM tools
                  WHERE (status = 'maintenance' OR next_maintenance_date <= CURDATE()) 
                    AND deleted_at IS NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function scheduleMaintenance($toolId, $maintenanceDate, $notes = null) {
        $query = "UPDATE tools 
                  SET status = 'maintenance',
                      next_maintenance_date = :maintenance_date,
                      notes = CONCAT(COALESCE(notes, ''), '\nMaintenance scheduled: ', :maintenance_date, ' - ', :notes)
                  WHERE id = :tool_id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':tool_id' => $toolId,
            ':maintenance_date' => $maintenanceDate,
            ':notes' => $notes ?? ''
        ]);
    }
}
?>