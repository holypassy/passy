<?php
namespace Utils;

class ToolStatusHelper {
    
    public static function getStatusBadgeClass($status) {
        $classes = [
            'available' => 'success',
            'taken' => 'warning',
            'maintenance' => 'danger',
            'damaged' => 'danger',
            'retired' => 'secondary'
        ];
        return $classes[$status] ?? 'secondary';
    }
    
    public static function getStatusIcon($status) {
        $icons = [
            'available' => 'fa-check-circle',
            'taken' => 'fa-hand-holding',
            'maintenance' => 'fa-wrench',
            'damaged' => 'fa-exclamation-triangle',
            'retired' => 'fa-trash-alt'
        ];
        return $icons[$status] ?? 'fa-question-circle';
    }
    
    public static function getStatusText($status) {
        $texts = [
            'available' => 'Available',
            'taken' => 'Taken',
            'maintenance' => 'In Maintenance',
            'damaged' => 'Damaged',
            'retired' => 'Retired'
        ];
        return $texts[$status] ?? ucfirst($status);
    }
    
    public static function getConditionBadgeClass($condition) {
        $classes = [
            'new' => 'success',
            'good' => 'info',
            'fair' => 'warning',
            'poor' => 'danger'
        ];
        return $classes[$condition] ?? 'secondary';
    }
    
    public static function getUrgencyBadgeClass($urgency) {
        $classes = [
            'emergency' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary'
        ];
        return $classes[$urgency] ?? 'secondary';
    }
}