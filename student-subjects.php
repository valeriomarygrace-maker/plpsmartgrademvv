<?php
require_once 'config.php';

// Remove the session_start() here since it's already handled in config.php
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Initialize variables
$success_message = '';
$error_message = '';
$student = null;
$subjects = [];
$available_subjects = [];

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        $error_message = 'Student record not found.';
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Initialize subjects if empty
try {
    $check_subjects = supabaseFetch('subjects');
    $subject_count = $check_subjects ? count($check_subjects) : 0;
    
    if ($subject_count == 0) {
        $actual_subjects = [
            // First Semester
                        ['COMP 104', 'Data Structures and Algorithms', 3, 'First Semester'],
                        ['COMP 105', 'Information Management', 3, 'First Semester'],
                        ['IT 102', 'Quantitative Methods', 3, 'First Semester'],
                        ['IT 201', 'IT Elective: Platform Technologies', 3, 'First Semester'],
                        ['IT 202', 'IT Elective: Object-Oriented Programming (VB.Net)', 3, 'First Semester'],
                        
                        // Second Semester
                        ['IT 103', 'Advanced Database Systems', 3, 'Second Semester'],
                        ['IT 104', 'Integrative Programming and Technologies I', 3, 'Second Semester'],
                        ['IT 105', 'Networking I', 3, 'Second Semester'],
                        ['IT 301', 'Web Programming', 3, 'Second Semester'],
                        ['COMP 106', 'Applications Development and Emerging Technologies', 3, 'Second Semester']
                    ];
        
        foreach ($actual_subjects as $subject_data) {
            $subject_data['created_at'] = date('Y-m-d H:i:s');
            supabaseInsert('subjects', $subject_data);
        }
    }
} catch (Exception $e) {
    // Silently continue
}

// Get student's enrolled subjects
try {
    $subjects_result = supabaseFetch('student_subjects', ['student_id' => $student['id']]);
    if ($subjects_result) {
        foreach ($subjects_result as $subject_record) {
            $subject_info = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
            if ($subject_info) {
                $subject_info = $subject_info[0];
                $subjects[] = array_merge($subject_record, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester']
                ]);
            }
        }
    }
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}

// Get available subjects for dropdown
try {
    $semester_mapping = [
        '1st Semester' => 'First Semester',
        '2nd Semester' => 'Second Semester', 
        '1st' => 'First Semester',
        '2nd' => 'Second Semester'
    ];
    
    $student_semester_raw = $student['semester'];
    $student_semester = $semester_mapping[$student_semester_raw] ?? 'First Semester';
    
    $all_subjects = supabaseFetch('subjects', ['semester' => $student_semester]);
    
    if (!$all_subjects || count($all_subjects) === 0) {
        $all_subjects = supabaseFetch('subjects', ['semester' => $student_semester_raw]);
        
        if (!$all_subjects || count($all_subjects) === 0) {
            $all_subjects = supabaseFetchAll('subjects');
            if ($all_subjects) {
                $all_subjects = array_filter($all_subjects, function($subject) use ($student_semester, $student_semester_raw) {
                    $subject_semester = strtolower($subject['semester'] ?? '');
                    $search_semester = strtolower($student_semester);
                    $search_semester_raw = strtolower($student_semester_raw);
                    
                    return strpos($subject_semester, $search_semester) !== false || 
                           strpos($subject_semester, $search_semester_raw) !== false;
                });
                $all_subjects = array_values($all_subjects);
            }
        }
    }
    
    $enrolled_subject_ids = [];
    if ($subjects && is_array($subjects)) {
        foreach ($subjects as $enrolled_subject) {
            if (isset($enrolled_subject['subject_id'])) {
                $enrolled_subject_ids[] = $enrolled_subject['subject_id'];
            }
        }
    }
    
    $available_subjects = [];
    if ($all_subjects && is_array($all_subjects)) {
        foreach ($all_subjects as $subject) {
            if (isset($subject['id']) && !in_array($subject['id'], $enrolled_subject_ids)) {
                $available_subjects[] = $subject;
            }
        }
    }
    
} catch (Exception $e) {
    $available_subjects = [];
}

