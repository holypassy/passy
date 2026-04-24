<?php
function formatMoney($amount) {
    return 'UGX ' . number_format($amount, 0, '.', ',');
}

function formatDate($date) {
    if (!$date) return '';
    return date('d M Y', strtotime($date));
}

function formatDateTime($datetime) {
    if (!$datetime) return '';
    return date('d M Y H:i', strtotime($datetime));
}

function generateRandomString($length = 10) {
    return bin2hex(random_bytes($length / 2));
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'in_progress' => '<span class="badge badge-info">In Progress</span>',
        'completed' => '<span class="badge badge-success">Completed</span>',
        'cancelled' => '<span class="badge badge-danger">Cancelled</span>',
        'approved' => '<span class="badge badge-success">Approved</span>',
        'rejected' => '<span class="badge badge-danger">Rejected</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
}

function getRoleBadge($role) {
    $badges = [
        'admin' => '<span class="badge badge-danger">Admin</span>',
        'manager' => '<span class="badge badge-warning">Manager</span>',
        'technician' => '<span class="badge badge-info">Technician</span>',
        'cashier' => '<span class="badge badge-success">Cashier</span>'
    ];
    
    return $badges[$role] ?? '<span class="badge badge-secondary">' . ucfirst($role) . '</span>';
}

function isActive($path) {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false ? 'active' : '';
}
?>