<?php
class ServiceProductHelper {
    
    public static function getServiceStatusBadge($trackInterval, $hasExpiry) {
        $badges = [];
        
        if ($trackInterval) {
            $badges[] = '<span class="badge badge-info"><i class="fas fa-sync-alt"></i> Recurring</span>';
        }
        
        if ($hasExpiry) {
            $badges[] = '<span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> Expires</span>';
        }
        
        if (empty($badges)) {
            $badges[] = '<span class="badge badge-secondary">One-time</span>';
        }
        
        return implode(' ', $badges);
    }
    
    public static function formatDuration($duration) {
        if (!$duration) return 'N/A';
        return $duration;
    }
    
    public static function formatInterval($interval, $unit) {
        if (!$interval) return 'Not tracked';
        
        $units = [
            'days' => 'day(s)',
            'weeks' => 'week(s)',
            'months' => 'month(s)',
            'years' => 'year(s)'
        ];
        
        $unitText = $units[$unit] ?? $unit;
        return "Every {$interval} {$unitText}";
    }
    
    public static function getStockStatusBadge($currentStock, $reorderLevel) {
        if ($currentStock <= 0) {
            return '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Out of Stock</span>';
        } elseif ($currentStock <= $reorderLevel) {
            return '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Low Stock</span>';
        } else {
            return '<span class="badge badge-success"><i class="fas fa-check-circle"></i> In Stock</span>';
        }
    }
    
    public static function getProfitMargin($cost, $selling) {
        if ($cost <= 0) return 0;
        return round(($selling - $cost) / $selling * 100, 1);
    }
    
    public static function calculateMarkup($cost, $percentage) {
        return $cost * (1 + $percentage / 100);
    }
    
    public static function getNextServiceDate($lastServiceDate, $interval, $unit) {
        if (!$lastServiceDate) return null;
        
        $date = new DateTime($lastServiceDate);
        
        switch ($unit) {
            case 'days':
                $date->modify("+{$interval} days");
                break;
            case 'weeks':
                $date->modify("+{$interval} weeks");
                break;
            case 'months':
                $date->modify("+{$interval} months");
                break;
            case 'years':
                $date->modify("+{$interval} years");
                break;
        }
        
        return $date->format('Y-m-d');
    }
    
    public static function getExpiryDate($serviceDate, $expiryDays, $expiryUnit) {
        if (!$serviceDate) return null;
        
        $date = new DateTime($serviceDate);
        
        switch ($expiryUnit) {
            case 'days':
                $date->modify("+{$expiryDays} days");
                break;
            case 'weeks':
                $date->modify("+{$expiryDays} weeks");
                break;
            case 'months':
                $date->modify("+{$expiryDays} months");
                break;
            case 'years':
                $date->modify("+{$expiryDays} years");
                break;
        }
        
        return $date->format('Y-m-d');
    }
    
    public static function formatPrice($price, $currency = 'UGX') {
        return $currency . ' ' . number_format($price, 0, '.', ',');
    }
    
    public static function getUnitOptions() {
        return [
            'piece' => 'Piece',
            'liter' => 'Liter',
            'kilogram' => 'Kilogram',
            'meter' => 'Meter',
            'box' => 'Box',
            'set' => 'Set',
            'pack' => 'Pack'
        ];
    }
    
    public static function getIntervalUnits() {
        return [
            'days' => 'Days',
            'weeks' => 'Weeks',
            'months' => 'Months',
            'years' => 'Years'
        ];
    }
    
    public static function getServiceCategories() {
        return [
            'Oil Change' => 'Oil Change',
            'Brake Service' => 'Brake Service',
            'Engine Tune-up' => 'Engine Tune-up',
            'Transmission Service' => 'Transmission Service',
            'AC Service' => 'AC Service',
            'Electrical' => 'Electrical',
            'Inspection' => 'Inspection',
            'Tire Service' => 'Tire Service',
            'Body Work' => 'Body Work',
            'General Maintenance' => 'General Maintenance'
        ];
    }
    
    public static function getProductCategories() {
        return [
            'Engine Parts' => 'Engine Parts',
            'Brake Parts' => 'Brake Parts',
            'Electrical Parts' => 'Electrical Parts',
            'Body Parts' => 'Body Parts',
            'Accessories' => 'Accessories',
            'Fluids & Lubricants' => 'Fluids & Lubricants',
            'Filters' => 'Filters',
            'Tires' => 'Tires',
            'Batteries' => 'Batteries',
            'Tools' => 'Tools'
        ];
    }
    
    public static function validateServiceData($data) {
        $errors = [];
        
        if (empty($data['service_name'])) {
            $errors[] = 'Service name is required';
        }
        
        if (empty($data['standard_price']) || $data['standard_price'] <= 0) {
            $errors[] = 'Valid price is required';
        }
        
        if (!empty($data['track_interval']) && empty($data['service_interval'])) {
            $errors[] = 'Service interval is required when tracking is enabled';
        }
        
        if (!empty($data['has_expiry']) && empty($data['expiry_days'])) {
            $errors[] = 'Expiry days are required when expiry is enabled';
        }
        
        return $errors;
    }
    
    public static function validateProductData($data) {
        $errors = [];
        
        if (empty($data['item_name'])) {
            $errors[] = 'Product name is required';
        }
        
        if (empty($data['selling_price']) || $data['selling_price'] <= 0) {
            $errors[] = 'Valid selling price is required';
        }
        
        return $errors;
    }
}
?>