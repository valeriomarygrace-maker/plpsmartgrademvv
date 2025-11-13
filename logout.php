<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log logout activity before destroying session
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    require_once 'config.php';
    
    logUserLogout(
        $_SESSION['user_email'] ?? '',
        $_SESSION['user_type'] ?? '',
        $_SESSION['user_id'] ?? 0
    );
}

// Destroy all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Clear any cached pages
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Redirect to login page
header("Location: login.php");
exit();
?>