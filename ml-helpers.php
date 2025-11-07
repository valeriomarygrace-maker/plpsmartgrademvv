<?php
// ml-helpers.php - ENHANCED VERSION FOR GWA

/**
 * ML Service Integration for Enhanced Predictions
 */
class MLService {
    private static $api_url = 'https://plpsmartgrademvv.onrender.com/predict';
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
                
                // Convert risk level to GWA prediction
                if (isset($result['risk_level'])) {
                    $result['gwa_prediction'] = self::convertRiskToGWAPrediction($result['risk_level']);
                    $result['gwa_description'] = self::getGWADescription($result['gwa_prediction']);
                }
                
                return $result;
            }
            
        } catch (Exception $e) {
            error_log("ML Service Error: " . $e->getMessage());
        }
        
        return ['success' => false, 'source' => 'service_unavailable'];
    }
    
    private static function convertRiskToGWAPrediction($riskLevel) {
        // Convert risk levels to GWA ranges
        switch ($riskLevel) {
            case 'low':
                return ['min' => 1.00, 'max' => 1.75, 'average' => 1.25];
            case 'medium':
                return ['min' => 1.76, 'max' => 2.50, 'average' => 2.00];
            case 'high':
                return ['min' => 2.51, 'max' => 3.00, 'average' => 2.75];
            default:
                return ['min' => 0, 'max' => 0, 'average' => 0];
        }
    }
    
    private static function getGWADescription($gwaPrediction) {
        if ($gwaPrediction['average'] <= 1.75) {
            return 'Excellent Performance';
        } elseif ($gwaPrediction['average'] <= 2.50) {
            return 'Good Performance';
        } else {
            return 'Needs Improvement';
        }
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
        $gwaPrediction = self::calculateGWAPrediction($gwa);
        
        $baseInsights = [
            'behavioral_insights' => InterventionSystem::getBehavioralInsights($studentId, $subjectId, $calculatedGrade, $gwaPrediction),
            'interventions' => InterventionSystem::getInterventions($studentId, $subjectId, $gwaPrediction),
            'recommendations' => InterventionSystem::getRecommendations($studentId, $subjectId, $calculatedGrade, $gwaPrediction),
            'gwa_prediction' => $gwaPrediction,
            'current_gwa' => $gwa,
            'overall_grade' => $calculatedGrade,
            'source' => 'php_fallback'
        ];
        
        return $baseInsights;
    }
    
    private static function calculateOverallGrade($classStandings, $examScores) {
        if (empty($classStandings) && empty($examScores)) {
            return 0;
        }
        
        // New calculation: (Midterm 100% + Final 100%) / 2
        // For ML purposes, we'll simulate this with available data
        $classAvg = !empty($classStandings) ? array_sum($classStandings) / count($classStandings) : 0;
        $examAvg = !empty($examScores) ? array_sum($examScores) / count($examScores) : 0;
        
        // Simulate midterm and final grades
        $midtermGrade = ($classAvg * 0.6) + ($examAvg * 0.4); // 60% class standing + 40% exam
        $finalGrade = ($classAvg * 0.6) + ($examAvg * 0.4);   // Same calculation for final
        
        $overallGrade = ($midtermGrade + $finalGrade) / 2;
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
    
    private static function calculateGWAPrediction($gwa) {
        if ($gwa <= 1.75) {
            return [
                'range' => '1.00 - 1.75',
                'description' => 'Excellent Performance',
                'level' => 'excellent'
            ];
        } elseif ($gwa <= 2.50) {
            return [
                'range' => '1.76 - 2.50',
                'description' => 'Good Performance',
                'level' => 'good'
            ];
        } else {
            return [
                'range' => '2.51 - 3.00+',
                'description' => 'Needs Improvement',
                'level' => 'needs_improvement'
            ];
        }
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
    
    public static function getBehavioralInsights($studentId, $subjectId, $currentGrade = 0, $gwaPrediction = null) {
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
        
        // GWA-based insights
        if ($gwaPrediction && isset($gwaPrediction['level'])) {
            switch ($gwaPrediction['level']) {
                case 'excellent':
                    $insights[] = [
                        'message' => 'Excellent GWA predicted! Your current performance suggests strong academic standing.',
                        'priority' => 'low',
                        'source' => 'ml'
                    ];
                    $insights[] = [
                        'message' => 'Maintain your study consistency to preserve this excellent performance level.',
                        'priority' => 'low',
                        'source' => 'system'
                    ];
                    break;
                case 'good':
                    $insights[] = [
                        'message' => 'Good GWA predicted. You are on track for solid academic performance.',
                        'priority' => 'medium',
                        'source' => 'ml'
                    ];
                    $insights[] = [
                        'message' => 'Focus on consistent performance across all assessments to maintain good standing.',
                        'priority' => 'medium',
                        'source' => 'system'
                    ];
                    break;
                case 'needs_improvement':
                    $insights[] = [
                        'message' => 'GWA prediction indicates areas for improvement. Focus on core concepts.',
                        'priority' => 'high',
                        'source' => 'ml'
                    ];
                    $insights[] = [
                        'message' => 'Targeted study sessions on challenging topics can improve your GWA.',
                        'priority' => 'high',
                        'source' => 'system'
                    ];
                    break;
            }
        }
        
        // Grade-based insights
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
    
    public static function getInterventions($studentId, $subjectId, $gwaPrediction = null) {
        $interventions = [];
        
        // ALWAYS RETURN INTERVENTIONS - EVEN WITH NO SCORES
        if (!$gwaPrediction) {
            $interventions[] = [
                'message' => 'Begin by adding your class standing scores to enable personalized intervention planning.',
                'priority' => 'low'
            ];
            return $interventions;
        }
        
        // GWA-based interventions
        if ($gwaPrediction && isset($gwaPrediction['level'])) {
            switch ($gwaPrediction['level']) {
                case 'excellent':
                    $interventions[] = [
                        'message' => 'Maintain current study habits and consider advanced topic exploration',
                        'priority' => 'low'
                    ];
                    $interventions[] = [
                        'message' => 'Peer mentoring can reinforce your understanding of complex concepts',
                        'priority' => 'low'
                    ];
                    break;
                case 'good':
                    $interventions[] = [
                        'message' => 'Focus on improving consistency in assignments and quizzes',
                        'priority' => 'medium'
                    ];
                    $interventions[] = [
                        'message' => 'Join study groups to enhance understanding through collaboration',
                        'priority' => 'medium'
                    ];
                    break;
                case 'needs_improvement':
                    $interventions[] = [
                        'message' => 'Create structured daily study schedule with specific learning objectives',
                        'priority' => 'high'
                    ];
                    $interventions[] = [
                        'message' => 'Seek immediate academic advising for personalized support plan',
                        'priority' => 'high'
                    ];
                    $interventions[] = [
                        'message' => 'Request tutoring sessions for challenging topics',
                        'priority' => 'high'
                    ];
                    break;
            }
        }
        
        // General interventions
        $interventions[] = [
            'message' => 'Practice with past examination papers under timed conditions',
            'priority' => 'medium'
        ];
        
        return $interventions;
    }
    
    public static function getRecommendations($studentId, $subjectId, $overallGrade, $gwaPrediction = null) {
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
        
        // GWA-based recommendations
        if ($gwaPrediction && isset($gwaPrediction['level'])) {
            switch ($gwaPrediction['level']) {
                case 'excellent':
                    $recommendations[] = [
                        'message' => 'Excellent GWA predicted! Continue your current effective study strategies.',
                        'priority' => 'low',
                        'source' => 'ml'
                    ];
                    $recommendations[] = [
                        'message' => 'Consider exploring advanced topics to further enhance your knowledge.',
                        'priority' => 'low',
                        'source' => 'system'
                    ];
                    break;
                case 'good':
                    $recommendations[] = [
                        'message' => 'Good GWA predicted. Focus on minor improvements to reach excellence.',
                        'priority' => 'medium',
                        'source' => 'ml'
                    ];
                    $recommendations[] = [
                        'message' => 'Identify specific weak areas from recent assessments for targeted improvement.',
                        'priority' => 'medium',
                        'source' => 'system'
                    ];
                    break;
                case 'needs_improvement':
                    $recommendations[] = [
                        'message' => 'GWA prediction suggests focusing on foundational concepts first.',
                        'priority' => 'high',
                        'source' => 'ml'
                    ];
                    $recommendations[] = [
                        'message' => 'Break down complex topics into smaller, manageable learning units.',
                        'priority' => 'high',
                        'source' => 'system'
                    ];
                    $recommendations[] = [
                        'message' => 'Utilize all available academic support resources consistently.',
                        'priority' => 'high',
                        'source' => 'system'
                    ];
                    break;
            }
        }
        
        // Grade-based recommendations
        if ($overallGrade >= 90) {
            $recommendations[] = [
                'message' => 'Excellent performance! Consider peer mentoring to reinforce your understanding through teaching.',
                'priority' => 'low',
                'source' => 'system'
            ];
        } elseif ($overallGrade >= 80) {
            $recommendations[] = [
                'message' => 'Strong performance. Focus on maintaining consistency across all types of assessments.',
                'priority' => 'low',
                'source' => 'system'
            ];
        } elseif ($overallGrade >= 75) {
            $recommendations[] = [
                'message' => 'Good progress. Target specific weak areas identified in recent assessments.',
                'priority' => 'medium',
                'source' => 'system'
            ];
        } else {
            $recommendations[] = [
                'message' => 'Prioritize understanding foundational concepts before attempting advanced applications.',
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
                    'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, 0, null),
                    'interventions' => self::getInterventions($student_id, $subject_id, null),
                    'recommendations' => self::getRecommendations($student_id, $subject_id, 0, null)
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
            $gwa = self::calculateGWAFromGrade($overallGrade);
            $gwaPrediction = self::calculateGWAPrediction($gwa);
            
            // Generate fresh insights
            return [
                'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, $overallGrade, $gwaPrediction),
                'interventions' => self::getInterventions($student_id, $subject_id, $gwaPrediction),
                'recommendations' => self::getRecommendations($student_id, $subject_id, $overallGrade, $gwaPrediction)
            ];
            
        } catch (Exception $e) {
            error_log("ML Insights Refresh Error: " . $e->getMessage());
            // Return basic insights even if calculation fails
            return [
                'behavioralInsights' => self::getBehavioralInsights($student_id, $subject_id, 0, null),
                'interventions' => self::getInterventions($student_id, $subject_id, null),
                'recommendations' => self::getRecommendations($student_id, $subject_id, 0, null)
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
    
    private static function calculateGWAPrediction($gwa) {
        if ($gwa <= 1.75) {
            return [
                'range' => '1.00 - 1.75',
                'description' => 'Excellent Performance',
                'level' => 'excellent'
            ];
        } elseif ($gwa <= 2.50) {
            return [
                'range' => '1.76 - 2.50', 
                'description' => 'Good Performance',
                'level' => 'good'
            ];
        } else {
            return [
                'range' => '2.51 - 3.00+',
                'description' => 'Needs Improvement',
                'level' => 'needs_improvement'
            ];
        }
    }
}

/**
 * Helper function to ensure insights are always available
 */
function ensureInsightsAvailable($studentId, $subjectId, $overallGrade = 0, $gwaPrediction = null) {
    return [
        'behavioral' => InterventionSystem::getBehavioralInsights($studentId, $subjectId, $overallGrade, $gwaPrediction),
        'interventions' => InterventionSystem::getInterventions($studentId, $subjectId, $gwaPrediction),
        'recommendations' => InterventionSystem::getRecommendations($studentId, $subjectId, $overallGrade, $gwaPrediction)
    ];
}
?>