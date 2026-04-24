<?php
// Simple autoloader for our classes
spl_autoload_register(function ($class) {
    $prefix = '';
    $base_dir = __DIR__ . '/../';
    
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    
    if (file_exists($file)) {
        require $file;
        return true;
    }
    
    // Check in subdirectories
    $directories = ['models', 'controllers', 'helpers', 'middleware'];
    foreach ($directories as $dir) {
        $file = $base_dir . $dir . '/' . $class . '.php';
        if (file_exists($file)) {
            require $file;
            return true;
        }
    }
    
    return false;
});
?>