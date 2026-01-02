<?php
/**
 * 個人メモAPI
 *
 * エンドポイント:
 *   GET    /api/notes.php                      - メモ一覧取得
 *   GET    /api/notes.php?session_id={id}      - セッション別メモ取得
 *   POST   /api/notes.php                      - メモ追加
 *   PUT    /api/notes.php?id={id}              - メモ更新（ステータス変更等）
 *   DELETE /api/notes.php?id={id}              - メモ削除
 */

require_once __DIR__ . '/db.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$noteId = $_GET['id'] ?? null;
$sessionId = $_GET['session_id'] ?? null;

// 認証チェック
$user = requireAuth();

switch ($method) {
    case 'GET':
        if ($sessionId) {
            handleGetNotesBySession($user, (int)$sessionId);
        } else {
            handleGetAllNotes($user);
        }
        break;

    case 'POST':
        handleCreateNote($user);
        break;

    case 'PUT':
        if (!$noteId) errorResponse('メモIDが必要です');
        handleUpdateNote($user, (int)$noteId);
        break;

    case 'DELETE':
        if (!$noteId) errorResponse('メモIDが必要です');
        handleDeleteNote($user, (int)$noteId);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * 全メモ取得
 */
function handleGetAllNotes(array $user): void
{
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null; // 'solved' or 'unsolved'

    $db = Database::getInstance();

    $sql = '
        SELECT
            cn.note_id,
            cn.session_id,
            cn.content,
            cn.status,
            cn.created_at,
            cn.updated_at,
            s.subject_name,
            cs.unit_name,
            cs.study_date
        FROM class_notes cn
        JOIN class_sessions cs ON cn.session_id = cs.session_id
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cn.user_id = ?
    ';

    $params = [$user['user_id']];

    if ($status && in_array($status, ['solved', 'unsolved'])) {
        $sql .= ' AND cn.status = ?';
        $params[] = $status;
    }

    $sql .= ' ORDER BY cn.created_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $notes = $stmt->fetchAll();

    // 総件数
    $countSql = 'SELECT COUNT(*) as total FROM class_notes WHERE user_id = ?';
    $countParams = [$user['user_id']];
    if ($status && in_array($status, ['solved', 'unsolved'])) {
        $countSql .= ' AND status = ?';
        $countParams[] = $status;
    }
    $stmt = $db->prepare($countSql);
    $stmt->execute($countParams);
    $total = $stmt->fetch()['total'];

    successResponse([
        'notes' => $notes,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

/**
 * セッション別メモ取得
 */
function handleGetNotesBySession(array $user, int $sessionId): void
{
    $db = Database::getInstance();

    // セッション所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('セッションが見つかりません', 404);
    }

    $stmt = $db->prepare('
        SELECT
            note_id,
            content,
            status,
            created_at,
            updated_at
        FROM class_notes
        WHERE session_id = ? AND user_id = ?
        ORDER BY created_at DESC
    ');
    $stmt->execute([$sessionId, $user['user_id']]);
    $notes = $stmt->fetchAll();

    successResponse(['notes' => $notes, 'session_id' => $sessionId]);
}

/**
 * メモ追加
 */
function handleCreateNote(array $user): void
{
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $content = trim($input['content'] ?? '');

    // バリデーション
    if ($sessionId <= 0) {
        errorResponse('セッションIDが必要です');
    }

    if (empty($content)) {
        errorResponse('メモ内容を入力してください');
    }

    if (mb_strlen($content) > 200) {
        errorResponse('メモは200文字以内で入力してください');
    }

    $db = Database::getInstance();

    // セッション所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('無効なセッションです', 403);
    }

    // メモ追加
    $stmt = $db->prepare('
        INSERT INTO class_notes (session_id, user_id, content, status)
        VALUES (?, ?, ?, "unsolved")
    ');
    $stmt->execute([$sessionId, $user['user_id'], $content]);

    $newId = $db->lastInsertId();

    // 作成したメモを取得
    $stmt = $db->prepare('SELECT * FROM class_notes WHERE note_id = ?');
    $stmt->execute([$newId]);
    $note = $stmt->fetch();

    successResponse(['note' => $note], 'メモを追加しました');
}

/**
 * メモ更新（ステータス変更等）
 */
function handleUpdateNote(array $user, int $noteId): void
{
    $db = Database::getInstance();

    // 所有者チェック
    $stmt = $db->prepare('SELECT * FROM class_notes WHERE note_id = ?');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();

    if (!$note) {
        errorResponse('メモが見つかりません', 404);
    }

    if ($note['user_id'] != $user['user_id']) {
        errorResponse('更新権限がありません', 403);
    }

    $input = getJsonInput();
    $updates = [];
    $params = [];

    // ステータス更新
    if (isset($input['status'])) {
        $newStatus = $input['status'];
        if (!in_array($newStatus, ['solved', 'unsolved'])) {
            errorResponse('無効なステータスです');
        }
        $updates[] = 'status = ?';
        $params[] = $newStatus;
    }

    // 内容更新
    if (isset($input['content'])) {
        $content = trim($input['content']);
        if (empty($content)) {
            errorResponse('メモ内容を入力してください');
        }
        if (mb_strlen($content) > 200) {
            errorResponse('メモは200文字以内で入力してください');
        }
        $updates[] = 'content = ?';
        $params[] = $content;
    }

    if (empty($updates)) {
        errorResponse('更新する項目がありません');
    }

    $params[] = $noteId;
    $sql = 'UPDATE class_notes SET ' . implode(', ', $updates) . ' WHERE note_id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // 更新後のメモを取得
    $stmt = $db->prepare('SELECT * FROM class_notes WHERE note_id = ?');
    $stmt->execute([$noteId]);
    $updatedNote = $stmt->fetch();

    successResponse(['note' => $updatedNote], '更新しました');
}

/**
 * メモ削除
 */
function handleDeleteNote(array $user, int $noteId): void
{
    $db = Database::getInstance();

    // 所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_notes WHERE note_id = ?');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();

    if (!$note) {
        errorResponse('メモが見つかりません', 404);
    }

    if ($note['user_id'] != $user['user_id']) {
        errorResponse('削除権限がありません', 403);
    }

    $stmt = $db->prepare('DELETE FROM class_notes WHERE note_id = ?');
    $stmt->execute([$noteId]);

    successResponse([], 'メモを削除しました');
}
