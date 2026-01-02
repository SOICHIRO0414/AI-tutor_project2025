<?php
/**
 * 認証API
 *
 * エンドポイント:
 *   POST   /api/auth.php?action=login    - ログイン
 *   POST   /api/auth.php?action=register - ユーザー登録
 *   POST   /api/auth.php?action=logout   - ログアウト
 *   GET    /api/auth.php?action=check    - セッション確認
 *   GET    /api/auth.php?action=subjects - 教科一覧取得
 */

require_once __DIR__ . '/db.php';

handleCors();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'login':
        if ($method !== 'POST') errorResponse('Method not allowed', 405);
        handleLogin();
        break;

    case 'register':
        if ($method !== 'POST') errorResponse('Method not allowed', 405);
        handleRegister();
        break;

    case 'logout':
        if ($method !== 'POST') errorResponse('Method not allowed', 405);
        handleLogout();
        break;

    case 'check':
        if ($method !== 'GET') errorResponse('Method not allowed', 405);
        handleCheck();
        break;

    case 'subjects':
        if ($method !== 'GET') errorResponse('Method not allowed', 405);
        handleGetSubjects();
        break;

    default:
        errorResponse('Invalid action', 400);
}

/**
 * ログイン処理
 */
function handleLogin(): void
{
    $input = getJsonInput();
    $studentCode = trim($input['student_code'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($studentCode) || empty($password)) {
        errorResponse('学籍番号とパスワードを入力してください');
    }

    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, student_code, display_name, password_hash, current_streak FROM users WHERE student_code = ?');
    $stmt->execute([$studentCode]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        errorResponse('学籍番号またはパスワードが正しくありません', 401);
    }

    // 継続日数の更新
    updateStreak($user['id']);

    // セッション開始
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['student_code'] = $user['student_code'];
    $_SESSION['display_name'] = $user['display_name'];

    successResponse([
        'user' => [
            'id' => $user['id'],
            'student_code' => $user['student_code'],
            'display_name' => $user['display_name'],
            'current_streak' => $user['current_streak']
        ]
    ], 'ログインしました');
}

/**
 * ユーザー登録処理
 */
function handleRegister(): void
{
    $input = getJsonInput();
    $studentCode = trim($input['student_code'] ?? '');
    $displayName = trim($input['display_name'] ?? '');
    $password = $input['password'] ?? '';

    // バリデーション
    if (empty($studentCode) || empty($displayName) || empty($password)) {
        errorResponse('すべての項目を入力してください');
    }

    if (strlen($password) < 4) {
        errorResponse('パスワードは4文字以上で入力してください');
    }

    if (mb_strlen($displayName) > 50) {
        errorResponse('ニックネームは50文字以内で入力してください');
    }

    $db = Database::getInstance();

    // 重複チェック
    $stmt = $db->prepare('SELECT id FROM users WHERE student_code = ?');
    $stmt->execute([$studentCode]);
    if ($stmt->fetch()) {
        errorResponse('この学籍番号は既に登録されています');
    }

    // ユーザー作成
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (student_code, display_name, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$studentCode, $displayName, $passwordHash]);

    $userId = $db->lastInsertId();

    // 自動ログイン
    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['student_code'] = $studentCode;
    $_SESSION['display_name'] = $displayName;

    successResponse([
        'user' => [
            'id' => $userId,
            'student_code' => $studentCode,
            'display_name' => $displayName,
            'current_streak' => 0
        ]
    ], '登録が完了しました');
}

/**
 * ログアウト処理
 */
function handleLogout(): void
{
    session_start();
    session_destroy();
    successResponse([], 'ログアウトしました');
}

/**
 * セッション確認
 */
function handleCheck(): void
{
    session_start();
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(['success' => true, 'authenticated' => false]);
    }

    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, student_code, display_name, current_streak FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        jsonResponse(['success' => true, 'authenticated' => false]);
    }

    jsonResponse([
        'success' => true,
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'student_code' => $user['student_code'],
            'display_name' => $user['display_name'],
            'current_streak' => $user['current_streak']
        ]
    ]);
}

/**
 * 教科一覧取得
 */
function handleGetSubjects(): void
{
    $db = Database::getInstance();
    $stmt = $db->query('SELECT subject_id, subject_name FROM subjects ORDER BY subject_id');
    $subjects = $stmt->fetchAll();

    successResponse(['subjects' => $subjects]);
}

/**
 * 継続日数の更新
 */
function updateStreak(int $userId): void
{
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT last_study_date, current_streak FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $newStreak = 1;
    if ($user['last_study_date'] === $yesterday) {
        $newStreak = $user['current_streak'] + 1;
    } elseif ($user['last_study_date'] === $today) {
        $newStreak = $user['current_streak'];
    }

    $stmt = $db->prepare('UPDATE users SET last_study_date = ?, current_streak = ? WHERE id = ?');
    $stmt->execute([$today, $newStreak, $userId]);
}
