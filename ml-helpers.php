<?php
/**
 * ML Helpers for PLP SmartGrade
 * Contains ML service integration and intervention system
 */

/**
 * Helper function to get student by email
 */
function getStudentByEmail($email) {
    try {
        $student_data = supabaseFetch('students', ['email' => $email]);
        return $student_data && count($student_data) > 0 ? $student_data[0] : null;
    } catch (Exception $e) {
        error_log("Error fetching student: " . $e->getMessage());
        return null;
    }
}

/**
 * Helper function to calculate risk level
 */
function calculateRiskLevel($grade) {
    if ($grade >= 85) return 'low_risk';
    elseif ($grade >= 80) return 'moderate_risk';
    else return 'high_risk';
}

/**
 * Helper function to get risk description
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
 * Helper function to get grade description
 */
function getGradeDescription($grade) {
    if ($grade >= 90) return 'Excellent';
    elseif ($grade >= 85) return 'Very Good';
    elseif ($grade >= 80) return 'Good';
    elseif ($grade >= 75) return 'Satisfactory';
    elseif ($grade >= 70) return 'Passing';
    else return 'Needs Improvement';
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

/**
 * ML Service for predictive analytics
 */
class MLService {
    private static $api_url = 'https://plpsmartgrademvv.onrender.com/predict';
    private static $timeout = 5;
    private static $enabled = true;
    
    /**
     * Get ML predictions for student performance
     */
    public static function getMLPredictions($classStandings, $examScores, $attendanceRecords, $subjectName, $subjectGrade) {
        if (!self::$enabled) {
            return [
                'success' => false, 
                'source' => 'disabled',
                'risk_level' => self::calculateRiskLevel($subjectGrade),
                'risk_description' => self::getRiskDescription(self::calculateRiskLevel($subjectGrade))
            ];
        }
        
        try {
            $studentData = [
                'class_standings' => $classStandings,
                'exam_scores' => $examScores,
                'attendance' => $attendanceRecords,
                'subject' => $subjectName,
                'subject_grade' => $subjectGrade
            ];
            
            $post_data = json_encode($studentData);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => self::$api_url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::$timeout,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code === 200) {
                $result = json_decode($response, true);
                $result['source'] = 'ml_enhanced';
                return $result;
            }
            
        } catch (Exception $e) {
            error_log("ML Service Error: " . $e->getMessage());
        }
        
        return [
            'success' => false, 
            'source' => 'service_unavailable',
            'risk_level' => self::calculateRiskLevel($subjectGrade),
            'risk_description' => self::getRiskDescription(self::calculateRiskLevel($subjectGrade))
        ];
    }
    
    /**
     * Calculate risk level based on grade
     */
    private static function calculateRiskLevel($grade) {
        if ($grade >= 85) return 'low_risk';
        elseif ($grade >= 80) return 'moderate_risk';
        else return 'high_risk';
    }

    /**
     * Get risk description based on risk level
     */
    private static function getRiskDescription($riskLevel) {
        switch ($riskLevel) {
            case 'low_risk': return 'Excellent/Good Performance';
            case 'moderate_risk': return 'Needs Improvement';
            case 'high_risk': return 'Need to Communicate with Professor';
            default: return 'No Data Inputted';
        }
    }
    
    /**
     * Enable or disable ML service
     */
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
}

/**
 * Intervention System for student support
 */
class InterventionSystem {
    
