<?php
/**
 * 共有疑問API
 *
 * エンドポイント:
 *   GET    /api/questions.php                  - 共有疑問一覧取得（全員分）
 *   POST   /api/questions.php                  - 共有疑問追加
 *   DELETE /api/questions.php?id={id}          - 共有疑問削除（自分のもののみ）
 */

require_once __DIR__ . '/db.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$questionId = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        handleGetQuestions();
        break;

    case 'POST':
        $user = requireAuth();
        handleCreateQuestion($user);
        break;

    case 'DELETE':
        $user = requireAuth();
        if (!$questionId) errorResponse('疑問IDが必要です');
        handleDeleteQuestion($user, (int)$questionId);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * 共有疑問一覧取得（認証不要で全員分取得可能）
 */
function handleGetQuestions(): void
{
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    $date = $_GET['date'] ?? null; // 特定日付でフィルタ

    $db = Database::getInstance();

    $sql = '
        SELECT
            sq.question_id,
            sq.content,
            sq.created_at,
            u.display_name,
            s.subject_name,
            cs.unit_name,
            cs.study_date
        FROM shared_questions sq
        JOIN users u ON sq.user_id = u.id
        JOIN class_sessions cs ON sq.session_id = cs.session_id
        JOIN subjects s ON cs.subject_id = s.subject_id
    ';

    $params = [];

    if ($date) {
        $sql .= ' WHERE cs.study_date = ?';
        $params[] = $date;
    }

    $sql .= ' ORDER BY sq.created_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();

    // 総件数
    $countSql = 'SELECT COUNT(*) as total FROM shared_questions sq
                 JOIN class_sessions cs ON sq.session_id = cs.session_id';
    if ($date) {
        $countSql .= ' WHERE cs.study_date = ?';
        $stmt = $db->prepare($countSql);
        $stmt->execute([$date]);
    } else {
        $stmt = $db->query($countSql);
    }
    $total = $stmt->fetch()['total'];

    successResponse([
        'questions' => $questions,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

/**
 * 共有疑問追加
 */
function handleCreateQuestion(array $user): void
{
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $content = trim($input['content'] ?? '');

    // バリデーション
    if ($sessionId <= 0) {
        errorResponse('セッションIDが必要です');
    }

    if (empty($content)) {
        errorResponse('疑問内容を入力してください');
    }

    if (mb_strlen($content) > 50) {
        errorResponse('疑問は50文字以内で入力してください');
    }

    $db = Database::getInstance();

    // セッション所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM class_sessions WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('無効なセッションです', 403);
    }

    // 疑問追加
    $stmt = $db->prepare('
        INSERT INTO shared_questions (session_id, user_id, content)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$sessionId, $user['user_id'], $content]);

    $newId = $db->lastInsertId();

    // 作成した疑問を取得
    $stmt = $db->prepare('
        SELECT
            sq.question_id,
            sq.content,
            sq.created_at,
            u.display_name
        FROM shared_questions sq
        JOIN users u ON sq.user_id = u.id
        WHERE sq.question_id = ?
    ');
    $stmt->execute([$newId]);
    $question = $stmt->fetch();

    successResponse(['question' => $question], '疑問を共有しました');
}

/**
 * 共有疑問削除（自分のもののみ）
 */
function handleDeleteQuestion(array $user, int $questionId): void
{
    $db = Database::getInstance();

    // 所有者チェック
    $stmt = $db->prepare('SELECT user_id FROM shared_questions WHERE question_id = ?');
    $stmt->execute([$questionId]);
    $question = $stmt->fetch();

    if (!$question) {
        errorResponse('疑問が見つかりません', 404);
    }

    if ($question['user_id'] != $user['user_id']) {
        errorResponse('削除権限がありません', 403);
    }

    $stmt = $db->prepare('DELETE FROM shared_questions WHERE question_id = ?');
    $stmt->execute([$questionId]);

    successResponse([], '疑問を削除しました');
}
