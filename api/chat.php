<?php
/**
 * チャットAPI（ローカルLLMプロキシ）
 *
 * エンドポイント:
 *   POST   /api/chat.php                       - メッセージ送信＆AI応答取得
 *   GET    /api/chat.php?session_id={id}       - チャット履歴取得
 *   GET    /api/chat.php?action=test           - LLM接続テスト
 */

require_once __DIR__ . '/db.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];
$sessionId = $_GET['session_id'] ?? null;
$action = $_GET['action'] ?? null;

// LLM接続テスト（認証不要）
if ($action === 'test') {
    handleTestLLM();
    exit;
}

// 認証チェック
$user = requireAuth();

switch ($method) {
    case 'GET':
        if (!$sessionId) errorResponse('セッションIDが必要です');
        handleGetChatHistory($user, (int)$sessionId);
        break;

    case 'POST':
        handleSendMessage($user);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * LLM接続テスト
 */
function handleTestLLM(): void
{
    $payload = [
        'model' => LLM_MODEL,
        'prompt' => 'こんにちは。簡単に自己紹介してください。',
        'stream' => false
    ];

    $ch = curl_init(LLM_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    jsonResponse([
        'llm_url' => LLM_API_URL,
        'llm_model' => LLM_MODEL,
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: null,
        'response' => $response ? json_decode($response, true) : null,
        'raw_response' => $response
    ]);
}

/**
 * チャット履歴取得
 */
function handleGetChatHistory(array $user, int $sessionId): void
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
        SELECT message_id, sender, content, created_at
        FROM chat_messages
        WHERE session_id = ?
        ORDER BY created_at ASC
    ');
    $stmt->execute([$sessionId]);
    $messages = $stmt->fetchAll();

    successResponse(['messages' => $messages, 'session_id' => $sessionId]);
}

/**
 * メッセージ送信＆AI応答取得
 */
function handleSendMessage(array $user): void
{
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $message = trim($input['message'] ?? '');

    // バリデーション
    if ($sessionId <= 0) {
        errorResponse('セッションIDが必要です');
    }

    if (empty($message)) {
        errorResponse('メッセージを入力してください');
    }

    $db = Database::getInstance();

    // セッション情報取得
    $stmt = $db->prepare('
        SELECT cs.*, s.subject_name
        FROM class_sessions cs
        JOIN subjects s ON cs.subject_id = s.subject_id
        WHERE cs.session_id = ?
    ');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session || $session['user_id'] != $user['user_id']) {
        errorResponse('無効なセッションです', 403);
    }

    // ユーザーメッセージを保存
    $stmt = $db->prepare('
        INSERT INTO chat_messages (session_id, sender, content)
        VALUES (?, "user", ?)
    ');
    $stmt->execute([$sessionId, $message]);
    $userMessageId = $db->lastInsertId();

    // 過去の会話履歴を取得（コンテキスト用、最新5件）
    $stmt = $db->prepare('
        SELECT sender, content
        FROM chat_messages
        WHERE session_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ');
    $stmt->execute([$sessionId]);
    $history = array_reverse($stmt->fetchAll());

    // LLMにリクエスト
    $subject = $session['subject_name'];
    $unit = $session['unit_name'] ?? '指定なし';

    try {
        $aiResponse = callLocalLLM($message, $subject, $unit, $history);

        // AI応答を保存
        $stmt = $db->prepare('
            INSERT INTO chat_messages (session_id, sender, content)
            VALUES (?, "ai", ?)
        ');
        $stmt->execute([$sessionId, $aiResponse['answer']]);
        $aiMessageId = $db->lastInsertId();

        // 共有疑問と個人メモを自動保存（summaryがある場合のみ）
        if (!empty($aiResponse['summary']) && $aiResponse['summary'] !== 'null') {
            // 共有疑問
            $stmt = $db->prepare('
                INSERT INTO shared_questions (session_id, user_id, content)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$sessionId, $user['user_id'], $aiResponse['summary']]);

            // 個人メモ
            $stmt = $db->prepare('
                INSERT INTO class_notes (session_id, user_id, content, status)
                VALUES (?, ?, ?, "unsolved")
            ');
            $stmt->execute([$sessionId, $user['user_id'], $aiResponse['summary']]);
        }

        successResponse([
            'answer' => $aiResponse['answer'],
            'summary' => $aiResponse['summary'],
            'user_message_id' => $userMessageId,
            'ai_message_id' => $aiMessageId
        ]);

    } catch (Exception $e) {
        // デバッグ用：詳細なエラー情報を返す
        errorResponse('AI応答エラー: ' . $e->getMessage(), 500);
    }
}

/**
 * ローカルLLM呼び出し（Ollama形式）
 */
function callLocalLLM(string $userMessage, string $subject, string $unit, array $history): array
{
    // 会話履歴を構築
    $conversationContext = "";
    foreach ($history as $msg) {
        $role = $msg['sender'] === 'user' ? '生徒' : '先生';
        $conversationContext .= "{$role}: {$msg['content']}\n";
    }

    // シンプルなプロンプト（JSON出力は要求しない）
    $prompt = <<<PROMPT
あなたは中学校の先生です。{$subject}の授業（単元：{$unit}）で生徒の質問に答えてください。

ルール：
- 優しく丁寧に答える
- いきなり答えを教えず、ヒントを出して考えさせる
- 最後に確認問題を1つ出す

{$conversationContext}
生徒: {$userMessage}

先生:
PROMPT;

    // Ollama API呼び出し
    $payload = [
        'model' => LLM_MODEL,
        'prompt' => $prompt,
        'stream' => false
    ];

    $ch = curl_init(LLM_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180, // 3分タイムアウト（大きいモデル用）
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('LLM接続エラー: ' . $curlError . ' (URL: ' . LLM_API_URL . ')');
    }

    if ($httpCode === 0) {
        throw new Exception('LLMサーバーに接続できません。Ollamaが起動しているか確認してください。');
    }

    if ($httpCode !== 200) {
        throw new Exception('LLM APIエラー: HTTP ' . $httpCode . ' - ' . $response);
    }

    $data = json_decode($response, true);

    if (!$data) {
        throw new Exception('LLM応答のJSON解析に失敗: ' . $response);
    }

    if (!isset($data['response'])) {
        throw new Exception('LLM応答にresponseフィールドがありません: ' . json_encode($data));
    }

    $answer = trim($data['response']);

    if (empty($answer)) {
        throw new Exception('LLMからの応答が空です');
    }

    // 疑問の要約を抽出（5文字以上のメッセージなら要約を作成）
    $summary = null;
    if (mb_strlen($userMessage) > 5) {
        $summary = mb_substr($userMessage, 0, 20);
        if (mb_strlen($userMessage) > 20) {
            $summary .= '...';
        }
    }

    return [
        'answer' => $answer,
        'summary' => $summary
    ];
}
