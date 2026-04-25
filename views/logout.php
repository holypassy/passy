<?php
session_start();

// Destroy all session data
$_SESSION = array();

// If a session cookie is used, delete it too
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Delete the "remember me" cookies if they exist
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}
if (isset($_COOKIE['user_id'])) {
    setcookie('user_id', '', time() - 3600, '/');
}

// Optional: clear any other custom cookies

// Redirect to the login page (or index)
header('Location: login.php');
exit;
?>