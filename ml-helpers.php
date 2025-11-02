<?php
// ml-helpers.php - UPDATED FOR SUPABASE WITH GWA

// =============================================================================
// NEW ML INTEGRATION CLASSES - ADD THESE AT THE TOP
// =============================================================================

/**
 * ML Service Integration for Enhanced Predictions
 */
class MLService {
    private static $api_url = 'http://localhost:5000/predict';
    private static $timeout = 5;
    private static $enabled = true;
    
    /**
     * Get ML-enhanced predictions for student
     */
    public static function getMLPredictions($classStandings, $examScores, $attendanceRecords, $subjectName) {
        // Check if ML service is enabled
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
                CURLOPT_TIMEOUT => self::$timeout
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
    
    /**
     * Enable/disable ML service
     */
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
    
    /**
     * Check if ML service is available
     */
    public static function isAvailable() {
        if (!self::$enabled) {
            return false;
        }
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => str_replace('/predict', '/health', self::$api_url),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3
            ]);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $http_code === 200;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Enhanced Intervention System with ML Support
 */
class EnhancedInterventionSystem extends InterventionSystem {
    
    /**
     * Get enhanced insights with ML predictions
     */
    public static function getEnhancedInsights($studentId, $subjectId, $classStandings, $examScores, $attendanceRecords, $subjectName) {
        $baseInsights = [
            'behavioral_insights' => parent::getBehavioralInsights($studentId, $subjectId),
            'interventions' => [],
            'recommendations' => [],
            'risk_level' => 'medium', // default
            'overall_grade' => 0,
            'gwa' => 0
        ];
        
        // Get ML predictions
        $mlResult = MLService::getMLPredictions($classStandings, $examScores, $attendanceRecords, $subjectName);
        
        if ($mlResult['success']) {
            // Use ML risk level if available
            $riskLevel = $mlResult['risk_level'];
            $overallGrade = $mlResult['overall_grade'] ?? self::calculateOverallGrade($classStandings, $examScores);
            $gwa = $mlResult['gwa'] ?? self::calculateGWA($overallGrade);
            
            $baseInsights['ml_risk_level'] = $riskLevel;
            $baseInsights['risk_level'] = $riskLevel;
            $baseInsights['overall_grade'] = $overallGrade;
            $baseInsights['gwa'] = $gwa;
            $baseInsights['ml_confidence'] = $mlResult['confidence'];
            $baseInsights['ml_grade'] = $mlResult['overall_grade'] ?? null;
            $baseInsights['ml_gwa'] = $mlResult['gwa'] ?? null;
            
            // Add ML insights to behavioral insights
            if (!empty($mlResult['behavioral_insights'])) {
                foreach ($mlResult['behavioral_insights'] as $mlInsight) {
                    $baseInsights['behavioral_insights'][] = [
                        'message' => $mlInsight,
                        'priority' => 'medium',
                        'source' => 'ml'
                    ];
                }
            }
            
            // Get interventions based on ML risk level
            $baseInsights['interventions'] = parent::getInterventions($studentId, $subjectId, $riskLevel);
            
            // Combine ML recommendations with base recommendations
            $baseRecommendations = parent::getRecommendations($studentId, $subjectId, $overallGrade);
            $mlRecommendations = $mlResult['recommendations'] ?? [];
            
            // Convert ML recommendations to same format
            $mlRecsFormatted = array_map(function($rec) {
                return ['message' => $rec, 'priority' => 'medium', 'source' => 'ml'];
            }, array_slice($mlRecommendations, 0, 3)); // Limit to 3 ML recommendations
            
            $baseInsights['recommendations'] = array_merge($baseRecommendations, $mlRecsFormatted);
            $baseInsights['source'] = 'ml_enhanced';
            
        } else {
            // Fallback to original PHP logic
            $calculatedGrade = self::calculateOverallGrade($classStandings, $examScores);
            $riskLevel = self::calculateRiskLevel($calculatedGrade);
            $gwa = self::calculateGWA($calculatedGrade);
            
            $baseInsights['risk_level'] = $riskLevel;
            $baseInsights['overall_grade'] = $calculatedGrade;
            $baseInsights['gwa'] = $gwa;
            $baseInsights['interventions'] = parent::getInterventions($studentId, $subjectId, $riskLevel);
            $baseInsights['recommendations'] = parent::getRecommendations($studentId, $subjectId, $calculatedGrade);
            $baseInsights['source'] = 'php_fallback';
            $baseInsights['fallback_reason'] = $mlResult['source'] ?? 'service_unavailable';
        }
        
        return $baseInsights;
    }
    
