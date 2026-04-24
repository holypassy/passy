<?php
class ToolRequestHelper {
    
    public static function getUrgencyBadge($urgency) {
        $badges = [
            'emergency' => '<span class="badge badge-emergency"><i class="fas fa-bell"></i> EMERGENCY</span>',
            'urgent' => '<span class="badge badge-urgent"><i class="fas fa-exclamation-circle"></i> URGENT</span>',
            'normal' => '<span class="badge badge-normal"><i class="fas fa-check-circle"></i> NORMAL</span>'
        ];
        
        return $badges[$urgency] ?? '<span class="badge badge-secondary">' . ucfirst($urgency) . '</span>';
    }
    
    public static function getStatusBadge($status) {
        $badges = [
            'pending' => '<span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span>',
            'approved' => '<span class="badge badge-approved"><i class="fas fa-check-circle"></i> Approved</span>',
            'rejected' => '<span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected</span>',
            'cancelled' => '<span class="badge badge-cancelled"><i class="fas fa-ban"></i> Cancelled</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function getUrgencyColor($urgency) {
        $colors = [
            'emergency' => '#dc3545',
            'urgent' => '#ffc107',
            'normal' => '#17a2b8'
        ];
        
        return $colors[$urgency] ?? '#6c757d';
    }
    
    public static function getUrgencyIcon($urgency) {
        $icons = [
            'emergency' => 'fa-bell',
            'urgent' => 'fa-exclamation-circle',
            'normal' => 'fa-check-circle'
        ];
        
        return $icons[$urgency] ?? 'fa-circle';
    }
    
    public static function calculateEstimatedAvailability($requestDate, $durationDays) {
        $availabilityDate = date('Y-m-d', strtotime("+{$durationDays} days", strtotime($requestDate)));
        $today = date('Y-m-d');
        
        if ($availabilityDate < $today) {
            return ['status' => 'overdue', 'message' => 'Overdue', 'date' => $availabilityDate];
        } elseif ($availabilityDate == $today) {
            return ['status' => 'today', 'message' => 'Due Today', 'date' => $availabilityDate];
        } else {
            $daysLeft = ceil((strtotime($availabilityDate) - strtotime($today)) / 86400);
            return ['status' => 'pending', 'message' => "Due in {$daysLeft} days", 'date' => $availabilityDate];
        }
    }
    
    public static function formatRequestSummary($request) {
        return [
            'id' => $request['id'],
            'request_number' => $request['request_number'],
            'technician' => $request['technician_name'],
            'tool' => $request['tool_name'] ?? $request['tool_name_requested'],
            'quantity' => $request['quantity'],
            'urgency' => $request['urgency'],
            'status' => $request['status'],
            'date' => $request['created_at'],
            'duration' => $request['expected_duration_days']
        ];
    }
    
    public static function validateToolRequest($data) {
        $errors = [];
        
        if (empty($data['technician_id'])) {
            $errors[] = 'Technician selection is required';
        }
        
        if (empty($data['quantity']) || $data['quantity'] < 1) {
            $errors[] = 'Quantity must be at least 1';
        }
        
        if (empty($data['expected_duration_days']) || $data['expected_duration_days'] < 1) {
            $errors[] = 'Expected duration must be at least 1 day';
        }
        
        if (empty($data['reason']) || strlen($data['reason']) < 10) {
            $errors[] = 'Please provide a detailed reason (minimum 10 characters)';
        }
        
        if (empty($data['tool_id']) && empty($data['tool_name_requested'])) {
            $errors[] = 'Please select a tool or describe the tool needed';
        }
        
        return $errors;
    }
    
    public static function getRequestStatistics($requests) {
        $stats = [
            'total' => count($requests),
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'emergency' => 0,
            'urgent' => 0,
            'normal' => 0
        ];
        
        foreach ($requests as $request) {
            $stats[$request['status']]++;
            $stats[$request['urgency']]++;
        }
        
        return $stats;
    }
}
?>