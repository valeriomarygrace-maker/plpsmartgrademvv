<?php
// Simple test file to check admin login
session_start();

require_once 'config.php';

if ($_POST['email'] === 'admin@plpasig.edu.ph' && $_POST['password'] === 'plpadmin123') {
    $_SESSION['logged_in'] = true;
    $_SESSION['user_type'] = 'admin';
    $_SESSION['user_email'] = 'admin@plpasig.edu.ph';
    
    echo "LOGIN SUCCESS! Redirecting...";
    echo "<script>setTimeout(function() { window.location.href = 'admin-dashboard.php'; }, 1000);</script>";
    exit;
} else {
    echo "Invalid credentials";
}
?>