<?php
// export-semester-grades.php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$selected_semester = $_GET['semester'] ?? '';

if (empty($selected_semester)) {
    die('No semester selected for export.');
}

try {
    // Get student info
    $student = getStudentByEmail($_SESSION['user_email']);
    
    if (!$student) {
        die('Student record not found.');
    }

    // Get archived subjects for the selected semester
    $archived_subjects = supabaseFetch('archived_subjects', ['student_id' => $student['id']]);
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
                    'subject_code' => $subject['subject_code'],
                    'subject_name' => $subject['subject_name'],
                    'professor_name' => $archived_subject['professor_name'],
                    'schedule' => $archived_subject['schedule'],
                    'credits' => $subject['credits'],
                    'gwa' => $has_scores ? number_format($gwa, 2) : '--',
                    'overall_grade' => $has_scores ? number_format($overall_grade, 2) : '--'
                ];
            }
        }
    }

    // Set headers for Excel file download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="semester_grades_' . $selected_semester . '_' . date('Y-m-d') . '.xls"');
    
    // Start output
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "table { border-collapse: collapse; width: 100%; }";
    echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
    echo "th { background-color: #f2f2f2; font-weight: bold; }";
    echo "tr:nth-child(even) { background-color: #f9f9f9; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    echo "<h2>Semester Grades - " . htmlspecialchars($selected_semester) . "</h2>";
    echo "<h3>Student: " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "</h3>";
    echo "<h4>Export Date: " . date('F j, Y g:i A') . "</h4>";
    
    echo "<table>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>Subject Code</th>";
    echo "<th>Subject Description</th>";
    echo "<th>Professor</th>";
    echo "<th>Schedule</th>";
    echo "<th>Credits</th>";
    echo "<th>GWA</th>";
    echo "<th>Overall Grade (%)</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    foreach ($semester_grades as $subject) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($subject['subject_code']) . "</td>";
        echo "<td>" . htmlspecialchars($subject['subject_name']) . "</td>";
        echo "<td>" . htmlspecialchars($subject['professor_name']) . "</td>";
        echo "<td>" . htmlspecialchars($subject['schedule']) . "</td>";
        echo "<td>" . htmlspecialchars($subject['credits']) . "</td>";
        echo "<td>" . $subject['gwa'] . "</td>";
        echo "<td>" . $subject['overall_grade'] . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    
    echo "</body>";
    echo "</html>";
    
} catch (Exception $e) {
    die('Error generating export: ' . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores - UPDATED FOR GWA
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (empty($categories)) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id'], 'score_type' => 'class_standing']);
            
            if (!empty($scores)) {
                $hasScores = true;
                $categoryTotal = 0;
                $categoryMax = 0;
                
                foreach ($scores as $score) {
                    $categoryTotal += $score['score_value'];
                    $categoryMax += $score['max_score'];
                }
                
                if ($categoryMax > 0) {
                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                    $weightedScore = ($categoryPercentage * $category['category_percentage']) / 100;
                    $totalClassStanding += $weightedScore;
                }
            }
        }
        
        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }
        
        // Get exam scores
        $midterm_exams = supabaseFetch('archived_subject_scores', ['score_type' => 'midterm_exam']);
        $final_exams = supabaseFetch('archived_subject_scores', ['score_type' => 'final_exam']);
        $exam_scores = array_merge($midterm_exams ?: [], $final_exams ?: []);
        
        foreach ($exam_scores as $exam) {
            if ($exam['max_score'] > 0) {
                $examPercentage = ($exam['score_value'] / $exam['max_score']) * 100;
                if ($exam['score_type'] === 'midterm_exam') {
                    $midtermScore = ($examPercentage * 20) / 100;
                } elseif ($exam['score_type'] === 'final_exam') {
                    $finalScore = ($examPercentage * 20) / 100;
                }
            }
        }
        
        if (!$hasScores && $midtermScore == 0 && $finalScore == 0) {
            return [
                'overall_grade' => 0,
                'gwa' => 0,
                'has_scores' => false
            ];
        }
        
        // Calculate overall grade
        $overallGrade = $totalClassStanding + $midtermScore + $finalScore;
        if ($overallGrade > 100) {
            $overallGrade = 100;
        }
        
        // Calculate GWA
        $gwa = calculateGWA($overallGrade);
        
        return [
            'overall_grade' => $overallGrade,
            'gwa' => $gwa,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating archived subject performance: " . $e->getMessage());
        return null;
    }
}

/**
 * Calculate GWA from grade (Philippine system)
 */
function calculateGWA($grade) {
    if ($grade >= 90) return 1.00;
    elseif ($grade >= 85) return 1.25;
    elseif ($grade >= 80) return 1.50;
    elseif ($grade >= 75) return 1.75;
    elseif ($grade >= 70) return 2.00;
    elseif ($grade >= 65) return 2.25;
    elseif ($grade >= 60) return 2.50;
    elseif ($grade >= 55) return 2.75;
    elseif ($grade >= 50) return 3.00;
    else return 5.00;
}
?>