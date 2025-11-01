# ml_helpers.py
from supabase import create_client, Client
from datetime import datetime, timedelta
import json

# 🔗 Connect to Supabase
url = "https://YOUR_SUPABASE_URL.supabase.co"
key = "YOUR_SUPABASE_API_KEY"
supabase: Client = create_client(url, key)


class InterventionSystem:

    @staticmethod
    def log_behavior(student_id, behavior_type, data):
        """Log student behavior for analysis"""
        try:
            log_data = {
                "student_id": student_id,
                "behavior_type": behavior_type,
                "behavior_data": data,
                "created_at": datetime.now().isoformat()
            }
            supabase.table("student_behavior_logs").insert(log_data).execute()
            return True
        except Exception as e:
            print(f"❌ Behavior logging error: {e}")
            return False

    @staticmethod
    def get_behavioral_insights(student_id, subject_id):
        """Analyze behavioral patterns and generate insights"""
        insights = []
        try:
            activities = supabase.table("student_behavior_logs").select("*").eq("student_id", student_id).execute().data
            subject_activities = [a for a in activities if a.get("behavior_data", {}).get("subject_id") == subject_id]

            # Fetch student subject and related scores
            student_subject = supabase.table("student_subjects").select("*").eq("student_id", student_id).eq("id", subject_id).execute().data
            if student_subject:
                subject_ref = student_subject[0]
                scores = supabase.table("student_subject_scores").select("*").eq("student_subject_id", subject_ref["id"]).execute().data

                if scores:
                    total_score = sum(s["score_value"] for s in scores)
                    total_max = sum(s["max_score"] for s in scores)
                    avg_score = (total_score / total_max) * 100 if total_max else 0
                    dates = [s["score_date"] for s in scores if s.get("score_date")]
                    first_score = min(dates) if dates else None
                    last_score = max(dates) if dates else None

                    # Activity frequency
                    if len(subject_activities) > 10:
                        insights.append({"message": "Consistent engagement with regular score updates.", "priority": "low"})
                    elif len(subject_activities) > 0:
                        insights.append({"message": "Increase engagement frequency for better tracking.", "priority": "medium"})

                    # Score timeliness
                    if first_score and last_score:
                        days_between = (datetime.fromisoformat(last_score) - datetime.fromisoformat(first_score)).days
                        if days_between > 30 and len(scores) < 5:
                            insights.append({"message": "Long gaps between submissions detected.", "priority": "medium"})
                        if avg_score < 70:
                            insights.append({"message": "Low average score detected. Focus on core concepts.", "priority": "high"})

            # Recent activity
            if subject_activities:
                last_activity = subject_activities[-1]
                last_time = datetime.fromisoformat(last_activity["created_at"])
                days_since = (datetime.now() - last_time).days
                if days_since > 7:
                    insights.append({
                        "message": f"It's been {days_since} days since your last activity. Stay engaged.",
                        "priority": "medium"
                    })

        except Exception as e:
            print(f"❌ Behavioral insights error: {e}")

        if not insights:
            insights.append({
                "message": "Continue tracking your scores regularly to generate personalized insights.",
                "priority": "low"
            })
        return insights

    @staticmethod
    def get_interventions(student_id, subject_id, risk_level):
        """Generate intervention recommendations based on risk"""
        interventions = []
        try:
            # Get subject name
            subj_data = supabase.table("student_subjects").select("*").eq("id", subject_id).execute().data
            subject_name = "this subject"
            if subj_data:
                subject_ref = subj_data[0]
                subj_detail = supabase.table("subjects").select("*").eq("id", subject_ref["subject_id"]).execute().data
                if subj_detail:
                    subject_name = subj_detail[0]["subject_name"]

            if risk_level == "high":
                interventions += [
                    {"message": f"Immediate advising recommended for {subject_name}.", "priority": "high"},
                    {"message": "Form a study group or seek tutoring support.", "priority": "high"},
                    {"message": "Focus on foundational concepts before advanced topics.", "priority": "medium"}
                ]
            elif risk_level == "medium":
                interventions += [
                    {"message": f"Schedule review sessions for {subject_name}.", "priority": "medium"},
                    {"message": "Identify areas of difficulty and seek clarification.", "priority": "medium"},
                    {"message": "Increase practice with problem sets.", "priority": "low"}
                ]
            elif risk_level == "low":
                interventions += [
                    {"message": "Maintain current study habits.", "priority": "low"},
                    {"message": "Challenge yourself with advanced topics.", "priority": "low"}
                ]
            else:
                interventions.append({"message": "Keep tracking your progress.", "priority": "low"})

            # Attendance-based check
            categories = supabase.table("student_class_standing_categories").select("*").eq("student_subject_id", subject_id).execute().data
            attendance_cat = next((c for c in categories if c["category_name"].lower() == "attendance"), None)
            if attendance_cat:
                scores = supabase.table("student_subject_scores").select("*").eq("category_id", attendance_cat["id"]).execute().data
                total_classes = len(scores)
                absences = sum(1 for s in scores if s["score_name"].lower() == "absent")
                if total_classes > 0:
                    absence_rate = (absences / total_classes) * 100
                    if absence_rate > 20:
                        interventions.append({
                            "message": f"High absence rate ({round(absence_rate)}%). Regular attendance is crucial.",
                            "priority": "high"
                        })

        except Exception as e:
            print(f"❌ Interventions error: {e}")

        if not interventions:
            interventions.append({"message": "Maintain consistent study habits.", "priority": "medium"})
        return interventions

    @staticmethod
    def get_recommendations(student_id, subject_id, overall_grade):
        """Generate personalized study recommendations"""
        recommendations = []
        try:
            # Subject details
            subj_data = supabase.table("student_subjects").select("*").eq("id", subject_id).execute().data
            subject_name = "this subject"
            if subj_data:
                subject_ref = subj_data[0]
                subj_detail = supabase.table("subjects").select("*").eq("id", subject_ref["subject_id"]).execute().data
                if subj_detail:
                    subject_name = subj_detail[0]["subject_name"]

            # Grade-based advice
            if overall_grade >= 90:
                recommendations.append({"message": f"Excellent work in {subject_name}!", "priority": "low"})
            elif overall_grade >= 80:
                recommendations.append({"message": "Strong performance. Maintain consistency.", "priority": "low"})
            elif overall_grade >= 70:
                recommendations.append({"message": "Solid base. Improve specific weak areas.", "priority": "medium"})
            else:
                recommendations.append({"message": "Focus on core concepts and seek help.", "priority": "high"})

            # Category-specific checks
            categories = supabase.table("student_class_standing_categories").select("*").eq("student_subject_id", subject_id).execute().data
            weak_categories = []
            for cat in categories:
                scores = supabase.table("student_subject_scores").select("*").eq("category_id", cat["id"]).execute().data
                if scores:
                    total_score = sum(s["score_value"] for s in scores)
                    total_max = sum(s["max_score"] for s in scores)
                    avg = (total_score / total_max) * 100 if total_max else 0
                    if avg < 70:
                        weak_categories.append(cat["category_name"])
            if weak_categories:
                recommendations.append({
                    "message": f"Focus improvement on: {', '.join(weak_categories)}.",
                    "priority": "high"
                })

            # Study habit check (recent activity)
            scores = supabase.table("student_subject_scores").select("*").eq("student_subject_id", subject_id).execute().data
            one_week_ago = datetime.now() - timedelta(days=7)
            recent_scores = [s for s in scores if s.get("score_date") and datetime.fromisoformat(s["score_date"]) >= one_week_ago]
            if not recent_scores:
                recommendations.append({"message": "No recent score updates. Stay consistent!", "priority": "medium"})

        except Exception as e:
            print(f"❌ Recommendations error: {e}")

        if not recommendations:
            recommendations.append({"message": "Continue your study habits and track progress.", "priority": "low"})
        return recommendations


class GradeCalculator:
    """Grade computation and prediction logic"""

    @staticmethod
    def predict_final_grade(current_grade, remaining_weight, expected_performance="maintain"):
        multipliers = {"improve": 1.1, "maintain": 1.0, "decline": 0.9}
        multiplier = multipliers.get(expected_performance, 1.0)
        predicted_remaining = remaining_weight * multiplier
        return min(100, current_grade + predicted_remaining)

    @staticmethod
    def calculate_required_performance(current_grade, remaining_weight, target_grade):
        if remaining_weight <= 0:
            return 0
        points_needed = max(0, target_grade - current_grade)
        required_percentage = (points_needed / remaining_weight) * 100
        return min(100, required_percentage)
