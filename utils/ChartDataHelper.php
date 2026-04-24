<?php
namespace Utils;

class ChartDataHelper {
    
    public static function prepareWeeklyData($weeklyData) {
        $labels = [];
        $income = [];
        $expense = [];
        
        foreach ($weeklyData as $data) {
            $labels[] = date('D, M j', strtotime($data['date']));
            $income[] = (float)$data['income'];
            $expense[] = (float)$data['expense'];
        }
        
        return [
            'labels' => $labels,
            'income' => $income,
            'expense' => $expense
        ];
    }
    
    public static function prepareMonthlyData($monthlyData) {
        $labels = [];
        $income = [];
        $expense = [];
        
        foreach ($monthlyData as $data) {
            $labels[] = date('M Y', strtotime($data['month'] . '-01'));
            $income[] = (float)$data['income'];
            $expense[] = (float)$data['expense'];
        }
        
        return [
            'labels' => $labels,
            'income' => $income,
            'expense' => $expense
        ];
    }
    
    public static function prepareCategoryData($categoryData) {
        $incomeCategories = [];
        $expenseCategories = [];
        $incomeAmounts = [];
        $expenseAmounts = [];
        
        foreach ($categoryData as $data) {
            if ($data['transaction_type'] === 'income') {
                $incomeCategories[] = $data['category'];
                $incomeAmounts[] = (float)$data['total_amount'];
            } else {
                $expenseCategories[] = $data['category'];
                $expenseAmounts[] = (float)$data['total_amount'];
            }
        }
        
        return [
            'income' => ['labels' => $incomeCategories, 'data' => $incomeAmounts],
            'expense' => ['labels' => $expenseCategories, 'data' => $expenseAmounts]
        ];
    }
}