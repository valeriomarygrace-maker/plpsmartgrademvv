<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

if ($user_type === 'admin') {
    $student_id = $_GET['student_id'] ?? null;
    if ($student_id) {
        // Get messages between admin and specific student
        $messages = supabaseFetch('messages', [
            'or' => "(sender_id.eq.{$user_id},receiver_id.eq.{$user_id})",
            'and' => "(sender_id.eq.{$student_id},receiver_id.eq.{$student_id})"
        ], 'GET', null, 'created_at.asc');
        
        echo json_encode($messages ?: []);
    }
} elseif ($user_type === 'student') {
    $admin_id = $_GET['admin_id'] ?? null;
    if ($admin_id) {
        // Get messages between student and specific admin
        $messages = supabaseFetch('messages', [
            'or' => "(sender_id.eq.{$user_id},receiver_id.eq.{$user_id})",
            'and' => "(sender_id.eq.{$admin_id},receiver_id.eq.{$admin_id})"
        ], 'GET', null, 'created_at.asc');
        
        echo json_encode($messages ?: []);
    }
}
?>