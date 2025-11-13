<?php
session_start();

// Log logout activity before destroying session
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $email = $_SESSION['user_email'] ?? '';
    $user_type = $_SESSION['user_type'] ?? '';
    
    if (!empty($email) && !empty($user_type)) {
        require_once 'config.php';
        logSystemActivity($email, $user_type, 'logout', 'User logged out successfully');
    }
}

session_destroy();
header('Location: login.php');
exit;
?>