    /**
     * Log student behavior for analysis
     */
    public static function logBehavior($studentId, $behaviorType, $data) {
        try {
            $insert_data = [
                'student_id' => $studentId,
                'behavior_type' => $behaviorType,
                'behavior_data' => json_encode($data),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $result = supabaseInsert('student_behavior_logs', $insert_data);
            return $result !== false;
        } catch (Exception $e) {
            error_log("Behavior logging error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get behavioral insights based on performance data
     */
    public static function getBehavioralInsights($studentId, $subjectId, $currentGrade, $subjectGrade, $riskLevel, $categoryTotals, $term = 'midterm') {
        $insights = [];
        
        if ($currentGrade == 0) {
            $insights[] = [
                'message' => "Welcome to {$term} term! Start adding your scores to get personalized insights.",
                'priority' => 'low',
                'source' => 'system'
            ];
            return $insights;
        }
        
        // Risk level based insights
        switch ($riskLevel) {
            case 'low_risk':
                $insights[] = [
                    'message' => "Low Risk: You're maintaining excellent/good performance. Continue your current study habits.",
                    'priority' => 'low',
                    'source' => 'risk_analysis'
                ];
                break;
            case 'moderate_risk':
                $insights[] = [
                    'message' => "Moderate Risk: Needs improvement. Focus on strengthening weaker areas.",
                    'priority' => 'medium',
                    'source' => 'risk_analysis'
                ];
                break;
            case 'high_risk':
                $insights[] = [
                    'message' => "High Risk: Consider communicating with your professor for additional support.",
                    'priority' => 'high',
                    'source' => 'risk_analysis'
                ];
                break;
        }
        
        // Analyze low scores in categories for behavioral insights
        $lowScoreCategories = [];
        foreach ($categoryTotals as $categoryId => $category) {
            if (!empty($category['low_scores'])) {
                $lowScoreCategories[] = [
                    'name' => $category['name'],
                    'low_scores' => $category['low_scores'],
                    'average' => $category['percentage_score']
                ];
            }
        }
        
        // Generate insights based on low scores
        foreach ($lowScoreCategories as $category) {
            if ($category['average'] < 75) {
                $insights[] = [
                    'message' => "Low scores in {$category['name']}. Focus on improving your performance in this area by reviewing related modules.",
                    'priority' => 'high',
                    'source' => 'score_analysis'
                ];
                
                // Specific feedback for low scores
                foreach ($category['low_scores'] as $lowScore) {
                    if ($lowScore['percentage'] < 60) {
                        $insights[] = [
                            'message' => "Very low score in {$category['name']} - {$lowScore['name']} ({$lowScore['percentage']}%). Consider seeking help from your instructor.",
                            'priority' => 'high',
                            'source' => 'score_analysis'
                        ];
                    }
                }
            }
        }
        
        return $insights;
    }
    
    /**
     * Get interventions based on risk level and performance
     */
    public static function getInterventions($studentId, $subjectId, $riskLevel, $categoryTotals, $term = 'midterm') {
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
                    'message' => "Maintain current study schedule and performance level",
                    'priority' => 'low'
                ];
                break;
            case 'moderate_risk':
                $interventions[] = [
                    'message' => "Increase study time on challenging topics",
                    'priority' => 'medium'
                ];
                $interventions[] = [
                    'message' => "Join study groups for better understanding",
                    'priority' => 'medium'
                ];
                break;
            case 'high_risk':
                $interventions[] = [
                    'message' => "Schedule meeting with professor for guidance",
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => "Request academic advising support",
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => "Develop intensive study plan with tutor",
                    'priority' => 'high'
                ];
                break;
        }
        
        // Category-specific interventions based on low scores
        foreach ($categoryTotals as $categoryId => $category) {
            if ($category['percentage_score'] < 75 && !empty($category['low_scores'])) {
                $interventions[] = [
                    'message' => "Focus on improving {$category['name']} performance through additional practice",
                    'priority' => 'high'
                ];
            }
        }
        
        return $interventions;
    }
    
    /**
     * Get personalized recommendations for improvement
     */
    public static function getRecommendations($studentId, $subjectId, $subjectGrade, $riskLevel, $categoryTotals, $term = 'midterm') {
        $recommendations = [];
        
        if ($subjectGrade == 0) {
            $recommendations[] = [
                'message' => "Start tracking your quizzes, assignments, and projects to monitor your progress",
                'priority' => 'low',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => "Add attendance records regularly to track engagement patterns",
                'priority' => 'low',
                'source' => 'system'
            ];
            return $recommendations;
        }
        
        // Risk level based recommendations
        switch ($riskLevel) {
            case 'low_risk':
                $recommendations[] = [
                    'message' => "Continue effective study strategies for maintained performance",
                    'priority' => 'low',
                    'source' => 'risk_analysis'
                ];
                break;
            case 'moderate_risk':
                $recommendations[] = [
                    'message' => "Focus on improving consistency across all assessments",
                    'priority' => 'medium',
                    'source' => 'risk_analysis'
                ];
                break;
            case 'high_risk':
                $recommendations[] = [
                    'message' => "Communicate with professor immediately for academic support",
                    'priority' => 'high',
                    'source' => 'risk_analysis'
                ];
                $recommendations[] = [
                    'message' => "Utilize all available tutoring and academic resources",
                    'priority' => 'high',
                    'source' => 'system'
                ];
                break;
        }
        
        // Category-specific recommendations based on low scores
        foreach ($categoryTotals as $categoryId => $category) {
            if ($category['percentage_score'] < 75) {
                $recommendations[] = [
                    'message' => "For low {$category['name']} scores: Review related modules and practice more exercises",
                    'priority' => 'high',
                    'source' => 'score_analysis'
                ];
                
                if ($category['name'] === 'Quizzes') {
                    $recommendations[] = [
                        'message' => "For low quiz scores: Read modules thoroughly before attempting quizzes",
                        'priority' => 'high',
                        'source' => 'score_analysis'
                    ];
                } elseif ($category['name'] === 'Assignments') {
                    $recommendations[] = [
                        'message' => "For low assignment scores: Start assignments early and seek feedback",
                        'priority' => 'high',
                        'source' => 'score_analysis'
                    ];
                } elseif ($category['name'] === 'Projects') {
                    $recommendations[] = [
                        'message' => "For low project scores: Break projects into smaller tasks with deadlines",
                        'priority' => 'high',
                        'source' => 'score_analysis'
                    ];
                }
            }
        }
        
        return $recommendations;
    }
}

/**
 * Performance calculation helper functions
 */

/**
 * Calculate archived subject performance
 */
function calculateArchivedSubjectPerformance($archived_subject_id) {
    try {
        // Get all categories for this archived subject
        $categories = supabaseFetch('archived_class_standing_categories', ['archived_subject_id' => $archived_subject_id]);
        
        if (!$categories || !is_array($categories)) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores = supabaseFetch('archived_subject_scores', [
                'archived_category_id' => $category['id'], 
                'score_type' => 'class_standing'
            ]);
            
            if ($scores && is_array($scores) && count($scores) > 0) {
                $hasScores = true;
                $categoryTotal = 0;
                $categoryMax = 0;
                
                foreach ($scores as $score) {
                    $categoryTotal += floatval($score['score_value']);
                    $categoryMax += floatval($score['max_score']);
                }
                
                if ($categoryMax > 0) {
                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                    $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                    $totalClassStanding += $weightedScore;
                }
            }
        }
        
        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }
        
        // Get exam scores from all categories for this archived subject
        foreach ($categories as $category) {
            $exam_scores = supabaseFetch('archived_subject_scores', ['archived_category_id' => $category['id']]);
            
            if ($exam_scores && is_array($exam_scores)) {
                foreach ($exam_scores as $exam) {
                    if (floatval($exam['max_score']) > 0) {
                        $examPercentage = (floatval($exam['score_value']) / floatval($exam['max_score'])) * 100;
                        if ($exam['score_type'] === 'midterm_exam') {
                            $midtermScore = ($examPercentage * 20) / 100;
                            $hasScores = true;
                        } elseif ($exam['score_type'] === 'final_exam') {
                            $finalScore = ($examPercentage * 20) / 100;
                            $hasScores = true;
                        }
                    }
                }
            }
        }
        
        if (!$hasScores) {
            return [
                'overall_grade' => 0,
                'gwa' => 0,
                'class_standing' => 0,
                'exams_score' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        // Calculate overall grade
        $overallGrade = $totalClassStanding + $midtermScore + $finalScore;
        if ($overallGrade > 100) {
            $overallGrade = 100;
        }
        
        // Calculate GWA (General Weighted Average) - Philippine system
        $gwa = calculateGWA($overallGrade);
        
        // Calculate risk level based on GWA
        $riskLevel = 'no-data';
        $riskDescription = 'No Data Inputted';

        if ($gwa <= 1.75) {
            $riskLevel = 'low';
            $riskDescription = 'Low Risk';
        } elseif ($gwa <= 2.50) {
            $riskLevel = 'medium';
            $riskDescription = 'Medium Risk';
        } elseif ($gwa <= 3.00) {
            $riskLevel = 'high';
            $riskDescription = 'High Risk';
        } else {
            $riskLevel = 'failed';
            $riskDescription = 'Failed';
        }
        
        return [
            'overall_grade' => $overallGrade,
            'gwa' => $gwa,
            'class_standing' => $totalClassStanding,
            'exams_score' => $midtermScore + $finalScore,
            'risk_level' => $riskLevel,
            'risk_description' => $riskDescription,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating archived subject performance: " . $e->getMessage());
        return null;
    }
}

/**
 * Calculate current term performance
 */
function calculateCurrentTermPerformance($student_subject_id, $term = 'midterm') {
    try {
        // Get categories for current term
        $categories = supabaseFetch('student_class_standing_categories', [
            'student_subject_id' => $student_subject_id,
            'term_type' => $term
        ]);
        
        if (!$categories) {
            return null;
        }
        
        $totalClassStanding = 0;
        $midtermScore = 0;
        $finalScore = 0;
        $hasScores = false;
        
        // Calculate class standing from categories
        foreach ($categories as $category) {
            $scores = supabaseFetch('student_subject_scores', [
                'category_id' => $category['id'], 
                'score_type' => 'class_standing'
            ]);
            
            if ($scores && is_array($scores) && count($scores) > 0) {
                $hasScores = true;
                $categoryTotal = 0;
                $categoryMax = 0;
                
                foreach ($scores as $score) {
                    $categoryTotal += floatval($score['score_value']);
                    $categoryMax += floatval($score['max_score']);
                }
                
                if ($categoryMax > 0) {
                    $categoryPercentage = ($categoryTotal / $categoryMax) * 100;
                    $weightedScore = ($categoryPercentage * floatval($category['category_percentage'])) / 100;
                    $totalClassStanding += $weightedScore;
                }
            }
        }
        
        // Ensure Class Standing doesn't exceed 60%
        if ($totalClassStanding > 60) {
            $totalClassStanding = 60;
        }
        
        // Get exam scores for current term
        $exam_scores = supabaseFetch('student_subject_scores', [
            'student_subject_id' => $student_subject_id,
            'category_id' => NULL
        ]);
        
        if ($exam_scores && is_array($exam_scores)) {
            foreach ($exam_scores as $exam) {
                if (floatval($exam['max_score']) > 0) {
                    $examPercentage = (floatval($exam['score_value']) / floatval($exam['max_score'])) * 100;
                    if ($exam['score_type'] === 'midterm_exam' && $term === 'midterm') {
                        $midtermScore = ($examPercentage * 40) / 100;
                        $hasScores = true;
                    } elseif ($exam['score_type'] === 'final_exam' && $term === 'final') {
                        $finalScore = ($examPercentage * 40) / 100;
                        $hasScores = true;
                    }
                }
            }
        }
        
        if (!$hasScores) {
            return [
                'overall_grade' => 0,
                'class_standing' => 0,
                'exams_score' => 0,
                'risk_level' => 'no-data',
                'risk_description' => 'No Data Inputted',
                'has_scores' => false
            ];
        }
        
        // Calculate overall grade
        $overallGrade = $totalClassStanding + ($term === 'midterm' ? $midtermScore : $finalScore);
        if ($overallGrade > 100) {
            $overallGrade = 100;
        }
        
        // Calculate risk level
        $riskLevel = calculateRiskLevel($overallGrade);
        $riskDescription = getRiskDescription($riskLevel);
        
        return [
            'overall_grade' => $overallGrade,
            'class_standing' => $totalClassStanding,
            'exams_score' => $term === 'midterm' ? $midtermScore : $finalScore,
            'risk_level' => $riskLevel,
            'risk_description' => $riskDescription,
            'has_scores' => true
        ];
        
    } catch (Exception $e) {
        error_log("Error calculating current term performance: " . $e->getMessage());
        return null;
    }
}
?>