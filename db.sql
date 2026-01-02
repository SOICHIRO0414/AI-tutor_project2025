-- ==========================================================
-- 中学生向け学習支援サイト データベース構築用SQL
-- ==========================================================

-- 1. データベース作成（存在しない場合）
CREATE DATABASE IF NOT EXISTS chatbot_kato CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chatbot_kato;

-- 2. 日本語対応のための文字コード設定
SET NAMES utf8mb4;

-- 3. 既存テーブルのリセット（外部キーの制約順に削除）
-- 警告: 既存のデータはすべて消えます
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS shared_questions;
DROP TABLE IF EXISTS class_notes;
DROP TABLE IF EXISTS reflections;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS class_sessions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS subjects;
SET FOREIGN_KEY_CHECKS = 1;

-- ==========================================================
-- 4. テーブル作成 (DDL)
-- ==========================================================

-- ① 教科マスタテーブル
-- ----------------------------------------------------------
CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY COMMENT '教科ID',
    subject_name VARCHAR(20) NOT NULL UNIQUE COMMENT '教科名'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='教科一覧';

-- ② ユーザーテーブル
-- ----------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'システム内部ID',
    student_code VARCHAR(50) UNIQUE NOT NULL COMMENT '学籍番号/ログインID',
    display_name VARCHAR(50) NOT NULL COMMENT 'ニックネーム',
    password_hash VARCHAR(255) NOT NULL COMMENT 'パスワード(ハッシュ値)',

    -- モチベーション管理用（継続日数）
    last_study_date DATE DEFAULT NULL COMMENT '最終学習日',
    current_streak INT DEFAULT 0 COMMENT '継続学習日数',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '登録日時',

    INDEX idx_student_code (student_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生徒情報';

-- ③ 授業セッションテーブル（学習活動の中心）
-- ----------------------------------------------------------
CREATE TABLE class_sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'セッションID',
    user_id INT NOT NULL COMMENT '生徒ID',
    subject_id INT NOT NULL COMMENT '教科ID',

    -- 学習開始時の入力情報
    study_date DATE NOT NULL COMMENT '学習日',
    period TINYINT NOT NULL COMMENT '時限(1〜12)',
    unit_name VARCHAR(100) COMMENT '単元名(例:二次関数)',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- 外部キー制約
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),

    -- インデックス
    INDEX idx_sessions_user_date (user_id, study_date DESC),
    INDEX idx_sessions_date (study_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授業記録';

-- ④ チャットメッセージテーブル
-- ----------------------------------------------------------
CREATE TABLE chat_messages (
    message_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    sender ENUM('user', 'ai') NOT NULL COMMENT '発言者(user/ai)',
    content TEXT NOT NULL COMMENT '会話内容',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_messages_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI対話履歴';

-- ⑤ 振り返りテーブル（3つの入力欄）
-- ----------------------------------------------------------
CREATE TABLE reflections (
    reflection_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL UNIQUE COMMENT '1セッション1振り返り',

    -- 振り返り3要素
    goal_text TEXT COMMENT 'めあて',
    understood_text TEXT COMMENT 'わかったこと',
    question_text TEXT COMMENT '疑問・次の課題',

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学習振り返り';

-- ⑥ 共有疑問テーブル（みんなへの共有）
-- ----------------------------------------------------------
CREATE TABLE shared_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id INT NOT NULL COMMENT '投稿者ID',
    content VARCHAR(50) NOT NULL COMMENT '共有疑問（20文字程度）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_shared_date (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='共有疑問一覧';

-- ⑦ 個人メモテーブル（授業中の疑問メモ）
-- ----------------------------------------------------------
CREATE TABLE class_notes (
    note_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    user_id INT NOT NULL COMMENT '所有者ID',
    content VARCHAR(200) NOT NULL COMMENT '疑問内容',
    status ENUM('unsolved', 'solved') DEFAULT 'unsolved' COMMENT '解決状態',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notes_user (user_id),
    INDEX idx_notes_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='個人の疑問メモ';

-- ==========================================================
-- 5. 初期データ投入 (DML)
-- ==========================================================

-- 教科データの登録（アプリに合わせた12教科）
INSERT INTO subjects (subject_name) VALUES
('国語'), ('社会'), ('数学'), ('理科'), ('英語'),
('美術'), ('保体'), ('技家'), ('音楽'), ('道徳'), ('総合'), ('学活');

-- テスト用生徒データ
-- パスワードは 'password123' をハッシュ化したもの（PHP password_hash使用想定）
-- 実際の運用時はPHP側でハッシュ化して登録すること
INSERT INTO users (student_code, display_name, password_hash, last_study_date, current_streak) VALUES
('ST001', 'タナカ', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', CURDATE(), 1),
('ST002', 'サトウ', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 5),
('ST003', 'ヤマダ', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', NULL, 0);

-- テスト用授業セッション（タナカくんが数学を勉強中）
INSERT INTO class_sessions (user_id, subject_id, study_date, period, unit_name)
VALUES (1, 3, CURDATE(), 1, '二次関数');

-- テスト用チャット履歴
INSERT INTO chat_messages (session_id, sender, content) VALUES
(1, 'user', 'y=ax^2のaが大きくなるとどうなる？'),
(1, 'ai', 'aの値が大きくなると、グラフの開き具合が狭くなりますよ。では確認問題です。a=1とa=2のグラフでは、どちらが開き具合が狭いでしょうか？');

-- テスト用振り返り
INSERT INTO reflections (session_id, goal_text, understood_text, question_text)
VALUES (1, 'グラフを書けるようになる', 'aがプラスだと上に開く', '変域の求め方がまだ曖昧');

-- テスト用共有疑問
INSERT INTO shared_questions (session_id, user_id, content) VALUES
(1, 1, '放物線のグラフの特徴');

-- テスト用個人メモ
INSERT INTO class_notes (session_id, user_id, content, status) VALUES
(1, 1, 'aの値とグラフの関係', 'solved'),
(1, 1, '変域の求め方', 'unsolved');

-- ==========================================================
-- 完了メッセージ
-- ==========================================================
SELECT 'データベース構築完了！' AS Status,
       (SELECT COUNT(*) FROM subjects) AS '教科数',
       (SELECT COUNT(*) FROM users) AS 'ユーザー数';
