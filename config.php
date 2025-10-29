<?php
$host = 'localhost';
$dbname = 'smartgrade';
$username = 'root';
$password = '';

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
    
    $stmt = $pdo->prepare("SELECT fullname FROM students WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $userType = 'Student';
        $fullname = $stmt->fetchColumn();
    }

    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification';
        $mail->Body    = "
            <div>
                <div>
                    <h2>Email Verification</h2>                
                    <div>
                        <p style='margin-bottom: 10px;'>Hello, <strong>$fullname</strong>, You are logging in as a <strong>$userType</strong>.</p>
                        <p style='margin-bottom: 15px;'>Your OTP code is:</p>
                        <div style='font-size: 24px; font-weight: bold; color: #141414;'><strong>$otp</strong></div>
                        <p>This code will expire in 10 minutes.</p>
                    </div>
                    
                    <p style='font-size: 12px; color: #777;'>
                        If you didn't request this OTP, please ignore this email.
                    </p>
                </div>
                
                <div style='font-size: 12px; color: #666;'>
                    © " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
<?php
$host = 'localhost';
$dbname = 'smartgrade';
$username = 'root';
$password = '';

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
    
    $stmt = $pdo->prepare("SELECT fullname FROM students WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $userType = 'Student';
        $fullname = $stmt->fetchColumn();
    }

    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marygracevalerio177@gmail.com';
        $mail->Password   = 'swjx bwoj taxq tjdv';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification';
        $mail->Body    = "
            <div>
                <div>
                    <h2>Email Verification</h2>                
                    <div>
                        <p style='margin-bottom: 10px;'>Hello, <strong>$fullname</strong>, You are logging in as a <strong>$userType</strong>.</p>
                        <p style='margin-bottom: 15px;'>Your OTP code is:</p>
                        <div style='font-size: 24px; font-weight: bold; color: #141414;'><strong>$otp</strong></div>
                        <p>This code will expire in 10 minutes.</p>
                    </div>
                    
                    <p style='font-size: 12px; color: #777;'>
                        If you didn't request this OTP, please ignore this email.
                    </p>
                </div>
                
                <div style='font-size: 12px; color: #666;'>
                    © " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP() {
    return sprintf("%06d", mt_rand(1, 999999));
}