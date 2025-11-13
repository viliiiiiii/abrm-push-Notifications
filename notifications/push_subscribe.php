<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$me = current_user();
$userId = (int)($me['id'] ?? 0);
$localUserId = $userId ? notif_resolve_local_user_id($userId) : null;

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$sanitizeDevices = static function (array $rows): array {
    return array_map(static function (array $row): array {
        return [
            'id'          => (int)($row['id'] ?? 0),
            'kind'        => $row['kind'] ?? '',
            'user_agent'  => $row['user_agent'] ?? '',
            'created_at'  => $row['created_at'] ?? null,
            'last_used_at'=> $row['last_used_at'] ?? null,
        ];
    }, $rows);
};

if (!$localUserId) {
    $respond(['ok' => false, 'error' => 'profile_unavailable'], 409);
}

$raw = file_get_contents('php://input');
$data = [];
if ($raw !== '' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$input = array_merge($_POST, $data);
$intent = strtolower((string)($input['intent'] ?? 'status'));

if ($intent !== 'status') {
    if (!verify_csrf_token($input[CSRF_TOKEN_NAME] ?? null)) {
        $respond(['ok' => false, 'error' => 'csrf'], 422);
    }
}

try {
    if ($intent === 'status') {
        $respond([
            'ok'      => true,
            'devices' => $sanitizeDevices(notif_fetch_push_subscriptions($localUserId)),
        ]);
    }

    if ($intent === 'unsubscribe') {
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') {
            $respond(['ok' => false, 'error' => 'missing_endpoint'], 422);
        }
        $pdo = notif_pdo();
        $stmt = $pdo->prepare('DELETE FROM notification_devices WHERE user_id = :uid AND endpoint = :ep');
        $stmt->execute([':uid' => $localUserId, ':ep' => $endpoint]);
        $respond([
            'ok'      => true,
            'devices' => $sanitizeDevices(notif_fetch_push_subscriptions($localUserId)),
        ]);
    }

    if ($intent !== 'subscribe') {
        $respond(['ok' => false, 'error' => 'bad_intent'], 400);
    }

    $subscription = $input['subscription'] ?? null;
    if (!is_array($subscription)) {
        $respond(['ok' => false, 'error' => 'missing_subscription'], 422);
    }

    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = $subscription['keys'] ?? [];
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        $respond(['ok' => false, 'error' => 'invalid_subscription'], 422);
    }

    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $pdo = notif_pdo();
    $stmt = $pdo->prepare("INSERT INTO notification_devices (user_id, kind, endpoint, p256dh, auth, user_agent, created_at, last_used_at)
                            VALUES (:uid, 'webpush', :endpoint, :p256dh, :auth, :ua, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent), last_used_at = NOW()");
    $stmt->execute([
        ':uid'      => $localUserId,
        ':endpoint' => $endpoint,
        ':p256dh'   => $p256dh,
        ':auth'     => $auth,
        ':ua'       => $userAgent,
    ]);

    $global = notif_get_global_preferences($localUserId);
    if (empty($global['allow_push'])) {
        notif_set_global_preferences($localUserId, ['allow_push' => true]);
    }

    $respond([
        'ok'      => true,
        'devices' => $sanitizeDevices(notif_fetch_push_subscriptions($localUserId)),
    ]);
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'server', 'message' => $e->getMessage()], 500);
}
