<?php
/**
 * ML Helpers for PLP SmartGrade
 * Contains functions for Python ML integration and fallback logic
 */

/**
 * Call Python ML API for risk prediction
 */
function callPythonMLAPI($student_data) {
    $url = 'http://localhost:5000/predict';
    
    $payload = json_encode([
        'class_standings' => $student_data['class_standings'] ?? [],
        'exam_scores' => $student_data['exam_scores'] ?? [],
        'attendance' => $student_data['attendance'] ?? [],
        'subject' => $student_data['subject'] ?? 'General',
        'term' => $student_data['term'] ?? 'midterm',
        'subject_grade' => $student_data['subject_grade'] ?? 0
    ]);
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $payload,
            'timeout' => 5 // 5 second timeout
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $result = @file_get_contents($url, false, $context);
        
        if ($result === FALSE) {
            error_log("Python ML API call failed - service might be down");
            return [
                'success' => false,
                'error' => 'ML service unavailable'
            ];
        }
        
        $decoded_result = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Python ML API returned invalid JSON");
            return [
                'success' => false,
                'error' => 'Invalid response from ML service'
            ];
        }
        
        return $decoded_result;
        
    } catch (Exception $e) {
        error_log("Python ML API exception: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Fallback function if ML service is down
 */
function calculateRiskLevelSimple($grade) {
    if ($grade >= 85) return 'low_risk';
    elseif ($grade >= 80) return 'moderate_risk';
    else return 'high_risk';
}

/**
 * Get risk description based on risk level
 */
function getRiskDescription($riskLevel) {
    switch ($riskLevel) {
        case 'low_risk': return 'Excellent/Good Performance';
        case 'moderate_risk': return 'Needs Improvement';
        case 'high_risk': return 'Need to Communicate with Professor';
        default: return 'No Data Inputted';
    }
}

/**
 * Generate behavioral insights based on student performance
 */
function generateBehavioralInsights($termGrade, $categoryTotals, $term, $studentId, $subjectId) {
    $insights = [];
    
    if ($termGrade == 0) {
        $insights[] = [
            'message' => "Welcome to {$term} term! Start adding your scores to get personalized insights.",
            'priority' => 'low',
            'source' => 'system'
        ];
        return $insights;
    }
    
    // Grade-based behavioral insights
    if ($termGrade >= 90) {
        $insights[] = [
            'message' => "Excellent {$term} performance! Your consistent effort and time management are paying off.",
            'priority' => 'low',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 85) {
        $insights[] = [
            'message' => "Very good {$term} performance. Your study habits and module review are effective.",
            'priority' => 'low',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 80) {
        $insights[] = [
            'message' => "Good {$term} performance. Consider improving time management for better results.",
            'priority' => 'medium',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 75) {
        $insights[] = [
            'message' => "Satisfactory {$term} performance. Focus on better module reading and time allocation.",
            'priority' => 'medium',
            'source' => 'grade_analysis'
        ];
    } else {
        $insights[] = [
            'message' => "{$term} performance needs improvement. Review time management and study strategies.",
            'priority' => 'high',
            'source' => 'grade_analysis'
        ];
    }
    
    // Category-specific behavioral insights
    $lowCategories = [];
    foreach ($categoryTotals as $categoryId => $category) {
        if ($category['percentage_score'] < 75 && !empty($category['low_scores'])) {
            $lowCategories[] = $category;
        }
    }
    
    if (!empty($lowCategories)) {
        $worstCategory = $lowCategories[0];
        
        $insights[] = [
            'message' => "Low performance in {$worstCategory['name']}. Consider spending more time reviewing related modules.",
            'priority' => 'high',
            'source' => 'score_analysis'
        ];
        
        // Category-type specific insights
        if (stripos($worstCategory['name'], 'quiz') !== false) {
            $insights[] = [
                'message' => "Low quiz scores suggest need for better module reading before assessments.",
                'priority' => 'medium',
                'source' => 'score_analysis'
            ];
        } elseif (stripos($worstCategory['name'], 'assignment') !== false) {
            $insights[] = [
                'message' => "Assignment scores indicate time management improvements needed for deadlines.",
                'priority' => 'medium',
                'source' => 'score_analysis'
            ];
        } elseif (stripos($worstCategory['name'], 'project') !== false) {
            $insights[] = [
                'message' => "Project performance shows need for better planning and milestone tracking.",
                'priority' => 'medium',
                'source' => 'score_analysis'
            ];
        } elseif (stripos($worstCategory['name'], 'attendance') !== false) {
            $insights[] = [
                'message' => "Low attendance affects learning. Regular class participation improves understanding.",
                'priority' => 'high',
                'source' => 'attendance_analysis'
            ];
        }
    }
    
    // Limit to 3-4 insights only
    usort($insights, function($a, $b) {
        $priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
        return $priorityOrder[$b['priority']] - $priorityOrder[$a['priority']];
    });
    
    return array_slice($insights, 0, 3);
}

/**
 * Generate interventions based on risk level
 */
function generateInterventions($riskLevel, $categoryTotals, $term) {
    $interventions = [];
    
    if (!$riskLevel || $riskLevel === 'no-data') {
        $interventions[] = [
            'message' => "Start adding your scores to enable personalized intervention planning.",
            'priority' => 'low'
        ];
        return $interventions;
    }
    
    // Risk level based interventions
    switch ($riskLevel) {
        case 'low_risk':
            $interventions[] = [
                'message' => "Maintain current study schedule and time management practices.",
                'priority' => 'low'
            ];
            $interventions[] = [
                'message' => "Continue regular module review to sustain performance.",
                'priority' => 'low'
            ];
            break;
            
        case 'moderate_risk':
            $interventions[] = [
                'message' => "Increase focused study time on challenging topics.",
                'priority' => 'medium'
            ];
            $interventions[] = [
                'message' => "Join study groups for collaborative learning and better time management.",
                'priority' => 'medium'
            ];
            $interventions[] = [
                'message' => "Schedule regular module reading sessions each week.",
                'priority' => 'medium'
            ];
            break;
            
        case 'high_risk':
            $interventions[] = [
                'message' => "Request immediate academic advising for time management support.",
                'priority' => 'high'
            ];
            $interventions[] = [
                'message' => "Schedule regular tutoring sessions for fundamental concepts.",
                'priority' => 'high'
            ];
            $interventions[] = [
                'message' => "Develop intensive catch-up study schedule with daily module review.",
                'priority' => 'high'
            ];
            $interventions[] = [
                'message' => "Communicate with professor about academic challenges and time constraints.",
                'priority' => 'high'
            ];
            break;
    }
    
    return array_slice($interventions, 0, 3);
}

/**
 * Generate recommendations based on performance
 */
function generateRecommendations($termGrade, $categoryTotals, $riskLevel, $term) {
    $recommendations = [];
    
    if ($termGrade == 0) {
        $recommendations[] = [
            'message' => "Start tracking your quizzes, assignments, and projects to monitor your progress.",
            'priority' => 'low',
            'source' => 'system'
        ];
        $recommendations[] = [
            'message' => "Add attendance records regularly to track engagement patterns.",
            'priority' => 'low',
            'source' => 'system'
        ];
        return $recommendations;
    }
    
    // Grade-based recommendations
    if ($termGrade >= 90) {
        $recommendations[] = [
            'message' => "Excellent {$term} grade! Continue your effective time management and module review strategies.",
            'priority' => 'low',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 85) {
        $recommendations[] = [
            'message' => "Very good {$term} performance. Continue regular module reading and time allocation.",
            'priority' => 'low',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 80) {
        $recommendations[] = [
            'message' => "Good {$term} performance. Improve consistency in module review and time management.",
            'priority' => 'medium',
            'source' => 'grade_analysis'
        ];
    } elseif ($termGrade >= 75) {
        $recommendations[] = [
            'message' => "Satisfactory {$term} grade. Prioritize understanding foundational concepts through better module reading.",
            'priority' => 'high',
            'source' => 'grade_analysis'
        ];
    } else {
        $recommendations[] = [
            'message' => "{$term} grade needs improvement. Focus on core concepts and time management first.",
            'priority' => 'high',
            'source' => 'grade_analysis'
        ];
    }
    
    // Risk-based recommendations
    if ($riskLevel === 'high_risk') {
        $recommendations[] = [
            'message' => "Utilize all available academic support resources for time management and study skills.",
            'priority' => 'high',
            'source' => 'risk_analysis'
        ];
    }
    
    return array_slice($recommendations, 0, 3);
}

/**
 * Get attendance data for ML analysis
 */
function getAttendanceData($categoryTotals) {
    $attendance = [];
    foreach ($categoryTotals as $category) {
        if (strtolower($category['name']) === 'attendance' && !empty($category['scores'])) {
            foreach ($category['scores'] as $score) {
                $attendance[] = $score['score_name'] === 'Present' ? 1 : 0;
            }
        }
    }
    return $attendance;
}
?>