CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(20) UNIQUE NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    year_level INT NOT NULL,
    semester VARCHAR(20) NOT NULL,  
    section VARCHAR(10) NOT NULL,
    course VARCHAR(100) NOT NULL,
    profile_picture VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE otp_verification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    otp_code VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    is_used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_otp (email, otp_code),
    INDEX idx_expires (expires_at)
);

INSERT INTO students (student_number, fullname, email, year_level, semester, section, course) 
VALUES 
('21-00598', 'Mary Grace Valerio', 'valerio_marygrace@plpasig.edu.ph', 2, '1st', 'A', 'BS Information Technology'),
('21-00958', 'Dannah Angela Velasco', 'velasco_dannahangela@plpasig.edu.ph', 2, '1st', 'B', 'BS Information Technology');

CREATE TABLE subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subject_code VARCHAR(50) NOT NULL UNIQUE,
    subject_name VARCHAR(255) NOT NULL,
    credits INT NOT NULL,
    semester VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE student_subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    professor_name VARCHAR(255) NOT NULL,
    schedule VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

DELETE FROM subjects;

-- Insert First Semester
INSERT INTO subjects (subject_code, subject_name, credits, semester) VALUES
('COMP 104', 'Data Structures and Algorithms', 3, 'First Semester'),
('COMP 105', 'Information Management', 3, 'First Semester'),
('IT 102', 'Quantitative Methods', 3, 'First Semester'),
('IT 201', 'IT Elective: Platform Technologies', 3, 'First Semester'),
('IT 202', 'IT Elective: Object-Oriented Programming (VB.Net)', 3, 'First Semester');

-- Insert Second Semester
INSERT INTO subjects (subject_code, subject_name, credits, semester) VALUES
('IT 103', 'Advanced Database Systems', 3, 'Second Semester'),
('IT 104', 'Integrative Programming and Technologies I', 3, 'Second Semester'),
('IT 105', 'Networking I', 3, 'Second Semester'),
('IT 301', 'Web Programming', 3, 'Second Semester'),
('COMP 106', 'Applications Development and Emerging Technologies', 3, 'Second Semester');


CREATE TABLE IF NOT EXISTS student_class_standing_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_subject_id INT NOT NULL,
    category_name VARCHAR(255) NOT NULL,
    category_percentage DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_subject_id) REFERENCES student_subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS student_subject_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_subject_id INT NOT NULL,
    category_id INT NULL,
    score_type ENUM('class_standing', 'midterm_exam', 'final_exam') NOT NULL,
    score_name VARCHAR(255) NOT NULL,
    score_value DECIMAL(5,2) DEFAULT 0,
    max_score DECIMAL(5,2) DEFAULT 100,
    score_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_subject_id) REFERENCES student_subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES student_class_standing_categories(id) ON DELETE SET NULL
);


CREATE TABLE student_behavioral_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    metric_type ENUM('login', 'grade_update', 'subject_added', 'intervention_completed'),
    metric_value DECIMAL(10,2),
    metric_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE student_interventions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    intervention_type VARCHAR(100),
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    due_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE student_predictions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    prediction_type ENUM('performance', 'completion', 'risk'),
    prediction_value DECIMAL(5,2),
    confidence DECIMAL(5,2),
    prediction_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_student_prediction (student_id, prediction_type, created_at)
);

CREATE TABLE archived_subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    professor_name VARCHAR(255) NOT NULL,
    schedule VARCHAR(100) NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE archived_class_standing_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archived_subject_id INT NOT NULL,
    category_name VARCHAR(255) NOT NULL,
    category_percentage DECIMAL(5,2) NOT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archived_subject_id) REFERENCES archived_subjects(id) ON DELETE CASCADE
);

CREATE TABLE archived_subject_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archived_category_id INT NOT NULL,
    score_type ENUM('class_standing', 'midterm_exam', 'final_exam') NOT NULL,
    score_name VARCHAR(255) NOT NULL,
    score_value DECIMAL(5,2) DEFAULT 0,
    max_score DECIMAL(5,2) DEFAULT 100,
    score_date DATE NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archived_category_id) REFERENCES archived_class_standing_categories(id) ON DELETE CASCADE
);

CREATE TABLE archived_subject_performance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archived_subject_id INT NOT NULL,
    overall_grade DECIMAL(5,2) DEFAULT 0,
    gpa DECIMAL(3,2) DEFAULT 0,
    class_standing DECIMAL(5,2) DEFAULT 0,
    exams_score DECIMAL(5,2) DEFAULT 0,
    risk_level VARCHAR(20) DEFAULT 'no-data',
    risk_description VARCHAR(255) DEFAULT 'No Data Inputted',
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (archived_subject_id) REFERENCES archived_subjects(id) ON DELETE CASCADE
);

CREATE TABLE subject_performance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_subject_id INT NOT NULL,
    overall_grade DECIMAL(5,2) DEFAULT 0,
    gpa DECIMAL(3,2) DEFAULT 0,
    class_standing DECIMAL(5,2) DEFAULT 0,
    exams_score DECIMAL(5,2) DEFAULT 0,
    risk_level VARCHAR(20) DEFAULT 'no-data',
    risk_description VARCHAR(255) DEFAULT 'No Data Inputted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_subject_id) REFERENCES student_subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS student_behavior_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    behavior_type VARCHAR(50) NOT NULL,
    behavior_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_behavior (student_id, behavior_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
