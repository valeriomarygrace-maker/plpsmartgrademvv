<?php
require_once 'config.php';

echo "<h2>OTP Sending Debug</h2>";

$email = 'test@plpasig.edu.ph'; // Use an email that exists in your students table

// Test 1: Check if student exists
echo "<h3>1. Checking if student exists</h3>";
$student = getStudentByEmail($email);
if ($student) {
    echo "<p style='color: green;'>✓ Student found: " . $student['fullname'] . "</p>";
} else {
    echo "<p style='color: red;'>✗ Student not found</p>";
    // List available students for testing
    $allStudents = supabaseFetch('students', 'select=*&limit=5', 'GET');
    echo "<p>Available students:</p>";
    foreach ($allStudents as $s) {
        echo "<p>- " . $s['email'] . " (" . $s['fullname'] . ")</p>";
    }
}

// Test 2: Test OTP insertion
echo "<h3>2. Testing OTP insertion</h3>";
$otp = generateOTP();
$otpData = [
    'email' => $email,
    'otp_code' => $otp,
    'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
    'is_used' => false
];

echo "<p>OTP Data: " . json_encode($otpData) . "</p>";

$result = supabaseInsert('otp_verification', $otpData);
if ($result !== false) {
    echo "<p style='color: green;'>✓ OTP stored successfully in database</p>";
    echo "<p>Result: " . json_encode($result) . "</p>";
} else {
    echo "<p style='color: red;'>✗ Failed to store OTP in database</p>";
}

// Test 3: Test email sending
echo "<h3>3. Testing email sending</h3>";
if (sendOTP($email, $otp)) {
    echo "<p style='color: green;'>✓ Email sent successfully</p>";
} else {
    echo "<p style='color: red;'>✗ Failed to send email</p>";
}
?>