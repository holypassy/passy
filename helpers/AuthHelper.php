<?php
namespace Core\Helpers;

class AuthHelper
{
    public static function isLoggedIn()
    {
        return SessionHelper::get('user_id') !== null;
    }

    public static function requireLogin()
    {
        if (!self::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }

    public static function user()
    {
        $userId = SessionHelper::get('user_id');
        if ($userId) {
            return \App\Models\User::findById($userId);
        }
        return null;
    }
}