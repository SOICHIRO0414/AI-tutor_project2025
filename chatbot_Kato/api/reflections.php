<?php
/**
 * 振り返りAPI
 *
 * エンドポイント:
 *   GET    /api/reflections.php?session_id={id}  - 振り返り取得
 *   POST   /api/reflections.php                  - 振り返り作成/更新
 */

require_once __DIR__ . '/db.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$sessionId = $_GET['session_id'] ?? null;

// 認証チェック
$user = requireAuth();

switch ($method) {
    case 'GET':
        if (!$sessionId) errorResponse('セッションIDが必要です');
        handleGetReflection($user, (int)$sessionId);
        break;

    case 'POST':
        handleSaveReflection($user);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * 振り返り取得
 */
function handleGetReflection(array $user, int $sessionId): void
{
    $db = Database::getInstance();

    // セッション所有者チェック
    $stmt = $db->prepare('
        SELECT cs.*, s.subject_name
        FROM class_sessions cs
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cs.session_id = ?
    ');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('セッションが見つかりません', 404);
    }

    // 振り返り取得
    $stmt = $db->prepare('SELECT * FROM reflections WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $reflection = $stmt->fetch();

    successResponse([
        'session' => $session,
        'reflection' => $reflection ?: null
    ]);
}

/**
 * 振り返り作成/更新
 */
function handleSaveReflection(array $user): void
{
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $goalText = trim($input['goal_text'] ?? '');
    $understoodText = trim($input['understood_text'] ?? '');
    $questionText = trim($input['question_text'] ?? '');

    // バリデーション
    if ($sessionId <= 0) {
        errorResponse('セッションIDが必要です');
    }

    $db = Database::getInstance();

    // セッション所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('無効なセッションです', 403);
    }

    // 既存の振り返りがあるかチェック
    $stmt = $db->prepare('SELECT reflection_id FROM reflections WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // 更新
        $stmt = $db->prepare('
            UPDATE reflections
            SET goal_text = ?, understood_text = ?, question_text = ?
            WHERE session_id = ?
        ');
        $stmt->execute([$goalText, $understoodText, $questionText, $sessionId]);
        $message = '振り返りを更新しました';
    } else {
        // 新規作成
        $stmt = $db->prepare('
            INSERT INTO reflections (session_id, goal_text, understood_text, question_text)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$sessionId, $goalText, $understoodText, $questionText]);
        $message = '振り返りを保存しました';
    }

    // 保存後のデータを取得
    $stmt = $db->prepare('SELECT * FROM reflections WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $reflection = $stmt->fetch();

    successResponse(['reflection' => $reflection], $message);
}
