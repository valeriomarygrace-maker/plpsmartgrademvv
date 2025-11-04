<?php
// ml-helpers.php - ENHANCED VERSION FOR GWA

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
        $gwa = self::calculateGWA($calculatedGrade);
        $performanceLevel = 'general'; // Changed from riskLevel
        
        $baseInsights = [
            'behavioral_insights' => InterventionSystem::getBehavioralInsights($studentId, $subjectId, $calculatedGrade, $performanceLevel),
            'interventions' => InterventionSystem::getInterventions($studentId, $subjectId, $performanceLevel),
            'recommendations' => InterventionSystem::getRecommendations($studentId, $subjectId, $calculatedGrade, $performanceLevel),
            'performance_level' => $performanceLevel, // Changed from risk_level
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
    
    private static function determineRiskLevel($gwa) {
        if ($gwa <= 1.75) return 'low';
        if ($gwa <= 2.50) return 'medium';
        return 'high';
    }
}

/**
 * Intervention System - ENHANCED FOR GWA
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
    
    public static function getBehavioralInsights($studentId, $subjectId, $currentGrade = 0, $performanceLevel = 'general') {
        $insights = [];
        
        // ALWAYS RETURN INSIGHTS - EVEN WITH NO SCORES
        if ($currentGrade == 0) {
            // No scores yet - encouraging messages
            $insights[] = [
                'message' => 'Welcome! Start adding your scores to get personalized behavioral insights and track your academic progress.',
                'priority' => 'low',
                'source' => 'system'
            ];
            $insights[] = [
                'message' => 'Regular score tracking helps identify patterns in your learning behavior and study effectiveness.',
                'priority' => 'low',
                'source' => 'ml'
            ];
            return $insights;
        }
        
        // Grade-based insights only (no risk level)
        if ($currentGrade >= 90) {
            $insights[] = [
                'message' => 'Excellent academic performance! Your consistent study habits are paying off.',
                'priority' => 'low',
                'source' => 'system'
            ];
        } elseif ($currentGrade >= 80) {
            $insights[] = [
                'message' => 'Strong performance detected. Focus on maintaining consistency across all assessment types.',
                'priority' => 'low',
                'source' => 'system'
            ];
        } elseif ($currentGrade >= 75) {
            $insights[] = [
                'message' => 'Solid foundation established. Target specific weak areas for focused improvement.',
                'priority' => 'medium',
                'source' => 'system'
            ];
        } else {
            $insights[] = [
                'message' => 'Focus needed on core concepts. Seek additional academic support and resources.',
                'priority' => 'high',
                'source' => 'system'
            ];
        }
        
        // General behavioral insights
        $insights[] = [
            'message' => 'Regular practice and consistent study schedule significantly improve long-term knowledge retention.',
            'priority' => 'medium',
            'source' => 'system'
        ];
        
        return $insights;
    }
    
    public static function getInterventions($studentId, $subjectId, $performanceLevel = 'general') {
        $interventions = [];
        
        // ALWAYS RETURN INTERVENTIONS - EVEN WITH NO SCORES
        if ($performanceLevel === 'no-data') {
            $interventions[] = [
                'message' => 'Begin by adding your class standing scores to enable personalized intervention planning.',
                'priority' => 'low'
            ];
            return $interventions;
        }
        
        // Performance-based interventions (not risk-based)
        $interventions[] = [
            'message' => 'Schedule regular study sessions with clear learning objectives for each topic',
            'priority' => 'medium'
        ];
        $interventions[] = [
            'message' => 'Practice with past examination papers under timed conditions',
            'priority' => 'medium'
        ];
        $interventions[] = [
            'message' => 'Join or form study group for collaborative learning and peer support',
            'priority' => 'low'
        ];
        
        return $interventions;
    }
    
    public static function getRecommendations($studentId, $subjectId, $overallGrade, $performanceLevel = 'general') {
        $recommendations = [];
        
        // ALWAYS RETURN RECOMMENDATIONS - EVEN WITH NO SCORES
        if ($overallGrade == 0) {
            $recommendations[] = [
                'message' => 'Start by adding your class standing scores (quizzes, assignments, projects) to establish baseline performance.',
                'priority' => 'low',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => 'Input attendance records regularly to track engagement patterns and consistency.',
                'priority' => 'low',
                'source' => 'ml'
            ];
            $recommendations[] = [
                'message' => 'Add exam scores as they become available for comprehensive academic analysis.',
                'priority' => 'low',
                'source' => 'system'
            ];
            return $recommendations;
        }
        
        // Grade-based recommendations
        if ($overallGrade >= 90) {
            $recommendations[] = [
                'message' => 'Excellent performance! Consider peer mentoring to reinforce your understanding through teaching.',
                'priority' => 'low',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => 'Maintain study consistency and explore advanced applications of course concepts.',
                'priority' => 'low',
                'source' => 'ml'
            ];
            $recommendations[] = [
                'message' => 'Document your successful study strategies for future reference and refinement.',
                'priority' => 'low',
                'source' => 'system'
            ];
        } elseif ($overallGrade >= 80) {
            $recommendations[] = [
                'message' => 'Strong performance. Focus on maintaining consistency across all types of assessments.',
                'priority' => 'low',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => 'Identify specific areas for minor improvements to reach excellence level.',
                'priority' => 'medium',
                'source' => 'ml'
            ];
            $recommendations[] = [
                'message' => 'Practice time management during exams to maximize point accumulation.',
                'priority' => 'medium',
                'source' => 'system'
            ];
        } elseif ($overallGrade >= 75) {
            $recommendations[] = [
                'message' => 'Good progress. Target specific weak areas identified in recent assessments.',
                'priority' => 'medium',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => 'Focus on improving exam preparation strategies and question analysis skills.',
                'priority' => 'medium',
                'source' => 'ml'
            ];
            $recommendations[] = [
                'message' => 'Increase practice with application-based questions to bridge theory-practice gap.',
                'priority' => 'medium',
                'source' => 'system'
            ];
        } else {
            $recommendations[] = [
                'message' => 'Prioritize understanding foundational concepts before attempting advanced applications.',
                'priority' => 'high',
                'source' => 'system'
            ];
            $recommendations[] = [
                'message' => 'Seek immediate clarification on core concepts from instructor or tutor.',
                'priority' => 'high',
                'source' => 'ml'
            ];
            $recommendations[] = [
                'message' => 'Break down complex topics into smaller, manageable learning units.',
                'priority' => 'high',
                'source' => 'system'
            ];
        }
        
        // General study recommendations
        $recommendations[] = [
            'message' => 'Implement spaced repetition technique for better long-term retention of key concepts.',
            'priority' => 'medium',
            'source' => 'ml'
        ];
        $recommendations[] = [
            'message' => 'Practice with past examination papers under timed conditions.',
            'priority' => 'medium',
            'source' => 'system'
        ];
        $recommendations[] = [
            'message' => 'Regularly review and update your study strategies based on assessment feedback.',
            'priority' => 'medium',
            'source' => 'system'
        ];
        
        return $recommendations;
    }
    
    /**
     * Force refresh insights - called after score updates
     */
    public static function refreshStudentInsights($student_id, $subject_id) {
        try {
            // Get latest scores
            $allScores = supabaseFetch('student_subject_scores', ['student_subject_id' => $subject_id]);
            
            if (!$allScores || empty($allScores)) {
                return [
                    'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, 0, 'no-data'),
                    'interventions' => self::getInterventions($student_id, $subject_id, 'no-data'),
                    'recommendations' => self::getRecommendations($student_id, $subject_id, 0, 'no-data')
                ];
            }
            
            // Calculate current performance metrics
            $totalScore = 0;
            $maxPossible = 0;
            
            foreach ($allScores as $score) {
                $totalScore += $score['score_value'];
                $maxPossible += $score['max_score'];
            }
            
            $overallGrade = $maxPossible > 0 ? ($totalScore / $maxPossible) * 100 : 0;
            
            // Generate fresh insights without risk level
            return [
                'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, $overallGrade, 'general'),
                'interventions' => self::getInterventions($student_id, $subject_id, 'general'),
                'recommendations' => self::getRecommendations($student_id, $subject_id, $overallGrade, 'general')
            ];
            
        } catch (Exception $e) {
            error_log("ML Insights Refresh Error: " . $e->getMessage());
            // Return basic insights even if calculation fails
            return [
                'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, 0, 'no-data'),
                'interventions' => self::getInterventions($student_id, $subject_id, 'no-data'),
                'recommendations' => self::getRecommendations($student_id, $subject_id, 0, 'no-data')
            ];
        }
    }
    
    private static function calculateGWAFromGrade($grade) {
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
}

/**
 * Helper function to ensure insights are always available
 */
function ensureInsightsAvailable($studentId, $subjectId, $overallGrade = 0, $performanceLevel = 'no-data') {
    return [
        'behavioral' => InterventionSystem::getBehavioralInsights($studentId, $subjectId, $overallGrade, $performanceLevel),
        'interventions' => InterventionSystem::getInterventions($studentId, $subjectId, $performanceLevel),
        'recommendations' => InterventionSystem::getRecommendations($studentId, $subjectId, $overallGrade, $performanceLevel)
    ];
}
?>