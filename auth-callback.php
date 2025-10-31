<?php
require_once 'config.php';

// Get the token from URL parameters
$access_token = $_GET['access_token'] ?? '';
$error = $_GET['error_description'] ?? '';

error_log("🔄 Auth callback accessed. Token: " . (!empty($access_token) ? 'Present' : 'Missing'));

if ($error) {
    error_log("❌ Auth error: $error");
    header('Location: login.php?error=' . urlencode($error));
    exit;
}

if (empty($access_token)) {
    error_log("❌ No access token provided");
    header('Location: login.php?error=Invalid authentication link. Please try logging in again.');
    exit;
}

// Verify the user with Supabase
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
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

error_log("🔍 Supabase user verification - HTTP Code: $httpCode");

if ($httpCode === 200) {
    $user = json_decode($response, true);
    $email = $user['email'] ?? '';
    
    error_log("📧 Verified user email: $email");
    
    if ($email) {
        $student = getStudentByEmail($email);
        
        if ($student) {
            // Regenerate session for security
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            
            // Set session variables
            $_SESSION['logged_in'] = true;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_type'] = 'student';
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_name'] = $student['fullname'];
            $_SESSION['login_time'] = time();
            $_SESSION['created'] = time();
            
            error_log("✅ Magic Link login successful for: $email - Redirecting to dashboard");
            
            // Redirect to dashboard
            header('Location: student-dashboard.php');
            exit;
        } else {
            error_log("❌ Student not found in database for email: $email");
            header('Location: login.php?error=Student account not found. Please contact administrator.');
            exit;
        }
    } else {
        error_log("❌ No email in user data");
    }
} else {
    error_log("❌ Supabase verification failed with code: $httpCode");
}

header('Location: login.php?error=Authentication failed. Please try logging in again.');
exit;
?>