-- WARNING: This schema is for context only and is not meant to be run.
-- Table order and constraints may not be valid for execution.

CREATE TABLE public.admins (
  id bigint NOT NULL DEFAULT nextval('admins_id_seq'::regclass),
  username character varying NOT NULL UNIQUE,
  email character varying NOT NULL UNIQUE,
  password character varying NOT NULL,
  fullname character varying NOT NULL,
  role character varying DEFAULT 'admin'::character varying,
  is_active boolean DEFAULT true,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT admins_pkey PRIMARY KEY (id)
);
CREATE TABLE public.otp_verification (
  id bigint NOT NULL DEFAULT nextval('otp_verification_id_seq'::regclass),
  email character varying NOT NULL,
  otp_code character varying NOT NULL,
  expires_at timestamp with time zone NOT NULL,
  is_used boolean DEFAULT false,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT otp_verification_pkey PRIMARY KEY (id)
);
CREATE TABLE public.student_behavior_logs (
  id bigint NOT NULL DEFAULT nextval('student_behavior_logs_id_seq'::regclass),
  student_id bigint NOT NULL,
  behavior_type character varying NOT NULL,
  behavior_data jsonb,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT student_behavior_logs_pkey PRIMARY KEY (id),
  CONSTRAINT student_behavior_logs_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(id)
);
CREATE TABLE public.student_behavioral_metrics (
  id bigint NOT NULL DEFAULT nextval('student_behavioral_metrics_id_seq'::regclass),
  student_id bigint NOT NULL,
  metric_type character varying CHECK (metric_type::text = ANY (ARRAY['login'::character varying, 'grade_update'::character varying, 'subject_added'::character varying, 'intervention_completed'::character varying]::text[])),
  metric_value numeric,
  metric_data jsonb,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT student_behavioral_metrics_pkey PRIMARY KEY (id),
  CONSTRAINT student_behavioral_metrics_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(id)
);
CREATE TABLE public.student_class_standing_categories (
  id bigint NOT NULL DEFAULT nextval('student_class_standing_categories_id_seq'::regclass),
  student_subject_id bigint NOT NULL,
  category_name character varying NOT NULL,
  category_percentage numeric NOT NULL,
  created_at timestamp with time zone DEFAULT now(),
  term_type character varying NOT NULL DEFAULT 'midterm'::character varying CHECK (term_type::text = ANY (ARRAY['midterm'::character varying, 'final'::character varying]::text[])),
  CONSTRAINT student_class_standing_categories_pkey PRIMARY KEY (id),
  CONSTRAINT student_class_standing_categories_student_subject_id_fkey FOREIGN KEY (student_subject_id) REFERENCES public.student_subjects(id)
);
CREATE TABLE public.student_interventions (
  id bigint NOT NULL DEFAULT nextval('student_interventions_id_seq'::regclass),
  student_id bigint NOT NULL,
  intervention_type character varying,
  title character varying NOT NULL,
  description text,
  status character varying DEFAULT 'pending'::character varying CHECK (status::text = ANY (ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying]::text[])),
  priority character varying DEFAULT 'medium'::character varying CHECK (priority::text = ANY (ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying]::text[])),
  due_date date,
  created_at timestamp with time zone DEFAULT now(),
  completed_at timestamp with time zone,
  CONSTRAINT student_interventions_pkey PRIMARY KEY (id),
  CONSTRAINT student_interventions_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(id)
);
CREATE TABLE public.student_predictions (
  id bigint NOT NULL DEFAULT nextval('student_predictions_id_seq'::regclass),
  student_id bigint NOT NULL,
  prediction_type character varying CHECK (prediction_type::text = ANY (ARRAY['performance'::character varying, 'completion'::character varying, 'risk'::character varying]::text[])),
  prediction_value numeric,
  confidence numeric,
  prediction_data jsonb,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT student_predictions_pkey PRIMARY KEY (id),
  CONSTRAINT student_predictions_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(id)
);
CREATE TABLE public.student_subject_scores (
  id bigint NOT NULL DEFAULT nextval('student_subject_scores_id_seq'::regclass),
  student_subject_id bigint NOT NULL,
  category_id bigint,
  score_type character varying NOT NULL CHECK (score_type::text = ANY (ARRAY['class_standing'::character varying, 'midterm_exam'::character varying, 'final_exam'::character varying]::text[])),
  score_name character varying NOT NULL,
  score_value numeric DEFAULT 0,
  max_score numeric DEFAULT 100,
  score_date date,
  created_at timestamp with time zone DEFAULT now(),
  updated_at timestamp with time zone DEFAULT now(),
  CONSTRAINT student_subject_scores_pkey PRIMARY KEY (id),
  CONSTRAINT student_subject_scores_student_subject_id_fkey FOREIGN KEY (student_subject_id) REFERENCES public.student_subjects(id),
  CONSTRAINT student_subject_scores_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.student_class_standing_categories(id)
);
CREATE TABLE public.student_subjects (
  id bigint NOT NULL DEFAULT nextval('student_subjects_id_seq'::regclass),
  student_id bigint NOT NULL,
  subject_id bigint NOT NULL,
  professor_name character varying NOT NULL,
  schedule character varying,
  archived boolean DEFAULT false,
  archived_at timestamp with time zone,
  deleted_at timestamp with time zone,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT student_subjects_pkey PRIMARY KEY (id),
  CONSTRAINT student_subjects_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.students(id),
  CONSTRAINT student_subjects_subject_id_fkey FOREIGN KEY (subject_id) REFERENCES public.subjects(id)
);
CREATE TABLE public.students (
  id bigint NOT NULL DEFAULT nextval('students_id_seq'::regclass),
  student_number character varying NOT NULL UNIQUE,
  fullname character varying NOT NULL,
  email character varying NOT NULL UNIQUE,
  year_level integer NOT NULL,
  semester character varying NOT NULL,
  section character varying NOT NULL,
  course character varying NOT NULL,
  profile_picture character varying DEFAULT NULL::character varying,
  password character varying,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT students_pkey PRIMARY KEY (id)
);
CREATE TABLE public.subject_performance (
  id bigint NOT NULL DEFAULT nextval('subject_performance_id_seq'::regclass),
  student_subject_id bigint NOT NULL,
  overall_grade numeric DEFAULT 0,
  gpa numeric DEFAULT 0,
  class_standing numeric DEFAULT 0,
  exams_score numeric DEFAULT 0,
  risk_level character varying DEFAULT 'no-data'::character varying,
  risk_description character varying DEFAULT 'No Data Inputted'::character varying,
  created_at timestamp with time zone DEFAULT now(),
  updated_at timestamp with time zone DEFAULT now(),
  CONSTRAINT subject_performance_pkey PRIMARY KEY (id),
  CONSTRAINT subject_performance_student_subject_id_fkey FOREIGN KEY (student_subject_id) REFERENCES public.student_subjects(id)
);
CREATE TABLE public.subjects (
  id bigint NOT NULL DEFAULT nextval('subjects_id_seq'::regclass),
  subject_code character varying NOT NULL UNIQUE,
  subject_name character varying NOT NULL,
  credits integer NOT NULL,
  semester character varying,
  created_at timestamp with time zone DEFAULT now(),
  CONSTRAINT subjects_pkey PRIMARY KEY (id)
);