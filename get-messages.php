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
        $messages = getMessagesBetweenUsers($user_id, 'admin', $student_id, 'student');
        echo json_encode($messages);
    } else {
        echo json_encode([]);
    }
} elseif ($user_type === 'student') {
    $admin_id = $_GET['admin_id'] ?? null;
    if ($admin_id) {
        $messages = getMessagesBetweenUsers($user_id, 'student', $admin_id, 'admin');
        echo json_encode($messages);
    } else {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}
?>