<?php
$host = getenv('DB_HOST') ?: 'db';
$dbname = getenv('DB_NAME') ?: 'smartgrade';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: 'rootpassword';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
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

    $stmt = $pdo->prepare("SELECT fullname FROM students WHERE email = ?");
    $stmt->execute([$email]);
    $fullname = $stmt->fetchColumn() ?: 'User';

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
                <h2>Email Verification</h2>
                <p>Hello, <strong>$fullname</strong>!</p>
                <p>Your OTP code is:</p>
                <div style='font-size: 24px; font-weight: bold;'>$otp</div>
                <p>This code will expire in 10 minutes.</p>
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
?>
