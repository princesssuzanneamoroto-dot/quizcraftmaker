-- QuizCraft database schema (MySQL 5.7+ / MariaDB 10.2+)
-- Import this into the `quizcraft` database in phpMyAdmin (or via mysql CLI).
-- It is safe to run again: existing tables are left untouched.

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('teacher', 'student') NOT NULL,
  avatar        VARCHAR(40) DEFAULT NULL,
  created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_users_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Older installs created `users` with MyISAM; InnoDB is required for the
-- foreign keys below. Safe to re-run.
ALTER TABLE users ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_settings (
  user_id         INT PRIMARY KEY,
  theme           VARCHAR(5) DEFAULT NULL,
  notify_assign   TINYINT(1) NOT NULL DEFAULT 1,
  notify_deadline TINYINT(1) NOT NULL DEFAULT 1,
  notify_updates  TINYINT(1) NOT NULL DEFAULT 1,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedback (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  role       VARCHAR(10) NOT NULL,
  message    TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_feedback_user_time (user_id, created_at),
  CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Classroom data. Each record keeps the browser's client-side id in `client_id`
-- so the existing single-page app can sync its state without rewriting ids.
CREATE TABLE IF NOT EXISTS classes (
  client_id  VARCHAR(64) PRIMARY KEY,
  owner_id   INT NOT NULL,
  code       VARCHAR(16) NOT NULL UNIQUE,
  name       VARCHAR(150) NOT NULL,
  data       LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_classes_owner (owner_id),
  CONSTRAINT fk_classes_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_members (
  class_client_id VARCHAR(64) NOT NULL,
  user_id         INT NOT NULL,
  name            VARCHAR(150) NOT NULL DEFAULT '',
  joined_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_client_id, user_id),
  KEY idx_members_user (user_id),
  CONSTRAINT fk_members_class FOREIGN KEY (class_client_id) REFERENCES classes (client_id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quizzes (
  client_id      VARCHAR(64) PRIMARY KEY,
  owner_id       INT NOT NULL,
  class_client_id VARCHAR(64) DEFAULT NULL,
  published      TINYINT(1) NOT NULL DEFAULT 0,
  data           LONGTEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_quizzes_owner (owner_id),
  KEY idx_quizzes_class (class_client_id, published),
  CONSTRAINT fk_quizzes_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_quizzes_class FOREIGN KEY (class_client_id) REFERENCES classes (client_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS materials (
  client_id       VARCHAR(64) PRIMARY KEY,
  owner_id        INT NOT NULL,
  class_client_id VARCHAR(64) DEFAULT NULL,
  data            LONGTEXT NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_materials_owner (owner_id),
  KEY idx_materials_class (class_client_id),
  CONSTRAINT fk_materials_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_materials_class FOREIGN KEY (class_client_id) REFERENCES classes (client_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submissions (
  client_id      VARCHAR(64) PRIMARY KEY,
  quiz_client_id VARCHAR(64) NOT NULL,
  student_id     INT NOT NULL,
  data           LONGTEXT NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_submissions_quiz (quiz_client_id),
  KEY idx_submissions_student (student_id),
  CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_state (
  user_id  INT PRIMARY KEY,
  practice LONGTEXT NOT NULL,
  att      LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
