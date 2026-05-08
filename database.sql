CREATE DATABASE IF NOT EXISTS job_finder;
USE job_finder;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('job_seeker', 'employer', 'admin') NOT NULL DEFAULT 'job_seeker',
    company_name VARCHAR(160) DEFAULT NULL,
    company_description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    location VARCHAR(120) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    resume_path VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employer_id INT DEFAULT NULL,
    title VARCHAR(120) NOT NULL,
    company VARCHAR(120) NOT NULL,
    company_description TEXT DEFAULT NULL,
    location VARCHAR(60) NOT NULL,
    job_type VARCHAR(40) NOT NULL,
    salary VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    job_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    cover_letter TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_application (user_id, job_id)
);

CREATE TABLE IF NOT EXISTS saved_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    job_id INT NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_saved_job (user_id, job_id)
);

INSERT INTO jobs (title, company, location, job_type, salary, description) VALUES
('Frontend Developer', 'Bright Labs', 'BD', 'Full Time', '$1800 - $2400', 'Build responsive web interfaces and work closely with the product team.'),
('UI/UX Designer', 'Pixel Works', 'PK', 'Remote', '$1500 - $2200', 'Create user flows, wireframes, and polished interface designs.'),
('Marketing Specialist', 'Growth Forge', 'US', 'Full Time', '$2500 - $3200', 'Plan campaigns, optimize acquisition, and support launch strategy.'),
('Data Analyst', 'Insight Stack', 'UK', 'Contract', '$2200 - $2800', 'Analyze business data, build reports, and surface actionable insights.');
