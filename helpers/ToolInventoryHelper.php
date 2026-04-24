<?php
class ToolInventoryHelper {
    
    public static function getCategoryColors() {
        return [
            'hand_tool' => ['class' => 'hand_tool', 'icon' => 'fa-wrench', 'color' => '#2a5298'],
            'power_tool' => ['class' => 'power_tool', 'icon' => 'fa-bolt', 'color' => '#ffc107'],
            'diagnostic' => ['class' => 'diagnostic', 'icon' => 'fa-microchip', 'color' => '#17a2b8'],
            'safety' => ['class' => 'safety', 'icon' => 'fa-shield-alt', 'color' => '#28a745'],
            'special' => ['class' => 'special', 'icon' => 'fa-star', 'color' => '#6f42c1']
        ];
    }
    
    public static function getStatusBadge($status) {
        $badges = [
            'available' => '<span class="status-badge status-available"><i class="fas fa-check-circle"></i> Available</span>',
            'assigned' => '<span class="status-badge status-assigned"><i class="fas fa-hand-paper"></i> Assigned</span>',
            'maintenance' => '<span class="status-badge status-maintenance"><i class="fas fa-tools"></i> Maintenance</span>',
            'lost' => '<span class="status-badge status-lost"><i class="fas fa-question-circle"></i> Lost</span>',
            'damaged' => '<span class="status-badge status-damaged"><i class="fas fa-exclamation-triangle"></i> Damaged</span>'
        ];
        
        return $badges[$status] ?? '<span class="status-badge">' . ucfirst($status) . '</span>';
    }
    
    public static function getConditionBadge($condition) {
        $badges = [
            'new' => '<span class="badge-new">New</span>',
            'good' => '<span class="badge-good">Good</span>',
            'fair' => '<span class="badge-fair">Fair</span>',
            'poor' => '<span class="badge-poor">Poor</span>'
        ];
        
        return $badges[$condition] ?? '<span class="badge-secondary">' . ucfirst($condition) . '</span>';
    }
    
    public static function calculateMaintenanceStatus($nextMaintenanceDate) {
        if (!$nextMaintenanceDate) {
            return ['status' => 'not_scheduled', 'class' => 'secondary', 'message' => 'Not Scheduled'];
        }
        
        $today = new DateTime();
        $next = new DateTime($nextMaintenanceDate);
        $diff = $today->diff($next);
        
        if ($next < $today) {
            return ['status' => 'overdue', 'class' => 'danger', 'message' => 'Overdue by ' . $diff->days . ' days'];
        } elseif ($diff->days <= 7) {
            return ['status' => 'due_soon', 'class' => 'warning', 'message' => 'Due in ' . $diff->days . ' days'];
        } else {
            return ['status' => 'good', 'class' => 'success', 'message' => 'Due in ' . $diff->days . ' days'];
        }
    }
    
    public static function formatToolValue($value) {
        if ($value >= 1000000) {
            return 'UGX ' . number_format($value / 1000000, 1) . 'M';
        } elseif ($value >= 1000) {
            return 'UGX ' . number_format($value / 1000, 1) . 'K';
        }
        return 'UGX ' . number_format($value);
    }
    
    public static function getToolSummary($tools) {
        $summary = [
            'total' => 0,
            'total_value' => 0,
            'by_status' => [],
            'by_category' => []
        ];
        
        foreach ($tools as $tool) {
            $summary['total']++;
            $summary['total_value'] += $tool['purchase_price'] ?? 0;
            
            if (!isset($summary['by_status'][$tool['status']])) {
                $summary['by_status'][$tool['status']] = 0;
            }
            $summary['by_status'][$tool['status']]++;
            
            if (!isset($summary['by_category'][$tool['category']])) {
                $summary['by_category'][$tool['category']] = 0;
            }
            $summary['by_category'][$tool['category']]++;
        }
        
        return $summary;
    }
    
    public static function getToolAge($purchaseDate) {
        if (!$purchaseDate) return 'Unknown';
        
        $purchase = new DateTime($purchaseDate);
        $today = new DateTime();
        $diff = $purchase->diff($today);
        
        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
        } elseif ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
        } else {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
        }
    }
    
    public static function getToolCategories() {
        return [
            'hand_tool' => 'Hand Tool',
            'power_tool' => 'Power Tool',
            'diagnostic' => 'Diagnostic Equipment',
            'safety' => 'Safety Equipment',
            'special' => 'Special Tool'
        ];
    }
    
    public static function getToolConditions() {
        return [
            'new' => 'New',
            'good' => 'Good',
            'fair' => 'Fair',
            'poor' => 'Poor'
        ];
    }
}
?>