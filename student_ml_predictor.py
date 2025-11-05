import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.tree import DecisionTreeClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
import joblib
from flask import Flask, request, jsonify
import json

# =============================================================================
# 1. STUDENT GRADE PREDICTOR WITH GWA
# =============================================================================
class StudentGradePredictor:
    def __init__(self):
        self.rf_model = None
        self.dt_model = None
        self.label_encoder = LabelEncoder()
        self.feature_names = ['class_standing_avg', 'exam_score_avg', 'attendance_rate', 'score_consistency']
    
    def generate_sample_data(self, num_students=500):
        """Generate training data with GWA-based risk"""
        np.random.seed(42)
        data = []
        for _ in range(num_students):
            class_standing = np.random.normal(75, 15)
            exam_score = np.random.normal(70, 20)
            attendance = np.random.normal(85, 10)
            consistency = np.random.normal(70, 12)
            
            # Calculate GWA first
            overall_grade = (class_standing * 0.6) + (exam_score * 0.4)
            gwa = self.calculate_gwa(overall_grade)
            
            # Risk based on GWA
            if gwa <= 1.75: risk = 'low'
            elif gwa <= 2.50: risk = 'medium'
            else: risk = 'high'
            
            data.append({
                'class_standing_avg': max(0, min(100, class_standing)),
                'exam_score_avg': max(0, min(100, exam_score)),
                'attendance_rate': max(0, min(100, attendance)),
                'score_consistency': max(0, min(100, consistency)),
                'risk_level': risk
            })
        
        return pd.DataFrame(data)
    
    def calculate_gwa(self, grade):
        """Convert grade to GWA (Philippine system)"""
        if grade >= 90: return 1.00
        elif grade >= 85: return 1.25
        elif grade >= 80: return 1.50
        elif grade >= 75: return 1.75
        elif grade >= 70: return 2.00
        elif grade >= 65: return 2.25
        elif grade >= 60: return 2.50
        elif grade >= 55: return 2.75
        elif grade >= 50: return 3.00
        else: return 5.00
    
    def train_models(self):
        """Train ML models"""
        df = self.generate_sample_data()
        X = df[self.feature_names]
        y = self.label_encoder.fit_transform(df['risk_level'])
        
        X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
        
        # Random Forest
        self.rf_model = RandomForestClassifier(n_estimators=50, max_depth=8, random_state=42)
        self.rf_model.fit(X_train, y_train)
        
        # Decision Tree
        self.dt_model = DecisionTreeClassifier(max_depth=5, random_state=42)
        self.dt_model.fit(X_train, y_train)
        
        rf_score = self.rf_model.score(X_test, y_test)
        dt_score = self.dt_model.score(X_test, y_test)
        
        print(f"✅ Models trained! RF Accuracy: {rf_score:.2f}, DT Accuracy: {dt_score:.2f}")
        return rf_score, dt_score
    
    def predict_risk(self, student_data):
    """Predict student performance level"""
    if self.rf_model is None:
        self.train_models()

    features_df = pd.DataFrame([student_data], columns=self.feature_names)

    rf_pred = self.rf_model.predict(features_df)[0]
    dt_pred = self.dt_model.predict(features_df)[0]

    rf_risk = self.label_encoder.inverse_transform([rf_pred])[0]
    dt_risk = self.label_encoder.inverse_transform([dt_pred])[0]

    rf_proba = self.rf_model.predict_proba(features_df)[0]

    # Convert risk to description
    performance_map = {
        'low': 'Excellent Performance',
        'medium': 'Good Performance',
        'high': 'Needs Improvement'
    }

    return {
        'risk_level': rf_risk,          
        'performance_level': performance_map.get(rf_risk, 'Good Performance'),
        'confidence': float(max(rf_proba)),
        'random_forest': performance_map.get(rf_risk, 'Good Performance'),
        'decision_tree': performance_map.get(dt_risk, 'Good Performance')
    }


