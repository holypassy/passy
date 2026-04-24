<?php
namespace Core;

class Application {
    private $router;

    public function __construct() {
        $this->router = new Router();
        Session::start();
    }

    public function run() {
        $requestUri = $_SERVER['REQUEST_URI'];
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->router->dispatch($requestUri, $requestMethod);
    }

    public function getRouter() {
        return $this->router;
    }
}