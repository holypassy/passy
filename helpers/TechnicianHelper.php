<?php
class TechnicianHelper {
    
    public static function getStatusBadge($status, $isBlocked = false) {
        if ($isBlocked) {
            return '<span class="status-badge status-blocked"><i class="fas fa-ban"></i> Blocked</span>';
        }
        
        $badges = [
            'active' => '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Active</span>',
            'inactive' => '<span class="status-badge status-inactive"><i class="fas fa-circle"></i> Inactive</span>',
            'suspended' => '<span class="status-badge status-suspended"><i class="fas fa-pause-circle"></i> Suspended</span>',
            'on_leave' => '<span class="status-badge status-on_leave"><i class="fas fa-umbrella-beach"></i> On Leave</span>'
        ];
        
        return $badges[$status] ?? '<span class="status-badge">' . ucfirst($status) . '</span>';
    }
    
    public static function getDepartmentIcon($department) {
        $icons = [
            'mechanical' => 'fa-wrench',
            'electrical' => 'fa-bolt',
            'bodywork' => 'fa-car-side',
            'diagnostic' => 'fa-microchip',
            'general' => 'fa-tools',
            'paint' => 'fa-palette',
            'ac' => 'fa-snowflake',
            'tyre' => 'fa-circle'
        ];
        
        return $icons[$department] ?? 'fa-user-cog';
    }
    
    public static function formatPhoneNumber($phone) {
        if (empty($phone)) return 'N/A';
        
        // Remove all non-digits
        $cleaned = preg_replace('/\D/', '', $phone);
        
        // Format for Uganda numbers
        if (strlen($cleaned) === 9) {
            return '+256 ' . substr($cleaned, 0, 3) . ' ' . 
                   substr($cleaned, 3, 3) . ' ' . substr($cleaned, 6);
        } elseif (strlen($cleaned) === 10 && substr($cleaned, 0, 1) === '0') {
            return '+256 ' . substr($cleaned, 1, 3) . ' ' . 
                   substr($cleaned, 4, 3) . ' ' . substr($cleaned, 7);
        } elseif (strlen($cleaned) === 12 && substr($cleaned, 0, 3) === '256') {
            return '+' . substr($cleaned, 0, 3) . ' ' . 
                   substr($cleaned, 3, 3) . ' ' . 
                   substr($cleaned, 6, 3) . ' ' . substr($cleaned, 9);
        }
        
        return $phone;
    }
    
    public static function calculateExperienceLevel($years) {
        if ($years < 2) {
            return ['level' => 'Junior', 'class' => 'junior', 'icon' => 'fa-seedling'];
        } elseif ($years <= 5) {
            return ['level' => 'Intermediate', 'class' => 'intermediate', 'icon' => 'fa-tree'];
        } else {
            return ['level' => 'Senior', 'class' => 'senior', 'icon' => 'fa-crown'];
        }
    }
    
    public static function getMedicalBadge($status) {
        $badges = [
            0 => ['text' => 'Missing', 'class' => 'danger', 'icon' => 'fa-times-circle'],
            1 => ['text' => 'Valid', 'class' => 'success', 'icon' => 'fa-check-circle'],
            2 => ['text' => 'Expired', 'class' => 'warning', 'icon' => 'fa-exclamation-triangle']
        ];
        
        $badge = $badges[$status] ?? $badges[0];
        return '<span class="badge badge-' . $badge['class'] . '"><i class="fas ' . $badge['icon'] . '"></i> ' . $badge['text'] . '</span>';
    }
    
    public static function getInitials($fullName) {
        $nameParts = explode(' ', $fullName);
        $initials = '';
        foreach ($nameParts as $part) {
            if (strlen($part) > 0) {
                $initials .= strtoupper($part[0]);
            }
        }
        return substr($initials, 0, 2);
    }
    
    public static function getToolAssignmentSummary($assignments) {
        $summary = [
            'total' => count($assignments),
            'overdue' => 0,
            'due_soon' => 0,
            'tools' => []
        ];
        
        foreach ($assignments as $assignment) {
            $summary['tools'][] = $assignment['tool_name'];
            if ($assignment['is_overdue']) {
                $summary['overdue']++;
            } elseif (strtotime($assignment['expected_return_date']) <= strtotime('+2 days')) {
                $summary['due_soon']++;
            }
        }
        
        return $summary;
    }
    
    public static function getAttendanceRate($presentDays, $totalDays) {
        if ($totalDays == 0) return 0;
        return round(($presentDays / $totalDays) * 100, 1);
    }
    
    public static function formatExperience($years) {
        $years = floatval($years);
        if ($years == 0) return 'Less than 1 year';
        if ($years == 1) return '1 year';
        return $years . ' years';
    }
    
    public static function getDepartmentColors() {
        return [
            'mechanical' => '#3b82f6',
            'electrical' => '#f59e0b',
            'bodywork' => '#10b981',
            'diagnostic' => '#8b5cf6',
            'general' => '#6b7280',
            'paint' => '#ec489a',
            'ac' => '#06b6d4',
            'tyre' => '#84cc16'
        ];
    }
    
    public static function validateTechnicianData($data) {
        $errors = [];
        
        if (empty($data['full_name'])) {
            $errors[] = 'Full name is required';
        }
        
        if (empty($data['phone'])) {
            $errors[] = 'Phone number is required';
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address';
        }
        
        if (!empty($data['experience_years']) && (!is_numeric($data['experience_years']) || $data['experience_years'] < 0 || $data['experience_years'] > 50)) {
            $errors[] = 'Experience years must be between 0 and 50';
        }
        
        return $errors;
    }
}
?>