<?php
class CorsMiddleware {
    public static function handle() {
        $allowed_origins = [
            'http://localhost',
            'http://localhost:3000',
            'http://127.0.0.1'
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: $origin");
        }
        
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: true");
        header("Content-Type: application/json; charset=UTF-8");
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}
?>