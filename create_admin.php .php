<?php
require_once 'config.php';

// Update admin password
$admin_email = 'admin@plpasig.edu.ph';
$new_password = 'plpadmin123';

// Get existing admin
$admin = getAdminByEmail($admin_email);

if ($admin) {
    error_log("Admin found, updating password...");
    
    $update_data = [
        'password' => hashPassword($new_password)
    ];
    
    $result = supabaseUpdate('admins', $update_data, ['id' => $admin['id']]);
    
    if ($result !== false) {
        echo "<h2>Admin Password Updated Successfully!</h2>";
        echo "Email: $admin_email<br>";
        echo "New Password: $new_password<br><br>";
        echo "<a href='login.php'>Go to Login</a>";
        error_log("Admin password updated successfully");
    } else {
        echo "<h2>Failed to update admin password!</h2>";
        error_log("Failed to update admin password");
    }
} else {
    echo "<h2>Admin account not found!</h2>";
    echo "Creating new admin account...<br>";
    
    // Create new admin account
    $admin_data = [
        'username' => 'admin',
        'email' => $admin_email,
        'password' => hashPassword($new_password),
        'fullname' => 'System Administrator',
        'role' => 'admin',
        'is_active' => true
    ];

    $result = supabaseInsert('admins', $admin_data);

    if ($result !== false) {
        echo "<h2>Admin Account Created Successfully!</h2>";
        echo "Email: $admin_email<br>";
        echo "Password: $new_password<br><br>";
        echo "<a href='login.php'>Go to Login</a>";
    } else {
        echo "<h2>Failed to create admin account!</h2>";
    }
}
?>