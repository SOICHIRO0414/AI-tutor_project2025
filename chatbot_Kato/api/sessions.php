<?php
/**
 * 授業セッションAPI
 *
 * エンドポイント:
 *   POST   /api/sessions.php              - 新規セッション作成
 *   GET    /api/sessions.php              - 履歴一覧取得
 *   GET    /api/sessions.php?id={id}      - セッション詳細取得
 *   GET    /api/sessions.php?action=current - 現在のセッション取得/作成
 *   PUT    /api/sessions.php?id={id}      - セッション更新
 */

require_once __DIR__ . '/db.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$sessionId = $_GET['id'] ?? null;

// 認証チェック
$user = requireAuth();

switch ($method) {
    case 'GET':
        if ($action === 'current') {
            handleGetOrCreateCurrent($user);
        } elseif ($sessionId) {
            handleGetSession($user, (int)$sessionId);
        } else {
            handleGetHistory($user);
        }
        break;

    case 'POST':
        handleCreateSession($user);
        break;

    case 'PUT':
        if (!$sessionId) errorResponse('セッションIDが必要です');
        handleUpdateSession($user, (int)$sessionId);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * セッション作成
 */
function handleCreateSession(array $user): void
{
    $input = getJsonInput();

    $subjectId = (int)($input['subject_id'] ?? 0);
    $studyDate = $input['study_date'] ?? date('Y-m-d');
    $period = (int)($input['period'] ?? 1);
    $unitName = trim($input['unit_name'] ?? '');

    // バリデーション
    if ($subjectId <= 0) {
        errorResponse('教科を選択してください');
    }

    if ($period < 1 || $period > 12) {
        errorResponse('時限は1〜12の間で入力してください');
    }

    $db = Database::getInstance();

    // 教科存在チェック
    $stmt = $db->prepare('SELECT subject_id FROM subjects WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    if (!$stmt->fetch()) {
        errorResponse('無効な教科です');
    }

    // セッション作成
    $stmt = $db->prepare('
        INSERT INTO class_sessions (user_id, subject_id, study_date, period, unit_name)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$user['user_id'], $subjectId, $studyDate, $period, $unitName]);

    $newSessionId = $db->lastInsertId();

    // 作成したセッションを取得
    $session = getSessionById($newSessionId);

    successResponse(['session' => $session], 'セッションを作成しました');
}

/**
 * 現在のセッション取得または作成
 */
function handleGetOrCreateCurrent(array $user): void
{
    $input = getJsonInput();
    $subjectId = (int)($_GET['subject_id'] ?? $input['subject_id'] ?? 0);
    $studyDate = $_GET['study_date'] ?? $input['study_date'] ?? date('Y-m-d');
    $period = (int)($_GET['period'] ?? $input['period'] ?? 1);
    $unitName = trim($_GET['unit_name'] ?? $input['unit_name'] ?? '');

    $db = Database::getInstance();

    // 今日の同じ教科・時限のセッションを検索
    $stmt = $db->prepare('
        SELECT session_id FROM class_sessions
        WHERE user_id = ? AND subject_id = ? AND study_date = ? AND period = ?
        LIMIT 1
    ');
    $stmt->execute([$user['user_id'], $subjectId, $studyDate, $period]);
    $existing = $stmt->fetch();

    if ($existing) {
        $session = getSessionById($existing['session_id']);
        successResponse(['session' => $session, 'created' => false]);
    }

    // 新規作成
    if ($subjectId <= 0) {
        errorResponse('教科を選択してください');
    }

    $stmt = $db->prepare('
        INSERT INTO class_sessions (user_id, subject_id, study_date, period, unit_name)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$user['user_id'], $subjectId, $studyDate, $period, $unitName]);

    $session = getSessionById($db->lastInsertId());
    successResponse(['session' => $session, 'created' => true]);
}

/**
 * セッション詳細取得
 */
function handleGetSession(array $user, int $sessionId): void
{
    $session = getSessionById($sessionId);

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('セッションが見つかりません', 404);
    }

    successResponse(['session' => $session]);
}

/**
 * 履歴一覧取得
 */
function handleGetHistory(array $user): void
{
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);

    $db = Database::getInstance();
    $stmt = $db->prepare('
        SELECT
            cs.session_id,
            cs.study_date,
            cs.period,
            cs.unit_name,
            cs.created_at,
            s.subject_id,
            s.subject_name,
            (SELECT COUNT(*) FROM chat_messages WHERE session_id = cs.session_id) as message_count,
            (SELECT COUNT(*) FROM reflections WHERE session_id = cs.session_id) as has_reflection
        FROM class_sessions cs
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cs.user_id = ?
        ORDER BY cs.study_date DESC, cs.period DESC
        LIMIT ? OFFSET ?
    ');
    $stmt->execute([$user['user_id'], $limit, $offset]);
    $sessions = $stmt->fetchAll();

    // 総件数取得
    $stmt = $db->prepare('SELECT COUNT(*) as total FROM class_sessions WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $total = $stmt->fetch()['total'];

    successResponse([
        'sessions' => $sessions,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

/**
 * セッション更新
 */
function handleUpdateSession(array $user, int $sessionId): void
{
    $db = Database::getInstance();

    // 所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('セッションが見つかりません', 404);
    }

    $input = getJsonInput();
    $updates = [];
    $params = [];

    if (isset($input['subject_id'])) {
        $updates[] = 'subject_id = ?';
        $params[] = (int)$input['subject_id'];
    }
    if (isset($input['unit_name'])) {
        $updates[] = 'unit_name = ?';
        $params[] = trim($input['unit_name']);
    }
    if (isset($input['period'])) {
        $updates[] = 'period = ?';
        $params[] = (int)$input['period'];
    }

    if (empty($updates)) {
        errorResponse('更新する項目がありません');
    }

    $params[] = $sessionId;
    $sql = 'UPDATE class_sessions SET ' . implode(', ', $updates) . ' WHERE session_id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $updatedSession = getSessionById($sessionId);
    successResponse(['session' => $updatedSession], '更新しました');
}

/**
 * セッションID指定で取得（ヘルパー）
 */
function getSessionById(int $sessionId): ?array
{
    $db = Database::getInstance();
    $stmt = $db->prepare('
        SELECT
            cs.*,
            s.subject_name
        FROM class_sessions cs
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cs.session_id = ?
    ');
    $stmt->execute([$sessionId]);
    return $stmt->fetch() ?: null;
}
