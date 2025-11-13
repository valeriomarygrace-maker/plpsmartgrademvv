<?php
require_once 'config.php';

// Check if admin already exists
$existing_admin = getAdminByEmail('admin@plpasig.edu.ph');

if ($existing_admin) {
    echo "<h2>Admin Account Already Exists</h2>";
    echo "Email: " . $existing_admin['email'] . "<br>";
    echo "Fullname: " . $existing_admin['fullname'] . "<br>";
    echo "ID: " . $existing_admin['id'] . "<br><br>";
    echo "<a href='login.php'>Go to Login</a>";
} else {
    // Create new admin account
    $admin_data = [
        'username' => 'admin',
        'email' => 'admin@plpasig.edu.ph',
        'password' => hashPassword('plpadmin123'),
        'fullname' => 'System Administrator',
        'role' => 'admin',
        'is_active' => true
    ];

    $result = supabaseInsert('admins', $admin_data);

    if ($result !== false) {
        echo "<h2>Admin Account Created Successfully!</h2>";
        echo "<strong>Login Credentials:</strong><br>";
        echo "Email: admin@plpasig.edu.ph<br>";
        echo "Password: plpadmin123<br><br>";
        echo "<a href='login.php'>Go to Login</a>";
    } else {
        echo "<h2>Failed to Create Admin Account!</h2>";
        echo "Check your Supabase connection and make sure the admins table exists.";
    }
}
?>