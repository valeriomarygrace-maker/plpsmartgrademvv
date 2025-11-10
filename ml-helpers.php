<?php
// Add these functions to ml-helpers.php

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
?>