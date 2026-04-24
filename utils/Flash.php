<?php
namespace Utils;

use Core\Session;

class Flash {
    public static function set($key, $message) {
        $flashes = Session::get('_flashes', []);
        $flashes[$key] = $message;
        Session::set('_flashes', $flashes);
    }

    public static function get($key) {
        $flashes = Session::get('_flashes', []);
        $message = $flashes[$key] ?? null;
        if (isset($flashes[$key])) {
            unset($flashes[$key]);
            Session::set('_flashes', $flashes);
        }
        return $message;
    }
}