<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$sender_id = $_SESSION['user_id'];
$sender_type = $_SESSION['user_type'];
$receiver_id = sanitizeInput($_POST['receiver_id']);
$message = sanitizeInput($_POST['message']);

if (empty($message) || empty($receiver_id)) {
    echo json_encode(['success' => false]);
    exit;
}

// Determine receiver type
$receiver_type = ($sender_type === 'student') ? 'admin' : 'student';

$message_data = [
    'sender_id' => $sender_id,
    'sender_type' => $sender_type,
    'receiver_id' => $receiver_id,
    'receiver_type' => $receiver_type,
    'message' => $message,
    'is_read' => false
];

if (supabaseInsert('messages', $message_data)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false]);
}
?>