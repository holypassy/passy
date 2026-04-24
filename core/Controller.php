<?php
namespace App\Core;

abstract class Controller {
    protected function view($view, $data = []) {
        extract($data);
        $viewPath = __DIR__ . "/../Views/{$view}.php";
        
        if (file_exists($viewPath)) {
            require_once $viewPath;
        } else {
            die("View not found: {$view}");
        }
    }
    
    protected function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
    
    protected function redirect($url) {
        header("Location: {$url}");
        exit();
    }
    
    protected function setFlash($key, $message) {
        $_SESSION['flash'][$key] = $message;
    }
    
    protected function getFlash($key) {
        $message = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $message;
    }
}
?>