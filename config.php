<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send OTP - FIXED VERSION THAT ACTUALLY SENDS EMAILS
 */
function sendOTP($email, $otp) {
    error_log("🔐 Attempting to send OTP to: $email");
    
    try {
        $student = getStudentByEmail($email);
        if (!$student) {
            error_log("❌ Student not found for email: $email");
            return false;
        }

        $fullname = $student['fullname'];
        error_log("✅ Student found: $fullname");

        // Store OTP in Supabase
        $otpData = [
            'email' => $email,
            'otp_code' => $otp,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
            'is_used' => false
        ];
        
        error_log("💾 Storing OTP: $otp");
        
        $result = supabaseInsert('otp_verification', $otpData);
        
        if (!$result) {
            error_log("❌ FAILED to store OTP in Supabase");
            return false;
        }
        
        // ✅ ACTUALLY SEND THE EMAIL
        if (sendOTPEmail($email, $otp, $fullname)) {
            error_log("✅ OTP email sent successfully to: $email");
            return true;
        } else {
            error_log("❌ FAILED to send OTP email to: $email");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ OTP sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP Email using PHPMailer
 */
function sendOTPEmail($email, $otp, $fullname) {
    $mail = new PHPMailer(true);
    
    try {
        // Enable verbose debugging
        $mail->SMTPDebug = 0; // Set to 2 for debugging, 0 for production
        $mail->Debugoutput = 'error_log';
        
        // Server settings - CHOOSE ONE OPTION BELOW:

        // OPTION 1: Gmail SMTP (Recommended)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'marygracevalerio177@gmail.com'; // Your Gmail
        $mail->Password = 'swjx bwoj taxq tjdv'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // OPTION 2: If Gmail doesn't work, try these alternatives:
        /*
        // SendGrid
        $mail->isSMTP();
        $mail->Host = 'smtp.sendgrid.net';
        $mail->SMTPAuth = true;
        $mail->Username = 'apikey';
        $mail->Password = 'your-sendgrid-api-key';
        $mail->Port = 587;

        // OR Zoho Mail
        $mail->isSMTP();
        $mail->Host = 'smtp.zoho.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'your-email@yourdomain.com';
        $mail->Password = 'your-zoho-password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        */
        
        // Recipients
        $mail->setFrom('noreply@plpasig.edu.ph', 'PLP SmartGrade');
        $mail->addAddress($email, $fullname);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your PLP SmartGrade OTP Code';
        $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #f5f5f5; padding: 20px; }
                    .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
                    .header { color: #006341; text-align: center; margin-bottom: 20px; }
                    .otp-code { font-size: 32px; font-weight: bold; color: #006341; text-align: center; margin: 20px 0; padding: 15px; background: #f0f9f5; border-radius: 8px; }
                    .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>PLP SmartGrade OTP Verification</h2>
                    </div>
                    
                    <p>Hello <strong>$fullname</strong>,</p>
                    
                    <p>Your One-Time Password (OTP) for login is:</p>
                    
                    <div class='otp-code'>$otp</div>
                    
                    <p>This OTP will expire in <strong>10 minutes</strong>.</p>
                    
                    <p>If you didn't request this OTP, please ignore this email.</p>
                    
                    <div class='footer'>
                        <p>PLP - SmartGrade System<br>
                        Pamantasan ng Lungsod ng Pasig</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Plain text version for non-HTML email clients
        $mail->AltBody = "PLP SmartGrade OTP Code: $otp\n\nThis OTP will expire in 10 minutes.\n\nIf you didn't request this OTP, please ignore this email.";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("❌ PHPMailer Error: {$mail->ErrorInfo}");
        
        // Fallback: Store OTP in session for manual retrieval
        $_SESSION['debug_otp'] = $otp;
        $_SESSION['debug_email'] = $email;
        error_log("✅ OTP stored in session for debugging: $otp");
        
        return false;
    }
}
?>