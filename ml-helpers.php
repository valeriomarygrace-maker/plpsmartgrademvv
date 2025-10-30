<?php
// ml-helpers.php

class InterventionSystem {
    public static function logBehavior($studentId, $behaviorType, $data, $pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO student_behavior_logs 
                (student_id, behavior_type, behavior_data, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                $studentId, 
                $behaviorType, 
                json_encode($data)
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("Behavior logging error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get behavioral insights based on student performance patterns
     */
    public static function getBehavioralInsights($studentId, $subjectId, $pdo) {
        $insights = [];
        
        try {
            // Get recent activity patterns
            $activityStmt = $pdo->prepare("
                SELECT behavior_type, COUNT(*) as count, 
                       MAX(created_at) as last_activity
                FROM student_behavior_logs 
                WHERE student_id = ? 
                AND behavior_data LIKE ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY behavior_type
                ORDER BY count DESC
            ");
            $activityStmt->execute([$studentId, "%\"subject_id\":\"$subjectId\"%"]);
            $activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get score submission patterns
            $scoreStmt = $pdo->prepare("
                SELECT COUNT(*) as total_scores,
                       AVG(score_value) as avg_score,
                       MIN(score_date) as first_score,
                       MAX(score_date) as last_score
                FROM student_subject_scores ss
                JOIN student_subjects sub ON ss.student_subject_id = sub.id
                WHERE sub.student_id = ? AND sub.id = ?
            ");
            $scoreStmt->execute([$studentId, $subjectId]);
            $scorePatterns = $scoreStmt->fetch(PDO::FETCH_ASSOC);
            
            // Generate insights based on patterns
            
            // Insight 1: Activity frequency
            $totalActivities = array_sum(array_column($activities, 'count'));
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
            if ($scorePatterns['total_scores'] > 0) {
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
            
            // Insight 4: Recent activity
            $recentStmt = $pdo->prepare("
                SELECT behavior_type, created_at 
                FROM student_behavior_logs 
                WHERE student_id = ? 
                AND behavior_data LIKE ?
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $recentStmt->execute([$studentId, "%\"subject_id\":\"$subjectId\"%"]);
            $recentActivity = $recentStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($recentActivity) {
                $lastActivity = strtotime($recentActivity['created_at']);
                $daysSinceLast = (time() - $lastActivity) / (60 * 60 * 24);
                
                if ($daysSinceLast > 7) {
                    $insights[] = [
                        'message' => "It's been " . round($daysSinceLast) . " days since your last activity. Regular engagement helps maintain progress.",
                        'priority' => 'medium'
                    ];
                }
            }
            
            // Default insight if no specific patterns detected
            if (empty($insights)) {
                $insights[] = [
                    'message' => 'Continue tracking your scores regularly to generate personalized insights.',
                    'priority' => 'low'
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Behavioral insights error: " . $e->getMessage());
            // Return default insights on error
            $insights[] = [
                'message' => 'Track your learning patterns by regularly updating your scores.',
                'priority' => 'low'
            ];
        }
        
        return $insights;
    }
    
    /**
     * Get interventions based on risk level and performance
     */
    public static function getInterventions($studentId, $subjectId, $riskLevel, $pdo) {
        $interventions = [];
        
        try {
            // Get subject details
            $subjectStmt = $pdo->prepare("
                SELECT s.subject_name, s.subject_code 
                FROM student_subjects ss 
                JOIN subjects s ON ss.subject_id = s.id 
                WHERE ss.id = ?
            ");
            $subjectStmt->execute([$subjectId]);
            $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
            
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
            $attendanceStmt = $pdo->prepare("
                SELECT COUNT(*) as total_classes,
                       SUM(CASE WHEN score_name = 'Absent' THEN 1 ELSE 0 END) as absences
                FROM student_subject_scores ss
                JOIN student_class_standing_categories cat ON ss.category_id = cat.id
                WHERE ss.student_subject_id = ? 
                AND LOWER(cat.category_name) = 'attendance'
            ");
            $attendanceStmt->execute([$subjectId]);
            $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($attendance['total_classes'] > 0) {
                $absenceRate = ($attendance['absences'] / $attendance['total_classes']) * 100;
                if ($absenceRate > 20) {
                    $interventions[] = [
                        'message' => "High absence rate (" . round($absenceRate) . "%) detected. Regular attendance is crucial for success.",
                        'priority' => 'high'
                    ];
                }
            }
            
        } catch (PDOException $e) {
            error_log("Interventions error: " . $e->getMessage());
            // Return default interventions on error
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
    public static function getRecommendations($studentId, $subjectId, $overallGrade, $pdo) {
        $recommendations = [];
        
        try {
            // Get category performance breakdown
            $categoryStmt = $pdo->prepare("
                SELECT cat.category_name, 
                       COUNT(ss.id) as score_count,
                       AVG(ss.score_value/ss.max_score * 100) as avg_percentage
                FROM student_class_standing_categories cat
                LEFT JOIN student_subject_scores ss ON cat.id = ss.category_id
                WHERE cat.student_subject_id = ?
                GROUP BY cat.id, cat.category_name
                HAVING score_count > 0
            ");
            $categoryStmt->execute([$subjectId]);
            $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get subject details
            $subjectStmt = $pdo->prepare("
                SELECT s.subject_name, s.subject_code 
                FROM student_subjects ss 
                JOIN subjects s ON ss.subject_id = s.id 
                WHERE ss.id = ?
            ");
            $subjectStmt->execute([$subjectId]);
            $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
            
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
            foreach ($categories as $category) {
                if ($category['avg_percentage'] < 70) {
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
            $scoreStmt = $pdo->prepare("
                SELECT COUNT(*) as recent_scores
                FROM student_subject_scores 
                WHERE student_subject_id = ? 
                AND score_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ");
            $scoreStmt->execute([$subjectId]);
            $recentScores = $scoreStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($recentScores['recent_scores'] == 0) {
                $recommendations[] = [
                    'message' => 'No recent score updates. Regular tracking helps identify problems early.',
                    'priority' => 'medium'
                ];
            }
            
            // Exam preparation recommendation
            $examStmt = $pdo->prepare("
                SELECT COUNT(*) as exam_count
                FROM student_subject_scores 
                WHERE student_subject_id = ? 
                AND score_type IN ('midterm_exam', 'final_exam')
            ");
            $examStmt->execute([$subjectId]);
            $exams = $examStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exams['exam_count'] == 0 && $overallGrade < 80) {
                $recommendations[] = [
                    'message' => 'Consider starting exam preparation early. Review materials consistently.',
                    'priority' => 'medium'
                ];
            }
            
            // Default recommendation if none generated
            if (empty($recommendations)) {
                $recommendations[] = [
                    'message' => 'Continue your current study approach and monitor progress regularly.',
                    'priority' => 'low'
                ];
            }
            
        } catch (PDOException $e) {
            error_log("Recommendations error: " . $e->getMessage());
            // Return default recommendations on error
            $recommendations[] = [
                'message' => 'Focus on consistent study habits and regular progress tracking.',
                'priority' => 'medium'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Get performance trends over time
     */
    public static function getPerformanceTrends($studentId, $subjectId, $pdo) {
        try {
            $trendStmt = $pdo->prepare("
                SELECT DATE(score_date) as date,
                       AVG(score_value/max_score * 100) as daily_avg
                FROM student_subject_scores 
                WHERE student_subject_id = ? 
                AND score_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(score_date)
                ORDER BY date
            ");
            $trendStmt->execute([$subjectId]);
            return $trendStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Performance trends error: " . $e->getMessage());
            return [];
        }
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

// Database table creation script for new features
class DatabaseSetup {
    
    public static function createBehaviorLogsTable($pdo) {
        $sql = "
            CREATE TABLE IF NOT EXISTS student_behavior_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                behavior_type VARCHAR(50) NOT NULL,
                behavior_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_student_behavior (student_id, behavior_type),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            error_log("Table creation error: " . $e->getMessage());
            return false;
        }
    }
}
?>