    /**
     * Calculate overall grade (60% class standing + 40% exams)
     */
    private static function calculateOverallGrade($classStandings, $examScores) {
        if (empty($classStandings) && empty($examScores)) {
            return 0;
        }
        
        $classAvg = !empty($classStandings) ? array_sum($classStandings) / count($classStandings) : 70;
        $examAvg = !empty($examScores) ? array_sum($examScores) / count($examScores) : 70;
        
        $overallGrade = ($classAvg * 0.6) + ($examAvg * 0.4);
        return round(max(0, min(100, $overallGrade)), 1);
    }
    
    /**
     * Calculate GWA from grade (Philippine system)
     */
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
    
    /**
     * Calculate risk level based on GWA
     */
    private static function calculateRiskLevel($gwa) {
        if ($gwa <= 1.75) return 'low';
        if ($gwa <= 2.50) return 'medium';
        return 'high';
    }
}

// =============================================================================
// YOUR EXISTING CODE - UPDATED FOR SUPABASE
// =============================================================================

class InterventionSystem {
    
    /**
     * Log student behavior for analysis - UPDATED FOR SUPABASE
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
     * Get behavioral insights based on student performance patterns - SIMPLIFIED FOR NOW
     */
    public static function getBehavioralInsights($studentId, $subjectId) {
        $insights = [];
        
        try {
            // Simplified version for now - you can enhance this later with Supabase queries
            $insights[] = [
                'message' => 'Track your scores regularly to get detailed behavioral insights.',
                'priority' => 'low'
            ];
            
        } catch (Exception $e) {
            error_log("Behavioral insights error: " . $e->getMessage());
            $insights[] = [
                'message' => 'Track your learning patterns by regularly updating your scores.',
                'priority' => 'low'
            ];
        }
        
        return $insights;
    }
    
    /**
     * Get interventions based on risk level and performance - UPDATED
     */
    public static function getInterventions($studentId, $subjectId, $riskLevel) {
        $interventions = [];
        
        try {
            // Get subject details from Supabase
            $student_subjects = supabaseFetch('student_subjects', ['id' => $subjectId]);
            if ($student_subjects && count($student_subjects) > 0) {
                $student_subject = $student_subjects[0];
                $subjects = supabaseFetch('subjects', ['id' => $student_subject['subject_id']]);
                
                if ($subjects && count($subjects) > 0) {
                    $subject = $subjects[0];
                    $subjectName = $subject['subject_name'];
                } else {
                    $subjectName = 'this subject';
                }
            } else {
                $subjectName = 'this subject';
            }
            
            switch ($riskLevel) {
                case 'high':
                    $interventions[] = [
                        'message' => "Immediate academic advising recommended for $subjectName. Contact your instructor.",
                        'priority' => 'high'
                    ];
                    $interventions[] = [
                        'message' => 'Consider forming a study group or seeking tutoring support.',
                        'priority' => 'high'
                    ];
                    $interventions[] = [
                        'message' => 'Focus on foundational concepts before attempting advanced topics.',
                        'priority' => 'medium'
                    ];
                    break;
                    
                case 'medium':
                    $interventions[] = [
                        'message' => "Schedule regular review sessions for $subjectName to prevent further decline.",
                        'priority' => 'medium'
                    ];
                    $interventions[] = [
                        'message' => 'Identify specific areas of difficulty and seek clarification.',
                        'priority' => 'medium'
                    ];
                    $interventions[] = [
                        'message' => 'Increase practice with problem sets and past assignments.',
                        'priority' => 'low'
                    ];
                    break;
                    
                case 'low':
                    $interventions[] = [
                        'message' => 'Maintain your current study habits and regular review schedule.',
                        'priority' => 'low'
                    ];
                    $interventions[] = [
                        'message' => 'Consider challenging yourself with advanced topics or helping peers.',
                        'priority' => 'low'
                    ];
                    break;
                    
                default:
                    $interventions[] = [
                        'message' => 'Continue tracking your progress and maintain consistent study habits.',
                        'priority' => 'low'
                    ];
                    break;
            }
            
        } catch (Exception $e) {
            error_log("Interventions error: " . $e->getMessage());
            $interventions[] = [
                'message' => 'Focus on consistent study habits and regular progress tracking.',
                'priority' => 'medium'
            ];
        }
        
        return $interventions;
    }
    
