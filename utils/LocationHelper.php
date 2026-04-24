<?php
namespace Utils;

class LocationHelper {
    
    public static function formatAddress($address, $landmark = null, $instructions = null) {
        $formatted = $address;
        if ($landmark) {
            $formatted .= "\nNear: " . $landmark;
        }
        if ($instructions) {
            $formatted .= "\nInstructions: " . $instructions;
        }
        return $formatted;
    }
    
    public static function getPickupTypeIcon($type) {
        $icons = [
            'workshop' => 'fa-building',
            'home' => 'fa-home',
            'office' => 'fa-briefcase'
        ];
        return $icons[$type] ?? 'fa-map-marker-alt';
    }
    
    public static function getStatusBadgeClass($status) {
        $classes = [
            'pending' => 'warning',
            'scheduled' => 'info',
            'in_progress' => 'primary',
            'completed' => 'success',
            'cancelled' => 'danger'
        ];
        return $classes[$status] ?? 'secondary';
    }
    
    public static function getStatusText($status) {
        $texts = [
            'pending' => 'Pending',
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled'
        ];
        return $texts[$status] ?? ucfirst($status);
    }
}