# =============================================================================
# BEHAVIOR & RECOMMENDATION 
# =============================================================================
class BehaviorAnalyzer:
    def __init__(self):
        self.interventions = {
            'low': [
                "Maintain current study habits",
                "Consider advanced topics",
                "Help peers with difficult concepts"
            ],
            'medium': [
                "Increase study time by 30 minutes daily",
                "Focus on weak areas identified",
                "Join study group for better understanding"
            ],
            'high': [
                "Seek immediate academic advising",
                "Create intensive study plan",
                "Request tutoring sessions"
            ]
        }
    
    def analyze_behavior(self, scores, attendance):
        """Analyze student behavior patterns"""
        insights = []
        
        if len(scores) > 1:
            score_std = np.std(scores)
            if score_std > 15:
                insights.append("Inconsistent performance detected")
            elif score_std < 5:
                insights.append("Very consistent performance")
        
        if attendance:
            attendance_rate = np.mean(attendance) * 100
            if attendance_rate < 75:
                insights.append(f"Low attendance ({attendance_rate:.1f}%) affecting grades")
            elif attendance_rate > 90:
                insights.append("Excellent attendance record")
        
        if len(scores) > 2:
            recent_trend = np.mean(scores[-2:]) - np.mean(scores[:2])
            if recent_trend < -5:
                insights.append("Recent performance decline")
            elif recent_trend > 5:
                insights.append("Recent improvement detected")
        
        return insights
    
    def get_recommendations(self, risk_level, insights, subject):
        """Generate personalized recommendations"""
        recommendations = self.interventions.get(risk_level, [])
        
        # Add insight-based recommendations
        for insight in insights:
            if "inconsistent" in insight.lower():
                recommendations.append("Practice regular study schedule")
            if "attendance" in insight.lower():
                recommendations.append("Improve class attendance")
            if "decline" in insight.lower():
                recommendations.append("Review recent topics thoroughly")
        
        # Subject-specific
        if "programming" in subject.lower():
            recommendations.append("Practice coding exercises daily")
        elif "math" in subject.lower():
            recommendations.append("Solve additional practice problems")
        
        return list(set(recommendations))[:6]  # Limit to 6 recommendations

# =============================================================================
#  MAIN SYSTEM INTEGRATION
# =============================================================================
class StudentPerformanceSystem:
    def __init__(self):
        self.predictor = StudentGradePredictor()
        self.analyzer = BehaviorAnalyzer()
        print("🎓 Student Performance System Initialized!")
    
    def analyze_student(self, student_data):
        """Complete student analysis"""
        # Calculate metrics from raw scores
        metrics = self.calculate_metrics(student_data)
        
        # Predict risk level
        prediction = self.predictor.predict_risk(metrics)
        
        # Behavioral analysis
        insights = self.analyzer.analyze_behavior(
            student_data.get('class_standings', []),
            student_data.get('attendance', [])
        )
        
        # Generate recommendations
        recommendations = self.analyzer.get_recommendations(
            prediction['risk_level'],
            insights,
            student_data.get('subject', 'General')
        )
        
        # Calculate final grade and GWA
        overall_grade = self.calculate_final_grade(metrics)
        gwa = self.predictor.calculate_gwa(overall_grade)
        
        return {
            'success': True,
            'risk_level': prediction['risk_level'],
            'confidence': prediction['confidence'],
            'overall_grade': overall_grade,
            'gwa': gwa,
            'behavioral_insights': insights,
            'recommendations': recommendations,
            'calculated_metrics': metrics
        }
    
    def calculate_metrics(self, data):
        """Calculate features from raw data"""
        class_standings = data.get('class_standings', [70, 75, 80])
        exam_scores = data.get('exam_scores', [70, 75])
        attendance = data.get('attendance', [1, 1, 1, 1, 0])  # 1=present, 0=absent
        
        metrics = {
            'class_standing_avg': np.mean(class_standings) if class_standings else 70,
            'exam_score_avg': np.mean(exam_scores) if exam_scores else 70,
            'attendance_rate': np.mean(attendance) * 100 if attendance else 80,
            'score_consistency': 100 - (np.std(class_standings) if class_standings else 10)
        }
        
        # Ensure values are within bounds
        for key in metrics:
            metrics[key] = max(0, min(100, metrics[key]))
        
        return metrics
    
    def calculate_final_grade(self, metrics):
        """Calculate final grade (60% class standing + 40% exams)"""
        final_grade = (metrics['class_standing_avg'] * 0.6) + (metrics['exam_score_avg'] * 0.4)
        return round(min(100, max(0, final_grade)), 1)

# =============================================================================
# 4. FLASK API SERVER
# =============================================================================
app = Flask(__name__)
system = StudentPerformanceSystem()

print("🚀 Starting Student ML Prediction Server...")
print("📊 Training machine learning models...")
system.predictor.train_models()
print("✅ System ready! API running on http://localhost:5000")

@app.route('/predict', methods=['POST'])
def predict():
    """Main prediction endpoint"""
    try:
        data = request.json
        
        # Required fields with defaults
        student_data = {
            'class_standings': data.get('class_standings', [70, 75, 80]),
            'exam_scores': data.get('exam_scores', [70, 75]),
            'attendance': data.get('attendance', [1, 1, 1, 1, 1]),
            'subject': data.get('subject', 'Mathematics')
        }
        
        result = system.analyze_student(student_data)
        return jsonify(result)
        
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'healthy', 'service': 'Student ML Predictor'})

@app.route('/example', methods=['GET'])
def example():
    """Example of how to use the API"""
    example_data = {
        'class_standings': [65, 70, 60, 75, 68],
        'exam_scores': [58, 62],
        'attendance': [1, 1, 0, 1, 0, 1, 1, 1, 0, 1],
        'subject': 'Programming'
    }
    
    result = system.analyze_student(example_data)
    return jsonify({
        'example_request': example_data,
        'example_response': result
    })

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)