    /**
     * Get personalized recommendations based on performance - UPDATED
     */
    public static function getRecommendations($studentId, $subjectId, $overallGrade) {
        $recommendations = [];
        
        try {
            // Get subject details from Supabase
            $student_subjects = supabaseFetch('student_subjects', ['id' => $subjectId]);
            $subjectName = 'this subject';
            
            if ($student_subjects && count($student_subjects) > 0) {
                $student_subject = $student_subjects[0];
                $subjects = supabaseFetch('subjects', ['id' => $student_subject['subject_id']]);
                
                if ($subjects && count($subjects) > 0) {
                    $subject = $subjects[0];
                    $subjectName = $subject['subject_name'];
                }
            }
            
            // Grade-based recommendations
            if ($overallGrade >= 90) {
                $recommendations[] = [
                    'message' => "Excellent performance in $subjectName! Consider mentoring peers or exploring advanced topics.",
                    'priority' => 'low'
                ];
            } elseif ($overallGrade >= 80) {
                $recommendations[] = [
                    'message' => "Strong performance. Focus on maintaining consistency and addressing minor gaps.",
                    'priority' => 'low'
                ];
            } elseif ($overallGrade >= 70) {
                $recommendations[] = [
                    'message' => "Solid foundation. Identify specific areas for improvement to reach the next level.",
                    'priority' => 'medium'
                ];
            } else {
                $recommendations[] = [
                    'message' => "Focus on core concepts and seek additional help to improve understanding.",
                    'priority' => 'high'
                ];
            }
            
            // General study recommendations
            $recommendations[] = [
                'message' => 'Regular practice and consistent study schedule improve long-term retention.',
                'priority' => 'medium'
            ];
            
        } catch (Exception $e) {
            error_log("Recommendations error: " . $e->getMessage());
            $recommendations[] = [
                'message' => 'Focus on consistent study habits and regular progress tracking.',
                'priority' => 'medium'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Get performance trends over time - SIMPLIFIED FOR NOW
     */
    public static function getPerformanceTrends($studentId, $subjectId) {
        // Simplified version - you can implement this later with Supabase
        return [];
    }
}

/**
 * Utility functions for grade calculations and predictions
 */
class GradeCalculator {
    
    /**
     * Predict final grade based on current performance
     */
    public static function predictFinalGrade($currentGrade, $remainingWeight, $expectedPerformance = 'maintain') {
        $performanceMultipliers = [
            'improve' => 1.1,      // 10% improvement
            'maintain' => 1.0,     // Same performance
            'decline' => 0.9       // 10% decline
        ];
        
        $multiplier = $performanceMultipliers[$expectedPerformance] ?? 1.0;
        $predictedRemaining = $remainingWeight * $multiplier;
        
        return min(100, $currentGrade + $predictedRemaining);
    }
    
    /**
     * Calculate required performance for target grade
     */
    public static function calculateRequiredPerformance($currentGrade, $remainingWeight, $targetGrade) {
        if ($remainingWeight <= 0) return 0;
        
        $pointsNeeded = max(0, $targetGrade - $currentGrade);
        $requiredPercentage = ($pointsNeeded / $remainingWeight) * 100;
        
        return min(100, $requiredPercentage);
    }
}

class DatabaseSetup {
    public static function createBehaviorLogsTable() {
        return true;
    }
}
?>