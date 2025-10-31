<?php
// Database configuration - Use environment variables for Render
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'smartgrade';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

session_start();

require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function sendOTP($email, $otp) {
    global $pdo;
    
    $userType = '';
    $fullname = '';
    
    // Check if email exists in students table
    $stmt = $pdo->prepare("SELECT fullname FROM students WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $userType = 'Student';
        $fullname = $stmt->fetchColumn();
    } else {
        // Check if email exists in teachers table (add this if you have teachers)
        $stmt = $pdo->prepare("SELECT fullname FROM teachers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $userType = 'Teacher';
            $fullname = $stmt->fetchColumn();
        } else {
            $userType = 'User';
            $fullname = 'User';
        }
    }

    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'marygracevalerio177@gmail.com';
        $mail->Password   = getenv('SMTP_PASS') ?: 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        
        // Add SMTP options for Render compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Debugging (remove in production)
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Change to DEBUG_SERVER for troubleshooting

        $mail->setFrom(getenv('SMTP_USER') ?: 'noreply@smartgrade.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; text-align: center; color: white;'>
                    <h2 style='margin: 0;'>PLP SmartGrade</h2>
                    <p style='margin: 5px 0 0 0; opacity: 0.9;'>Email Verification</p>
                </div>
                
                <div style='padding: 30px; background: #f9f9f9;'>
                    <p style='margin-bottom: 15px; color: #333;'>Hello, <strong>$fullname</strong>,</p>
                    <p style='margin-bottom: 15px; color: #333;'>You are logging in as a <strong>$userType</strong>.</p>
                    <p style='margin-bottom: 20px; color: #333;'>Your OTP code is:</p>
                    
                    <div style='text-align: center; margin: 25px 0;'>
                        <div style='display: inline-block; padding: 15px 30px; background: white; border: 2px dashed #667eea; border-radius: 8px;'>
                            <div style='font-size: 32px; font-weight: bold; color: #141414; letter-spacing: 5px;'>$otp</div>
                        </div>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; text-align: center;'>
                        This code will expire in 10 minutes.
                    </p>
                    
                    <div style='margin-top: 25px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;'>
                        <p style='margin: 0; color: #856404; font-size: 12px;'>
                            <strong>Security Tip:</strong> If you didn't request this OTP, please ignore this email and contact support immediately.
                        </p>
                    </div>
                </div>
                
                <div style='padding: 20px; text-align: center; background: #333; color: #fff;'>
                    <p style='margin: 0; font-size: 12px;'>
                        © " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.
                    </p>
                </div>
            </div>
        ";

        // Alternative plain text version
        $mail->AltBody = "PLP SmartGrade OTP Verification\n\nHello $fullname,\nYou are logging in as a $userType.\nYour OTP code is: $otp\nThis code will expire in 10 minutes.\n\nIf you didn't request this OTP, please ignore this email.";

        if ($mail->send()) {
            error_log("OTP sent successfully to: $email");
            return true;
        } else {
            error_log("Failed to send OTP to: $email");
            return false;
        }
    } catch (Exception $e) {
        error_log("PHPMailer Error for $email: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", random_int(1, 999999));
}

// Test function to check email configuration
function testEmailConfig() {
    $testEmail = 'test@example.com'; // Replace with your test email
    $testOTP = generateOTP();
    
    if (sendOTP($testEmail, $testOTP)) {
        echo "Email configuration test: SUCCESS";
        error_log("Email test: SUCCESS - OTP sent to $testEmail");
    } else {
        echo "Email configuration test: FAILED";
        error_log("Email test: FAILED - Check SMTP configuration");
    }
}

// Uncomment the line below to test email configuration
// testEmailConfig();
?>