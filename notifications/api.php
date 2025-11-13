<?php
// notifications/api.php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();
if (($_GET['action'] ?? '') === 'connect') {
    require_login();
    ignore_user_abort(true);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no'); // nginx

    $me      = current_user();
    $userId  = (int)($me['id'] ?? 0);
    if ($userId <= 0) { http_response_code(401); exit; }

    // Upsert this browser as a "web" device
    if (!function_exists('notif_touch_web_device')) {
        function notif_touch_web_device(int $userId, string $userAgent): void {
            $pdo = notif_pdo();
            $ua   = substr($userAgent, 0, 255);

            $sessionId = session_id();
            if ($sessionId === '' || $sessionId === false) {
                $sessionId = $_COOKIE['PHPSESSID'] ?? bin2hex(random_bytes(8));
            }

            $fingerprint = implode('|', [
                (string)$userId,
                (string)$sessionId,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                $ua,
            ]);
            $endpoint = 'internal-webpush://' . sha1($fingerprint);

            $sql = "INSERT INTO notification_devices (user_id, kind, endpoint, user_agent, created_at, last_used_at)"
                 . " VALUES (:u, 'webpush', :ep, :ua, NOW(), NOW())"
                 . " ON DUPLICATE KEY UPDATE last_used_at = NOW(), user_agent = VALUES(user_agent), endpoint = VALUES(endpoint)";

            try {
                $pdo->prepare($sql)->execute([':u' => $userId, ':ep' => $endpoint, ':ua' => $ua]);
            } catch (Throwable $e) {
                try {
                    error_log('notif_touch_web_device failed: ' . $e->getMessage());
                } catch (Throwable $_) {}
            }
        }
    }
    notif_touch_web_device($userId, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $pdo = notif_pdo();

    // Helper: send one SSE event
    $send = function(string $event, array $data, ?int $id = null) {
        if ($id !== null) echo "id: {$id}\n";
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush(); @flush();
    };

    // Prefs check (allow_web + mute_until)
    $shouldDeliver = function(int $userId, string $type) use ($pdo): bool {
        try {
            $st = $pdo->prepare("SELECT allow_web, mute_until
                                 FROM notification_type_prefs
                                 WHERE user_id = :u AND notif_type = :t LIMIT 1");
            $st->execute([':u'=>$userId, ':t'=>$type]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return true; // default allow
            if (empty($row['allow_web'])) return false;
            if (!empty($row['mute_until']) && strtotime((string)$row['mute_until']) > time()) return false;
            return true;
        } catch (Throwable $e) {
            return true; // fail-open
        }
    };

    $columnMap = notif_notifications_column_map();
    $urlColumn = $columnMap['url'] ?? 'url';
    $dataColumn = $columnMap['data'] ?? 'data';
    $createdColumn = $columnMap['created_at'] ?? 'created_at';

    // Resume cursor from Last-Event-ID header or ?cursor=
    $cursor = 0;
    if (!empty($_SERVER['HTTP_LAST_EVENT_ID'])) {
        $cursor = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
    } elseif (isset($_GET['cursor'])) {
        $cursor = (int)$_GET['cursor'];
    }

    // Say hello once
    $send('hello', ['ok' => true, 'cursor' => $cursor]);

    // Main loop (keep it short; client will reconnect)
    $endAt   = time() + 110; // ~2 minutes per connection
    while (!connection_aborted() && time() < $endAt) {
        // Fetch new rows for this user since cursor
        $sql = sprintf(
            'SELECT
                n.id,
                n.type,
                n.title,
                n.body,
                `%s` AS url,
                `%s` AS payload,
                `%s` AS created_at
             FROM notifications n
             WHERE n.user_id = :u
               AND n.id > :cursor
             ORDER BY n.id ASC
             LIMIT 50',
            $urlColumn,
            $dataColumn,
            $createdColumn
        );
        $st = $pdo->prepare($sql);
        $st->execute([':u' => $userId, ':cursor' => $cursor]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $r) {
            $type = (string)$r['type'];
            if (!$shouldDeliver($userId, $type)) {
                // Skip delivering, but don't advance cursor past it (so we reconsider later)
                continue;
            }
            $rid = (int)$r['id'];
            $payload = [];
            if (!empty($r['payload'])) {
                $dec = json_decode((string)$r['payload'], true);
                if (is_array($dec)) $payload = $dec;
            }
            $data = [
                'id'      => $rid,
                'type'    => $type,
                'title'   => (string)$r['title'],
                'body'    => (string)$r['body'],
                'link'    => (string)($r['url'] ?? ''),
                'created' => (string)$r['created_at'],
                'payload' => $payload,
            ];
            $send('notify', $data, $rid);
            $cursor = $rid;
        }

        // Heartbeat (keeps proxies happy)
        if (!$rows) {
            echo ": ping\n\n";
            @ob_flush(); @flush();
        }

        // Touch device every loop or every ~30s
        static $lastTouch = 0;
        if (time() - $lastTouch > 30) {
            notif_touch_web_device($userId, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $lastTouch = time();
        }

        sleep(2);
    }

    // Graceful close
    $send('bye', ['cursor' => $cursor]);
    exit;
}

$acceptHeader = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$xhrHeader    = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$wantsJson    = $xhrHeader === 'xmlhttprequest'
  || stripos($acceptHeader, 'application/json') !== false
  || stripos($acceptHeader, 'text/json') !== false;

header('Vary: Accept');

if (!function_exists('notifications_api_respond')) {
    function notifications_api_respond(array $payload, bool $wantsJson, int $status = 200, string $successMessage = '', string $errorMessage = ''): void {
        if ($wantsJson) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload);
            exit;
        }

        $ok = !empty($payload['ok']);
        $message = $ok
            ? ($successMessage !== '' ? $successMessage : 'Done.')
            : ($errorMessage !== '' ? $errorMessage : ($payload['error'] ?? 'Unable to complete request.'));
        $type = $ok ? 'success' : 'error';
        redirect_with_message('/notifications/index.php', $message, $type);
    }
}

$me = current_user();
$userId = (int)($me['id'] ?? 0);
if (!$userId) {
    notifications_api_respond(['ok' => false, 'error' => 'auth'], $wantsJson, 401, '', 'You need to be signed in to manage notifications.');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'unread_count':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'count'=>notif_unread_count($userId)]);
            break;

        case 'list':
            $limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $rows = notif_list($userId, $limit, $offset);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true,'items'=>$rows,'unread'=>notif_unread_count($userId)]);
            break;

        case 'peek':
            $limit = max(1, min(10, (int)($_GET['limit'] ?? $_POST['limit'] ?? 3)));
            $items = notif_recent($userId, $limit);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'     => true,
                'items'  => $items,
                'count'  => notif_unread_count($userId),
            ]);
            break;

        case 'mark_read':
            if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
                notifications_api_respond(['ok'=>false,'error'=>'csrf'], $wantsJson, 422, '', 'We could not verify that request.');
                break;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                notif_mark_read($userId, $id);
            }
            $count = notif_unread_count($userId);
            notifications_api_respond(['ok'=>true,'count'=>$count], $wantsJson, 200, 'Notification marked as read.');
            break;

        case 'mark_unread':
            if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
                notifications_api_respond(['ok'=>false,'error'=>'csrf'], $wantsJson, 422, '', 'We could not verify that request.');
                break;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                notif_mark_unread($userId, $id);
            }
            $count = notif_unread_count($userId);
            notifications_api_respond(['ok'=>true,'count'=>$count], $wantsJson, 200, 'Notification marked as unread.');
            break;

        case 'mark_all_read':
            if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
                notifications_api_respond(['ok'=>false,'error'=>'csrf'], $wantsJson, 422, '', 'We could not verify that request.');
                break;
            }
            notif_mark_all_read($userId);
            $count = notif_unread_count($userId);
            notifications_api_respond(['ok'=>true,'count'=>$count], $wantsJson, 200, 'All notifications marked as read.');
            break;

        case 'delete':
            if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
                notifications_api_respond(['ok'=>false,'error'=>'csrf'], $wantsJson, 422, '', 'We could not verify that request.');
                break;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                notif_delete($userId, $id);
            }
            $count = notif_unread_count($userId);
            notifications_api_respond(['ok'=>true,'count'=>$count], $wantsJson, 200, 'Notification removed.');
            break;

        default:
            notifications_api_respond(['ok'=>false,'error'=>'bad_action'], $wantsJson, 400, '', 'Unsupported notification action.');
    }
} catch (Throwable $e) {
    notifications_api_respond(['ok'=>false,'error'=>'server','msg'=>$e->getMessage()], $wantsJson, 500, '', 'Something went wrong while updating notifications.');
}