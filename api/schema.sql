-- 数独·静心一刻 数据库结构（阶段一）
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(32)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nickname      VARCHAR(32)  NOT NULL DEFAULT '',
  avatar        VARCHAR(255) NOT NULL DEFAULT '',
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  status        TINYINT      NOT NULL DEFAULT 1,
  exp           INT          NOT NULL DEFAULT 0,
  level         INT          NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_progress (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  cells      VARCHAR(81) NOT NULL DEFAULT '',
  notes      TEXT NULL,
  seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  revision   BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_level (user_id, level),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_records (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  seconds    INT UNSIGNED NOT NULL,
  is_record  TINYINT(1) NOT NULL DEFAULT 0,
  used_hint  TINYINT(1) NOT NULL DEFAULT 0,
  played_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id, level),
  INDEX idx_records_level_user_seconds (level, user_id, seconds),
  CONSTRAINT fk_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS achievements (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(64) NOT NULL UNIQUE,
  name        VARCHAR(64) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  icon        VARCHAR(255) NOT NULL DEFAULT '',
  category    ENUM('progress','speed','persistence','challenge','daily') NOT NULL DEFAULT 'progress',
  cond_type   VARCHAR(32) NOT NULL,
  cond_value  INT NOT NULL DEFAULT 0,
  points      INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_achievements (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  achievement_id INT UNSIGNED NOT NULL,
  unlocked_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_ach (user_id, achievement_id),
  CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_ach  FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 每日挑战完成记录（每天每用户一条）
CREATE TABLE IF NOT EXISTS daily_records (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  date       DATE NOT NULL,
  seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  solved_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_date (user_id, date),
  CONSTRAINT fk_daily_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 游戏尝试记录（幂等结算 + 防刷分；存量部署运行 php api/migrate.php 迁移）
CREATE TABLE IF NOT EXISTS game_attempts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  level           SMALLINT UNSIGNED NOT NULL,
  attempt_key     VARCHAR(64) NOT NULL,
  started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  submitted_cells VARCHAR(81) NULL,
  is_valid        TINYINT(1) NOT NULL DEFAULT 0,
  used_hint       TINYINT(1) NOT NULL DEFAULT 0,
  result_json     TEXT NULL,
  UNIQUE KEY uq_user_level_key (user_id, level, attempt_key),
  INDEX idx_attempt_user (user_id, level),
  CONSTRAINT fk_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 云进度历史快照（恢复时写回当前 game_progress）
CREATE TABLE IF NOT EXISTS game_progress_history (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  level      SMALLINT UNSIGNED NOT NULL,
  cells      VARCHAR(81) NOT NULL DEFAULT '',
  notes      TEXT NULL,
  seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  revision   BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_history_user_level (user_id, level, id),
  CONSTRAINT fk_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
