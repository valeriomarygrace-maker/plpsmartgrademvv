<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

try {
    if ($user_type === 'admin') {
        $student_id = $_GET['student_id'] ?? null;
        if ($student_id) {
            $messages = getMessagesBetweenUsers($user_id, 'admin', $student_id, 'student');
            echo json_encode(['success' => true, 'messages' => $messages]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Student ID required', 'messages' => []]);
        }
    } elseif ($user_type === 'student') {
        $admin_id = $_GET['admin_id'] ?? null;
        if ($admin_id) {
            $messages = getMessagesBetweenUsers($user_id, 'student', $admin_id, 'admin');
            echo json_encode(['success' => true, 'messages' => $messages]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Admin ID required', 'messages' => []]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid user type', 'messages' => []]);
    }
} catch (Exception $e) {
    error_log("Error in get_messages.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error', 'messages' => []]);
}
?>