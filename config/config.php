<?php
// Application Configuration
define('APP_NAME', 'SAVANT MOTORS ERP');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/savant_motors_erp');
define('TIMEZONE', 'Africa/Kampala');
date_default_timezone_set(TIMEZONE);

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Upload Configuration
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

// API Rate Limiting
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT_MINUTES', 15);

// Pagination
define('ITEMS_PER_PAGE', 20);
?>