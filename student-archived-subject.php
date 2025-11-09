<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Get student information
$userEmail = $_SESSION['user_email'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

// Fetch student data from Supabase
try {
    $student_data = supabaseFetch('students', ['id' => $userId, 'email' => $userEmail]);
    if (!$student_data || count($student_data) === 0) {
        $_SESSION['error_message'] = "Student account not found";
        header('Location: login.php');
        exit;
    }
    $student = $student_data[0];
} catch (Exception $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: login.php');
    exit;
}

$success_message = '';
$error_message = '';

// Handle subject restoration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_subject'])) {
    $archived_subject_id = $_POST['archived_subject_id'];
    
    try {
        // Get the archived subject details
        $archived_subject_data = supabaseFetch('archived_subjects', ['id' => $archived_subject_id, 'student_id' => $student['id']]);
        if (!$archived_subject_data || count($archived_subject_data) === 0) {
            throw new Exception("Archived subject not found.");
        }
        
        $archived_subject = $archived_subject_data[0];
        
        // Check if subject already exists in active subjects
        $existing_subject = supabaseFetch('student_subjects', [
            'student_id' => $student['id'], 
            'subject_id' => $archived_subject['subject_id'],
            'deleted_at' => null
        ]);
        
        if ($existing_subject && count($existing_subject) > 0) {
            throw new Exception("This subject is already in your active subjects.");
        }
        
        // Restore to student_subjects
        $restore_data = [
            'student_id' => $archived_subject['student_id'],
            'subject_id' => $archived_subject['subject_id'],
            'professor_name' => $archived_subject['professor_name'],
            'schedule' => $archived_subject['schedule'],
            'created_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null
        ];
        
        $restored_subject = supabaseInsert('student_subjects', $restore_data);
        
        if (!$restored_subject) {
            throw new Exception("Failed to restore subject.");
        }
        
        $new_subject_id = $restored_subject[0]['id'] ?? null;
        
        if (!$new_subject_id) {
            throw new Exception("Failed to get new subject ID.");
        }
        
        // Restore performance data
        $archived_performance = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject_id]);
        if ($archived_performance && count($archived_performance) > 0) {
            $performance = $archived_performance[0];
            $new_performance = [
                'student_subject_id' => $new_subject_id,
                'overall_grade' => $performance['overall_grade'] ?? 0,
                'gpa' => $performance['gpa'] ?? 0,
                'class_standing' => $performance['class_standing'] ?? 0,
                'exams_score' => $performance['exams_score'] ?? 0,
                'risk_level' => $performance['risk_level'] ?? 'no-data',
                'risk_description' => $performance['risk_description'] ?? 'No Data Inputted',
                'created_at' => date('Y-m-d H:i:s')
            ];
            supabaseInsert('subject_performance', $new_performance);
        }
        
        // Restore categories and scores
        $archived_categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        if ($archived_categories) {
            foreach ($archived_categories as $category) {
                $new_category = [
                    'student_subject_id' => $new_subject_id,
                    'category_name' => $category['category_name'],
                    'category_percentage' => $category['category_percentage'],
                    'term_type' => $category['term_type'] ?? 'midterm',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $restored_category = supabaseInsert('student_class_standing_categories', $new_category);
                
                if ($restored_category && isset($restored_category[0]['id'])) {
                    $new_category_id = $restored_category[0]['id'];
                    
                    // Restore scores for this category
                    $archived_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id']]);
                    if ($archived_scores) {
                        foreach ($archived_scores as $score) {
                            $new_score = [
                                'student_subject_id' => $new_subject_id,
                                'category_id' => $new_category_id,
                                'score_type' => $score['score_type'],
                                'score_name' => $score['score_name'],
                                'score_value' => $score['score_value'],
                                'max_score' => $score['max_score'],
                                'score_date' => $score['score_date'],
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                            supabaseInsert('student_subject_scores', $new_score);
                        }
                    }
                }
            }
        }
        
        // Delete the archived subject and all its related data
        // First delete scores
        $archived_categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        if ($archived_categories) {
            foreach ($archived_categories as $category) {
                supabaseDelete('archived_subject_scores', ['archived_category_id' => $category['id']]);
            }
            // Delete categories
            supabaseDelete('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        }
        
        // Delete performance
        supabaseDelete('archived_subject_performance', ['archived_subject_id' => $archived_subject_id]);
        
        // Finally delete the subject
        $delete_result = supabaseDelete('archived_subjects', ['id' => $archived_subject_id]);
        
        if ($delete_result) {
            $success_message = 'Subject restored successfully with all grades and data!';
            header("Location: student-archived-subject.php");
            exit;
        } else {
            throw new Exception("Failed to delete archived subject.");
        }
        
    } catch (Exception $e) {
        $error_message = 'Error restoring subject: ' . $e->getMessage();
        error_log("Restore error: " . $e->getMessage());
    }
}

// Handle AJAX request for term evaluation data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_term_evaluation') {
    $archived_subject_id = $_POST['archived_subject_id'] ?? 0;
    
    if ($archived_subject_id) {
        displayTermEvaluationForArchivedSubject($archived_subject_id);
    }
    exit;
}

// Fetch archived subjects
try {
    $archived_subjects_data = supabaseFetch('archived_subjects', ['student_id' => $student['id']]);
    
    $archived_subjects = [];
    
    if ($archived_subjects_data && is_array($archived_subjects_data)) {
        foreach ($archived_subjects_data as $archived_subject) {
            // Get subject details
            $subject_data = supabaseFetch('subjects', ['id' => $archived_subject['subject_id']]);
            $subject_info = $subject_data && count($subject_data) > 0 ? $subject_data[0] : null;
            
            if ($subject_info) {
                // First, try to get performance from archived_subject_performance table
                $archived_performance_data = supabaseFetch('archived_subject_performance', ['archived_subject_id' => $archived_subject['id']]);
                
                $final_performance = [
                    'midterm_grade' => 0,
                    'final_grade' => 0,
                    'subject_grade' => 0,
                    'risk_level' => 'no-data',
                    'risk_description' => 'No Data Inputted',
                    'has_scores' => false
                ];
                
                if ($archived_performance_data && count($archived_performance_data) > 0) {
                    // Use the pre-calculated performance data
                    $archived_performance = $archived_performance_data[0];
                    
                    $final_performance = [
                        'midterm_grade' => $archived_performance['class_standing'] ?? 0,
                        'final_grade' => $archived_performance['exams_score'] ?? 0,
                        'subject_grade' => $archived_performance['overall_grade'] ?? 0,
                        'risk_level' => $archived_performance['risk_level'] ?? 'no-data',
                        'risk_description' => $archived_performance['risk_description'] ?? 'No Data Inputted',
                        'has_scores' => ($archived_performance['overall_grade'] ?? 0) > 0
                    ];
                    
                } else {
                    // Calculate performance from scores (fallback)
                    $calculated_performance = calculateArchivedSubjectPerformance($archived_subject['id']);
                    $final_performance = $calculated_performance;
                }
                
                // Combine all data
                $archived_subjects[] = array_merge($archived_subject, [
                    'subject_code' => $subject_info['subject_code'],
                    'subject_name' => $subject_info['subject_name'],
                    'credits' => $subject_info['credits'],
                    'semester' => $subject_info['semester'],
                    'midterm_grade' => $final_performance['midterm_grade'],
                    'final_grade' => $final_performance['final_grade'],
                    'subject_grade' => $final_performance['subject_grade'],
                    'risk_level' => $final_performance['risk_level'],
                    'risk_description' => $final_performance['risk_description'],
                    'has_scores' => $final_performance['has_scores']
                ]);
            }
        }
    }
    
    // Sort by archived date descending
    usort($archived_subjects, function($a, $b) {
        return strtotime($b['archived_at']) - strtotime($a['archived_at']);
    });
    
    $total_archived = count($archived_subjects);
    
} catch (Exception $e) {
    $archived_subjects = [];
    $total_archived = 0;
    error_log("Error fetching archived subjects: " . $e->getMessage());
}

/**
 * Calculate performance for archived subject from scores
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (!$categories || !is_array($categories) || count($categories) === 0) {
            return [
                'midterm_grade' => 0,
                'final_grade' => 0,
                'subject_grade' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        $midtermGrade = 0;
        $finalGrade = 0;
        $subjectGrade = 0;
        $hasScores = false;
        
        // Separate midterm and final categories
        $midtermCategories = array_filter($categories, function($cat) {
            return isset($cat['term_type']) && $cat['term_type'] === 'midterm';
        });
        
        $finalCategories = array_filter($categories, function($cat) {
            return isset($cat['term_type']) && $cat['term_type'] === 'final';
        });
        
        // Calculate Midterm Grade
        if (!empty($midtermCategories)) {
            $midtermClassStandingTotal = 0;
            $midtermExamScore = 0;
            
            foreach ($midtermCategories as $category) {
                $scores = supabaseFetch('archived_subject_scores', [
                    'archived_category_id' => $category['id']
                ]);
                
                if ($scores && is_array($scores) && count($scores) > 0) {
                    $hasScores = true;
                    
                    // Check if this category is for exams
                    if (strtolower($category['category_name']) === 'exam scores' || strpos(strtolower($category['category_name']), 'exam') !== false) {
                        // Handle exam scores
                        foreach ($scores as $score) {
                            if ($score['score_type'] === 'midterm_exam' && floatval($score['max_score']) > 0) {
                                $examPercentage = (floatval($score['score_value']) / floatval($score['max_score'])) * 100;
                                $midtermExamScore = ($examPercentage * 40) / 100;
                                break;
                            }
                        }
                    } else {
                        // Handle class standing
                        $categoryTotal = 0;
                        $categoryMax = 0;
                        
                        foreach ($scores as $score) {
                            if ($score['score_type'] === 'class_standing') {
                                $categoryTotal += floatval($score['score_value']);
                                $categoryMax += floatval($score['max_score']);
                            }
                        }
                        
                        if ($categoryMax > 0) {
                            $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                            $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                            $midtermClassStandingTotal += $weightedScore;
                        }
                    }
                }
            }
            
            // Ensure Class Standing doesn't exceed 60%
            if ($midtermClassStandingTotal > 60) {
                $midtermClassStandingTotal = 60;
            }
            
            $midtermGrade = $midtermClassStandingTotal + $midtermExamScore;
            if ($midtermGrade > 100) {
                $midtermGrade = 100;
            }
        }
        
        // Calculate Final Grade
        if (!empty($finalCategories)) {
            $finalClassStandingTotal = 0;
            $finalExamScore = 0;
            
            foreach ($finalCategories as $category) {
                $scores = supabaseFetch('archived_subject_scores', [
                    'archived_category_id' => $category['id']
                ]);
                
                if ($scores && is_array($scores) && count($scores) > 0) {
                    $hasScores = true;
                    
                    // Check if this category is for exams
                    if (strtolower($category['category_name']) === 'exam scores' || strpos(strtolower($category['category_name']), 'exam') !== false) {
                        // Handle exam scores
                        foreach ($scores as $score) {
                            if ($score['score_type'] === 'final_exam' && floatval($score['max_score']) > 0) {
                                $examPercentage = (floatval($score['score_value']) / floatval($score['max_score'])) * 100;
                                $finalExamScore = ($examPercentage * 40) / 100;
                                break;
                            }
                        }
                    } else {
                        // Handle class standing
                        $categoryTotal = 0;
                        $categoryMax = 0;
                        
                        foreach ($scores as $score) {
                            if ($score['score_type'] === 'class_standing') {
                                $categoryTotal += floatval($score['score_value']);
                                $categoryMax += floatval($score['max_score']);
                            }
                        }
                        
                        if ($categoryMax > 0) {
                            $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                            $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                            $finalClassStandingTotal += $weightedScore;
                        }
                    }
                }
            }
            
            // Ensure Class Standing doesn't exceed 60%
            if ($finalClassStandingTotal > 60) {
                $finalClassStandingTotal = 60;
            }
            
            $finalGrade = $finalClassStandingTotal + $finalExamScore;
            if ($finalGrade > 100) {
                $finalGrade = 100;
            }
        }
        
        // Calculate Subject Grade (average of midterm and final)
        $validGrades = array_filter([$midtermGrade, $finalGrade], function($grade) {
            return $grade > 0;
        });
        
        if (!empty($validGrades)) {
            $subjectGrade = array_sum($validGrades) / count($validGrades);
            if ($subjectGrade > 100) {
                $subjectGrade = 100;
            }
        }
        
        if (!$hasScores) {
            return [
                'midterm_grade' => 0,
                'final_grade' => 0,
                'subject_grade' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        // Calculate risk level based on subject grade
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';

        if ($subjectGrade >= 85) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($subjectGrade >= 80) {
            $riskLevel = 'medium';
            $riskDescription = 'Moderate Risk';
        } elseif ($subjectGrade > 0) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
        }
        
        return [
            'midterm_grade' => $midtermGrade,
            'final_grade' => $finalGrade,
            'subject_grade' => $subjectGrade,
            'risk_level' => $riskLevel,
            'risk_description' => $riskDescription,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating archived subject performance: " . $e->getMessage());
        return [
            'midterm_grade' => 0,
            'final_grade' => 0,
            'subject_grade' => 0,
            'risk_level' => 'no-data',
            'risk_description' => 'Error calculating grades',
            'has_scores' => false
        ];
    }
}

/**
 * Display term evaluation interface for archived subject
 */
function displayTermEvaluationForArchivedSubject($archived_subject_id) {
    try {
        // Get archived subject data
        $archived_subject_data = supabaseFetch('archived_subjects', ['id' => $archived_subject_id]);
        
        if (!$archived_subject_data || count($archived_subject_data) === 0) {
            echo '<div class="alert-error">Subject not found.</div>';
            return;
        }
        
        $archived_subject = $archived_subject_data[0];
        
        // Get performance data
        $performance_data = calculateArchivedSubjectPerformance($archived_subject_id);
        
        // Get categories and scores for detailed display
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        // Calculate grades using the same logic as termevaluations.php
        $grades = calculateDetailedGradesForArchivedSubject($archived_subject_id);
        
        // Display the term evaluation interface
        ?>
        <div class="term-evaluation-container">
            <!-- Overview Section -->
            <div class="overview-section">
                <div class="overview-grid">
                    <div class="overview-card subject-grade-card">
                        <div class="overview-label">SUBJECT GRADE</div>
                        <div class="overview-value">
                            <?php echo $grades['subject_grade'] > 0 ? number_format($grades['subject_grade'], 1) . '%' : '--'; ?>
                        </div>
                        <div class="overview-description">
                            <?php if ($grades['subject_grade'] > 0): ?>
                                <?php 
                                $riskLevel = getSubjectRiskDescription($grades['subject_grade']);
                                ?>
                                <span class="risk-badge <?php echo strtolower(str_replace(' ', '-', $riskLevel)); ?>">
                                    <?php echo $riskLevel; ?>
                                </span>
                            <?php else: ?>
                                No grades calculated
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="overview-label">MIDTERM GRADE</div>
                        <div class="overview-value">
                            <?php echo $grades['midterm_grade'] > 0 ? number_format($grades['midterm_grade'], 1) . '%' : '--'; ?>
                        </div>
                        <div class="overview-description">
                            <?php if ($grades['midterm_grade'] > 0): ?>
                                <?php echo getTermGradeDescription($grades['midterm_grade']); ?>
                            <?php else: ?>
                                No midterm data
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="overview-card">
                        <div class="overview-label">FINAL GRADE</div>
                        <div class="overview-value">
                            <?php echo $grades['final_grade'] > 0 ? number_format($grades['final_grade'], 1) . '%' : '--'; ?>
                        </div>
                        <div class="overview-description">
                            <?php if ($grades['final_grade'] > 0): ?>
                                <?php echo getTermGradeDescription($grades['final_grade']); ?>
                            <?php else: ?>
                                No final data
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Terms Section -->
            <div class="terms-container">
                <!-- Midterm Card -->
                <div class="term-card midterm">
                    <div class="term-title">MIDTERM</div>
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Midterm Exam</div>
                        </div>
                    </div>
                    
                    <!-- Midterm Categories -->
                    <?php if ($categories): ?>
                        <?php 
                        $midterm_categories = array_filter($categories, function($cat) {
                            return isset($cat['term_type']) && $cat['term_type'] === 'midterm';
                        });
                        ?>
                        <?php if (!empty($midterm_categories)): ?>
                            <div class="categories-section" style="margin-top: 1rem;">
                                <h4 style="color: var(--text-medium); font-size: 0.9rem; margin-bottom: 0.5rem;">Midterm Categories:</h4>
                                <?php foreach ($midterm_categories as $category): ?>
                                    <div style="background: var(--plp-green-pale); padding: 0.5rem; border-radius: 6px; margin-bottom: 0.5rem;">
                                        <div style="display: flex; justify-content: between; align-items: center;">
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($category['category_name']); ?></span>
                                            <span style="color: var(--plp-green); font-weight: 600;"><?php echo $category['category_percentage']; ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Final Card -->
                <div class="term-card final">
                    <div class="term-title">FINAL</div>
                    <div class="term-stats">
                        <div class="stat-item">
                            <div class="stat-value">60%</div>
                            <div class="stat-label">Class Standing</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">40%</div>
                            <div class="stat-label">Final Exam</div>
                        </div>
                    </div>
                    
                    <!-- Final Categories -->
                    <?php if ($categories): ?>
                        <?php 
                        $final_categories = array_filter($categories, function($cat) {
                            return isset($cat['term_type']) && $cat['term_type'] === 'final';
                        });
                        ?>
                        <?php if (!empty($final_categories)): ?>
                            <div class="categories-section" style="margin-top: 1rem;">
                                <h4 style="color: var(--text-medium); font-size: 0.9rem; margin-bottom: 0.5rem;">Final Categories:</h4>
                                <?php foreach ($final_categories as $category): ?>
                                    <div style="background: var(--plp-green-pale); padding: 0.5rem; border-radius: 6px; margin-bottom: 0.5rem;">
                                        <div style="display: flex; justify-content: between; align-items: center;">
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($category['category_name']); ?></span>
                                            <span style="color: var(--plp-green); font-weight: 600;"><?php echo $category['category_percentage']; ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subject Information -->
            <div class="subject-info-card" style="background: var(--plp-green-pale); padding: 1.5rem; border-radius: var(--border-radius); margin-top: 1.5rem;">
                <h4 style="color: var(--plp-green); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-info-circle"></i> Subject Information
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <strong>Professor:</strong><br>
                        <?php echo htmlspecialchars($archived_subject['professor_name']); ?>
                    </div>
                    <div>
                        <strong>Schedule:</strong><br>
                        <?php echo htmlspecialchars($archived_subject['schedule']); ?>
                    </div>
                    <div>
                        <strong>Semester:</strong><br>
                        <?php echo htmlspecialchars($archived_subject['semester']); ?>
                    </div>
                    <div>
                        <strong>Credits:</strong><br>
                        <?php echo htmlspecialchars($archived_subject['credits']); ?> credits
                    </div>
                </div>
            </div>
        </div>
        <?php
        
    } catch (Exception $e) {
        echo '<div class="alert-error">Error loading subject details: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

/**
 * Calculate detailed grades for archived subject
 */
function calculateDetailedGradesForArchivedSubject($archived_subject_id) {
    $performance = calculateArchivedSubjectPerformance($archived_subject_id);
    
    return [
        'midterm_grade' => $performance['midterm_grade'],
        'final_grade' => $performance['final_grade'],
        'subject_grade' => $performance['subject_grade']
    ];
}

// Add these helper functions if they don't exist
function getSubjectRiskDescription($grade) {
    if ($grade >= 85) return 'Low Risk';
    elseif ($grade >= 80) return 'Moderate Risk';
    else return 'High Risk';
}

function getTermGradeDescription($grade) {
    if ($grade >= 90) return 'Excellent';
    elseif ($grade >= 85) return 'Very Good';
    elseif ($grade >= 80) return 'Good';
    elseif ($grade >= 75) return 'Satisfactory';
    elseif ($grade >= 70) return 'Passing';
    else return 'Needs Improvement';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Subjects - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Your existing CSS styles here - they are correct */
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
            line-height: 1.2;
        }

        .subject-count {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

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

        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 0.10rem;
        }

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
        }

        .subject-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow-lg);
        }

        .subject-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 0.7rem;
            padding: 0.3rem 0.8rem;
            border-radius: 10px;
            font-weight: 600;
            background: var(--plp-green);
            color: white;
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .subject-code {
            font-size: 0.8rem;
            color: var(--plp-green);
            font-weight: 600;
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0.5rem 0;
            line-height: 1.3;
        }

        .credits {
            font-size: 0.85rem;
            color: var(--plp-green);
            font-weight: 1000;
            white-space: nowrap;
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

        .subject-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            flex-wrap: wrap;
        }

        .btn-restore {
            background: var(--plp-green);
            color: white;
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
            flex: 1;
            min-width: 120px;
            justify-content: center;
        }

        .btn-restore:hover {
            background: var(--plp-green-light);
            transform: translateY(-2px);
        }

        .btn-view {
            background: var(--plp-dark-green);
            color: white;
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
            flex: 1;
            min-width: 120px;
            justify-content: center;
        }

        .btn-view:hover {
            background: var(--plp-green);
            transform: translateY(-2px);
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

        .alert-success {
            background: #c6f6d5;
            color: #2f855a;
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 4px solid #38a169;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

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

        .risk-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .risk-badge.low {
            background: #c6f6d5;
            color: #2f855a;
        }

        .risk-badge.medium {
            background: #fef5e7;
            color: #d69e2e;
        }

        .risk-badge.high {
            background: #fed7d7;
            color: #c53030;
        }

        .risk-badge.failed {
            background: #7f1d1d;
            color: #fecaca;
        }

        .risk-badge.no-data {
            background: #e2e8f0;
            color: #718096;
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
            z-index: 2000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 1rem;
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
            max-width: 700px;
            width: 100%;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-title {
            color: var(--plp-green);
            font-size: 1.5rem;
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
            padding-top: 1rem;
            border-top: 1px solid var(--plp-green-lighter);
            flex-wrap: wrap;
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
            flex: 1;
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

        .performance-overview {
            margin-bottom: 1.5rem;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            text-align: center;
            padding: 1rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
        }

        .detail-label {
            font-size: 0.8rem;
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .grade-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--plp-green);
            margin: 0.5rem 0;
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: var(--plp-green);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            z-index: 3000;
            cursor: pointer;
            font-size: 1.2rem;
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background: var(--plp-green-light);
            transform: scale(1.05);
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .sidebar {
                width: 280px;
                transform: translateX(-100%);
                z-index: 2000;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                max-width: 100%;
                padding: 1rem;
                margin-top: 60px;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1rem;
            }
            
            .subjects-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .subject-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-restore,
            .btn-view {
                flex: none;
                width: 100%;
            }
        }

        .overview-section {
            margin-bottom: 2rem;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .overview-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--plp-green);
            text-align: center;
            transition: var(--transition);
        }

        .overview-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .subject-grade-card {
            border-left: 4px solid var(--plp-green);
        }

        .overview-label {
            font-size: 0.8rem;
            color: var(--text-light);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .overview-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--plp-green);
            margin: 0.5rem 0;
        }

        .overview-description {
            font-size: 0.85rem;
            color: var(--text-medium);
        }

        .terms-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .term-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            cursor: pointer;
            transition: var(--transition);
        }

        .term-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-lg);
        }

        .term-card.midterm {
            border-left: 4px solid #3B82F6;
        }

        .term-card.final {
            border-left: 4px solid #10B981;
        }

        .term-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .term-card.midterm .term-title {
            color: #3B82F6;
        }

        .term-card.final .term-title {
            color: #10B981;
        }

        .term-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>

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
                <a href="student-archived-subject.php" class="nav-link active">
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
            <div class="welcome">Archived Subjects</div>
            <div class="subject-count">
                <i class="fas fa-layer-group"></i>
                <?php echo $total_archived; ?> Archived Subjects
            </div>
        </div>

        <div class="card">
            <?php if (empty($archived_subjects)): ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived subjects found</p>
                    <small>Subjects will appear here once you archive them from your active subjects list</small>
                    <br>
                    <a href="student-subjects.php" class="btn-restore" style="margin-top: 1rem; border-radius: 10px; text-decoration: none;">
                        <i class="fas fa-book"></i> Go to Active Subjects
                    </a>
                </div>
            <?php else: ?>
                <div class="subjects-grid">
                    <?php foreach ($archived_subjects as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-status">Archived</div>
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
                                    <span><strong>Archived:</strong> <?php echo date('M j, Y g:i A', strtotime($subject['archived_at'])); ?></span>
                                </div>
                                
                                <!-- Grade Summary -->
                                <div class="info-item">
                                    <i class="fas fa-chart-line"></i>
                                    <span>
                                        <strong>Final Grade:</strong> 
                                        <?php if ($subject['subject_grade'] > 0): ?>
                                            <span style="color: var(--plp-green); font-weight: 600;">
                                                <?php echo number_format($subject['subject_grade'], 1); ?>%
                                            </span>
                                            <span class="risk-badge <?php echo $subject['risk_level']; ?>" style="margin-left: 0.5rem;">
                                                <?php echo $subject['risk_description']; ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-light);">No grades recorded</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="subject-actions">
                                <form action="student-archived-subject.php" method="POST" style="display: inline;">
                                    <input type="hidden" name="archived_subject_id" value="<?php echo $subject['id']; ?>">
                                    <button type="submit" name="restore_subject" class="btn-restore" onclick="return confirm('Are you sure you want to restore this subject?')">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <button type="button" class="btn-view" 
                                    onclick="openViewModal(
                                        '<?php echo $subject['id']; ?>',
                                        '<?php echo htmlspecialchars($subject['subject_code']); ?>',
                                        '<?php echo htmlspecialchars($subject['subject_name']); ?>'
                                    )">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content" style="max-width: 1000px; height: 90vh; display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--plp-green-lighter);">
                <h3 class="modal-title" style="margin: 0;">
                    <i class="fas fa-chart-line"></i>
                    <span id="modal_subject_title">Subject Performance</span>
                </h3>
                <button type="button" class="modal-btn modal-btn-cancel" id="closeViewModal" style="padding: 0.5rem 1rem; font-size: 0.9rem;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            
            <div id="modalContent" style="flex: 1; overflow-y: auto; padding: 0.5rem;">
                <!-- Content will be loaded dynamically -->
                <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Loading subject details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.querySelector('.sidebar');

        mobileMenuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });

        // Close sidebar when clicking on a link (mobile)
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close sidebar when clicking outside (mobile)
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && 
                sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                !mobileMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        function openViewModal(archivedSubjectId, subjectCode, subjectName) {
            console.log('Opening modal for archived subject:', archivedSubjectId);
            
            // Update modal title
            document.getElementById('modal_subject_title').textContent = subjectCode + ' - ' + subjectName;
            
            // Show loading state
            const modalContent = document.getElementById('modalContent');
            modalContent.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Loading subject details...</p>
                </div>
            `;
            
            // Show modal
            document.getElementById('viewModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Load term evaluation content
            loadTermEvaluationContent(archivedSubjectId);
        }

        function loadTermEvaluationContent(archivedSubjectId) {
            // Create a form to fetch the archived subject data
            const formData = new FormData();
            formData.append('archived_subject_id', archivedSubjectId);
            formData.append('action', 'get_term_evaluation');
            
            fetch('student-archived-subject.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                document.getElementById('modalContent').innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading term evaluation:', error);
                document.getElementById('modalContent').innerHTML = `
                    <div class="alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Error loading subject details. Please try again.
                    </div>
                `;
            });
        }

        // Close modal when clicking close button
        document.getElementById('closeViewModal').addEventListener('click', function() {
            closeViewModal();
        });

        // Close modal when clicking outside
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeViewModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeViewModal();
            }
        });

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        // Auto-hide success/error messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-success, .alert-error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.1s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 100);
            });
        }, 5000);
    </script>
</body>
</html>