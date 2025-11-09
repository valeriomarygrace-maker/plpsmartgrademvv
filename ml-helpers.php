<?php
require_once 'config.php';

/**
 * ML Helper Functions for Student Performance Analysis
 */

/**
 * Calculate risk level based on grade percentage
 */
function calculateRiskLevel($grade) {
    if ($grade >= 85) return 'low_risk';
    elseif ($grade >= 80) return 'moderate_risk';
    else return 'high_risk';
}

/**
 * Get risk description
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
 * Generate behavioral insights based on performance data
 */
function generateBehavioralInsights($categories, $scores, $overallGrade) {
    $insights = [];
    
    // Check for attendance issues
    $attendanceCategory = array_filter($categories, function($cat) {
        return strtolower($cat['category_name']) === 'attendance';
    });
    
    if (!empty($attendanceCategory)) {
        $attendanceCategory = reset($attendanceCategory);
        $attendanceScores = array_filter($scores, function($score) use ($attendanceCategory) {
            return $score['category_id'] == $attendanceCategory['id'];
        });
        
        $presentCount = count(array_filter($attendanceScores, function($score) {
            return strtolower($score['score_name']) === 'present';
        }));
        
        $totalAttendance = count($attendanceScores);
        $attendanceRate = $totalAttendance > 0 ? ($presentCount / $totalAttendance) * 100 : 0;
        
        if ($attendanceRate < 80) {
            $insights[] = [
                'message' => "Low attendance rate ({$attendanceRate}%) may be affecting your performance. Consider improving class attendance.",
                'priority' => 'high',
                'source' => 'attendance'
            ];
        }
    }
    
    // Check for consistent low scores in specific categories
    foreach ($categories as $category) {
        if (strtolower($category['category_name']) === 'attendance') continue;
        
        $categoryScores = array_filter($scores, function($score) use ($category) {
            return $score['category_id'] == $category['id'];
        });
        
        if (count($categoryScores) > 0) {
            $lowScores = array_filter($categoryScores, function($score) {
                $percentage = ($score['score_value'] / $score['max_score']) * 100;
                return $percentage < 75;
            });
            
            $lowScorePercentage = (count($lowScores) / count($categoryScores)) * 100;
            
            if ($lowScorePercentage > 50) {
                $categoryName = $category['category_name'];
                $insights[] = [
                    'message' => "Struggling with {$categoryName} - {$lowScorePercentage}% of scores are below 75%. Consider seeking help in this area.",
                    'priority' => 'medium',
                    'source' => 'category_analysis'
                ];
            }
        }
    }
    
    // Overall performance insight
    if ($overallGrade > 0) {
        if ($overallGrade < 75) {
            $insights[] = [
                'message' => "Overall performance needs significant improvement. Consider meeting with your professor to discuss strategies.",
                'priority' => 'high',
                'source' => 'overall_performance'
            ];
        } elseif ($overallGrade < 80) {
            $insights[] = [
                'message' => "Performance is satisfactory but has room for improvement. Focus on consistent preparation.",
                'priority' => 'medium',
                'source' => 'overall_performance'
            ];
        } elseif ($overallGrade >= 90) {
            $insights[] = [
                'message' => "Excellent performance! Maintain your current study habits and consider helping classmates.",
                'priority' => 'low',
                'source' => 'overall_performance'
            ];
        }
    }
    
    // If no specific insights, provide general encouragement
    if (empty($insights)) {
        $insights[] = [
            'message' => "Continue tracking your scores regularly to identify areas for improvement.",
            'priority' => 'low',
            'source' => 'general'
        ];
    }
    
    return $insights;
}

/**
 * Generate intervention recommendations
 */
function generateInterventions($riskLevel, $behavioralInsights) {
    $interventions = [];
    
    switch ($riskLevel) {
        case 'high_risk':
            $interventions[] = [
                'message' => 'Schedule a meeting with your professor immediately to discuss performance concerns.',
                'priority' => 'high'
            ];
            $interventions[] = [
                'message' => 'Utilize campus tutoring services for additional academic support.',
                'priority' => 'high'
            ];
            $interventions[] = [
                'message' => 'Develop a structured study schedule with dedicated time for this subject.',
                'priority' => 'medium'
            ];
            break;
            
        case 'moderate_risk':
            $interventions[] = [
                'message' => 'Review course materials regularly and don\'t wait until exams to study.',
                'priority' => 'medium'
            ];
            $interventions[] = [
                'message' => 'Form a study group with classmates to reinforce learning.',
                'priority' => 'medium'
            ];
            break;
            
        case 'low_risk':
            $interventions[] = [
                'message' => 'Maintain current study habits and continue active participation in class.',
                'priority' => 'low'
            ];
            $interventions[] = [
                'message' => 'Consider taking on leadership roles in group projects or study sessions.',
                'priority' => 'low'
            ];
            break;
            
        default:
            $interventions[] = [
                'message' => 'Start by adding your scores to get personalized recommendations.',
                'priority' => 'low'
            ];
    }
    
    // Add interventions based on specific behavioral insights
    foreach ($behavioralInsights as $insight) {
        if ($insight['priority'] === 'high') {
            $interventions[] = [
                'message' => 'Address this issue as a priority in your study plan.',
                'priority' => 'high'
            ];
        }
    }
    
    return $interventions;
}

