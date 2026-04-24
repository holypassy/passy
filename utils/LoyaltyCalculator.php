<?php
namespace Utils;

class LoyaltyCalculator {
    
    const POINTS_PER_AMOUNT = 1; // 1 point per 1000 UGX
    const POINTS_MULTIPLIER = [
        'bronze' => 1,
        'silver' => 1.2,
        'gold' => 1.5,
        'platinum' => 2
    ];
    
    public static function calculatePoints($amount, $tier = 'bronze') {
        $basePoints = floor($amount / 1000);
        $multiplier = self::POINTS_MULTIPLIER[$tier] ?? 1;
        return floor($basePoints * $multiplier);
    }
    
    public static function getTierFromSpending($totalSpent) {
        if ($totalSpent >= 10000000) return 'platinum';
        if ($totalSpent >= 5000000) return 'gold';
        if ($totalSpent >= 1000000) return 'silver';
        return 'bronze';
    }
    
    public static function getNextTierInfo($totalSpent) {
        if ($totalSpent < 1000000) {
            return [
                'next_tier' => 'silver',
                'amount_needed' => 1000000 - $totalSpent,
                'benefits' => '5% discount on all services, Priority support'
            ];
        } elseif ($totalSpent < 5000000) {
            return [
                'next_tier' => 'gold',
                'amount_needed' => 5000000 - $totalSpent,
                'benefits' => '10% discount, Free pickup & delivery, Dedicated account manager'
            ];
        } elseif ($totalSpent < 10000000) {
            return [
                'next_tier' => 'platinum',
                'amount_needed' => 10000000 - $totalSpent,
                'benefits' => '15% discount, VIP lounge access, Free annual service, Birthday gifts'
            ];
        } else {
            return null;
        }
    }
    
    public static function getPointsValue($points) {
        // 100 points = 5000 UGX value
        return $points * 50;
    }
    
    public static function getDiscountFromPoints($points) {
        // Maximum discount of 50% of order value
        return floor($points / 100) * 5000;
    }
}