<?php
require_once 'config.php';
require_once 'ml-helpers.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$student = null;
$semester_grades = [];
$selected_semester = $_GET['semester'] ?? '';
$error_message = '';

try {
    // Get student info using Supabase
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    } else {
        // Get all semesters with archived subjects
        $archived_subjects = supabaseFetch('archived_subjects', ['student_id' => $student['id']]);
        $semesters = [];
        
        foreach ($archived_subjects as $archived_subject) {
            $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
            if ($subject_data && count($subject_data) > 0) {
                $subject = $subject_data[0];
                if (!in_array($subject['semester'], $semesters)) {
                    $semesters[] = $subject['semester'];
                }
            }
        }
        
        // Sort semesters logically
        usort($semesters, function($a, $b) {
            $order = ['First Semester' => 1, 'Second Semester' => 2, 'Summer' => 3];
            return ($order[$a] ?? 4) - ($order[$b] ?? 4);
        });
        
        // If no semester selected, use the first one
        if (empty($selected_semester) && !empty($semesters)) {
            $selected_semester = $semesters[0];
        }
        
        // Get archived subjects for the selected semester with calculated performance
        if ($selected_semester) {
            $semester_grades = [];
            
            foreach ($archived_subjects as $archived_subject) {
                $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
                if ($subject_data && count($subject_data) > 0) {
                    $subject = $subject_data[0];
                    
                    if ($subject['semester'] === $selected_semester) {
                        // Get performance data
                        $performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                        $performance = $performance_data && count($performance_data) > 0 ? $performance_data[0] : null;
                        
                        $has_scores = false;
                        $overall_grade = 0;
                        $gwa = 0;
                        
                        if ($performance) {
                            $has_scores = true;
                            $overall_grade = $performance['overall_grade'] ?? 0;
                            $gwa = $performance['gwa'] ?? calculateGWA($overall_grade);
                        } else {
                            // Calculate performance from scores if no performance data exists
                            $calculated_performance = calculateArchivedSubjectPerformance($archived_subject['id']);
                            if ($calculated_performance && $calculated_performance['has_scores']) {
                                $has_scores = true;
                                $overall_grade = $calculated_performance['overall_grade'];
                                $gwa = $calculated_performance['gwa'];
                            }
                        }
                        
                        $semester_grades[] = [
                            'archived_subject_id' => $archived_subject['id'],
                            'subject_code' => $subject['subject_code'],
                            'subject_name' => $subject['subject_name'],
                            'credits' => $subject['credits'],
                            'semester' => $subject['semester'],
                            'professor_name' => $archived_subject['professor_name'],
                            'archived_at' => $archived_subject['archived_at'],
                            'overall_grade' => $overall_grade,
                            'gwa' => $gwa,
                            'has_scores' => $has_scores
                        ];
                    }
                }
            }
            
            // Sort by subject code
            usort($semester_grades, function($a, $b) {
                return strcmp($a['subject_code'], $b['subject_code']);
            });
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
    error_log("Error in student-semester-grades.php: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Grades - PLP SmartGrade</title>
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

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .export-btn {
            background: white;
            color: var(--plp-green);
            border: 2px solid var(--plp-green);
            padding: 0.6rem 1.25rem;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .export-btn:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: var(--box-shadow);
        }

        .export-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .export-btn:disabled:hover {
            background: white;
            color: var(--plp-green);
            box-shadow: none;
        }

        /* Semester Selector */
        .semester-selector {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .semester-btn {
            padding: 0.75rem 1.5rem;
            background: white;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-medium);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .semester-btn:hover {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            transform: translateY(-2px);
        }

        .semester-btn.active {
            background: var(--plp-green-gradient);
            color: white;
            border-color: var(--plp-green);
            box-shadow: var(--box-shadow);
        }

        /* Grades Table */
        .grades-table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
        }

        .grades-table th {
            background: var(--plp-green-pale);
            color: var(--plp-green);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .grades-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .grades-table tr:last-child td {
            border-bottom: none;
        }

        .grades-table tr:hover {
            background: var(--plp-green-pale);
        }

        .subject-code {
            font-weight: 600;
            color: var(--plp-green);
        }

        .subject-name {
            color: var(--text-dark);
        }

        .credits {
            text-align: center;
            font-weight: 600;
            color: var(--plp-green);
        }

        .grade {
            text-align: center;
            font-weight: 700;
        }

        .grade-excellent {
            color: var(--success);
        }

        .grade-good {
            color: var(--info);
        }

        .grade-average {
            color: var(--warning);
        }

        .grade-poor {
            color: var(--danger);
        }

        .grade-failed {
            color: #7f1d1d;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .grade-no-data {
            color: var(--text-light);
            font-style: italic;
        }

        .gwa {
            text-align: center;
            font-weight: 700;
        }

        .gwa-excellent {
            color: var(--success);
        }

        .gwa-good {
            color: var(--info);
        }

        .gwa-average {
            color: var(--warning);
        }

        .gwa-poor {
            color: var(--danger);
        }

        .gwa-failed {
            color: #7f1d1d;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .gwa-no-data {
            color: var(--text-light);
            font-style: italic;
        }

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
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        /* Alert styles */
        .alert-error {
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #e53e3e;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* Modal */
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

        /* Responsive */
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
            
            .header-actions {
                justify-content: center;
            }
            
            .semester-selector {
                justify-content: center;
            }
            
            .grades-table-container {
                overflow-x: auto;
            }
            
            .grades-table {
                min-width: 800px;
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
                <a href="student-profile.php" class="nav-link">
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
                <a href="student-archived-subject.php" class="nav-link">
                    <i class="fas fa-archive"></i>
                    Archived Subjects
                </a>
            </li>
            <li class="nav-item">
                <a href="student-semester-grades.php" class="nav-link active">
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
        <div class="header">
            <div class="welcome">History Records</div>
            <div class="header-actions">
                <?php if (!empty($semester_grades)): ?>
                    <a href="export-semester-grades.php?semester=<?php echo urlencode($selected_semester); ?>" 
                       class="export-btn" id="exportExcelBtn">
                        <i class="fas fa-file-excel"></i>
                        Export to Excel
                    </a>
                <?php else: ?>
                    <button class="export-btn" disabled>
                        <i class="fas fa-file-excel"></i>
                        Export to Excel
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($semesters)): ?>
            <!-- Semester Selector -->
            <div class="semester-selector">
                <?php foreach ($semesters as $semester): ?>
                    <a href="?semester=<?php echo urlencode($semester); ?>" 
                       class="semester-btn <?php echo $selected_semester === $semester ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo htmlspecialchars($semester); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Grades Table -->
            <div class="grades-table-container">
                <?php if (!empty($semester_grades)): ?>
                    <table class="grades-table" id="gradesTable">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Description</th>
                                <th>Professor</th>
                                <th>Credits</th>
                                <th>GWA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semester_grades as $subject): ?>
                                <tr>
                                    <td>
                                        <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    </td>
                                    <td>
                                        <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($subject['professor_name']); ?></td>
                                    <td class="credits"><?php echo htmlspecialchars($subject['credits']); ?></td>
                                    <td class="gwa 
                                        <?php if ($subject['has_scores']): ?>
                                            <?php 
                                            if ($subject['gwa'] <= 1.25) echo 'gwa-excellent';
                                            elseif ($subject['gwa'] <= 1.75) echo 'gwa-good';
                                            elseif ($subject['gwa'] <= 2.50) echo 'gwa-average';
                                            elseif ($subject['gwa'] <= 3.00) echo 'gwa-poor';
                                            else echo 'gwa-failed';
                                            ?>
                                        <?php else: ?>
                                            gwa-no-data
                                        <?php endif; ?>
                                    ">
                                        <?php echo $subject['has_scores'] ? number_format($subject['gwa'], 2) : '--'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No grades found for this semester</p>
                        <small>Grades will appear here once subjects are archived with performance data</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <p>No semester history available</p>
                <small>Complete and archive subjects to see your semester grades history</small>
                <br>
                <a href="student-subjects.php" class="semester-btn active" style="margin-top: 1rem;">
                    <i class="fas fa-book"></i> Go to Active Subjects
                </a>
            </div>
        <?php endif; ?>
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
        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.1s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 100);
            });
        }, 5000);

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
        logoutModal.addEventListener('click', (e) => {
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });
    </script>
</body>
</html>