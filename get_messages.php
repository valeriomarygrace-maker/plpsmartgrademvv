<?php
require_once 'config.php';

if (!isset($_GET['partner_id']) || !isset($_GET['user_type'])) {
    echo json_encode([]);
    exit;
}

$partner_id = $_GET['partner_id'];
$user_type = $_GET['user_type'];
$user_id = $_SESSION['user_id'];

if ($user_type === 'student') {
    $messages = getMessagesBetweenUsers($user_id, 'student', $partner_id, 'admin');
} else {
    $messages = getMessagesBetweenUsers($user_id, 'admin', $partner_id, 'student');
}

// Mark unread messages as read
$unread_ids = [];
foreach ($messages as $msg) {
    if (!$msg['is_read'] && $msg['receiver_id'] == $user_id && $msg['receiver_type'] == $user_type) {
        $unread_ids[] = $msg['id'];
    }
}

if (!empty($unread_ids)) {
    markMessagesAsRead($unread_ids);
}

echo json_encode($messages);
?>