<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Always get fresh count
$count = getUnreadMessageCount($user_id, $user_type);

echo json_encode(['count' => $count]);
?>