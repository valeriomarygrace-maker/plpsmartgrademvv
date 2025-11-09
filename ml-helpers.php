<?php
class MLService {
    private static $api_url = 'https://plpsmartgrademvv.onrender.com/predict';
    private static $timeout = 5;
    private static $enabled = true;
    
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
    
    // Add these as static methods inside MLService class
    private static function calculateRiskLevel($grade) {
        if ($grade >= 85) return 'low_risk';
        elseif ($grade >= 80) return 'moderate_risk';
        else return 'high_risk';
    }

    private static function getRiskDescription($riskLevel) {
        switch ($riskLevel) {
            case 'low_risk': return 'Excellent/Good Performance';
            case 'moderate_risk': return 'Needs Improvement';
            case 'high_risk': return 'Need to Communicate with Professor';
            default: return 'No Data Inputted';
        }
    }
    
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
}

/**
 * Intervention System - BASED ON RISK LEVELS
 */
class InterventionSystem {
    
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
?>