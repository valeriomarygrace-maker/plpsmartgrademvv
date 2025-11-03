<?php
// ml-helpers.php - CORRECTED VERSION

/**
 * ML Service Integration for Enhanced Predictions
 */
class MLService {
    private static $api_url = 'http://localhost:5000/predict';
    private static $timeout = 5;
    private static $enabled = true;
    
    public static function getMLPredictions($classStandings, $examScores, $attendanceRecords, $subjectName) {
        if (!self::$enabled) {
            return ['success' => false, 'source' => 'disabled'];
        }
        
        try {
            $studentData = [
                'class_standings' => $classStandings,
                'exam_scores' => $examScores,
                'attendance' => $attendanceRecords,
                'subject' => $subjectName
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
        
        return ['success' => false, 'source' => 'service_unavailable'];
    }
    
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
}

/**
 * Enhanced Intervention System with ML Support
 */
class EnhancedInterventionSystem {
    
    public static function getEnhancedInsights($studentId, $subjectId, $classStandings, $examScores, $attendanceRecords, $subjectName) {
        // Always use PHP fallback for now to ensure data displays
        $calculatedGrade = self::calculateOverallGrade($classStandings, $examScores);
        $riskLevel = self::calculateRiskLevel($calculatedGrade);
        $gwa = self::calculateGWA($calculatedGrade);
        
        $baseInsights = [
            'behavioral_insights' => InterventionSystem::getBehavioralInsights($studentId, $subjectId, $calculatedGrade, $riskLevel),
            'interventions' => InterventionSystem::getInterventions($studentId, $subjectId, $riskLevel),
            'recommendations' => InterventionSystem::getRecommendations($studentId, $subjectId, $calculatedGrade, $riskLevel),
            'risk_level' => $riskLevel,
            'overall_grade' => $calculatedGrade,
            'gwa' => $gwa,
            'source' => 'php_fallback'
        ];
        
        return $baseInsights;
    }
    
    private static function calculateOverallGrade($classStandings, $examScores) {
        if (empty($classStandings) && empty($examScores)) {
            return 0;
        }
        
        $classAvg = !empty($classStandings) ? array_sum($classStandings) / count($classStandings) : 0;
        $examAvg = !empty($examScores) ? array_sum($examScores) / count($examScores) : 0;
        
        // If only one type of score exists, use it directly
        if (empty($classStandings)) {
            return round($examAvg, 1);
        }
        if (empty($examScores)) {
            return round($classAvg, 1);
        }
        
        $overallGrade = ($classAvg * 0.6) + ($examAvg * 0.4);
        return round(max(0, min(100, $overallGrade)), 1);
    }
    
    private static function calculateGWA($grade) {
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
    
    private static function calculateRiskLevel($grade) {
        if ($grade >= 75) return 'low';
        if ($grade >= 60) return 'medium';
        return 'high';
    }
}

/**
 * Intervention System - CORRECTED VERSION
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
    
    public static function getBehavioralInsights($studentId, $subjectId, $currentGrade = 0, $riskLevel = 'medium') {
        $insights = [];
        
        // Grade-based insights
        if ($currentGrade > 0) {
            if ($currentGrade >= 90) {
                $insights[] = [
                    'message' => 'Excellent academic performance! Maintain your consistent study habits.',
                    'priority' => 'low'
                ];
            } elseif ($currentGrade >= 80) {
                $insights[] = [
                    'message' => 'Strong performance. Focus on maintaining consistency in all assessments.',
                    'priority' => 'low'
                ];
            } elseif ($currentGrade >= 70) {
                $insights[] = [
                    'message' => 'Good foundation. Identify specific areas for improvement to reach the next level.',
                    'priority' => 'medium'
                ];
            } else {
                $insights[] = [
                    'message' => 'Need to focus on core concepts and seek additional academic support.',
                    'priority' => 'high'
                ];
            }
            
            // Risk level insights
            if ($riskLevel === 'high') {
                $insights[] = [
                    'message' => 'High risk detected. Consider immediate academic intervention and tutoring.',
                    'priority' => 'high'
                ];
            } elseif ($riskLevel === 'medium') {
                $insights[] = [
                    'message' => 'Medium risk level. Regular review sessions recommended.',
                    'priority' => 'medium'
                ];
            }
        } else {
            $insights[] = [
                'message' => 'Start adding your scores to get personalized behavioral insights.',
                'priority' => 'low'
            ];
        }
        
        // General insights
        $insights[] = [
            'message' => 'Regular practice and consistent study schedule improve long-term retention.',
            'priority' => 'medium'
        ];
        
        return $insights;
    }
    
    public static function getInterventions($studentId, $subjectId, $riskLevel) {
        $interventions = [];
        
        switch ($riskLevel) {
            case 'high':
                $interventions[] = [
                    'message' => 'Schedule immediate meeting with academic advisor',
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => 'Create intensive study plan with daily goals',
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => 'Request tutoring sessions for difficult topics',
                    'priority' => 'medium'
                ];
                break;
                
            case 'medium':
                $interventions[] = [
                    'message' => 'Join study group for collaborative learning',
                    'priority' => 'medium'
                ];
                $interventions[] = [
                    'message' => 'Increase study time by 30 minutes daily',
                    'priority' => 'medium'
                ];
                $interventions[] = [
                    'message' => 'Focus on weak areas identified in recent assessments',
                    'priority' => 'medium'
                ];
                break;
                
            case 'low':
                $interventions[] = [
                    'message' => 'Maintain current effective study habits',
                    'priority' => 'low'
                ];
                $interventions[] = [
                    'message' => 'Consider exploring advanced topics in the subject',
                    'priority' => 'low'
                ];
                $interventions[] = [
                    'message' => 'Help peers who may be struggling with concepts',
                    'priority' => 'low'
                ];
                break;
                
            default:
                $interventions[] = [
                    'message' => 'Continue tracking progress and maintain consistent study habits',
                    'priority' => 'low'
                ];
                break;
        }
        
        return $interventions;
    }
    
    public static function getRecommendations($studentId, $subjectId, $overallGrade, $riskLevel = 'medium') {
        $recommendations = [];
        
        // Grade-based recommendations
        if ($overallGrade >= 90) {
            $recommendations[] = [
                'message' => 'Excellent work! Consider mentoring peers or exploring advanced topics.',
                'priority' => 'low'
            ];
        } elseif ($overallGrade >= 80) {
            $recommendations[] = [
                'message' => 'Strong performance. Focus on maintaining consistency across all assessments.',
                'priority' => 'low'
            ];
        } elseif ($overallGrade >= 70) {
            $recommendations[] = [
                'message' => 'Good progress. Identify specific weak areas for targeted improvement.',
                'priority' => 'medium'
            ];
        } else {
            $recommendations[] = [
                'message' => 'Focus on foundational concepts before attempting advanced topics.',
                'priority' => 'high'
            ];
        }
        
        // Risk-based recommendations
        if ($riskLevel === 'high') {
            $recommendations[] = [
                'message' => 'Prioritize understanding core concepts over advanced topics.',
                'priority' => 'high'
            ];
            $recommendations[] = [
                'message' => 'Break down complex topics into smaller, manageable parts.',
                'priority' => 'high'
            ];
        }
        
        // General study recommendations
        $recommendations[] = [
            'message' => 'Review material regularly instead of cramming before exams.',
            'priority' => 'medium'
        ];
        $recommendations[] = [
            'message' => 'Practice with past papers and sample questions.',
            'priority' => 'medium'
        ];
        
        return $recommendations;
    }
}
?>