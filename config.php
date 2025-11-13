<?php
// Environment Configuration
$supabase_url = getenv('SUPABASE_URL') ?: 'https://xwvrgpxcceivakzrwwji.supabase.co';
$supabase_key = getenv('SUPABASE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh3dnJncHhjY2VpdmFrenJ3d2ppIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjE3MjQ0NzQsImV4cCI6MjA3NzMwMDQ3NH0.ovd8v3lqsYtJU78D4iM6CyAyvi6jK4FUbYUjydFi4FM';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Simple Supabase API Helper Function
 */
function supabaseFetch($table, $filters = [], $method = 'GET', $data = null) {
    global $supabase_url, $supabase_key;
    
    $url = $supabase_url . "/rest/v1/$table";
    
    $queryParams = [];
    foreach ($filters as $key => $value) {
        if ($value !== null) {
            $queryParams[] = "$key=eq.$value";
        }
    }
    
    if (!empty($queryParams)) {
        $url .= "?" . implode('&', $queryParams);
    }
    
    $ch = curl_init();
    $headers = [
        'apikey: ' . $supabase_key,
        'Authorization: Bearer ' . $supabase_key,
        'Content-Type: ' . 'application/json',
        'Prefer: return=representation'
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        return false;
    }
    
    $result = json_decode($response, true);
    
    if ($result === null && $httpCode === 200) {
        return true;
    }
    
    return $result;
}

/**
 * Password Hashing and Verification
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hashedPassword) {
    return password_verify($password, $hashedPassword);
}

/**
 * Student Functions
 */
function getStudentByEmail($email) {
    $students = supabaseFetch('students', ['email' => $email]);
    return $students && count($students) > 0 ? $students[0] : null;
}

function getStudentById($id) {
    $students = supabaseFetch('students', ['id' => $id]);
    return $students && count($students) > 0 ? $students[0] : null;
}

function isValidPLPEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = substr(strrchr($email, "@"), 1);
    return strtolower($domain) === 'plpasig.edu.ph';
}

/**
 * Admin Functions
 */
function getAdminByEmail($email) {
    $admins = supabaseFetch('admins', ['email' => $email]);
    return $admins && count($admins) > 0 ? $admins[0] : null;
}

function requireAdminRole() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

function requireStudentRole() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
        header('Location: login.php');
        exit;
    }
}

function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Additional Supabase Helper Functions
 */
function supabaseUpdate($table, $data, $filters = []) {
    return supabaseFetch($table, $filters, 'PATCH', $data);
}

function supabaseInsert($table, $data) {
    return supabaseFetch($table, [], 'POST', $data);
}

function supabaseFetchAll($table, $filters = []) {
    return supabaseFetch($table, $filters);
}

function getUnreadMessageCount($userId, $userType) {
    if ($userType === 'student') {
        $filters = [
            'receiver_id' => $userId,
            'receiver_type' => 'student',
            'is_read' => 'false'
        ];
    } else {
        $filters = [
            'receiver_id' => $userId,
            'receiver_type' => 'admin', 
            'is_read' => 'false'
        ];
    }
    
    $messages = supabaseFetch('messages', $filters);
    return $messages ? count($messages) : 0;
}

function getMessagesBetweenUsers($user1_id, $user1_type, $user2_id, $user2_type) {
    global $supabase_url, $supabase_key;
    
    // Get messages between two specific users
    $url = $supabase_url . "/rest/v1/messages?" . http_build_query([
        'or' => "(and(sender_id.eq.{$user1_id},sender_type.eq.{$user1_type},receiver_id.eq.{$user2_id},receiver_type.eq.{$user2_type}),and(sender_id.eq.{$user2_id},sender_type.eq.{$user2_type},receiver_id.eq.{$user1_id},receiver_type.eq.{$user1_type}))",
        'order' => 'created_at.asc'
    ]);
    
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
        return json_decode($response, true) ?: [];
    }
    
    return [];
}

function markMessagesAsRead($message_ids) {
    if (empty($message_ids)) return false;
    
    $data = ['is_read' => true];
    $filters = ['id' => 'in.(' . implode(',', $message_ids) . ')'];
    
    return supabaseUpdate('messages', $data, $filters);
}

function getConversationPartners($userId, $userType) {
    if ($userType === 'student') {
        // For students, get all admins (show all admins even if no messages yet)
        $admins = supabaseFetchAll('admins');
        $partners = [];
        
        foreach ($admins as $admin) {
            $messages = getMessagesBetweenUsers($userId, 'student', $admin['id'], 'admin');
            $unread_count = 0;
            
            if (!empty($messages)) {
                $unread_count = count(array_filter($messages, function($msg) use ($userId) {
                    return $msg['sender_type'] === 'admin' && !$msg['is_read'];
                }));
            }
            
            $partners[] = [
                'id' => $admin['id'],
                'name' => $admin['fullname'],
                'type' => 'admin',
                'unread_count' => $unread_count
            ];
        }
        return $partners;
    } else {
        // For admins, get all students (show all students even if no messages yet)
        $students = supabaseFetchAll('students');
        $partners = [];
        
        foreach ($students as $student) {
            $messages = getMessagesBetweenUsers($userId, 'admin', $student['id'], 'student');
            $unread_count = 0;
            
            if (!empty($messages)) {
                $unread_count = count(array_filter($messages, function($msg) use ($userId) {
                    return $msg['sender_type'] === 'student' && !$msg['is_read'];
                }));
            }
            
            $partners[] = [
                'id' => $student['id'],
                'name' => $student['fullname'],
                'type' => 'student',
                'unread_count' => $unread_count
            ];
        }
        return $partners;
    }
}

?>