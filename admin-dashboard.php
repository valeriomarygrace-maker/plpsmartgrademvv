<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$admin = null;
$students = [];
$error_message = '';
$success_message = '';
$search_query = '';
$filter_semester = '';

try {
    // Get admin info
    $admin = getAdminByEmail($_SESSION['user_email']);
    
    if (!$admin) {
        $error_message = 'Admin record not found.';
    } else {
        // Handle search and filters
        $search_query = $_GET['search'] ?? '';
        $filter_semester = $_GET['semester'] ?? '';
        
        // Build query filters
        $filters = [];
        if (!empty($search_query)) {
            // Search in multiple fields
            $students = searchStudents($search_query);
        } else {
            // Get all students with optional semester filter
            $students = getAllStudents($filter_semester);
        }
        
        // Handle student actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['delete_student'])) {
                $student_id = $_POST['student_id'];
                $result = deleteStudent($student_id);
                
                if ($result) {
                    $success_message = 'Student account deleted successfully!';
                    // Refresh student list
                    $students = getAllStudents($filter_semester);
                } else {
                    $error_message = 'Failed to delete student account.';
                }
            }
            
            if (isset($_POST['reset_password'])) {
                $student_id = $_POST['student_id'];
                $result = resetStudentPassword($student_id);
                
                if ($result) {
                    $success_message = 'Student password reset successfully!';
                } else {
                    $error_message = 'Failed to reset student password.';
                }
            }
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in admin-students.php: " . $e->getMessage());
}

/**
 * Get all students with optional semester filter
 */
function getAllStudents($semester = '') {
    $all_students = supabaseFetchAll('students');
    
    if (!$all_students) {
        return [];
    }
    
    // Filter by semester if specified
    if (!empty($semester)) {
        $all_students = array_filter($all_students, function($student) use ($semester) {
            return strpos(strtolower($student['semester']), strtolower($semester)) !== false;
        });
    }
    
    // Sort by creation date (newest first)
    usort($all_students, function($a, $b) {
        $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $dateB - $dateA;
    });
    
    return $all_students;
}

/**
 * Search students by name, email, or student number
 */
function searchStudents($query) {
    $all_students = supabaseFetchAll('students');
    
    if (!$all_students) {
        return [];
    }
    
    $filtered_students = array_filter($all_students, function($student) use ($query) {
        $search_fields = [
            strtolower($student['fullname']),
            strtolower($student['email']),
            strtolower($student['student_number']),
            strtolower($student['course']),
            strtolower($student['section'])
        ];
        
        foreach ($search_fields as $field) {
            if (strpos($field, strtolower($query)) !== false) {
                return true;
            }
        }
        return false;
    });
    
    return array_values($filtered_students);
}

/**
 * Delete student account and related data
 */
