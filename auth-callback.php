<?php
require_once 'config.php';

// Get the token from URL parameters
$access_token = $_GET['access_token'] ?? '';
$error = $_GET['error_description'] ?? '';

if ($error) {
    header('Location: login.php?error=' . urlencode($error));
    exit;
}

if (empty($access_token)) {
    header('Location: login.php?error=Invalid authentication link');
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
            regenerateSession();
            
            $_SESSION['logged_in'] = true;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_type'] = 'student';
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_name'] = $student['fullname'];
            $_SESSION['login_time'] = time();
            
            error_log("✅ Magic Link login successful for: $email");
            header('Location: student-dashboard.php');
            exit;
        } else {
            error_log("❌ Student not found for email: $email");
            header('Location: login.php?error=Student not found in our system');
            exit;
        }
    }
}

header('Location: login.php?error=Authentication failed. Please try again.');
exit;
?>