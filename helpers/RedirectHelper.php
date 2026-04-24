<?php
namespace Core\Helpers;

class RedirectHelper
{
    public static function to($url)
    {
        header("Location: $url");
        exit;
    }
}