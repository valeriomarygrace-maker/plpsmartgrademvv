<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';
$student = null;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($subject_id <= 0) {
    header('Location: student-subjects.php');
    exit;
}

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    if (!$student) {
        $error_message = 'Student record not found.';
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Get subject information
try {
    $student_subjects = supabaseFetch('student_subjects', ['id' => $subject_id, 'student_id' => $student['id']]);
    if (!$student_subjects || count($student_subjects) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $student_subject = $student_subjects[0];
    $subjects = supabaseFetch('subjects', ['id' => $student_subject['subject_id']]);
    if (!$subjects || count($subjects) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $subject_info = $subjects[0];
    $subject = array_merge($student_subject, [
        'subject_code' => $subject_info['subject_code'],
        'subject_name' => $subject_info['subject_name'],
        'credits' => $subject_info['credits'],
        'semester' => $subject_info['semester']
    ]);
    
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_midterm']) || isset($_POST['add_final'])) {
        $exam_type = isset($_POST['add_midterm']) ? 'midterm_exam' : 'final_exam';
        $score_value = floatval($_POST['score_value']);
        $max_score = floatval($_POST['max_score']);
        
        if ($score_value < 0 || $max_score <= 0) {
            $error_message = 'Score value and maximum score must be positive numbers.';
        } elseif ($score_value > $max_score) {
            $error_message = 'Score value cannot exceed maximum score.';
        } else {
            $exam_name = $exam_type === 'midterm_exam' ? 'Midterm Exam' : 'Final Exam';
            
            try {
                // Delete any existing exam score
                supabaseDelete('student_subject_scores', [
                    'student_subject_id' => $subject_id,
                    'score_type' => $exam_type
                ]);
                
                // Insert new exam score
                $insert_data = [
                    'student_subject_id' => $subject_id,
                    'score_type' => $exam_type,
                    'score_name' => $exam_name,
                    'score_value' => $score_value,
                    'max_score' => $max_score,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $result = supabaseInsert('student_subject_scores', $insert_data);
                
                if ($result) {
                    $success_message = $exam_name . ' score added successfully!';
                    // Redirect back to subject management
                    header("Location: subject-management.php?subject_id=$subject_id&success=" . urlencode($success_message));
                    exit;
                } else {
                    $error_message = 'Failed to add exam score.';
                }
            } catch (Exception $e) {
                $error_message = 'Database error: ' . $e->getMessage();
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
    <title>Term Evaluations - PLP SmartGrade</title>
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
            overflow-y: auto;
        }

        .header {
            background: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1rem;
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
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        .btn-secondary {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
        }

        .btn-secondary:hover {
            background: var(--plp-green-light);
            color: white;
            transform: translateY(-2px);
        }

        .container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            overflow: hidden;
        }

        .page {
            display: none;
            padding: 2.5rem;
        }

        .page.active {
            display: block;
        }

        .page-title {
            color: var(--plp-green);
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .menu-card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
            border-color: var(--plp-green);
        }

        .menu-icon {
            width: 80px;
            height: 80px;
            background: var(--plp-green-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
        }

        .menu-card h3 {
            color: var(--plp-green);
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }

        .menu-card p {
            color: var(--text-medium);
            font-size: 0.9rem;
            margin: 0;
        }

        .exam-badge {
            background: var(--plp-green);
            color: white;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .form-container {
            background: var(--plp-green-pale);
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-top: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 3px rgba(0, 99, 65, 0.1);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
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

        .subject-info {
            background: var(--plp-green-lighter);
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            border-left: 4px solid var(--plp-green);
        }

        .subject-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--plp-green);
            margin-bottom: 0.5rem;
        }

        .subject-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .detail-item i {
            color: var(--plp-green);
            width: 16px;
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
                padding: 1rem;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .menu-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .subject-details {
                grid-template-columns: 1fr;
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
            <div class="welcome">Term Evaluations</div>
            <div class="header-actions">
                <a href="subject-management.php?subject_id=<?php echo $subject_id; ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Subject
                </a>
            </div>
        </div>

        <div class="subject-info">
            <div class="subject-name">
                <?php echo htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
            </div>
            <div class="subject-details">
                <div class="detail-item">
                    <i class="fas fa-user-tie"></i>
                    <span>Professor: <?php echo htmlspecialchars($subject['professor_name']); ?></span>
                </div>
                <div class="detail-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Schedule: <?php echo htmlspecialchars($subject['schedule']); ?></span>
                </div>
                <div class="detail-item">
                    <i class="fas fa-graduation-cap"></i>
                    <span>Credits: <?php echo htmlspecialchars($subject['credits']); ?></span>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Menu Page -->
            <div id="menuPage" class="page active">
                <h2 class="page-title">
                    <i class="fas fa-tasks"></i>
                    Select Term Evaluation
                </h2>
                <p style="text-align: center; color: var(--text-medium); margin-bottom: 2rem;">
                    Choose which term evaluation you want to input scores for:
                </p>
                
                <div class="menu-grid">
                    <div class="menu-card" onclick="showPage('midtermPage')">
                        <div class="menu-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <h3>Midterm Evaluation</h3>
                        <p>Input your midterm exam score and related assessments</p>
                        <div class="exam-badge">40% of Midterm Grade</div>
                    </div>
                    
                    <div class="menu-card" onclick="showPage('finalPage')">
                        <div class="menu-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <h3>Final Evaluation</h3>
                        <div class="exam-badge">40% of Final Grade</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showPage(pageId) {
            // Hide all pages
            document.querySelectorAll('.page').forEach(page => {
                page.classList.remove('active');
            });
            
            // Show selected page
            document.getElementById(pageId).classList.add('active');
            
            // Scroll to top
            window.scrollTo(0, 0);
        }

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            });
        }, 5000);

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const scoreInput = this.querySelector('input[name="score_value"]');
                    const maxScoreInput = this.querySelector('input[name="max_score"]');
                    
                    const scoreValue = parseFloat(scoreInput.value);
                    const maxScore = parseFloat(maxScoreInput.value);
                    
                    if (scoreValue < 0 || maxScore <= 0) {
                        e.preventDefault();
                        alert('Score value and maximum score must be positive numbers.');
                        return;
                    }
                    
                    if (scoreValue > maxScore) {
                        e.preventDefault();
                        alert('Score value cannot exceed maximum score.');
                        return;
                    }
                });
            });
        });
    </script>
</body>
</html>