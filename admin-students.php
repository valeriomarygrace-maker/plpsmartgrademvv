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
        
        // Handle student update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
            $student_id = $_POST['student_id'];
            $fullname = sanitizeInput($_POST['fullname']);
            $email = sanitizeInput($_POST['email']);
            $student_number = sanitizeInput($_POST['student_number']);
            $semester = sanitizeInput($_POST['semester']);
            $section = sanitizeInput($_POST['section']);
            
            // Validate required fields
            if (empty($fullname) || empty($email) || empty($student_number) || empty($semester) || empty($section)) {
                $error_message = 'All fields are required.';
            } else {
                // Update student data (excluding course and year_level)
                $update_data = [
                    'fullname' => $fullname,
                    'email' => $email,
                    'student_number' => $student_number,
                    'semester' => $semester,
                    'section' => $section
                ];
                
                $result = supabaseUpdate('students', $update_data, ['id' => $student_id]);
                
                if ($result !== false) {
                    $success_message = 'Student information updated successfully!';
                    // Refresh student list
                    $students = getAllStudents($filter_semester);
                } else {
                    $error_message = 'Failed to update student information.';
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
        /* ... (keep all the existing CSS styles) ... */
        
        .readonly-field {
            background-color: var(--plp-green-pale);
            border: 2px solid var(--plp-green-lighter);
            color: var(--text-medium);
            cursor: not-allowed;
        }
        
        .readonly-field:focus {
            outline: none;
            border-color: var(--plp-green-lighter);
            box-shadow: none;
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
                <a class="btn btn-primary">
                     <i class="fas fa-user-graduate"></i>
                    Student Accounts (<?php echo count($students); ?>)
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
                            <a href="admin-students.php" class="search-btn" style="background: #dc3545;">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Students Table -->
        <div class="students-table-container">
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
                                        <button type="button" 
                                                class="action-btn btn-update" 
                                                title="Update Student"
                                                onclick="openUpdateModal(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
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

    <!-- Update Student Modal -->
    <div class="modal" id="updateStudentModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-user-edit"></i>
                Update Student Information
            </h3>
            <form method="POST" action="admin-students.php" id="updateStudentForm">
                <input type="hidden" name="student_id" id="update_student_id">
                <input type="hidden" name="update_student" value="1">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="fullname" id="update_fullname" class="form-input" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="update_email" class="form-input" required>
                        </div>
                        <div class="form-col">
                            <label class="form-label">Student Number</label>
                            <input type="text" name="student_number" id="update_student_number" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Course</label>
                        <input type="text" id="update_course_display" class="form-input readonly-field" readonly>
                        <input type="hidden" name="course" id="update_course">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <label class="form-label">Year Level</label>
                            <input type="text" id="update_year_level_display" class="form-input readonly-field" readonly>
                            <input type="hidden" name="year_level" id="update_year_level">
                        </div>
                        <div class="form-col">
                            <label class="form-label">Semester</label>
                            <select name="semester" id="update_semester" class="form-select" required>
                                <option value="1st Semester">1st Semester</option>
                                <option value="2nd Semester">2nd Semester</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Section</label>
                        <input type="text" name="section" id="update_section" class="form-input" required>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="closeUpdateModal()">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">
                        <i class="fas fa-save"></i> Update Student
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logout Modal -->
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
        // Update Modal Functions
        function openUpdateModal(student) {
            document.getElementById('update_student_id').value = student.id;
            document.getElementById('update_fullname').value = student.fullname;
            document.getElementById('update_email').value = student.email;
            document.getElementById('update_student_number').value = student.student_number;
            
            // Set course (readonly)
            document.getElementById('update_course_display').value = student.course;
            document.getElementById('update_course').value = student.course;
            
            // Set year level (readonly)
            const yearLevelText = getYearLevelText(student.year_level);
            document.getElementById('update_year_level_display').value = yearLevelText;
            document.getElementById('update_year_level').value = student.year_level;
            
            document.getElementById('update_semester').value = student.semester;
            document.getElementById('update_section').value = student.section;
            
            document.getElementById('updateStudentModal').classList.add('show');
        }

        function getYearLevelText(yearLevel) {
            switch(yearLevel.toString()) {
                case '1': return '1st Year';
                case '2': return '2nd Year';
                case '3': return '3rd Year';
                case '4': return '4th Year';
                default: return yearLevel.toString() + ' Year';
            }
        }

        function closeUpdateModal() {
            document.getElementById('updateStudentModal').classList.remove('show');
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

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target === document.getElementById('updateStudentModal')) {
                closeUpdateModal();
            }
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeUpdateModal();
                logoutModal.classList.remove('show');
            }
        });
    </script>
</body>
</html>