<?php
require_once 'config.php';

$email = 'admin@plpasig.edu.ph'; // Change to your admin email
$new_password = 'admin123';

$hashed_password = hashPassword($new_password);

// Update admin password
$result = supabaseUpdate('admins', 
    ['password' => $hashed_password], 
    ['email' => $email]
);

if ($result) {
    echo "Admin password reset successfully. New password: $new_password";
} else {
    echo "Failed to reset admin password";
}
?>