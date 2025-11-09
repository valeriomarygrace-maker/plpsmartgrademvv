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
                'performance_level' => calculatePerformanceLevel($subjectGrade),
                'performance_description' => getPerformanceDescription(calculatePerformanceLevel($subjectGrade))
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
            'performance_level' => calculatePerformanceLevel($subjectGrade),
            'performance_description' => getPerformanceDescription(calculatePerformanceLevel($subjectGrade))
        ];
    }
    
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
}

/**
 * Intervention System - BASED ON INPUTTED SCORES AND SUBJECT GRADE
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
    
    public static function getBehavioralInsights($studentId, $subjectId, $currentGrade, $subjectGrade, $categoryTotals, $term = 'midterm') {
        $insights = [];
        
        if ($currentGrade == 0) {
            $insights[] = [
                'message' => "Welcome to {$term} term! Start adding your scores to get personalized insights.",
                'priority' => 'low',
                'source' => 'system'
            ];
            return $insights;
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
        
        // Subject grade based insights
        if ($subjectGrade >= 90) {
            $insights[] = [
                'message' => "Outstanding subject performance! Your consistent effort across all assessments is paying off.",
                'priority' => 'low',
                'source' => 'grade_analysis'
            ];
        } elseif ($subjectGrade >= 85) {
            $insights[] = [
                'message' => "Very good subject performance. Maintain your study habits for continued success.",
                'priority' => 'low',
                'source' => 'grade_analysis'
            ];
        } elseif ($subjectGrade >= 80) {
            $insights[] = [
                'message' => "Good subject performance. Identify areas where you can gain additional points.",
                'priority' => 'medium',
                'source' => 'grade_analysis'
            ];
        } elseif ($subjectGrade >= 75) {
            $insights[] = [
                'message' => "Satisfactory subject performance. Focus on improving weaker areas to reach higher grades.",
                'priority' => 'medium',
                'source' => 'grade_analysis'
            ];
        } else {
            $insights[] = [
                'message' => "Subject performance needs improvement. Prioritize studying core concepts and seek additional help.",
                'priority' => 'high',
                'source' => 'grade_analysis'
            ];
        }
        
        // Term-specific insights
        if ($term === 'midterm') {
            $insights[] = [
                'message' => "Midterm results provide valuable feedback. Use this information to prepare for final term assessments.",
                'priority' => 'medium',
                'source' => 'system'
            ];
        } else {
            $insights[] = [
                'message' => "Final term completion. Review your overall performance to identify learning patterns.",
                'priority' => 'medium',
                'source' => 'system'
            ];
        }
        
        return $insights;
    }
    
    public static function getInterventions($studentId, $subjectId, $performanceLevel, $categoryTotals, $term = 'midterm') {
        $interventions = [];
        
        if (!$performanceLevel || $performanceLevel === 'no-data') {
            $interventions[] = [
                'message' => "Start adding your scores to enable personalized intervention planning.",
                'priority' => 'low'
            ];
            return $interventions;
        }
        
        // Performance level based interventions
        switch ($performanceLevel) {
            case 'excellent':
                $interventions[] = [
                    'message' => "Maintain current study schedule and consider exploring advanced topics",
                    'priority' => 'low'
                ];
                $interventions[] = [
                    'message' => "Help peers who may be struggling with difficult concepts",
                    'priority' => 'low'
                ];
                break;
                
            case 'very_good':
                $interventions[] = [
                    'message' => "Review assessments to identify minor areas for improvement",
                    'priority' => 'low'
                ];
                $interventions[] = [
                    'message' => "Continue consistent study habits for maintained performance",
                    'priority' => 'low'
                ];
                break;
                
            case 'good':
                $interventions[] = [
                    'message' => "Increase focused study time on challenging topics",
                    'priority' => 'medium'
                ];
                $interventions[] = [
                    'message' => "Join study groups for collaborative learning",
                    'priority' => 'medium'
                ];
                break;
                
            case 'satisfactory':
                $interventions[] = [
                    'message' => "Create structured study plan with specific learning objectives",
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => "Seek clarification on misunderstood concepts from instructor",
                    'priority' => 'high'
                ];
                break;
                
            case 'needs_improvement':
                $interventions[] = [
                    'message' => "Request immediate academic advising for support plan",
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => "Schedule regular tutoring sessions for fundamental concepts",
                    'priority' => 'high'
                ];
                $interventions[] = [
                    'message' => "Develop intensive catch-up study schedule",
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
    
    public static function getRecommendations($studentId, $subjectId, $subjectGrade, $performanceLevel, $categoryTotals, $term = 'midterm') {
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
        
        // Performance level based recommendations
        switch ($performanceLevel) {
            case 'excellent':
                $recommendations[] = [
                    'message' => "Excellent subject grade! Continue your effective study strategies",
                    'priority' => 'low',
                    'source' => 'grade_analysis'
                ];
                $recommendations[] = [
                    'message' => "Consider exploring advanced applications of course concepts",
                    'priority' => 'low',
                    'source' => 'system'
                ];
                break;
                
            case 'very_good':
                $recommendations[] = [
                    'message' => "Very good subject performance. Target specific areas for minor improvements",
                    'priority' => 'low',
                    'source' => 'grade_analysis'
                ];
                $recommendations[] = [
                    'message' => "Maintain consistent study schedule for continued success",
                    'priority' => 'low',
                    'source' => 'system'
                ];
                break;
                
            case 'good':
                $recommendations[] = [
                    'message' => "Good subject performance. Focus on improving consistency across all assessments",
                    'priority' => 'medium',
                    'source' => 'grade_analysis'
                ];
                $recommendations[] = [
                    'message' => "Practice with past examination papers under timed conditions",
                    'priority' => 'medium',
                    'source' => 'system'
                ];
                break;
                
            case 'satisfactory':
                $recommendations[] = [
                    'message' => "Satisfactory subject grade. Prioritize understanding foundational concepts",
                    'priority' => 'high',
                    'source' => 'grade_analysis'
                ];
                $recommendations[] = [
                    'message' => "Break down complex topics into smaller, manageable learning units",
                    'priority' => 'high',
                    'source' => 'system'
                ];
                break;
                
            case 'needs_improvement':
                $recommendations[] = [
                    'message' => "Subject grade needs improvement. Focus on core concepts first",
                    'priority' => 'high',
                    'source' => 'grade_analysis'
                ];
                $recommendations[] = [
                    'message' => "Utilize all available academic support resources consistently",
                    'priority' => 'high',
                    'source' => 'system'
                ];
                $recommendations[] = [
                    'message' => "Create daily study schedule with specific learning objectives",
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
        
        // Term-specific recommendations
        if ($term === 'midterm') {
            $recommendations[] = [
                'message' => "Use midterm feedback to prepare effectively for final term assessments",
                'priority' => 'medium',
                'source' => 'system'
            ];
        } else {
            $recommendations[] = [
                'message' => "Final term results provide insights for future course planning and study strategies",
                'priority' => 'medium',
                'source' => 'system'
            ];
        }
        
        return $recommendations;
    }
}

// Global helper functions
function calculatePerformanceLevel($grade) {
    if ($grade >= 90) return 'excellent';
    elseif ($grade >= 85) return 'very_good';
    elseif ($grade >= 80) return 'good';
    elseif ($grade >= 75) return 'satisfactory';
    else return 'needs_improvement';
}

function getPerformanceDescription($performanceLevel) {
    switch ($performanceLevel) {
        case 'excellent': return 'Excellent Performance';
        case 'very_good': return 'Very Good Performance';
        case 'good': return 'Good Performance';
        case 'satisfactory': return 'Satisfactory Performance';
        case 'needs_improvement': return 'Needs Improvement';
        default: return 'No Data Inputted';
    }
}
?>