<?php
session_start();
header('Content-Type: application/json');
echo json_encode([
    'services' => $_SESSION['catalog']['services'] ?? [],
    'products' => $_SESSION['catalog']['products'] ?? []
]);