/**
 * Generate study recommendations based on performance patterns
 */
function generateRecommendations($categories, $scores, $overallGrade) {
    $recommendations = [];
    
    // Time management recommendation
    $totalScores = count($scores);
    if ($totalScores > 0) {
        $recentScores = array_slice($scores, -5); // Last 5 scores
        $recentPerformance = array_reduce($recentScores, function($carry, $score) {
            return $carry + (($score['score_value'] / $score['max_score']) * 100);
        }, 0) / count($recentScores);
        
        if ($recentPerformance < $overallGrade) {
            $recommendations[] = [
                'message' => 'Recent performance shows a declining trend. Review recent topics and seek clarification.',
                'priority' => 'medium',
                'source' => 'trend_analysis'
            ];
        }
    }
    
    // Category-specific recommendations
    foreach ($categories as $category) {
        $categoryScores = array_filter($scores, function($score) use ($category) {
            return $score['category_id'] == $category['id'];
        });
        
        if (count($categoryScores) > 2) { // Need enough data for pattern recognition
            $categoryPerformance = array_reduce($categoryScores, function($carry, $score) {
                return $carry + (($score['score_value'] / $score['max_score']) * 100);
            }, 0) / count($categoryScores);
            
            if ($categoryPerformance < 70) {
                $categoryName = $category['category_name'];
                $recommendations[] = [
                    'message' => "Focus on improving {$categoryName} skills through additional practice and review.",
                    'priority' => 'high',
                    'source' => 'category_performance'
                ];
            }
        }
    }
    
    // General study recommendations based on overall grade
    if ($overallGrade > 0) {
        if ($overallGrade < 75) {
            $recommendations[] = [
                'message' => 'Consider re-evaluating your study methods and seek academic advising.',
                'priority' => 'high',
                'source' => 'overall_performance'
            ];
        } elseif ($overallGrade < 85) {
            $recommendations[] = [
                'message' => 'Focus on consistent review and practice to move from good to excellent performance.',
                'priority' => 'medium',
                'source' => 'overall_performance'
            ];
        }
    }
    
    // Default recommendation if no specific ones
    if (empty($recommendations)) {
        $recommendations[] = [
            'message' => 'Continue your current study approach and regularly monitor your progress.',
            'priority' => 'low',
            'source' => 'general'
        ];
    }
    
    return $recommendations;
}

/**
 * Calculate performance metrics for a subject
 */
function calculatePerformanceMetrics($categories, $scores) {
    $metrics = [
        'total_class_standing' => 0,
        'has_scores' => false,
        'category_breakdown' => []
    ];
    
    foreach ($categories as $category) {
        $categoryScores = array_filter($scores, function($score) use ($category) {
            return $score['category_id'] == $category['id'];
        });
        
        $categoryTotal = 0;
        $categoryMax = 0;
        
        foreach ($categoryScores as $score) {
            $categoryTotal += $score['score_value'];
            $categoryMax += $score['max_score'];
        }
        
        if ($categoryMax > 0) {
            $metrics['has_scores'] = true;
            $percentage = ($categoryTotal / $categoryMax) * 100;
            $weighted = ($percentage * $category['category_percentage']) / 100;
            $metrics['total_class_standing'] += $weighted;
            
            $metrics['category_breakdown'][$category['category_name']] = [
                'percentage' => $percentage,
                'weighted' => $weighted,
                'scores_count' => count($categoryScores)
            ];
        }
    }
    
    // Cap class standing at 60%
    if ($metrics['total_class_standing'] > 60) {
        $metrics['total_class_standing'] = 60;
    }
    
    return $metrics;
}

/**
 * Predict final grade based on current performance
 */
function predictFinalGrade($currentGrade, $remainingWeight = 40) {
    if ($currentGrade <= 0) return 0;
    
    // Simple prediction: assume similar performance on remaining assessments
    $currentWeight = 100 - $remainingWeight;
    $currentPercentage = ($currentGrade / $currentWeight) * 100;
    $predictedRemaining = ($currentPercentage * $remainingWeight) / 100;
    
    return $currentGrade + $predictedRemaining;
}

/**
 * Get grade improvement suggestions
 */
function getImprovementSuggestions($currentGrade, $targetGrade = 85) {
    $suggestions = [];
    
    if ($currentGrade < $targetGrade) {
        $improvementNeeded = $targetGrade - $currentGrade;
        
        $suggestions[] = "Need to improve by {$improvementNeeded} percentage points to reach target grade.";
        
        if ($improvementNeeded > 10) {
            $suggestions[] = "Focus on major assessments and seek professor guidance.";
        } elseif ($improvementNeeded > 5) {
            $suggestions[] = "Consistent performance on remaining assessments should help reach target.";
        } else {
            $suggestions[] = "Small improvements in upcoming assignments can help reach target.";
        }
    } else {
        $suggestions[] = "You're meeting or exceeding your target grade. Maintain this performance!";
    }
    
    return $suggestions;
}
?>