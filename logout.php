<?php
require_once 'functions.php';

// 清除记住我cookie
if (isset($_COOKIE[REMEMBER_ME_COOKIE_NAME])) {
    $token = $_COOKIE[REMEMBER_ME_COOKIE_NAME];
    deleteRememberSession($token);
    setcookie(REMEMBER_ME_COOKIE_NAME, '', time() - 3600, '/');
}

// 清除会话
$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Location: login.php');
exit;