<?php
require_once 'PHPMailer/src/Exception.php';
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'marygracevalerio177@gmail.com';
    $mail->Password   = 'swjx bwoj taxq tjdv';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPDebug  = 2;
    $mail->Debugoutput = function($str, $level) {
        echo "Debug: $str<br>";
    };

    $mail->setFrom('marygracevalerio177@gmail.com', 'PLP SmartGrade');
    $mail->addAddress('test@plpasig.edu.ph'); // Use a real email for testing

    $mail->isHTML(true);
    $mail->Subject = 'Test SMTP Connection';
    $mail->Body    = 'This is a test email from PLP SmartGrade.';

    if ($mail->send()) {
        echo "<p style='color: green;'>✅ Test email sent successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Failed to send test email</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ SMTP Error: " . $mail->ErrorInfo . "</p>";
}
?>