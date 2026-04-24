<?php
namespace App\Core;

class Router {
    private $routes = [];
    private $basePath = '/savant/';
    
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
        return $this;
    }
    
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
        return $this;
    }
    
    public function put($path, $handler) {
        $this->addRoute('PUT', $path, $handler);
        return $this;
    }
    
    public function delete($path, $handler) {
        $this->addRoute('DELETE', $path, $handler);
        return $this;
    }
    
    private function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ];
    }
    
    public function dispatch() {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $requestUri = str_replace($this->basePath, '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $requestUri = '/' . ltrim($requestUri, '/');
        
        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $this->matchPath($route['path'], $requestUri, $params)) {
                $this->callHandler($route['handler'], $params);
                return;
            }
        }
        
        http_response_code(404);
        echo "404 - Page Not Found";
    }
    
    private function matchPath($routePath, $requestUri, &$params) {
        $routePattern = preg_replace('/\{([a-z]+)\}/', '(?P<$1>[^/]+)', $routePath);
        $routePattern = '#^' . $routePattern . '$#';
        
        if (preg_match($routePattern, $requestUri, $matches)) {
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return true;
        }
        
        return false;
    }
    
    private function callHandler($handler, $params) {
        if (is_callable($handler)) {
            call_user_func_array($handler, $params);
        } elseif (is_string($handler)) {
            list($controllerName, $method) = explode('@', $handler);
            $controllerClass = "App\\Controllers\\{$controllerName}";
            
            if (class_exists($controllerClass)) {
                $controller = new $controllerClass();
                if (method_exists($controller, $method)) {
                    call_user_func_array([$controller, $method], $params);
                } else {
                    die("Method {$method} not found in {$controllerName}");
                }
            } else {
                die("Controller {$controllerName} not found");
            }
        }
    }
}
?>