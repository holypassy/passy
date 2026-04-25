<?php
// new_invoice.php - Redirect to invoices list
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Set a session message to inform the user about the workflow
$_SESSION['info'] = "Invoices are created from approved quotations. Please use the 'Invoice' action on an approved quotation.";

// Redirect to the invoices list page
header('Location: invoices.php');
exit();
?>