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
    
    if ($user_type === 'admin' && isset($input['student_id'])) {
        // Mark messages from student to admin as read
        $filters = [
            'sender_id' => $input['student_id'],
            'sender_type' => 'student',
            'receiver_id' => $user_id,
            'receiver_type' => 'admin',
            'is_read' => false
        ];
        
        supabaseUpdate('messages', ['is_read' => true], $filters);
        
    } elseif ($user_type === 'student' && isset($input['admin_id'])) {
        // Mark messages from admin to student as read
        $filters = [
            'sender_id' => $input['admin_id'],
            'sender_type' => 'admin',
            'receiver_id' => $user_id,
            'receiver_type' => 'student',
            'is_read' => false
        ];
        
        supabaseUpdate('messages', ['is_read' => true], $filters);
    }
    
    echo json_encode(['success' => true]);
}
?>