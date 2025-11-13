<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Initialize unread_count
$unread_count = 0;
try {
    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
} catch (Exception $e) {
    // Silently handle error, default to 0
    $unread_count = 0;
    error_log("Error getting unread count: " . $e->getMessage());
}

// Get student info
$student = null;
try {
    $student = getStudentByEmail($_SESSION['user_email']);
} catch (Exception $e) {
    error_log("Error getting student info: " . $e->getMessage());
}
?>