<?php
/**
 * アプリケーション設定ファイル
 *
 * 重要: 本番環境ではこのファイルをWebからアクセス不可にするか、
 * 環境変数から読み込むように変更してください
 */

// 開発OS設定: 'windows' または 'mac' を指定
define('DEV_OS', 'mac'); // 自分の環境に合わせて変更してください

// エラー表示設定（本番環境では false に）
define('DEBUG_MODE', true);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// データベース設定
if (DEV_OS === 'windows') {
    // Windows (XAMPP) 設定
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'chatbot_kato');
    define('DB_USER', 'root');
    define('DB_PASS', ''); // XAMPPデフォルトは空文字
} else if (DEV_OS === 'mac') {
    // macOS (MAMP) 設定
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'chatbot_kato');
    define('DB_USER', 'root');
    define('DB_PASS', 'root'); // MAMPデフォルトは 'root'
} else {
    // デフォルト設定
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'chatbot_kato');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

// セッション設定
define('SESSION_LIFETIME', 86400); // 24時間

// ローカルLLM設定
// Ollama の場合
define('LLM_API_URL', 'http://localhost:11434/api/generate');
define('LLM_MODEL', 'gpt-oss:20b'); // 使用するモデル名

// LM Studio の場合はこちらを使用
// define('LLM_API_URL', 'http://localhost:1234/v1/chat/completions');
// define('LLM_MODEL', 'local-model');

// CORS設定（開発用）
define('CORS_ORIGIN', '*');

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// 文字コード
mb_internal_encoding('UTF-8');
