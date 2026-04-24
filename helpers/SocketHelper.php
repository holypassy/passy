<?php
class StockHelper {
    
    public static function calculateReorderQuantity($currentStock, $reorderLevel, $maxStock = null) {
        if ($currentStock >= $reorderLevel) {
            return 0;
        }
        
        $shortage = $reorderLevel - $currentStock;
        
        if ($maxStock) {
            $reorderQuantity = min($shortage, $maxStock - $currentStock);
        } else {
            $reorderQuantity = $shortage;
        }
        
        return max(0, $reorderQuantity);
    }
    
    public static function getStockStatus($quantity, $reorderLevel) {
        if ($quantity <= 0) {
            return [
                'status' => 'Out of Stock',
                'class' => 'danger',
                'icon' => 'fa-times-circle'
            ];
        } elseif ($quantity <= $reorderLevel) {
            return [
                'status' => 'Low Stock',
                'class' => 'warning',
                'icon' => 'fa-exclamation-triangle'
            ];
        } else {
            return [
                'status' => 'In Stock',
                'class' => 'success',
                'icon' => 'fa-check-circle'
            ];
        }
    }
    
    public static function calculateTurnoverRate($totalSold, $averageInventory) {
        if ($averageInventory == 0) return 0;
        return round($totalSold / $averageInventory, 2);
    }
    
    public static function calculateDaysOfStock($currentStock, $dailyAverageUsage) {
        if ($dailyAverageUsage == 0) return 0;
        return round($currentStock / $dailyAverageUsage);
    }
    
    public static function formatStockValue($quantity, $unitPrice) {
        return $quantity * $unitPrice;
    }
    
    public static function getMarginPercentage($costPrice, $sellingPrice) {
        if ($costPrice == 0) return 0;
        return round((($sellingPrice - $costPrice) / $sellingPrice) * 100, 2);
    }
    
    public static function getRecommendedReorderDate($currentStock, $dailyUsage, $leadTimeDays) {
        $daysRemaining = $currentStock / $dailyUsage;
        $reorderPoint = $dailyUsage * $leadTimeDays;
        
        if ($currentStock <= $reorderPoint) {
            return 'Order Now';
        }
        
        $daysUntilReorder = ($currentStock - $reorderPoint) / $dailyUsage;
        return date('Y-m-d', strtotime("+{$daysUntilReorder} days"));
    }
}
?>