// Handle add subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subject'])) {
    $subject_id = $_POST['subject_id'];
    $professor_name = trim($_POST['professor_name']);
    
    if (empty($subject_id)) {
        $error_message = 'Please select a subject.';
    } elseif (empty($professor_name)) {
        $error_message = 'Professor name is required.';
    } else {
        try {
            $insert_data = [
                'student_id' => $student['id'],
                'subject_id' => $subject_id,
                'professor_name' => $professor_name,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $result = supabaseInsert('student_subjects', $insert_data);
            
            if ($result) {
                $success_message = 'Subject added successfully!';
                header("Location: student-subjects.php");
                exit;
            } else {
                $error_message = 'Failed to add subject.';
            }
        } catch (Exception $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle delete subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    $subject_record_id = $_POST['subject_record_id'];
    
    try {
        $result = supabaseUpdate('student_subjects', ['deleted_at' => date('Y-m-d H:i:s')], ['id' => $subject_record_id, 'student_id' => $student['id']]);
        
        if ($result) {
            $success_message = 'Subject removed successfully!';
            header("Location: student-subjects.php");
            exit;
        } else {
            $error_message = 'Failed to remove subject.';
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Handle archive subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_subject'])) {
    $subject_record_id = $_POST['subject_record_id'];
    
    try {
        $subject_data = supabaseFetch('student_subjects', ['id' => $subject_record_id, 'student_id' => $student['id']]);
        if (!$subject_data || count($subject_data) === 0) {
            throw new Exception("Subject not found.");
        }
        
        $subject_to_archive = $subject_data[0];
        
        // Archive the main subject record - include schedule even if empty
        $archived_subject = supabaseInsert('archived_subjects', [
            'student_id' => $subject_to_archive['student_id'],
            'subject_id' => $subject_to_archive['subject_id'],
            'professor_name' => $subject_to_archive['professor_name'],
            'schedule' => $subject_to_archive['schedule'] ?? 'Not Set', // Add schedule field
            'archived_at' => date('Y-m-d H:i:s')
        ]);
        
        if (!$archived_subject) {
            throw new Exception("Failed to archive subject.");
        }
        
        $archived_subject_id = $archived_subject['id'];
        
        // Archive categories and scores
        $categories = supabaseFetch('student_class_standing_categories', ['student_subject_id' => $subject_record_id]);
        
        if ($categories && is_array($categories)) {
            foreach ($categories as $category) {
                $archived_category = supabaseInsert('archived_class_standing_categories', [
                    'archived_subject_id' => $archived_subject_id,
                    'category_name' => $category['category_name'],
                    'category_percentage' => $category['category_percentage'],
                    'archived_at' => date('Y-m-d H:i:s')
                ]);
                
                if ($archived_category) {
                    $archived_category_id = $archived_category['id'];
                    
                    $scores = supabaseFetch('student_subject_scores', ['category_id' => $category['id']]);
                    if ($scores && is_array($scores)) {
                        foreach ($scores as $score) {
                            supabaseInsert('archived_subject_scores', [
                                'archived_category_id' => $archived_category_id,
                                'score_type' => $score['score_type'],
                                'score_name' => $score['score_name'],
                                'score_value' => $score['score_value'],
                                'max_score' => $score['max_score'],
                                'score_date' => $score['score_date'],
                                'archived_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        }
        
        // Archive exam scores (scores without category_id)
        $exam_scores = supabaseFetch('student_subject_scores', [
            'student_subject_id' => $subject_record_id, 
            'category_id' => NULL
        ]);

        if ($exam_scores && is_array($exam_scores)) {
            // Create a special category for exam scores
            $exam_category = supabaseInsert('archived_class_standing_categories', [
                'archived_subject_id' => $archived_subject_id,
                'category_name' => 'Exam Scores',
                'category_percentage' => 0,
                'archived_at' => date('Y-m-d H:i:s')
            ]);
            
            if ($exam_category) {
                $exam_category_id = $exam_category['id'];
                foreach ($exam_scores as $score) {
                    supabaseInsert('archived_subject_scores', [
                        'archived_category_id' => $exam_category_id,
                        'score_type' => $score['score_type'],
                        'score_name' => $score['score_name'],
                        'score_value' => $score['score_value'],
                        'max_score' => $score['max_score'],
                        'score_date' => $score['score_date'],
                        'archived_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
                
        // Archive performance data
        $performance_data = supabaseFetch('subject_performance', ['student_subject_id' => $subject_record_id]);
        if ($performance_data && count($performance_data) > 0) {
            $performance = $performance_data[0];
            supabaseInsert('archived_subject_performance', [
                'archived_subject_id' => $archived_subject_id,
                'overall_grade' => $performance['overall_grade'],
                'gpa' => $performance['gpa'],
                'class_standing' => $performance['class_standing'],
                'exams_score' => $performance['exams_score'],
                'risk_level' => $performance['risk_level'],
                'risk_description' => $performance['risk_description'],
                'archived_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Delete from active tables
        supabaseDelete('student_subject_scores', ['student_subject_id' => $subject_record_id]);
        supabaseDelete('student_class_standing_categories', ['student_subject_id' => $subject_record_id]);
        supabaseDelete('subject_performance', ['student_subject_id' => $subject_record_id]);
        $delete_result = supabaseDelete('student_subjects', ['id' => $subject_record_id]);
        
        if ($delete_result) {
            $success_message = 'Subject archived successfully with all records preserved!';
            header("Location: student-subjects.php");
            exit;
        } else {
            throw new Exception("Failed to delete subject from active records.");
        }
        
    } catch (Exception $e) {
        $error_message = 'Database error during archiving: ' . $e->getMessage();
        error_log("Archive error: " . $e->getMessage());
    }
}

// Handle update subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    $subject_record_id = $_POST['subject_record_id'];
    $professor_name = trim($_POST['professor_name']);
    
    if (empty($professor_name)) {
        $error_message = 'Professor name is required.';
    } else {
        try {
            $update_data = [
                'professor_name' => $professor_name
            ];
            
            $result = supabaseUpdate('student_subjects', $update_data, ['id' => $subject_record_id, 'student_id' => $student['id']]);
            
            if ($result) {
                $success_message = 'Subject updated successfully!';
                header("Location: student-subjects.php");
                exit;
            } else {
                $error_message = 'Failed to update subject.';
            }
        } catch (Exception $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects - PLP SmartGrade</title>
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
        
        /* Modal styles */
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

        .modal-title {
            color: var(--plp-green);
            font-size: 1.50rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
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

        .welcome {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Cards */
        .card {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
            border-top: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--plp-green-gradient);
        }

        /* Improved Card Header */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--plp-green-lighter);
        }

        .card-title {
            color: var(--plp-green);
            font-size: 1.4rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0;
            padding: 0;
            border: none;
        }

        .card-title i {
            font-size: 1.2rem;
            width: 32px;
            height: 32px;
            background: var(--plp-green-pale);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .subject-count {
            background: var(--plp-green-gradient);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(0, 99, 65, 0.2);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-medium);
        }

        .form-input, .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            background: white;
        }

        .form-input:focus, .form-select:focus {
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

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Improved Subject Actions */
        .subject-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            pointer-events: auto;
            z-index: 2;
            position: relative;
        }

        .btn-edit {
            background: var(--plp-green-gradient);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 500;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        .btn-archive {
            background: var(--plp-green-lighter);
            color: var(--plp-green);
            box-shadow: 0 4px 12px rgba(0, 99, 65, 0.3);
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 500;
        }

        .btn-archive:hover {
            background: var(--plp-green);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 99, 65, 0.4);
        }

        /* Subjects Grid */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .subject-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
            transition: var(--transition);
        }

        .subject-card-link:hover {
            transform: translateY(-2px);
        }

        /* Subject Card Improvements */
        .subject-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            transition: var(--transition);
            position: relative;
            display: flex;
            flex-direction: column;
            height: 100%;
            cursor: pointer;
        }

        .subject-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow-lg);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--plp-green);
            font-weight: 600;
        }

        .credits {
            font-size: 0.85rem;
            color: var(--plp-green);
            font-weight: 1000;
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0.5rem 0;
            line-height: 1.3;
        }

        .subject-info {
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            color: var(--text-medium);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .info-item i {
            color: var(--plp-green);
            width: 14px;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .info-item span {
            flex: 1;
        }

        /* Status badge */
        .subject-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.7rem;
            padding: 0.2rem 0.6rem;
            border-radius: 10px;
            font-weight: 600;
            background: var(--success);
            color: white;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .semester-badge {
            font-size: 0.8rem;
            color: var(--plp-green);
            background: var(--plp-green-lighter);
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-weight: 500;
        }

        .semester-indicator {
            background: #035236ff;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Edit Subject Modal Styles */
        .edit-form-group {
            margin-bottom: 1rem;
        }

        .edit-form-label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .edit-form-input {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .edit-form-input:focus {
            outline: none;
            border-color: var(--plp-green);
            box-shadow: 0 0 0 2px rgba(0, 99, 65, 0.1);
        }

        /* Archive Confirmation Modal */
        .archive-modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow-lg);
            max-width: 450px;
            width: 90%;
            text-align: center;
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

        /* Responsive improvements */
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
            
            .card-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .card-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .modal-actions {
                flex-direction: column;
            }
            
            .modal-btn {
                width: 100%;
                justify-content: center;
            }
            
            .subject-actions {
                flex-direction: column;
            }
            
            .btn-edit, .btn-archive {
                pointer-events: auto;
                position: relative;
                z-index: 3;
            }
            
            .subject-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .credits {
                align-self: flex-start;
            }
        }

        .subject-card > *:not(.subject-actions) {
            cursor: pointer;
        }

        @media (max-width: 480px) {
            .card-actions {
                flex-direction: column;
                gap: 0.75rem;
                align-items: stretch;
            }
            
            .subject-count {
                justify-content: center;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .subject-status {
                position: relative;
                top: auto;
                right: auto;
                align-self: flex-start;
                margin-bottom: 0.5rem;
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
                <a href="student-subjects.php" class="nav-link active">
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
            <div class="welcome">Current Subjects</div>
            <div class="semester-indicator">
                <i class="fas fa-calendar-alt"></i>
                Current Semester: <?php echo htmlspecialchars($student['semester']); ?> 
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-book"></i>
                    My Enrolled Subjects
                </div>
                <div class="card-actions">
                    <div class="subject-count">
                        <i class="fas fa-layer-group"></i>
                        <?php echo count($subjects); ?> Subjects
                    </div>
                    <button type="button" class="btn btn-primary" id="addSubjectBtn">
                        <i class="fas fa-plus"></i> Add New Subject
                    </button>
                </div>
            </div>
            
            <?php if (empty($subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <p>No subjects enrolled yet</p>
                    <small>Click "Add New Subject" to enroll in your first subject</small>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="subject-card" onclick="window.location.href='termevaluations.php?subject_id=<?php echo $subject['id']; ?>'">
                            <div class="subject-header">
                                <div style="flex: 1;">
                                    <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
                                    <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
                                </div>
                                <div class="credits">
                                    <?php echo htmlspecialchars($subject['credits']); ?> CRDTS
                                </div>
                            </div>
                            
                            <div class="subject-info">
                                <div class="info-item">
                                    <i class="fas fa-user-tie"></i>
                                    <span><strong>Professor:</strong> <?php echo htmlspecialchars($subject['professor_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><strong>Semester:</strong> <?php echo htmlspecialchars($subject['semester']); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-clock"></i>
                                    <span><strong>Added:</strong> <?php echo date('M j, Y', strtotime($subject['created_at'])); ?></span>
                                </div>
                            </div>
                            
                            <div class="subject-actions">
                                <button type="button" class="btn-edit" onclick="openEditModal(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['subject_code']); ?> - <?php echo htmlspecialchars($subject['subject_name']); ?>', '<?php echo htmlspecialchars($subject['professor_name']); ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn-archive" onclick="openArchiveModal(<?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['subject_code']); ?> - <?php echo htmlspecialchars($subject['subject_name']); ?>')">
                                    <i class="fas fa-archive"></i> Archive
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div class="modal" id="editSubjectModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-edit"></i>
                Edit Subject Details
            </h3>
            
            <form action="student-subjects.php" method="POST" id="editSubjectForm">
                <input type="hidden" name="subject_record_id" id="edit_subject_id">
                
                <div class="edit-form-group">
                    <label class="edit-form-label">Subject Code & Name</label>
                    <input type="text" class="edit-form-input" id="edit_subject_info" readonly style="background: #f8f9fa; color: #6c757d;">
                </div>
                
                <div class="edit-form-group">
                    <label for="edit_professor_name" class="edit-form-label">Professor Name</label>
                    <input type="text" name="professor_name" id="edit_professor_name" class="edit-form-input" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" id="cancelEditSubject">
                        Cancel
                    </button>
                    <button type="submit" name="update_subject" class="modal-btn modal-btn-confirm">
                         Update Subject
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Archive Confirmation Modal -->
    <div class="modal" id="archiveSubjectModal">
        <div class="archive-modal-content">
            <h3 class="modal-title">
                <i class="fas fa-archive"></i>
                Archive Subject
            </h3>

            <p style="color: var(--text-medium); margin-bottom: 1.5rem;">
                Are you sure you want to archive <strong id="archive_subject_name"></strong>?
            </p>
            
            <form action="student-subjects.php" method="POST" id="archiveSubjectForm">
                <input type="hidden" name="subject_record_id" id="archive_subject_id">
                <input type="hidden" name="archive_subject" value="1">
            </form>
            
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" id="cancelArchiveSubject">
                     Cancel
                </button>
                <button type="button" class="modal-btn btn-archive" id="confirmArchiveSubject" style="background: var(--plp-green-gradient); color: white;">
                     Yes
                </button>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal" id="addSubjectModal">
        <div class="modal-content">
            <h3 class="modal-title">
                <i class="fas fa-plus"></i>
                Add New Subject
            </h3>
        
            <form action="student-subjects.php" method="POST" id="addSubjectForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="subject_id" class="form-label">Subject</label>
                        <select name="subject_id" id="subject_id" class="form-select" required>
                            <option value="">Select a subject</option>
                            <?php 
                            if (!empty($available_subjects) && is_array($available_subjects)): 
                                foreach ($available_subjects as $available_subject): 
                                    if (is_array($available_subject) && isset($available_subject['id'])): 
                            ?>
                                        <option value="<?php echo $available_subject['id']; ?>">
                                            <?php 
                                            echo htmlspecialchars(
                                                $available_subject['subject_code'] . ' - ' . 
                                                $available_subject['subject_name'] . ' (' . 
                                                $available_subject['credits'] . ' credits)'
                                            ); 
                                            ?>
                                        </option>
                            <?php 
                                    endif;
                                endforeach; 
                            else: 
                            ?>
                                <option value="" disabled>No subjects available for your semester</option>
                            <?php endif; ?>
                        </select>
                        
                        <?php if (empty($available_subjects) || !is_array($available_subjects)): ?>
                            <p style="color: var(--text-light); font-size: 0.85rem; margin-top: 0.5rem;">
                                <?php if (empty($available_subjects)): ?>
                                    All subjects for <?php echo htmlspecialchars($student['semester']); ?> have been enrolled.
                                <?php else: ?>
                                    No subjects found for your current semester.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="professor_name" class="form-label">Professor Name</label>
                        <input type="text" name="professor_name" id="professor_name" class="form-input" 
                            placeholder="Enter professor's name" required>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" id="cancelAddSubject">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_subject" class="modal-btn modal-btn-confirm" 
                        <?php echo (empty($available_subjects) || !is_array($available_subjects)) ? 'disabled' : ''; ?>>
                        <i class="fas fa-plus"></i> Add Subject
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
        // Your JavaScript code remains exactly the same
        const addSubjectBtn = document.getElementById('addSubjectBtn');
        const addSubjectModal = document.getElementById('addSubjectModal');
        const cancelAddSubject = document.getElementById('cancelAddSubject');
        const addSubjectForm = document.getElementById('addSubjectForm');

        const editSubjectModal = document.getElementById('editSubjectModal');
        const cancelEditSubject = document.getElementById('cancelEditSubject');
        const editSubjectForm = document.getElementById('editSubjectForm');

        const archiveSubjectModal = document.getElementById('archiveSubjectModal');
        const cancelArchiveSubject = document.getElementById('cancelArchiveSubject');
        const confirmArchiveSubject = document.getElementById('confirmArchiveSubject');
        const archiveSubjectForm = document.getElementById('archiveSubjectForm');

        // Logout modal functionality
        const logoutBtn = document.querySelector('.logout-btn');
        const logoutModal = document.getElementById('logoutModal');
        const cancelLogout = document.getElementById('cancelLogout');
        const confirmLogout = document.getElementById('confirmLogout');

        // Show modal when clicking add subject button
        addSubjectBtn.addEventListener('click', () => {
            addSubjectModal.classList.add('show');
        });

        // Hide modal when clicking cancel
        cancelAddSubject.addEventListener('click', () => {
            addSubjectModal.classList.remove('show');
        });

        cancelEditSubject.addEventListener('click', () => {
            editSubjectModal.classList.remove('show');
        });

        cancelArchiveSubject.addEventListener('click', () => {
            archiveSubjectModal.classList.remove('show');
        });

        // Confirm archive action
        confirmArchiveSubject.addEventListener('click', () => {
            archiveSubjectForm.submit();
        });

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

        // Reset form when modal is closed
        addSubjectModal.addEventListener('transitionend', () => {
            if (!addSubjectModal.classList.contains('show')) {
                addSubjectForm.reset();
            }
        });

        // Edit modal functions
        function openEditModal(subjectId, subjectInfo, professorName) {
            // Prevent event from bubbling to the card click
            event.stopPropagation();
            
            document.getElementById('edit_subject_id').value = subjectId;
            document.getElementById('edit_subject_info').value = subjectInfo;
            document.getElementById('edit_professor_name').value = professorName;
            editSubjectModal.classList.add('show');
        }

        // Archive modal functions
        function openArchiveModal(subjectId, subjectName) {
            // Prevent event from bubbling to the card click
            event.stopPropagation();
            
            document.getElementById('archive_subject_id').value = subjectId;
            document.getElementById('archive_subject_name').textContent = subjectName;
            archiveSubjectModal.classList.add('show');
        }

        // Auto-hide success/error messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.1s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 100);
            });
        }, 5000);

        // Close modal after successful form submission if there are no errors
        <?php if ($success_message && empty($error_message)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const modals = [
                    document.getElementById('addSubjectModal'),
                    document.getElementById('editSubjectModal'),
                    document.getElementById('archiveSubjectModal')
                ];
                modals.forEach(modal => {
                    if (modal) {
                        modal.classList.remove('show');
                    }
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>