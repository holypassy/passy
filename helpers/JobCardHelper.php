<?php
class JobCardHelper {
    
    public static function getStatusBadge($status) {
        $badges = [
            'pending' => '<span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>',
            'in_progress' => '<span class="badge badge-info"><i class="fas fa-spinner"></i> In Progress</span>',
            'completed' => '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Completed</span>',
            'cancelled' => '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>'
        ];
        
        return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
    
    public static function getPriorityBadge($priority) {
        $badges = [
            'urgent' => '<span class="badge badge-danger"><i class="fas fa-exclamation-circle"></i> Urgent</span>',
            'high' => '<span class="badge badge-warning"><i class="fas fa-arrow-up"></i> High</span>',
            'normal' => '<span class="badge badge-info"><i class="fas fa-minus"></i> Normal</span>',
            'low' => '<span class="badge badge-secondary"><i class="fas fa-arrow-down"></i> Low</span>'
        ];
        
        return $badges[$priority] ?? '<span class="badge badge-secondary">' . ucfirst($priority) . '</span>';
    }
    
    public static function calculateEstimatedCompletion($dateReceived, $priority) {
        $baseDays = [
            'urgent' => 1,
            'high' => 2,
            'normal' => 3,
            'low' => 5
        ];
        
        $days = $baseDays[$priority] ?? 3;
        $completionDate = date('Y-m-d', strtotime("+{$days} days", strtotime($dateReceived)));
        
        return [
            'estimated_days' => $days,
            'estimated_date' => $completionDate,
            'is_overdue' => strtotime($completionDate) < time()
        ];
    }
    
    public static function formatJobSummary($jobCard, $items) {
        $totalItems = count($items);
        $totalAmount = array_sum(array_column($items, 'total'));
        
        return [
            'id' => $jobCard['id'],
            'job_number' => $jobCard['job_number'],
            'customer' => $jobCard['customer_full_name'],
            'vehicle' => $jobCard['vehicle_reg'],
            'status' => $jobCard['status'],
            'total_items' => $totalItems,
            'total_amount' => $totalAmount,
            'date_received' => $jobCard['date_received'],
            'technician' => $jobCard['technician_name'] ?? 'Unassigned'
        ];
    }
    
    public static function getJobCardTemplate($jobCard, $items) {
        $total = array_sum(array_column($items, 'total'));
        
        return [
            'job_number' => $jobCard['job_number'],
            'customer' => [
                'name' => $jobCard['customer_full_name'],
                'phone' => $jobCard['customer_phone'],
                'email' => $jobCard['customer_email'],
                'address' => $jobCard['customer_address']
            ],
            'vehicle' => [
                'registration' => $jobCard['vehicle_reg'],
                'make' => $jobCard['vehicle_make'],
                'model' => $jobCard['vehicle_model'],
                'year' => $jobCard['vehicle_year'],
                'odometer' => $jobCard['odometer_reading'],
                'fuel_level' => $jobCard['fuel_level']
            ],
            'dates' => [
                'received' => $jobCard['date_received'],
                'promised' => $jobCard['date_promised'],
                'completed' => $jobCard['date_completed']
            ],
            'items' => $items,
            'total' => $total,
            'notes' => $jobCard['notes'],
            'technician' => $jobCard['technician_name']
        ];
    }
    
    public static function validateJobCard($data) {
        $errors = [];
        
        if (empty($data['customer_id'])) {
            $errors[] = 'Customer is required';
        }
        
        if (empty($data['vehicle_reg'])) {
            $errors[] = 'Vehicle registration is required';
        }
        
        if (empty($data['date_received'])) {
            $errors[] = 'Date received is required';
        }
        
        return $errors;
    }
}
?>