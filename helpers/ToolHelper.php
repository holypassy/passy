<?php
class ToolHelper {
    
    public static function generateToolCode($toolName) {
        $words = explode(' ', $toolName);
        $code = '';
        
        foreach ($words as $word) {
            if (strlen($word) > 0) {
                $code .= strtoupper($word[0]);
            }
        }
        
        $code = substr($code, 0, 3);
        $code = str_pad($code, 3, 'X');
        
        $randomNum = rand(100, 999);
        
        return "TOOL-{$code}{$randomNum}";
    }
    
    public static function getStatusBadge($status) {
        $badges = [
            'available' => '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Available</span>',
            'assigned' => '<span class="badge badge-info"><i class="fas fa-hand-paper"></i> Assigned</span>',
            'maintenance' => '<span class="badge badge-warning"><i class="fas fa-wrench"></i> Maintenance</span>',
            'lost' => '<span class="badge badge-secondary"><i class="fas fa-question-circle"></i> Lost</span>',
            'damaged' => '<span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> Damaged</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function calculateMaintenanceSchedule($lastMaintenanceDate, $intervalDays = 90) {
        if (!$lastMaintenanceDate) {
            return [
                'status' => 'unknown',
                'next_date' => null,
                'days_overdue' => 0,
                'is_due' => false
            ];
        }
        
        $lastDate = new DateTime($lastMaintenanceDate);
        $nextDate = clone $lastDate;
        $nextDate->modify("+{$intervalDays} days");
        $today = new DateTime();
        
        $daysUntilNext = $today->diff($nextDate)->days;
        $isOverdue = $today > $nextDate;
        
        return [
            'status' => $isOverdue ? 'overdue' : ($daysUntilNext <= 30 ? 'due_soon' : 'good'),
            'next_date' => $nextDate->format('Y-m-d'),
            'days_until' => $isOverdue ? -$daysUntilNext : $daysUntilNext,
            'is_due' => $isOverdue || $daysUntilNext <= 30
        ];
    }
    
    public static function getToolValueSummary($tools) {
        $totalValue = 0;
        $availableValue = 0;
        $assignedValue = 0;
        $maintenanceValue = 0;
        
        foreach ($tools as $tool) {
            $value = ($tool['purchase_price'] ?? 0) * ($tool['quantity'] ?? 1);
            $totalValue += $value;
            
            switch ($tool['status']) {
                case 'available':
                    $availableValue += $value;
                    break;
                case 'assigned':
                    $assignedValue += $value;
                    break;
                case 'maintenance':
                    $maintenanceValue += $value;
                    break;
            }
        }
        
        return [
            'total' => $totalValue,
            'available' => $availableValue,
            'assigned' => $assignedValue,
            'maintenance' => $maintenanceValue,
            'availability_rate' => $totalValue > 0 ? round(($availableValue / $totalValue) * 100, 1) : 0
        ];
    }
    
    public static function getToolsDueForMaintenance($tools, $daysThreshold = 30) {
        $dueTools = [];
        
        foreach ($tools as $tool) {
            if ($tool['next_maintenance_date']) {
                $nextDate = new DateTime($tool['next_maintenance_date']);
                $today = new DateTime();
                $diff = $today->diff($nextDate)->days;
                
                if ($nextDate <= $today || $diff <= $daysThreshold) {
                    $dueTools[] = $tool;
                }
            }
        }
        
        return $dueTools;
    }
    
    public static function formatToolUsage($totalAssignments, $currentAssignments) {
        $usageRate = $totalAssignments > 0 ? round(($currentAssignments / $totalAssignments) * 100, 1) : 0;
        
        return [
            'total' => $totalAssignments,
            'current' => $currentAssignments,
            'rate' => $usageRate
        ];
    }
}
?>