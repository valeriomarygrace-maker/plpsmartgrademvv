<?php
require_once 'config.php';

// Create admin account
$adminData = [
    'username' => 'admin',
    'email' => 'admin@plpasig.edu.ph',
    'password' => hashPassword('plpadmin123'),
    'fullname' => 'System Administrator',
    'role' => 'admin',
    'is_active' => true
];

$result = supabaseInsert('admins', $adminData);

if ($result !== false) {
    echo "Admin account created successfully!<br>";
    echo "Email: admin@plpasig.edu.ph<br>";
    echo "Password: plpadmin123<br>";
    echo "<a href='login.php'>Go to Login</a>";
} else {
    echo "Error creating admin account. It might already exist.";
}
?>