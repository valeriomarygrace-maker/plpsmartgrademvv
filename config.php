<?php
// 🔹 Database connection settings
$host = getenv('DB_HOST') ?: 'host.docker.internal';  // works with Docker Desktop + XAMPP
$dbname = getenv('DB_NAME') ?: 'smartgrade';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    // 🔹 Use Unix socket fallback for Docker-based connections
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 🔹 Load PHPMailer (make sure paths are correct)
require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// 🔹 Function to send OTP via Gmail SMTP
function sendOTP($email, $otp)
{
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

        // ⚠️ Move credentials to environment variables later
        $mail->Username   = getenv('MAIL_USER') ?: 'marygracevalerio177@gmail.com';
        $mail->Password   = getenv('MAIL_PASS') ?: 'swjx bwoj taxq tjdv';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'PLP SmartGrade - OTP Verification';
        $mail->Body    = "
            <div>
                <h2>Email Verification</h2>                
                <p>Hello, <strong>{$fullname}</strong>! You are logging in as a <strong>{$userType}</strong>.</p>
                <p>Your OTP code is:</p>
                <div style='font-size: 24px; font-weight: bold; color: #141414;'><strong>{$otp}</strong></div>
                <p>This code will expire in 10 minutes.</p>
                <p style='font-size: 12px; color: #777;'>If you didn't request this OTP, please ignore this email.</p>
                <div style='font-size: 12px; color: #666;'>© " . date('Y') . " Pamantasan ng Lungsod ng Pasig. All rights reserved.</div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateOTP()
{
    return sprintf("%06d", mt_rand(1, 999999));
}
?>
