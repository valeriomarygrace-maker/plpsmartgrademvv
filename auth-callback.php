<?php
require_once 'config.php';

error_log("🔄 Auth callback accessed");

// Get the token from URL
$access_token = $_GET['access_token'] ?? '';

if (empty($access_token)) {
    error_log("❌ No access token found");
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

error_log("🔍 Supabase verification - HTTP Code: $httpCode");

if ($httpCode === 200) {
    $user = json_decode($response, true);
    $email = $user['email'] ?? '';
    
    error_log("📧 Verified email: $email");
    
    if ($email) {
        $student = getStudentByEmail($email);
        
        if ($student) {
            // DESTROY any existing session and start fresh
            session_destroy();
            
            // Start new session with proper settings
            session_set_cookie_params([
                'lifetime' => 86400,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
            
            // SET SESSION VARIABLES
            $_SESSION['logged_in'] = true;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_type'] = 'student';
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_name'] = $student['fullname'];
            $_SESSION['login_time'] = time();
            $_SESSION['created'] = time();
            
            error_log("✅ Session created for: $email");
            error_log("✅ Session ID: " . session_id());
            
            // Force save and redirect IMMEDIATELY
            session_write_close();
            
            // REDIRECT DIRECTLY TO DASHBOARD
            header('Location: student-dashboard.php');
            exit;
        } else {
            error_log("❌ Student not found: $email");
            header('Location: login.php?error=Student not found');
            exit;
        }
    }
}

// If anything fails
error_log("❌ Authentication failed");
header('Location: login.php?error=Login failed');
exit;
?>