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
        global $supabase_url, $supabase_key;
        
        $url = $supabase_url . "/rest/v1/messages?select=*&or=(and(sender_id.eq.{$user_id},sender_type.eq.admin,receiver_id.eq.{$student_id},receiver_type.eq.student),and(sender_id.eq.{$student_id},sender_type.eq.student,receiver_id.eq.{$user_id},receiver_type.eq.admin))&order=created_at.asc";
        
        $ch = curl_init();
        $headers = [
            'apikey: ' . $supabase_key,
            'Authorization: Bearer ' . $supabase_key,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $messages = json_decode($response, true) ?: [];
            echo json_encode($messages);
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode([]);
    }
} elseif ($user_type === 'student') {
    $admin_id = $_GET['admin_id'] ?? null;
    if ($admin_id) {
        // Get messages between student and specific admin
        global $supabase_url, $supabase_key;
        
        $url = $supabase_url . "/rest/v1/messages?select=*&or=(and(sender_id.eq.{$user_id},sender_type.eq.student,receiver_id.eq.{$admin_id},receiver_type.eq.admin),and(sender_id.eq.{$admin_id},sender_type.eq.admin,receiver_id.eq.{$user_id},receiver_type.eq.student))&order=created_at.asc";
        
        $ch = curl_init();
        $headers = [
            'apikey: ' . $supabase_key,
            'Authorization: Bearer ' . $supabase_key,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $messages = json_decode($response, true) ?: [];
            echo json_encode($messages);
        } else {
            echo json_encode([]);
        }
    } else {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}
?>