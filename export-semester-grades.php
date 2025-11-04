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
                    $gwa = $performance['gpa'] ?? calculateGWA($overall_grade);
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
                    'overall_grade' => $has_scores ? number_format($overall_grade, 2) : '--',
                    'risk_level' => $performance['risk_level'] ?? 'no-data',
                    'risk_description' => $performance['risk_description'] ?? 'No Data Inputted'
                ];
            }
        }
    }

    // Set headers for Excel file download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="PLP_Grades_' . $selected_semester . '_' . date('Y-m-d') . '.xls"');
    
    // Start output
    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>PLP SmartGrade - Semester Grades</title>";
    echo "<style>";
    echo "body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; color: #333; line-height: 1.4; }";
    echo ".header { background: linear-gradient(135deg, #006341 0%, #008856 100%); color: white; padding: 25px; border-radius: 8px; margin-bottom: 25px; }";
    echo ".header h1 { margin: 0 0 10px 0; font-size: 24px; font-weight: 700; }";
    echo ".header h2 { margin: 0 0 15px 0; font-size: 18px; font-weight: 600; opacity: 0.9; }";
    echo ".student-info { background: #f8fcf9; padding: 20px; border-radius: 6px; border-left: 4px solid #006341; margin-bottom: 20px; }";
    echo ".student-info p { margin: 5px 0; }";
    echo ".grades-table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }";
    echo ".grades-table th { background: #006341; color: white; padding: 12px 8px; text-align: left; font-weight: 600; border: 1px solid #005a38; }";
    echo ".grades-table td { padding: 10px 8px; border: 1px solid #e0e0e0; vertical-align: top; }";
    echo ".grades-table tr:nth-child(even) { background-color: #f8fcf9; }";
    echo ".grades-table tr:hover { background-color: #e8f5e9; }";
    echo ".summary { background: #f8fcf9; padding: 20px; border-radius: 6px; border: 1px solid #e0e0e0; margin: 25px 0; }";
    echo ".summary h3 { color: #006341; margin-top: 0; border-bottom: 2px solid #006341; padding-bottom: 8px; }";
    echo ".summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px; }";
    echo ".summary-item { background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #006341; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }";
    echo ".summary-item .label { font-size: 12px; color: #666; margin-bottom: 5px; }";
    echo ".summary-item .value { font-size: 18px; font-weight: 700; color: #006341; }";
    echo ".footer { text-align: center; margin-top: 30px; padding: 20px; color: #666; font-size: 11px; border-top: 1px solid #e0e0e0; }";
    echo ".risk-badge { padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; display: inline-block; }";
    echo ".risk-low { background: #d4edda; color: #155724; }";
    echo ".risk-medium { background: #fff3cd; color: #856404; }";
    echo ".risk-high { background: #f8d7da; color: #721c24; }";
    echo ".risk-no-data { background: #e2e3e5; color: #383d41; }";
    echo ".grade-excellent { color: #28a745; font-weight: 700; }";
    echo ".grade-good { color: #17a2b8; font-weight: 700; }";
    echo ".grade-average { color: #ffc107; font-weight: 700; }";
    echo ".grade-poor { color: #dc3545; font-weight: 700; }";
    echo ".text-center { text-align: center; }";
    echo ".text-right { text-align: right; }";
    echo ".subject-code { font-weight: 600; color: #006341; }";
    echo "</style>";
    echo "</head>";
    echo "<body>";
    
    // Header
    echo "<div class='header'>";
    echo "<h1>🏛️ PLP SMARTGRADE - OFFICIAL GRADE REPORT</h1>";
    echo "<h2>Academic Semester: " . htmlspecialchars($selected_semester) . "</h2>";
    echo "<p style='margin: 0; opacity: 0.9;'>Pamantasan ng Lungsod ng Pasig</p>";
    echo "</div>";
    
    // Student Information
    echo "<div class='student-info'>";
    echo "<p><strong>Student Name:</strong> " . htmlspecialchars($student['fullname']) . "</p>";
    echo "<p><strong>Student Number:</strong> " . htmlspecialchars($student['student_number']) . "</p>";
    echo "<p><strong>Program:</strong> " . htmlspecialchars($student['course']) . " | <strong>Year Level:</strong> " . htmlspecialchars($student['year_level']) . " | <strong>Section:</strong> " . htmlspecialchars($student['section']) . "</p>";
    echo "<p><strong>Report Generated:</strong> " . date('F j, Y g:i A') . "</p>";
    echo "</div>";
    
    // Grades Table
    if (!empty($semester_grades)) {
        echo "<table class='grades-table'>";
        echo "<thead>";
        echo "<tr>";
        echo "<th width='12%'>Subject Code</th>";
        echo "<th width='25%'>Subject Description</th>";
        echo "<th width='18%'>Professor</th>";
        echo "<th width='15%'>Schedule</th>";
        echo "<th width='8%' class='text-center'>Credits</th>";
        echo "<th width='10%' class='text-center'>Grade %</th>";
        echo "<th width='8%' class='text-center'>GWA</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        
        $total_credits = 0;
        $total_subjects = 0;
        $total_gwa = 0;
        $graded_subjects = 0;
        
        foreach ($semester_grades as $subject) {
            $total_subjects++;
            $total_credits += $subject['credits'];
            
            // Determine risk level styling
            $risk_class = 'risk-no-data';
            $risk_display = 'No Data';
            if ($subject['risk_level'] !== 'no-data') {
                $risk_class = 'risk-' . $subject['risk_level'];
                $risk_display = ucfirst($subject['risk_level']);
            }
            
            // Determine grade styling
            $grade_class = '';
            if ($subject['overall_grade'] !== '--') {
                $numeric_grade = floatval($subject['overall_grade']);
                if ($numeric_grade >= 90) $grade_class = 'grade-excellent';
                elseif ($numeric_grade >= 80) $grade_class = 'grade-good';
                elseif ($numeric_grade >= 75) $grade_class = 'grade-average';
                else $grade_class = 'grade-poor';
            }
            
            // Calculate for semester GWA
            if ($subject['gwa'] !== '--') {
                $total_gwa += floatval($subject['gwa']);
                $graded_subjects++;
            }
            
            echo "<tr>";
            echo "<td><span class='subject-code'>" . htmlspecialchars($subject['subject_code']) . "</span></td>";
            echo "<td>" . htmlspecialchars($subject['subject_name']) . "</td>";
            echo "<td>" . htmlspecialchars($subject['professor_name']) . "</td>";
            echo "<td>" . htmlspecialchars($subject['schedule']) . "</td>";
            echo "<td class='text-center'><strong>" . htmlspecialchars($subject['credits']) . "</strong></td>";
            echo "<td class='text-center " . $grade_class . "'><strong>" . $subject['overall_grade'] . "</strong></td>";
            echo "<td class='text-center'><strong>" . $subject['gwa'] . "</strong></td>";
            echo "</tr>";
        }
        
        echo "</tbody>";
        echo "</table>";
        
        // Summary Section
        $semester_gwa = $graded_subjects > 0 ? $total_gwa / $graded_subjects : 0;
        
        // Determine overall remark
        $overall_remark = '';
        $remark_class = '';
        if ($semester_gwa > 0) {
            if ($semester_gwa <= 1.25) {
                $overall_remark = 'Excellent - Dean\'s List Potential';
                $remark_class = 'grade-excellent';
            } elseif ($semester_gwa <= 1.75) {
                $overall_remark = 'Very Good - High Performance';
                $remark_class = 'grade-good';
            } elseif ($semester_gwa <= 2.50) {
                $overall_remark = 'Good - Satisfactory Performance';
                $remark_class = 'grade-average';
            } elseif ($semester_gwa <= 3.00) {
                $overall_remark = 'Fair - Needs Improvement';
                $remark_class = 'grade-poor';
            } else {
                $overall_remark = 'Academic Probation Risk';
                $remark_class = 'grade-poor';
            }
        } else {
            $overall_remark = 'No Grade Data Available';
            $remark_class = 'risk-no-data';
        }
        
    } else {
        echo "<div style='text-align: center; padding: 40px; background: #f8fcf9; border-radius: 6px; margin: 20px 0;'>";
        echo "<h3 style='color: #666;'>No Grade Data Available</h3>";
        echo "<p>No academic records found for " . htmlspecialchars($selected_semester) . "</p>";
        echo "</div>";
    }
    
    echo "</body>";
    echo "</html>";
    
} catch (Exception $e) {
    die('Error generating export: ' . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores
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
        $midterm_exams = supabaseFetch('archived_subject_scores', [
            'archived_category_id' => $categories[0]['id'],
            'score_type' => 'midterm_exam'
        ]);
        $final_exams = supabaseFetch('archived_subject_scores', [
            'archived_category_id' => $categories[0]['id'],
            'score_type' => 'final_exam'
        ]);
        
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