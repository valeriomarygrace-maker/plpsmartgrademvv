<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'];
    
    try {
        if ($user_type === 'admin' && isset($input['student_id'])) {
            markMessagesAsRead($input['student_id'], 'student', $user_id, 'admin');
        } elseif ($user_type === 'student' && isset($input['admin_id'])) {
            markMessagesAsRead($input['admin_id'], 'admin', $user_id, 'student');
        }
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log("Error in mark_read.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>