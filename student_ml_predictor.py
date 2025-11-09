import pandas as pd
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.tree import DecisionTreeClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder
import joblib
from flask import Flask, request, jsonify
import json

class StudentGradePredictor:
    def __init__(self):
        self.rf_model = None
        self.dt_model = None
        self.label_encoder = LabelEncoder()
        self.feature_names = ['class_standing_avg', 'exam_score_avg', 'attendance_rate', 'score_consistency', 'subject_grade']
    
    def generate_sample_data(self, num_students=500):
        """Generate training data with risk levels based on subject grade"""
        np.random.seed(42)
        data = []
        for _ in range(num_students):
            class_standing = np.random.normal(75, 15)
            exam_score = np.random.normal(70, 20)
            attendance = np.random.normal(85, 10)
            consistency = np.random.normal(70, 12)
            
            # Calculate subject grade (Midterm 100 + Final 100) / 2
            midterm_grade = (class_standing * 0.6) + (exam_score * 0.4)
            final_grade = (class_standing * 0.6) + (exam_score * 0.4) + np.random.normal(0, 5)
            subject_grade = (midterm_grade + final_grade) / 2
            
            # Risk level based on subject grade
            if subject_grade >= 85: 
                risk_level = 'low_risk'
            elif subject_grade >= 80: 
                risk_level = 'moderate_risk'
            else: 
                risk_level = 'high_risk'
            
            data.append({
                'class_standing_avg': max(0, min(100, class_standing)),
                'exam_score_avg': max(0, min(100, exam_score)),
                'attendance_rate': max(0, min(100, attendance)),
                'score_consistency': max(0, min(100, consistency)),
                'subject_grade': max(0, min(100, subject_grade)),
                'risk_level': risk_level
            })
        
        return pd.DataFrame(data)
    
    def train_models(self):
        """Train ML models using Random Forest and Decision Tree"""
        df = self.generate_sample_data()
        X = df[self.feature_names]
        y = self.label_encoder.fit_transform(df['risk_level'])
        
        X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)
        
        # Random Forest
        self.rf_model = RandomForestClassifier(n_estimators=100, max_depth=10, random_state=42)
        self.rf_model.fit(X_train, y_train)
        
        # Decision Tree
        self.dt_model = DecisionTreeClassifier(max_depth=8, random_state=42)
        self.dt_model.fit(X_train, y_train)
        
        rf_score = self.rf_model.score(X_test, y_test)
        dt_score = self.dt_model.score(X_test, y_test)
        
        print(f"✅ Models trained! RF Accuracy: {rf_score:.2f}, DT Accuracy: {dt_score:.2f}")
        return rf_score, dt_score
    
    def predict_risk(self, student_data):
        """Predict student risk level based on subject grade"""
        if self.rf_model is None:
            self.train_models()

        features_df = pd.DataFrame([student_data], columns=self.feature_names)

        rf_pred = self.rf_model.predict(features_df)[0]
        dt_pred = self.dt_model.predict(features_df)[0]

        rf_risk = self.label_encoder.inverse_transform([rf_pred])[0]
        dt_risk = self.label_encoder.inverse_transform([dt_pred])[0]

        rf_proba = self.rf_model.predict_proba(features_df)[0]

        # Risk level descriptions
        risk_map = {
            'low_risk': 'Excellent/Good Performance',
            'moderate_risk': 'Needs Improvement', 
            'high_risk': 'Need to Communicate with Professor'
        }

        return {
            'risk_level': rf_risk,
            'risk_description': risk_map.get(rf_risk, 'Needs Improvement'),
            'confidence': float(max(rf_proba)),
            'random_forest': risk_map.get(rf_risk, 'Needs Improvement'),
            'decision_tree': risk_map.get(dt_risk, 'Needs Improvement'),
            'subject_grade': student_data['subject_grade']
        }