function deleteStudent($student_id) {
    try {
        // First, get all student subjects
        $student_subjects = supabaseFetch('student_subjects', ['student_id' => $student_id]);
        
        if ($student_subjects && is_array($student_subjects)) {
            foreach ($student_subjects as $subject) {
                // Delete subject scores
                supabaseDelete('student_subject_scores', ['student_subject_id' => $subject['id']]);
                
                // Delete class standing categories
                supabaseDelete('student_class_standing_categories', ['student_subject_id' => $subject['id']]);
                
                // Delete subject performance
                supabaseDelete('subject_performance', ['student_subject_id' => $subject['id']]);
            }
            
            // Delete student subjects
            supabaseDelete('student_subjects', ['student_id' => $student_id]);
        }
        
        // Delete behavioral data
        supabaseDelete('student_behavioral_metrics', ['student_id' => $student_id]);
        supabaseDelete('student_behavior_logs', ['student_id' => $student_id]);
        supabaseDelete('student_predictions', ['student_id' => $student_id]);
        supabaseDelete('student_interventions', ['student_id' => $student_id']);
        
        // Finally delete the student
        $result = supabaseDelete('students', ['id' => $student_id]);
        
        return $result !== false;
        
    } catch (Exception $e) {
        error_log("Error deleting student: " . $e->getMessage());
        return false;
    }
}

/**
 * Reset student password to default
 */
function resetStudentPassword($student_id) {
    try {
        $default_password = hashPassword('student123'); // Default password
        $result = supabaseUpdate('students', ['password' => $default_password], ['id' => $student_id]);
        
        return $result !== false;
        
    } catch (Exception $e) {
        error_log("Error resetting password: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - PLP SmartGrade</title>
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

        .admin-email {
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
            overflow-y: auto;
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

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
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
            text-decoration: none;
        }

        .btn-primary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .btn-primary:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* Search and Filter Section */
        .search-section {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        .search-group {
            flex: 1;
            min-width: 300px;
        }

        .filter-group {
            min-width: 200px;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .search-btn {
            background: var(--plp-green-gradient);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        /* Students Table */
        .students-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .table-header {
            background: var(--plp-green-pale);
            padding: 1.5rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .table-title {
            color: var(--plp-green);
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            background: var(--plp-green);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .students-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
            vertical-align: middle;
        }

        .students-table tr:hover {
            background: var(--plp-green-pale);
        }

        .students-table tr:last-child td {
            border-bottom: none;
        }

        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--plp-green);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .student-details {
            display: flex;
            flex-direction: column;
        }

        .student-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }

        .student-email {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .student-meta {
            font-size: 0.8rem;
            color: var(--text-medium);
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #c6f6d5;
            color: #2f855a;
        }

        .status-inactive {
            background: #fed7d7;
            color: #c53030;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 0.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            text-decoration: none;
        }

        .btn-view {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .btn-view:hover {
            background: var(--plp-green);
            color: white;
        }

        .btn-reset {
            background: #fff5f5;
            color: #e53e3e;
        }

        .btn-reset:hover {
            background: #e53e3e;
            color: white;
        }

        .btn-delete {
            background: #fed7d7;
            color: #c53030;
        }

        .btn-delete:hover {
            background: #c53030;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-medium);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--plp-green-lighter);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        /* Alerts */
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

        /* Modal Styles */
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
            max-width: 500px;
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
            text-align: center;
        }

        .modal-body {
            margin-bottom: 2rem;
            color: var(--text-medium);
            text-align: center;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .modal-btn {
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
            min-width: 120px;
            justify-content: center;
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

        .modal-btn-danger {
            background: #dc3545;
            color: white;
        }

        .modal-btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
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

        /* Responsive Design */
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
                padding: 1.5rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .search-section {
                flex-direction: column;
            }
            
            .search-group, .filter-group {
                min-width: 100%;
            }
            
            .students-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-btn {
                min-width: 100%;
            }
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
            <div class="admin-email"><?php echo htmlspecialchars($admin['email']); ?></div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="admin-dashboard.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-students.php" class="nav-link active">
                    <i class="fas fa-users"></i>
                    Manage Students
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-subjects.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Manage Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-reports.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="admin-settings.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    System Settings
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
            <div class="welcome">Manage Students</div>
            <div class="header-actions">
                <a href="admin-dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="search-section">
            <div class="search-group">
                <form method="GET" action="admin-students.php" class="search-form">
                    <label class="form-label">Search Students</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" 
                               name="search" 
                               class="form-input" 
                               placeholder="Search by name, email, student number..." 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="search-btn">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="filter-group">
                <form method="GET" action="admin-students.php" class="filter-form">
                    <label class="form-label">Filter by Semester</label>
                    <div style="display: flex; gap: 0.5rem;">
                        <select name="semester" class="form-select" onchange="this.form.submit()">
                            <option value="">All Semesters</option>
                            <option value="1st" <?php echo $filter_semester === '1st' ? 'selected' : ''; ?>>1st Semester</option>
                            <option value="2nd" <?php echo $filter_semester === '2nd' ? 'selected' : ''; ?>>2nd Semester</option>
                        </select>
                        <?php if (!empty($search_query) || !empty($filter_semester)): ?>
                            <a href="admin-students.php" class="search-btn" style="background: var(--danger);">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Students Table -->
        <div class="students-table-container">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-user-graduate"></i>
                    Student Accounts (<?php echo count($students); ?>)
                </div>
            </div>
            
            <?php if (!empty($students)): ?>
                <table class="students-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student Number</th>
                            <th>Course & Section</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar">
                                            <?php echo strtoupper(substr($student['fullname'], 0, 1)); ?>
                                        </div>
                                        <div class="student-details">
                                            <div class="student-name"><?php echo htmlspecialchars($student['fullname']); ?></div>
                                            <div class="student-email"><?php echo htmlspecialchars($student['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="student-meta"><?php echo htmlspecialchars($student['student_number']); ?></div>
                                </td>
                                <td>
                                    <div class="student-meta">
                                        <?php echo htmlspecialchars($student['course']); ?><br>
                                        Section <?php echo htmlspecialchars($student['section']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="student-meta"><?php echo htmlspecialchars($student['semester']); ?></div>
                                </td>
                                <td>
                                    <span class="status-badge status-active">Active</span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="student-profile.php?student_id=<?php echo $student['id']; ?>" 
                                           class="action-btn btn-view" 
                                           title="View Profile">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmReset()">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" name="reset_password" class="action-btn btn-reset" title="Reset Password">
                                                <i class="fas fa-key"></i> Reset
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmDelete()">
                                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                            <button type="submit" name="delete_student" class="action-btn btn-delete" title="Delete Student">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <p>No students found</p>
                    <small>
                        <?php if (!empty($search_query)): ?>
                            No students match your search criteria.
                        <?php else: ?>
                            No student accounts have been created yet.
                        <?php endif; ?>
                    </small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal" id="logoutModal">
        <div class="modal-content" style="max-width: 450px; text-align: center;">
            <h3 class="modal-title">Confirm Logout</h3>
            <div class="modal-body">
                Are you sure you want to logout? You'll need<br>
                to log in again to access your account.
            </div>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-cancel" id="cancelLogout">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmLogout">Yes, Logout</button>
            </div>
        </div>
    </div>

    <script>
        // Confirmation dialogs
        function confirmDelete() {
            return confirm('Are you sure you want to delete this student account? This action cannot be undone.');
        }

        function confirmReset() {
            return confirm('Are you sure you want to reset this student\'s password? The new password will be "student123".');
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Logout modal functionality
        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutModal.classList.add('show');
        });

        cancelLogout.addEventListener('click', () => {
            logoutModal.classList.remove('show');
        });

        confirmLogout.addEventListener('click', () => {
            window.location.href = 'logout.php';
        });

        logoutModal.addEventListener('click', (e) => {
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });
    </script>
</body>
</html>