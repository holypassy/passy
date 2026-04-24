<?php

function getStatusBadge($status) {
    $badges = [
        'available' => '<span class="status-badge status-available"><i class="fas fa-check-circle"></i> Available</span>',
        'taken' => '<span class="status-badge status-taken"><i class="fas fa-hand-holding"></i> Taken</span>',
        'maintenance' => '<span class="status-badge status-maintenance"><i class="fas fa-wrench"></i> Maintenance</span>',
        'damaged' => '<span class="status-badge status-damaged"><i class="fas fa-exclamation-triangle"></i> Damaged</span>',
        'retired' => '<span class="status-badge status-retired"><i class="fas fa-trash-alt"></i> Retired</span>'
    ];
    return $badges[$status] ?? '<span class="status-badge">' . ucfirst($status) . '</span>';
}

function getConditionBadge($condition) {
    $badges = [
        'new' => '<span class="badge badge-new">New</span>',
        'good' => '<span class="badge badge-good">Good</span>',
        'fair' => '<span class="badge badge-fair">Fair</span>',
        'poor' => '<span class="badge badge-poor">Poor</span>'
    ];
    return $badges[$condition] ?? '<span class="badge">' . ucfirst($condition) . '</span>';
}

function getUrgencyBadge($urgency) {
    $badges = [
        'emergency' => '<span class="badge badge-emergency"><i class="fas fa-exclamation-triangle"></i> Emergency</span>',
        'high' => '<span class="badge badge-high"><i class="fas fa-arrow-up"></i> High</span>',
        'medium' => '<span class="badge badge-medium"><i class="fas fa-minus"></i> Medium</span>',
        'low' => '<span class="badge badge-low"><i class="fas fa-arrow-down"></i> Low</span>'
    ];
    return $badges[$urgency] ?? '<span class="badge">' . ucfirst($urgency) . '</span>';
}

function formatCurrency($amount) {
    return 'UGX ' . number_format($amount, 0);
}

function formatDate($date) {
    if (!$date || $date === '0000-00-00') return 'N/A';
    return date('d M Y', strtotime($date));
}

function timeAgo($datetime) {
    if (!$datetime) return 'Never';
    
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    
    return date('d M Y', $timestamp);
}