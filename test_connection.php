<?php
// create_admin.php - Run this once then delete
require_once 'config.php';

$admin_data = [
    'username' => 'admin',
    'email' => 'admin@plpasig.edu.ph', // Change to desired admin email
    'password' => hashPassword('admin123'), // Change to desired password
    'fullname' => 'System Administrator',
    'role' => 'admin',
    'is_active' => true
];

$result = supabaseInsert('admins', $admin_data);

if ($result !== false) {
    echo "Admin account created successfully!<br>";
    echo "Email: admin@plpasig.edu.ph<br>";
    echo "Password: admin123<br>";
    echo "<a href='login.php'>Go to Login</a>";
} else {
    echo "Failed to create admin account!<br>";
    echo "Check your Supabase connection and make sure the admins table exists.";
}
?>