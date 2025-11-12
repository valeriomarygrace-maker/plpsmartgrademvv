<?php
require_once 'config.php';

// This script should be run once to create the first admin user
// Delete this file after use for security

// Check if admin already exists
$existing_admin = getAdminByEmail('admin@plpasig.edu.ph');
if ($existing_admin) {
    echo "Admin user already exists!";
    exit;
}

$admin_data = [
    'username' => 'admin',
    'email' => 'admin@plpasig.edu.ph',
    'password' => hashPassword('admin123'), // Change this password
    'fullname' => 'System Administrator',
    'role' => 'admin',
    'is_active' => true
];

$result = supabaseInsert('admins', $admin_data);

if ($result !== false) {
    echo "Admin user created successfully!";
    echo "<br><strong>Login Credentials:</strong>";
    echo "<br>Username: admin";
    echo "<br>Email: admin@plpasig.edu.ph"; 
    echo "<br>Password: admin123";
    echo "<br><br><strong style='color: red;'>Please change the password immediately and delete this file!</strong>";
} else {
    echo "Failed to create admin user. Check your Supabase connection and table structure.";
}
?>