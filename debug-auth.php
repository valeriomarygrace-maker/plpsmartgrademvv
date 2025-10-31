<?php
require_once 'config.php';

echo "<h2>🔍 Auth Callback Debug</h2>";
echo "<pre>";

// Show all GET parameters
echo "GET parameters:\n";
print_r($_GET);

// Check if we have the token
$access_token = $_GET['access_token'] ?? '';
$error = $_GET['error_description'] ?? '';

echo "Access Token: " . ($access_token ? "PRESENT (" . substr($access_token, 0, 20) . "...)" : "MISSING") . "\n";
echo "Error: " . ($error ? $error : "None") . "\n";

if ($access_token) {
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

    echo "Supabase API Response Code: $httpCode\n";
    echo "Supabase Response:\n";
    print_r(json_decode($response, true));
    
    if ($httpCode === 200) {
        $user = json_decode($response, true);
        $email = $user['email'] ?? '';
        echo "User Email: $email\n";
        
        if ($email) {
            $student = getStudentByEmail($email);
            echo "Student in database: " . ($student ? "FOUND" : "NOT FOUND") . "\n";
            
            if ($student) {
                // Try to set session
                session_start();
                $_SESSION['logged_in'] = true;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_type'] = 'student';
                $_SESSION['user_id'] = $student['id'];
                $_SESSION['user_name'] = $student['fullname'];
                $_SESSION['login_time'] = time();
                
                echo "Session set successfully!\n";
                echo "Session data:\n";
                print_r($_SESSION);
                
                // Test redirect
                echo "Redirecting to dashboard...\n";
                header('Location: student-dashboard.php');
                exit;
            }
        }
    }
}

echo "</pre>";
?>