class BehaviorAnalyzer:
    def __init__(self):
        self.interventions = {
            'low_risk': [
                "Maintain current study schedule",
                "Continue effective learning strategies",
                "Help peers with difficult concepts"
            ],
            'moderate_risk': [
                "Increase study time on challenging topics",
                "Join study groups for collaborative learning",
                "Practice with past examination papers"
            ],
            'high_risk': [
                "Schedule meeting with professor immediately",
                "Request academic advising support",
                "Develop intensive study plan with tutor",
                "Focus on foundational concepts first"
            ]
        }
    
    def analyze_scores(self, class_standings, exam_scores, attendance):
        """Analyze student scores for behavioral insights"""
        insights = []
        
        # Analyze class standing performance
        if class_standings:
            class_avg = np.mean(class_standings)
            class_std = np.std(class_standings)
            
            if class_avg < 75:
                insights.append("Low average in class standing activities - review modules thoroughly")
            if class_std > 15:
                insights.append("Inconsistent performance in class activities - establish study routine")
            
            # Check for very low scores
            low_scores = [score for score in class_standings if score < 60]
            if low_scores:
                insights.append(f"Very low scores detected in {len(low_scores)} assessments - focus on fundamentals")
        
        # Analyze exam performance
        if exam_scores:
            exam_avg = np.mean(exam_scores)
            if exam_avg < 75:
                insights.append("Below passing exam performance - practice more exam-type questions")
        
        # Analyze attendance
        if attendance:
            attendance_rate = np.mean(attendance) * 100
            if attendance_rate < 75:
                insights.append(f"Low attendance rate ({attendance_rate:.1f}%) - regular attendance improves learning")
            elif attendance_rate > 90:
                insights.append("Excellent attendance - maintain this good habit")
        
        return insights
    
    def get_recommendations(self, risk_level, insights, subject, term='midterm'):
        """Generate personalized recommendations based on risk level and scores"""
        recommendations = self.interventions.get(risk_level, [])
        
        # Add insight-based recommendations
        for insight in insights:
            if "low average" in insight.lower():
                recommendations.append("Read modules thoroughly before attempting assessments")
            if "inconsistent" in insight.lower():
                recommendations.append("Establish consistent daily study schedule")
            if "very low scores" in insight.lower():
                recommendations.append("Focus on understanding basic concepts first")
            if "below passing" in insight.lower():
                recommendations.append("Practice with sample exams and seek feedback")
            if "low attendance" in insight.lower():
                recommendations.append("Improve class attendance for better understanding")
        
        # Subject-specific recommendations
        if "programming" in subject.lower():
            recommendations.append("Practice coding exercises regularly")
            if any("low" in insight.lower() for insight in insights):
                recommendations.append("Start with basic programming concepts and build gradually")
        elif "math" in subject.lower():
            recommendations.append("Solve additional practice problems")
            if any("low" in insight.lower() for insight in insights):
                recommendations.append("Focus on understanding formulas and their applications")
        
        # Risk-specific additional recommendations
        if risk_level == 'high_risk':
            recommendations.append("Communicate with professor about your academic challenges")
            recommendations.append("Utilize all available academic support resources")
        
        # Term-specific recommendations
        if term == 'midterm':
            recommendations.append("Use midterm results to prepare final term strategy")
        else:
            recommendations.append("Review overall performance for future course planning")
        
        return list(set(recommendations))[:8]

class StudentPerformanceSystem:
    def __init__(self):
        self.predictor = StudentGradePredictor()
        self.analyzer = BehaviorAnalyzer()
        print("🎓 Student Performance System Initialized!")
    
    def analyze_student(self, student_data):
        """Complete student analysis based on risk levels"""
        # Calculate metrics from raw scores
        metrics = self.calculate_metrics(student_data)
        
        # Predict risk level based on subject grade
        prediction = self.predictor.predict_risk(metrics)
        
        # Behavioral analysis based on inputted scores
        insights = self.analyzer.analyze_scores(
            student_data.get('class_standings', []),
            student_data.get('exam_scores', []),
            student_data.get('attendance', [])
        )
        
        # Add risk-based insights
        if prediction['risk_level'] == 'high_risk':
            insights.append("High risk detected - immediate professor communication recommended")
        elif prediction['risk_level'] == 'moderate_risk':
            insights.append("Moderate risk - focus on improvement strategies")
        elif prediction['risk_level'] == 'low_risk':
            insights.append("Low risk - maintain current study habits")
        
        # Generate recommendations
        recommendations = self.analyzer.get_recommendations(
            prediction['risk_level'],
            insights,
            student_data.get('subject', 'General'),
            student_data.get('term', 'midterm')
        )
        
        return {
            'success': True,
            'risk_level': prediction['risk_level'],
            'risk_description': prediction['risk_description'],
            'confidence': prediction['confidence'],
            'subject_grade': student_data.get('subject_grade', 0),
            'behavioral_insights': insights,
            'recommendations': recommendations,
            'calculated_metrics': metrics
        }
    
    def calculate_metrics(self, data):
        """Calculate features from raw data"""
        class_standings = data.get('class_standings', [70, 75, 80])
        exam_scores = data.get('exam_scores', [70, 75])
        attendance = data.get('attendance', [1, 1, 1, 1, 1])
        subject_grade = data.get('subject_grade', 75)
        
        metrics = {
            'class_standing_avg': np.mean(class_standings) if class_standings else 70,
            'exam_score_avg': np.mean(exam_scores) if exam_scores else 70,
            'attendance_rate': np.mean(attendance) * 100 if attendance else 80,
            'score_consistency': 100 - (np.std(class_standings) if class_standings else 10),
            'subject_grade': subject_grade
        }
        
        # Ensure values are within bounds
        for key in metrics:
            if key != 'subject_grade':
                metrics[key] = max(0, min(100, metrics[key]))
        
        return metrics

# Flask API Server
app = Flask(__name__)
system = StudentPerformanceSystem()

print("Starting Student ML Prediction Server...")
print("Training machine learning models...")
system.predictor.train_models()
print("System ready! API running on http://localhost:5000")

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
            'subject': data.get('subject', 'Mathematics'),
            'term': data.get('term', 'midterm'),
            'subject_grade': data.get('subject_grade', 75)
        }
        
        result = system.analyze_student(student_data)
        return jsonify(result)
        
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})

@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'healthy', 'service': 'Student Performance Predictor'})

@app.route('/example', methods=['GET'])
def example():
    """Example of how to use the API"""
    example_data = {
        'class_standings': [65, 70, 60, 75, 68],
        'exam_scores': [58, 62],
        'attendance': [1, 1, 0, 1, 0, 1, 1, 1, 0, 1],
        'subject': 'Programming',
        'subject_grade': 72
    }
    
    result = system.analyze_student(example_data)
    return jsonify({
        'example_request': example_data,
        'example_response': result
    })

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)