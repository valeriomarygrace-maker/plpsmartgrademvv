<?php
require_once 'config.php';

// Force session start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the token from URL
$access_token = $_GET['access_token'] ?? '';

if (empty($access_token)) {
    header('Location: login.php?error=Invalid magic link');
    exit;
}

// Verify with Supabase
$url = $supabase_url . '/auth/v1/user';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $access_token,
        'apikey: ' . $supabase_key
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $user = json_decode($response, true);
    $email = $user['email'] ?? '';
    
    if ($email) {
        $student = getStudentByEmail($email);
        
        if ($student) {
            // SET SESSION - This is the key part!
            $_SESSION['logged_in'] = true;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_type'] = 'student';
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_name'] = $student['fullname'];
            $_SESSION['login_time'] = time();
            $_SESSION['created'] = time();
            
            // Force session write
            session_write_close();
            
            // Redirect to dashboard
            header('Location: student-dashboard.php');
            exit;
        }
    }
}

// If anything fails, go to login
header('Location: login.php?error=Login failed. Please try again.');
exit;
?>