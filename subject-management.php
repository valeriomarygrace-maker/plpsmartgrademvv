<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

// Get subject ID and term from URL
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$term = isset($_GET['term']) ? $_GET['term'] : '';

if (!$subject_id || !in_array($term, ['midterm', 'final'])) {
    header('Location: student-subjects.php');
    exit;
}

// Get student and subject information
$student = null;
$subject = null;
$term_evaluation = null;

try {
    $student = getStudentByEmail($_SESSION['user_email']);
    
    // Verify the subject belongs to the student
    $subject_record = supabaseFetch('student_subjects', [
        'id' => $subject_id, 
        'student_id' => $student['id']
    ]);
    
    if (!$subject_record || count($subject_record) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $subject_record = $subject_record[0];
    $subject_info = supabaseFetch('subjects', ['id' => $subject_record['subject_id']]);
    
    if (!$subject_info || count($subject_info) === 0) {
        header('Location: student-subjects.php');
        exit;
    }
    
    $subject = array_merge($subject_record, $subject_info[0]);
    
    // Check if term evaluation exists, if not create it
    $term_evaluation = supabaseFetch('term_evaluations', [
        'student_subject_id' => $subject_id,
        'term_type' => $term
    ]);
    
    if (!$term_evaluation || count($term_evaluation) === 0) {
        // Create new term evaluation
        $term_evaluation_data = [
            'student_subject_id' => $subject_id,
            'term_type' => $term,
            'class_standing_total' => 0,
            'exam_score' => 0,
            'term_grade' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $term_evaluation = supabaseInsert('term_evaluations', $term_evaluation_data);
        if ($term_evaluation) {
            $term_evaluation_id = $term_evaluation['id'];
        } else {
            throw new Exception("Failed to create term evaluation");
        }
    } else {
        $term_evaluation = $term_evaluation[0];
        $term_evaluation_id = $term_evaluation['id'];
    }
    
} catch (Exception $e) {
    header('Location: student-subjects.php');
    exit;
}

// Handle form submissions
$success_message = '';
$error_message = '';

// Add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $category_name = trim($_POST['category_name']);
    $category_percentage = floatval($_POST['category_percentage']);
    
    try {
        // Get current total percentage
        $existing_categories = supabaseFetch('class_standing_categories', [
            'term_evaluation_id' => $term_evaluation_id
        ]);
        
        $current_total = 0;
        if ($existing_categories) {
            foreach ($existing_categories as $cat) {
                $current_total += floatval($cat['category_percentage']);
            }
        }
        
        $new_total = $current_total + $category_percentage;
        
        if ($new_total > 60) {
            $error_message = "Total class standing percentage cannot exceed 60%. Current total: {$current_total}%";
        } else {
            $category_data = [
                'term_evaluation_id' => $term_evaluation_id,
                'category_name' => $category_name,
                'category_percentage' => $category_percentage,
                'current_score' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $result = supabaseInsert('class_standing_categories', $category_data);
            
            if ($result) {
                $success_message = 'Category added successfully!';
                header("Location: subject-management.php?subject_id=$subject_id&term=$term");
                exit;
            } else {
                $error_message = 'Failed to add category.';
            }
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Update exam score
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_exam_score'])) {
    $exam_score = floatval($_POST['exam_score']);
    
    try {
        $update_data = ['exam_score' => $exam_score];
        $result = supabaseUpdate('term_evaluations', $update_data, ['id' => $term_evaluation_id]);
        
        if ($result) {
            $success_message = 'Exam score updated successfully!';
            header("Location: subject-management.php?subject_id=$subject_id&term=$term");
            exit;
        } else {
            $error_message = 'Failed to update exam score.';
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Add score to category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_score'])) {
    $category_id = intval($_POST['category_id']);
    $score_name = trim($_POST['score_name']);
    $score_value = floatval($_POST['score_value']);
    $max_score = floatval($_POST['max_score']);
    
    try {
        $score_data = [
            'category_id' => $category_id,
            'score_name' => $score_name,
            'score_value' => $score_value,
            'max_score' => $max_score,
            'percentage' => ($max_score > 0) ? ($score_value / $max_score) * 100 : 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $result = supabaseInsert('category_scores', $score_data);
        
        if ($result) {
            // Update category current score
            $category_scores = supabaseFetch('category_scores', ['category_id' => $category_id]);
            $total_percentage = 0;
            $score_count = 0;
            
            if ($category_scores) {
                foreach ($category_scores as $score) {
                    $total_percentage += floatval($score['percentage']);
                    $score_count++;
                }
                $average_percentage = $total_percentage / $score_count;
            } else {
                $average_percentage = 0;
            }
            
            supabaseUpdate('class_standing_categories', 
                ['current_score' => $average_percentage], 
                ['id' => $category_id]
            );
            
            $success_message = 'Score added successfully!';
            header("Location: subject-management.php?subject_id=$subject_id&term=$term");
            exit;
        } else {
            $error_message = 'Failed to add score.';
        }
    } catch (Exception $e) {
        $error_message = 'Database error: ' . $e->getMessage();
    }
}

// Get categories and scores
$categories = [];
$class_standing_total = 0;
$exam_score = 0;

try {
    $categories = supabaseFetch('class_standing_categories', [
        'term_evaluation_id' => $term_evaluation_id
    ]);
    
    if ($categories) {
        foreach ($categories as &$category) {
            $category_scores = supabaseFetch('category_scores', [
                'category_id' => $category['id']
            ]);
            $category['scores'] = $category_scores ?: [];
            
            // Calculate category contribution to class standing
            $category_percentage = floatval($category['category_percentage']);
            $current_score = floatval($category['current_score']);
            $category['contribution'] = ($category_percentage * $current_score) / 100;
            $class_standing_total += $category['contribution'];
        }
    }
    
    // Get exam score
    $exam_score = floatval($term_evaluation['exam_score']);
    
    // Calculate term grade
    $exam_contribution = ($exam_score * 40) / 100;
    $term_grade = $class_standing_total + $exam_contribution;
    
    // Update term evaluation with calculated values
    supabaseUpdate('term_evaluations', [
        'class_standing_total' => $class_standing_total,
        'term_grade' => $term_grade
    ], ['id' => $term_evaluation_id]);
    
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($term); ?> Evaluation - PLP SmartGrade</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --plp-green: #006341;
            --plp-green-light: #008856;
            --plp-green-lighter: #e0f2e9;
            --plp-green-pale: #f5fbf8;
            --plp-green-gradient: linear-gradient(135deg, #006341 0%, #008856 100%);
            --midterm-color: #3b82f6;
            --final-color: #ef4444;
            --text-dark: #2d3748;
            --text-medium: #4a5568;
            --text-light: #718096;
            --border-radius: 12px;
            --border-radius-lg: 16px;
            --box-shadow: 0 4px 12px rgba(0, 99, 65, 0.1);
            --box-shadow-lg: 0 8px 24px rgba(0, 99, 65, 0.15);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--plp-green-pale);
            color: var(--text-dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--plp-green-gradient);
            color: white;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(-3px);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .term-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            background: <?php echo $term === 'midterm' ? 'var(--midterm-color)' : 'var(--final-color)'; ?>;
            color: white;
        }

        .subject-info {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            border-left: 4px solid var(--plp-green);
        }

        .subject-code {
            font-size: 1.25rem;
            font-weight: 700;
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
            gap: 0.75rem;
            color: var(--text-medium);
        }

        .detail-item i {
            color: var(--plp-green);
            width: 16px;
        }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-top: 4px solid <?php echo $term === 'midterm' ? 'var(--midterm-color)' : 'var(--final-color)'; ?>;
        }

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
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .percentage-display {
            font-size: 2rem;
            font-weight: 700;
            text-align: center;
            margin: 1rem 0;
            color: <?php echo $term === 'midterm' ? 'var(--midterm-color)' : 'var(--final-color)'; ?>;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: <?php echo $term === 'midterm' ? 'var(--midterm-color)' : 'var(--final-color)'; ?>;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .categories-list {
            margin-top: 1.5rem;
        }

        .category-item {
            background: var(--plp-green-pale);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            border-left: 3px solid var(--plp-green);
        }

        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .category-name {
            font-weight: 600;
            color: var(--text-dark);
        }

        .category-percentage {
            color: var(--plp-green);
            font-weight: 600;
        }

        .category-score {
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .scores-list {
            margin-top: 0.5rem;
            padding-left: 1rem;
        }

        .score-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.25rem 0;
            font-size: 0.85rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-medium);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--plp-green-lighter);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .form-input:focus {
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

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left-color: #10b981;
        }

        .alert-error {
            background: #fed7d7;
            color: #c53030;
            border-left-color: #e53e3e;
        }

        .grade-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .grade-item {
            text-align: center;
            padding: 1rem;
            background: var(--plp-green-pale);
            border-radius: var(--border-radius);
        }

        .grade-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--plp-green);
        }

        .grade-label {
            font-size: 0.9rem;
            color: var(--text-medium);
            margin-top: 0.25rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .grid-layout {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .subject-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="termevaluation.php?subject_id=<?php echo $subject_id; ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Term Evaluation
            </a>
            <div class="page-title">
                <?php echo ucfirst($term); ?> Evaluation Management
            </div>
            <div class="term-badge">
                <?php echo strtoupper($term); ?> TERM
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="subject-info">
            <div class="subject-code"><?php echo htmlspecialchars($subject['subject_code']); ?></div>
            <div class="subject-name"><?php echo htmlspecialchars($subject['subject_name']); ?></div>
            <div class="subject-details">
                <div class="detail-item">
                    <i class="fas fa-user-tie"></i>
                    <span><strong>Professor:</strong> <?php echo htmlspecialchars($subject['professor_name']); ?></span>
                </div>
                <div class="detail-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span><strong>Schedule:</strong> <?php echo htmlspecialchars($subject['schedule']); ?></span>
                </div>
            </div>
        </div>

        <div class="grade-summary">
            <div class="grade-item">
                <div class="grade-value"><?php echo number_format($class_standing_total, 1); ?>%</div>
                <div class="grade-label">Class Standing</div>
            </div>
            <div class="grade-item">
                <div class="grade-value"><?php echo number_format($exam_score, 1); ?>%</div>
                <div class="grade-label"><?php echo ucfirst($term); ?> Exam</div>
            </div>
            <div class="grade-item">
                <div class="grade-value"><?php echo number_format($term_grade, 1); ?>%</div>
                <div class="grade-label"><?php echo ucfirst($term); ?> Grade</div>
            </div>
        </div>

        <div class="grid-layout">
            <!-- Class Standing Section -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-chart-bar"></i>
                        Class Standing (60%)
                    </div>
                    <div class="percentage-display">
                        <?php echo number_format($class_standing_total, 1); ?>%
                    </div>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, ($class_standing_total / 60) * 100); ?>%"></div>
                </div>

                <form action="subject-management.php?subject_id=<?php echo $subject_id; ?>&term=<?php echo $term; ?>" method="POST">
                    <div class="form-group">
                        <label class="form-label">Category Name</label>
                        <input type="text" name="category_name" class="form-input" placeholder="e.g., Quizzes, Assignments, Attendance" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Percentage (Max: 60%)</label>
                        <input type="number" name="category_percentage" class="form-input" min="1" max="60" step="0.1" placeholder="e.g., 20" required>
                    </div>
                    
                    <button type="submit" name="add_category" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </form>

                <div class="categories-list">
                    <?php if ($categories): ?>
                        <?php foreach ($categories as $category): ?>
                            <div class="category-item">
                                <div class="category-header">
                                    <div class="category-name"><?php echo htmlspecialchars($category['category_name']); ?></div>
                                    <div class="category-percentage"><?php echo $category['category_percentage']; ?>%</div>
                                </div>
                                <div class="category-score">
                                    Current Score: <?php echo number_format($category['current_score'], 1); ?>%
                                    (Contribution: <?php echo number_format($category['contribution'], 1); ?>%)
                                </div>
                                
                                <!-- Add Score Form -->
                                <form action="subject-management.php?subject_id=<?php echo $subject_id; ?>&term=<?php echo $term; ?>" method="POST" style="margin-top: 0.5rem;">
                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 0.5rem; align-items: end;">
                                        <div>
                                            <label class="form-label" style="font-size: 0.8rem;">Score Name</label>
                                            <input type="text" name="score_name" class="form-input" placeholder="e.g., Quiz 1" required style="padding: 0.5rem; font-size: 0.9rem;">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size: 0.8rem;">Score</label>
                                            <input type="number" name="score_value" class="form-input" min="0" step="0.1" placeholder="Score" required style="padding: 0.5rem; font-size: 0.9rem;">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size: 0.8rem;">Max Score</label>
                                            <input type="number" name="max_score" class="form-input" min="1" step="0.1" placeholder="Max" required style="padding: 0.5rem; font-size: 0.9rem;">
                                        </div>
                                        <div>
                                            <button type="submit" name="add_score" class="btn btn-primary" style="padding: 0.5rem; font-size: 0.9rem;">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <?php if (!empty($category['scores'])): ?>
                                    <div class="scores-list">
                                        <?php foreach ($category['scores'] as $score): ?>
                                            <div class="score-item">
                                                <span><?php echo htmlspecialchars($score['score_name']); ?></span>
                                                <span><?php echo $score['score_value']; ?>/<?php echo $score['max_score']; ?> (<?php echo number_format($score['percentage'], 1); ?>%)</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: var(--text-light); padding: 2rem;">
                            <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                            <p>No categories added yet. Add categories to track your class standing.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Exam Section -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-file-alt"></i>
                        <?php echo ucfirst($term); ?> Exam (40%)
                    </div>
                    <div class="percentage-display">
                        <?php echo number_format($exam_score, 1); ?>%
                    </div>
                </div>

                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min(100, $exam_score); ?>%"></div>
                </div>

                <form action="subject-management.php?subject_id=<?php echo $subject_id; ?>&term=<?php echo $term; ?>" method="POST">
                    <div class="form-group">
                        <label class="form-label">Exam Score (0-100%)</label>
                        <input type="number" name="exam_score" class="form-input" min="0" max="100" step="0.1" 
                               value="<?php echo $exam_score; ?>" placeholder="Enter exam score" required>
                    </div>
                    
                    <button type="submit" name="update_exam_score" class="btn btn-primary" style="width: 100%;">
                        <i class="fas fa-save"></i> Update Exam Score
                    </button>
                </form>

                <div style="margin-top: 2rem; padding: 1rem; background: var(--plp-green-pale); border-radius: var(--border-radius);">
                    <h4 style="color: var(--plp-green); margin-bottom: 0.5rem;">Grading Formula</h4>
                    <p style="color: var(--text-medium); font-size: 0.9rem; line-height: 1.5;">
                        <strong>Term Grade = Class Standing (60%) + Exam (40%)</strong><br>
                        Class Standing: Sum of all category contributions<br>
                        Category Contribution = (Category Percentage × Current Score) ÷ 100
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>