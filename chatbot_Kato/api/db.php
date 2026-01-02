<?php
/**
 * データベース接続クラス
 * シングルトンパターンでPDO接続を管理
 */

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    /**
     * PDOインスタンスを取得（シングルトン）
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (DEBUG_MODE) {
                    die('DB接続エラー: ' . $e->getMessage());
                }
                die('データベースに接続できません');
            }
        }
        return self::$instance;
    }

    /**
     * 接続を閉じる
     */
    public static function close(): void
    {
        self::$instance = null;
    }
}

/**
 * APIレスポンス用ヘルパー関数
 */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * エラーレスポンス
 */
function errorResponse(string $message, int $status = 400): void
{
    jsonResponse(['success' => false, 'error' => $message], $status);
}

/**
 * 成功レスポンス
 */
function successResponse(array $data = [], string $message = 'OK'): void
{
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

/**
 * リクエストボディをJSONとして取得
 */
function getJsonInput(): array
{
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

/**
 * 認証チェック（セッションベース）
 */
function requireAuth(): array
{
    session_start();
    if (!isset($_SESSION['user_id'])) {
        errorResponse('認証が必要です', 401);
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'student_code' => $_SESSION['student_code'],
        'display_name' => $_SESSION['display_name']
    ];
}

/**
 * OPTIONSリクエスト処理（CORS preflight）
 */
function handleCors(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        exit(0);
    }
}
