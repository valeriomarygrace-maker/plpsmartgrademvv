<?php
// ml-helpers.php

class InterventionSystem {
    
    /**
     * Log student behavior for analysis
     */
    public static function logBehavior($studentId, $behaviorType, $data, $pdo) {
        try {
            // For Supabase implementation, we'll use direct API calls
            // Since we don't have PDO in your current setup
            $logData = [
                'student_id' => $studentId,
                'behavior_type' => $behaviorType,
                'behavior_data' => $data,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $result = supabaseInsert('student_behavior_logs', $logData);
            return $result !== false;
        } catch (Exception $e) {
            error_log("Behavior logging error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get behavioral insights based on student performance patterns
     */
    public static function getBehavioralInsights($studentId, $subjectId, $pdo = null) {
        $insights = [];
        
        try {
            // Get recent activity patterns from Supabase
            $activities = supabaseFetch('student_behavior_logs', [
                'student_id' => $studentId
            ]);
            
            // Filter activities for this subject
            $subjectActivities = array_filter($activities ?: [], function($activity) use ($subjectId) {
                $data = $activity['behavior_data'] ?? [];
                if (is_string($data)) {
                    $data = json_decode($data, true);
                }
                return isset($data['subject_id']) && $data['subject_id'] == $subjectId;
            });
            
            // Get score submission patterns
            $studentSubjects = supabaseFetch('student_subjects', [
                'student_id' => $studentId,
                'id' => $subjectId
            ]);
            
            if ($studentSubjects && count($studentSubjects) > 0) {
                $studentSubject = $studentSubjects[0];
                $scores = supabaseFetch('student_subject_scores', [
                    'student_subject_id' => $studentSubject['id']
                ]);
                
                $scorePatterns = [
                    'total_scores' => count($scores ?: []),
                    'avg_score' => 0,
                    'first_score' => null,
                    'last_score' => null
                ];
                
                if ($scorePatterns['total_scores'] > 0) {
                    $totalScore = 0;
                    $totalMax = 0;
                    $dates = [];
                    
                    foreach ($scores as $score) {
                        $totalScore += $score['score_value'];
                        $totalMax += $score['max_score'];
                        $dates[] = $score['score_date'];
                    }
                    
                    $scorePatterns['avg_score'] = $totalMax > 0 ? ($totalScore / $totalMax) * 100 : 0;
                    $scorePatterns['first_score'] = !empty($dates) ? min($dates) : null;
                    $scorePatterns['last_score'] = !empty($dates) ? max($dates) : null;
                }
                
                // Generate insights based on patterns
                
                // Insight 1: Activity frequency
                $totalActivities = count($subjectActivities);
                if ($totalActivities > 10) {
                    $insights[] = [
                        'message' => 'You maintain consistent engagement with this subject with regular score updates.',
                        'priority' => 'low'
                    ];
                } elseif ($totalActivities > 0) {
                    $insights[] = [
                        'message' => 'Consider increasing your engagement frequency for better performance tracking.',
                        'priority' => 'medium'
                    ];
                }
                
                // Insight 2: Score submission timeliness
                if ($scorePatterns['total_scores'] > 0 && $scorePatterns['first_score'] && $scorePatterns['last_score']) {
                    $firstScore = strtotime($scorePatterns['first_score']);
                    $lastScore = strtotime($scorePatterns['last_score']);
                    $daysBetween = ($lastScore - $firstScore) / (60 * 60 * 24);
                    
                    if ($daysBetween > 30 && $scorePatterns['total_scores'] < 5) {
                        $insights[] = [
                            'message' => 'Long gaps between score submissions detected. Try to update scores more regularly.',
                            'priority' => 'medium'
                        ];
                    }
                    
                    // Insight 3: Score consistency
                    if ($scorePatterns['avg_score'] < 70) {
                        $insights[] = [
                            'message' => 'Your average scores suggest areas for improvement. Focus on understanding core concepts.',
                            'priority' => 'high'
                        ];
                    }
                }
            }
            
            // Insight 4: Recent activity
            if (!empty($subjectActivities)) {
                $lastActivity = end($subjectActivities);
                $lastActivityTime = strtotime($lastActivity['created_at']);
                $daysSinceLast = (time() - $lastActivityTime) / (60 * 60 * 24);
                
                if ($daysSinceLast > 7) {
                    $insights[] = [
                        'message' => "It's been " . round($daysSinceLast) . " days since your last activity. Regular engagement helps maintain progress.",
                        'priority' => 'medium'
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("Behavioral insights error: " . $e->getMessage());
        }
        
        // Default insight if no specific patterns detected
        if (empty($insights)) {
            $insights[] = [
                'message' => 'Continue tracking your scores regularly to generate personalized insights.',
                'priority' => 'low'
            ];
        }
        
        return $insights;
    }
    
    /**
     * Get interventions based on risk level and performance
     */
    public static function getInterventions($studentId, $subjectId, $riskLevel, $pdo = null) {
        $interventions = [];
        
        try {
            // Get subject details
            $studentSubjects = supabaseFetch('student_subjects', ['id' => $subjectId]);
            $subject = null;
            
            if ($studentSubjects && count($studentSubjects) > 0) {
                $studentSubject = $studentSubjects[0];
                $subjects = supabaseFetch('subjects', ['id' => $studentSubject['subject_id']]);
                $subject = $subjects && count($subjects) > 0 ? $subjects[0] : null;
            }
            
            $subjectName = $subject ? $subject['subject_name'] : 'this subject';
            
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
            
            // Add attendance-based intervention if applicable
            $categories = supabaseFetch('student_class_standing_categories', [
                'student_subject_id' => $subjectId
            ]);
            
            $attendanceCategory = null;
            foreach ($categories ?: [] as $category) {
                if (strtolower($category['category_name']) === 'attendance') {
                    $attendanceCategory = $category;
                    break;
                }
            }
            
            if ($attendanceCategory) {
                $scores = supabaseFetch('student_subject_scores', [
                    'category_id' => $attendanceCategory['id']
                ]);
                
                $totalClasses = count($scores ?: []);
                $absences = 0;
                
                foreach ($scores ?: [] as $score) {
                    if ($score['score_name'] === 'Absent') {
                        $absences++;
                    }
                }
                
                if ($totalClasses > 0) {
                    $absenceRate = ($absences / $totalClasses) * 100;
                    if ($absenceRate > 20) {
                        $interventions[] = [
                            'message' => "High absence rate (" . round($absenceRate) . "%) detected. Regular attendance is crucial for success.",
                            'priority' => 'high'
                        ];
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Interventions error: " . $e->getMessage());
        }
        
        // Return default interventions on error
        if (empty($interventions)) {
            $interventions[] = [
                'message' => 'Focus on consistent study habits and regular progress tracking.',
                'priority' => 'medium'
            ];
        }
        
        return $interventions;
    }
    
    /**
     * Get personalized recommendations based on performance
     */
    public static function getRecommendations($studentId, $subjectId, $overallGrade, $pdo = null) {
        $recommendations = [];
        
        try {
            // Get category performance breakdown
            $categories = supabaseFetch('student_class_standing_categories', [
                'student_subject_id' => $subjectId
            ]);
            
            $categoryPerformance = [];
            foreach ($categories ?: [] as $category) {
                $scores = supabaseFetch('student_subject_scores', [
                    'category_id' => $category['id']
                ]);
                
                $totalScore = 0;
                $totalMax = 0;
                
                foreach ($scores ?: [] as $score) {
                    $totalScore += $score['score_value'];
                    $totalMax += $score['max_score'];
                }
                
                $avgPercentage = $totalMax > 0 ? ($totalScore / $totalMax) * 100 : 0;
                $categoryPerformance[] = [
                    'category_name' => $category['category_name'],
                    'score_count' => count($scores ?: []),
                    'avg_percentage' => $avgPercentage
                ];
            }
            
            // Get subject details
            $studentSubjects = supabaseFetch('student_subjects', ['id' => $subjectId]);
            $subject = null;
            
            if ($studentSubjects && count($studentSubjects) > 0) {
                $studentSubject = $studentSubjects[0];
                $subjects = supabaseFetch('subjects', ['id' => $studentSubject['subject_id']]);
                $subject = $subjects && count($subjects) > 0 ? $subjects[0] : null;
            }
            
            $subjectName = $subject ? $subject['subject_name'] : 'this subject';
            
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
            
            // Category-specific recommendations
            $weakCategories = [];
            foreach ($categoryPerformance as $category) {
                if ($category['avg_percentage'] < 70 && $category['score_count'] > 0) {
                    $weakCategories[] = $category['category_name'];
                }
            }
            
            if (!empty($weakCategories)) {
                $categoryList = implode(', ', $weakCategories);
                $recommendations[] = [
                    'message' => "Focus improvement efforts on: $categoryList. These areas show the most opportunity for growth.",
                    'priority' => 'high'
                ];
            }
            
            // Study habit recommendations
            $scores = supabaseFetch('student_subject_scores', [
                'student_subject_id' => $subjectId
            ]);
            
            $recentScores = 0;
            $oneWeekAgo = date('Y-m-d', strtotime('-7 days'));
            
            foreach ($scores ?: [] as $score) {
                if ($score['score_date'] >= $oneWeekAgo) {
                    $recentScores++;
                }
            }
            
            if ($recentScores == 0) {
                $recommendations[] = [
                    'message' => 'No recent score updates. Regular tracking helps identify problems early.',
                    'priority' => 'medium'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Recommendations error: " . $e->getMessage());
        }
        
        // Default recommendation if none generated
        if (empty($recommendations)) {
            $recommendations[] = [
                'message' => 'Continue your current study approach and monitor progress regularly.',
                'priority' => 'low'
            ];
        }
        
        return $recommendations;
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
?>