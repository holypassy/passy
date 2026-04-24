<?php
class ChartHelper {
    
    public static function prepareLineChartData($labels, $datasets) {
        return [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => $datasets
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'bottom'],
                    'tooltip' => ['mode' => 'index', 'intersect' => false]
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'callback' => 'function(value) { return "UGX " + value.toLocaleString(); }'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    public static function preparePieChartData($labels, $data, $colors = null) {
        $defaultColors = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec489a'];
        
        if (!$colors) {
            $colors = $defaultColors;
        }
        
        return [
            'type' => 'pie',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'data' => $data,
                    'backgroundColor' => array_slice($colors, 0, count($labels)),
                    'borderWidth' => 0
                ]]
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'bottom']
                ]
            ]
        ];
    }
    
    public static function prepareBarChartData($labels, $datasets) {
        return [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => $datasets
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'bottom']
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                        'ticks' => [
                            'callback' => 'function(value) { return "UGX " + value.toLocaleString(); }'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    public static function formatCurrency($amount) {
        return 'UGX ' . number_format($amount, 0, '.', ',');
    }
    
    public static function formatPercentage($value, $total) {
        if ($total == 0) return '0%';
        return round(($value / $total) * 100, 1) . '%';
    }
}
?>