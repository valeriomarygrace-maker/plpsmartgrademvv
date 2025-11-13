<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Get student data from Supabase - FIXED: Use getStudentByEmail instead of getStudentById
$student = getStudentByEmail($userEmail);
if (!$student) {
    $_SESSION['error_message'] = "Student account not found";
    header('Location: login.php');
    exit;
}

// Initialize messages
$success_message = '';
$error_message = '';

// Get student info
try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// After line 10 (after session_start())
$unread_count = 0;
try {
    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
} catch (Exception $e) {
    $unread_count = 0;
}

// Handle semester update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_semester'])) {
    $semester = trim($_POST['semester']);
    
    // Validate input
    if (empty($semester)) {
        $error_message = 'Semester is required.';
    } else {
        try {
            $update_result = supabaseUpdate('students', ['semester' => $semester], ['id' => $student['id']]);
            
            if ($update_result !== false) {
                $success_message = 'Semester updated successfully!';
                // Refresh student data
                $student = getStudentByEmail($_SESSION['user_email']);
            } else {
                $error_message = 'Failed to update semester.';
            }
        } catch (Exception $e) {
            $error_message = 'Database update error: ' . $e->getMessage();
        }
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'File upload error: ' . $_FILES['profile_picture']['error'];
    } else {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['profile_picture']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $error_message = 'Only JPG, PNG, and GIF files are allowed.';
        } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
            $error_message = 'File size must be less than 2MB.';
        } else {
            $upload_dir = 'uploads/profile_pictures/';
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    $error_message = 'Failed to create upload directory.';
                }
            }
            
            if (empty($error_message)) {
                // Delete old picture if exists
                if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])) {
                    unlink($student['profile_picture']);
                }
                
                $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $filename = 'student_' . $student['id'] . '_' . time() . '.' . $file_ext;
                $destination = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                    try {
                        $update_result = supabaseUpdate('students', ['profile_picture' => $destination], ['id' => $student['id']]);
                        
                        if ($update_result !== false) {
                            $success_message = 'Profile picture updated successfully!';
                            // Refresh student data
                            $student = getStudentByEmail($_SESSION['user_email']);
                        } else {
                            $error_message = 'Failed to update profile picture in database.';
                        }
                    } catch (Exception $e) {
                        $error_message = 'Database update error: ' . $e->getMessage();
                    }
                } else {
                    $error_message = 'Failed to upload file. Check directory permissions.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --plp-gold: #FFD700;
            --plp-dark-green: #004d33;
            --plp-light-green: #f8fcf9;
            --plp-pale-green: #e8f5e9;
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --box-shadow: 0 4px 12px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 8px 24px rgba(0, 99, 65, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --danger: #dc3545;
            --warning: #ffc107;
            --success: #28a745;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--plp-green-pale);
            display: flex;
            min-height: 100vh;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .sidebar {
            width: 320px;
            background: white;
            box-shadow: var(--box-shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            border-right: 1px solid rgba(0, 99, 65, 0.1);
        }

        .sidebar-header {
            text-align: center;
            border-bottom: 1px solid rgba(0, 99, 65, 0.1);
        }

        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo {
            width: 130px;
            height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 5px;
        }

        .portal-title {
            color: var(--plp-green);
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .student-email {
            color: var(--text-medium);
            font-size: 0.85rem;
            margin-bottom: 1rem;
            word-break: break-all;
            padding: 0.5rem;
            border-radius: 6px;
            font-weight: 500;
        }

        .nav-menu {
            list-style: none;
            flex-grow: 0.30;
            margin-top: 0.7rem;
        }

        .nav-item {
            margin-bottom: 0.7rem;
            position: relative;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.50rem;
            color: var(--text-medium);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: var(--transition);
            font-weight: 500;
        }

        .nav-link:hover:not(.active) {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            transform: translateY(-3px);
        }

        .nav-link.active {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: var(--box-shadow);
        }

        .sidebar-footer {
            border-top: 3px solid rgba(0, 99, 65, 0.1);
        }

        .logout-btn {
            margin-top:1rem;
            background: transparent;
            color: var(--text-medium);
            padding: 0.75rem 1rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            font-weight: 500;
            transition: var(--transition);
        }

        .logout-btn:hover {
            background: #fee2e2;
            color: #b91c1c;
            transform: translateX(5px);
        }

        .main-content {
            flex: 1;
            padding: 1rem 2.5rem; 
            background: var(--plp-green-pale);
            max-width: 100%;
            margin: 0 auto;
            width: 100%;
        }

        .header {
            background: white;
            padding: 0.6rem 1.25rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem; 
            background: var(--plp-green-gradient);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }


        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }

        .modal-body {
            margin-bottom: 2rem;
            color: var(--text-medium);
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
        }

        .modal-btn-cancel {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .modal-btn-cancel:hover {
            background: var(--plp-green-light);
            color: white;
            transform: translateY(-2px);
        }

        .modal-btn-confirm {
            background: var(--plp-green-gradient);
            color: white;
        }

        .modal-btn-confirm:hover {
            background: linear-gradient(135deg, var(--plp-green-light), var(--plp-green));
            transform: translateY(-2px);
        }

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .profile-header {
            display: flex;
            align-items: center;
            gap: 3rem;
            position: relative;
            z-index: 1;
            flex: 1;
        }

        .profile-avatar {
            position: relative;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 120px; 
            height: 120px; 
            border-radius: 50%; 
            background: white; 
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--box-shadow);
            border: 4px solid white;
        }
        
        .profile-avatar-edit {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: var(--plp-green);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            border: 2px solid white;
            transition: var(--transition);
        }

        .profile-avatar-edit:hover {
            transform: scale(1.05);
            background: var(--plp-dark-green);
        }
        
        .avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .avatar-initial {
            font-size: 3rem;
            font-weight: bold;
            color: var(--plp-green);
        }
        
        #profilePictureModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        #profilePictureModal.show {
            display: flex;
        }
        
        .picture-modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: var(--box-shadow-lg);
        }
        
        .picture-modal-title {
            color: var(--plp-green);
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .picture-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            background: var(--plp-green-pale);
            border: 3px solid var(--plp-green-lighter);
        }
        
        .picture-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .picture-preview-initial {
            font-size: 3rem;
            color: var(--plp-green);
            font-weight: bold;
        }
        
        .file-input-wrapper {
            margin-bottom: 1.5rem;
        }
        
        .file-input-label {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--plp-green);
            color: white;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .file-input-label:hover {
            background: var(--plp-dark-green);
            transform: translateY(-2px);
        }
        
        #profilePictureInput {
            display: none;
        }
        
        .picture-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .picture-modal-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            border: none;
            margin-top: 1rem;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .picture-modal-btn-cancel {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }
        
        .picture-modal-btn-cancel:hover {
            background: var(--plp-green-light);
            color: white;
            transform: translateY(-2px);
        }
        
        .picture-modal-btn-submit {
            background: var(--plp-green);
            color: white;
        }
        
        .picture-modal-btn-submit:hover {
            background: var(--plp-dark-green);
            transform: translateY(-2px);
        }
        
        .alert-error {
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .profile-basic {
            flex: 1;
            color: white;
        }

        .profile-name {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: 0.5px;
        }

        .profile-id {
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-email {
            font-size: 0.95rem;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
        }

        .profile-card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--plp-green-gradient);
        }

        .section-title {
            color: var(--plp-green);
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .section-title i {
            font-size: 1.2rem;
            width: 32px;
            height: 32px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .info-item {
            display: grid;
            gap: 1.5rem;
        }

        .info-group {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            color: var(--text-medium);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
        }

        .info-label i {
            color: var(--plp-green);
            font-size: 0.8rem;
            width: 20px;
            text-align: center;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--text-dark);
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        /* Edit Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-medium);
        }

        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            background: white;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 1200px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .main-content {
                max-width: 100%;
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .profile-basic {
                text-align: center;
            }
            
            .profile-id, .profile-email {
                justify-content: center;
            }
            
            .info-item {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                justify-content: center;
            }
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            padding: 2.5rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            max-width: 600px;
            width: 90%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-btn {
            font-size: 1rem;
            font-weight: 600;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-btn-cancel {
            background: #f1f5f9;
            color: var(--text-medium);
        }

        .modal-btn-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .modal-btn-confirm {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .modal-btn-confirm:hover {
            transform: translateY(-2px);
        }
        .unread-badge {
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
        }

        .sidebar-badge {
            background: #ff4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: auto;
            font-weight: 600;
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo">
                    <img src="plplogo.png" alt="PLP Logo">
                </div>
            </div>
            <div class="portal-title">PLPSMARTGRADE</div>
            <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="student-dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="student-profile.php" class="nav-link active">
                    <i class="fas fa-user"></i>
                    Profile
                </a>
            </li>
            <li class="nav-item">
                <a href="student-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-messages.php" class="nav-link">
                    <i class="fas fa-comments"></i>
                    Messages
                    <?php 
                    $unread_count = getUnreadMessageCount($_SESSION['user_id'], 'student');
                    if ($unread_count > 0): ?>
                        <span class="sidebar-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="student-archived-subject.php" class="nav-link">
                    <i class="fas fa-archive"></i>
                    Archived Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-semester-grades.php" class="nav-link">
                    <i class="fas fa-history"></i>
                    History Records
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <?php if ($success_message): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <div class="header">
            <div class="profile-header">
                <div class="profile-avatar" id="profileAvatar">
                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="avatar-image">
                    <?php else: ?>
                        <div class="avatar-initial"><?php echo strtoupper(substr($student['fullname'] ?? 'U', 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="profile-avatar-edit">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>

                <div class="profile-basic">
                    <div class="profile-name"><?php echo htmlspecialchars($student['fullname'] ?? 'Unknown Student'); ?></div>
                    <div class="profile-id">
                        <i class="fas fa-id-card"></i>
                        Student ID: <?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?>
                    </div>
                    <div class="profile-email">
                        <i class="fas fa-envelope"></i>
                        <?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?>
                    </div>
                </div>
            </div>

            <div class="header-actions">
                <button type="button" class="btn btn-secondary" id="editSemesterBtn">
                    <i class="fas fa-edit"></i> Edit Semester
                </button>
            </div>
        </div>

        <div class="profile-container">
            <div class="profile-card">
                <div class="section-title">
                    <i class="fas fa-user-circle"></i>
                    Personal Information
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-user"></i>
                                Full Name
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['fullname'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-id-badge"></i>
                                Student ID
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-envelope"></i>
                                Email
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="profile-card">
                <div class="section-title">
                    <i class="fas fa-graduation-cap"></i>
                    Academic Information
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-book-open"></i>
                                Program
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['course'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-calendar-alt"></i>
                                Year
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-label">
                                <i class="fas fa-calendar"></i>
                                Semester
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($student['semester'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Picture Modal -->
    <div class="modal" id="profilePictureModal">
        <div class="picture-modal-content">
            <h3 class="picture-modal-title">Update Profile Picture</h3>
            <form action="student-profile.php" method="POST" enctype="multipart/form-data" id="pictureForm">
                <div class="picture-preview" id="picturePreview">
                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Current Picture" id="currentPicture">
                    <?php else: ?>
                        <div class="picture-preview-initial"><?php echo strtoupper(substr($student['fullname'] ?? 'U', 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="file-input-wrapper">
                    <label for="profilePictureInput" class="file-input-label">
                        <i class="fas fa-upload"></i> Choose Photo
                    </label>
                    <input type="file" id="profilePictureInput" name="profile_picture" accept="image/*" required>
                </div>
                
                <div class="picture-modal-actions">
                    <button type="button" class="picture-modal-btn picture-modal-btn-cancel" id="cancelPictureUpdate">Cancel</button>
                    <button type="submit" class="picture-modal-btn picture-modal-btn-submit" id="submitPictureUpdate">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Semester Edit Modal -->
    <div class="modal" id="semesterModal">
        <div class="modal-content">
            <h3 class="modal-title">Update Semester</h3>
            <form action="student-profile.php" method="POST" id="semesterForm">
                <div class="form-group">
                    <select name="semester" id="semester" class="form-select" required>
                        <option value="">Select Semester</option>
                        <option value="1st Semester" <?php echo ($student['semester'] ?? '') === '1st Semester' ? 'selected' : ''; ?>>1st Semester</option>
                        <option value="2nd Semester" <?php echo ($student['semester'] ?? '') === '2nd Semester' ? 'selected' : ''; ?>>2nd Semester</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" id="cancelSemesterUpdate">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-confirm" name="update_semester">Update Semester</button>
                </div>
            </form>
        </div>
    </div>

        <!--  Logout Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <h3 style="color: var(--plp-green); font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem;">
                Confirm Logout
            </h3>
            <div style="color: var(--text-medium); margin-bottom: 2rem; line-height: 1.6;">
                Are you sure you want to logout? You'll need<br>
                to log in again to access your account.
            </div>
            <div style="display: flex; justify-content: center; gap: 1rem;">
                <button class="modal-btn modal-btn-cancel" id="cancelLogout" style="min-width: 120px;">
                    Cancel
                </button>
                <button class="modal-btn modal-btn-confirm" id="confirmLogout" style="min-width: 120px;">
                    Yes, Logout
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Profile Picture Modal
            const profileAvatar = document.getElementById('profileAvatar');
            const profilePictureModal = document.getElementById('profilePictureModal');
            const cancelPictureUpdate = document.getElementById('cancelPictureUpdate');
            const profilePictureInput = document.getElementById('profilePictureInput');
            const picturePreview = document.getElementById('picturePreview');
            const currentPicture = document.getElementById('currentPicture');
            
            // Semester Modal
            const editSemesterBtn = document.getElementById('editSemesterBtn');
            const semesterModal = document.getElementById('semesterModal');
            const cancelSemesterUpdate = document.getElementById('cancelSemesterUpdate');
            
            // Profile Picture Modal Functions
            profileAvatar.addEventListener('click', function() {
                profilePictureModal.classList.add('show');
            });
            
            cancelPictureUpdate.addEventListener('click', function() {
                profilePictureModal.classList.remove('show');
                // Reset file input
                profilePictureInput.value = '';
                // Reset preview
                resetPicturePreview();
            });
            
            profilePictureInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Clear previous content
                        picturePreview.innerHTML = '';
                        
                        // Create new image
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.alt = 'Preview';
                        
                        // Add image to preview
                        picturePreview.appendChild(img);
                    };
                    reader.readAsDataURL(file);
                }
            });
            
            function resetPicturePreview() {
                picturePreview.innerHTML = '';
                if (currentPicture) {
                    const img = document.createElement('img');
                    img.src = currentPicture.src;
                    img.alt = 'Current Picture';
                    img.id = 'currentPicture';
                    picturePreview.appendChild(img);
                } else {
                    const initial = document.createElement('div');
                    initial.className = 'picture-preview-initial';
                    initial.textContent = '<?php echo strtoupper(substr($student['fullname'] ?? 'U', 0, 1)); ?>';
                    picturePreview.appendChild(initial);
                }
            }
            
            // Semester Modal Functions
            editSemesterBtn.addEventListener('click', function() {
                semesterModal.classList.add('show');
            });
            
            cancelSemesterUpdate.addEventListener('click', function() {
                semesterModal.classList.remove('show');
            });
            
            // Close modals when clicking outside
            document.addEventListener('click', function(e) {
                if (e.target === profilePictureModal) {
                    profilePictureModal.classList.remove('show');
                    resetPicturePreview();
                }
                if (e.target === semesterModal) {
                    semesterModal.classList.remove('show');
                }
            });
            
            // Auto-hide success/error messages after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert-success, .alert-error');
                alerts.forEach(alert => {
                    alert.style.display = 'none';
                });
            }, 5000);
        });

        // Logout modal functionality
        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

// Show modal when clicking logout button
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutModal.classList.add('show');
        });

        // Hide modal when clicking cancel
        cancelLogout.addEventListener('click', () => {
            logoutModal.classList.remove('show');
        });

        // Handle logout confirmation
        confirmLogout.addEventListener('click', () => {
            window.location.href = 'logout.php';
        });

// Hide modal when clicking outside the modal content
        const modals = [addSubjectModal, editSubjectModal, archiveSubjectModal, logoutModal];
        modals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        });
    </script>
</body>
</html>