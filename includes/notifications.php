<?php
// includes/notifications.php
// Requires: get_pdo(), current_user(), require_login(), sanitize(), csrf_token() (same helpers you use elsewhere)

function notif_pdo(): PDO {
    // Use your primary connection. If you have a 'core' pool, swap to get_pdo('core')
    return get_pdo();
}

/** Cache containers for preferences so we can invalidate when updates occur. */
$GLOBALS['notif_type_pref_cache'] = $GLOBALS['notif_type_pref_cache'] ?? [];
$GLOBALS['notif_global_pref_cache'] = $GLOBALS['notif_global_pref_cache'] ?? [];

/** List of supported notification types and metadata. */
function notif_type_catalog(): array {
    static $catalog;
    if ($catalog !== null) {
        return $catalog;
    }

    $catalog = [
        'task.assigned' => [
            'label'            => 'Task assignments',
            'description'      => 'Alerts when someone assigns a task to you or your team.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => true],
        ],
        'task.unassigned' => [
            'label'            => 'Task unassigned',
            'description'      => 'Heads up when a task is no longer assigned to you.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'task.updated' => [
            'label'            => 'Task progress',
            'description'      => 'Updates when priority, due dates, or status change on tasks you follow.',
            'category'         => 'task',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'note.activity' => [
            'label'            => 'Note collaboration',
            'description'      => 'Comments, mentions, and edits on notes you created or follow.',
            'category'         => 'note',
            'default_channels' => ['web' => true, 'email' => false, 'push' => false],
        ],
        'system.broadcast' => [
            'label'            => 'System announcements',
            'description'      => 'Release notes and scheduled maintenance updates from the team.',
            'category'         => 'system',
            'default_channels' => ['web' => true, 'email' => true, 'push' => false],
        ],
        'system.alert' => [
            'label'            => 'Critical system alerts',
            'description'      => 'Immediate warnings about high-impact events.',
            'category'         => 'system',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.login_alert' => [
            'label'            => 'Sign-in alerts',
            'description'      => 'Alerts when a new browser or device signs in with your account.',
            'category'         => 'security',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.password_change' => [
            'label'            => 'Password changed',
            'description'      => 'Confirms when your account password is updated.',
            'category'         => 'password_reset',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'security.password_reset' => [
            'label'            => 'Password reset requests',
            'description'      => 'Notifies you when a password reset link is requested.',
            'category'         => 'password_reset',
            'default_channels' => ['web' => true, 'email' => true, 'push' => true],
        ],
        'digest.weekly' => [
            'label'            => 'Weekly digest',
            'description'      => 'Friday recap email with overdue tasks and unread notes.',
            'category'         => 'digest',
            'default_channels' => ['web' => true, 'email' => true, 'push' => false],
        ],
        'marketing.campaign' => [
            'label'            => 'Product updates & tips',
            'description'      => 'Occasional product announcements and webinars.',
            'category'         => 'marketing',
            'default_channels' => ['web' => false, 'email' => true, 'push' => false],
        ],
    ];

    return $catalog;
}

/** Map concrete notification types to high-level preference buckets. */
function notif_type_category(string $type): string {
    $catalog = notif_type_catalog();
    if (isset($catalog[$type]['category'])) {
        return (string)$catalog[$type]['category'];
    }

    if (str_starts_with($type, 'task.')) {
        return 'task';
    }
    if (str_starts_with($type, 'note.')) {
        return 'note';
    }
    if (str_starts_with($type, 'security.')) {
        return 'security';
    }
    if (str_starts_with($type, 'system.')) {
        return 'system';
    }
    if (str_starts_with($type, 'digest.')) {
        return 'digest';
    }
    if (str_starts_with($type, 'marketing.')) {
        return 'marketing';
    }

    return 'system';
}

/** Default global notification preferences. */
function notif_default_preferences(): array {
    return [
        'allow_in_app' => true,
        'allow_email'  => false,
        'allow_push'   => false,
        'types'        => [
            'task'           => true,
            'note'           => true,
            'system'         => true,
            'password_reset' => true,
            'security'       => true,
            'digest'         => true,
            'marketing'      => false,
        ],
    ];
}

/** Fetch or lazily create global notification preferences for a user. */
function notif_get_global_preferences(int $userId): array {
    if ($userId <= 0) {
        return notif_default_preferences();
    }

    global $notif_global_pref_cache;
    if (isset($notif_global_pref_cache[$userId])) {
        return $notif_global_pref_cache[$userId];
    }

    $defaults = notif_default_preferences();

    try {
        $pdo = notif_pdo();
        $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $prefs = [
                'allow_in_app' => !empty($row['allow_in_app']),
                'allow_email'  => !empty($row['allow_email']),
                'allow_push'   => !empty($row['allow_push']),
                'types'        => [
                    'task'           => !empty($row['type_task']),
                    'note'           => !empty($row['type_note']),
                    'system'         => !empty($row['type_system']),
                    'password_reset' => !empty($row['type_password_reset']),
                    'security'       => !empty($row['type_security']),
                    'digest'         => !empty($row['type_digest']),
                    'marketing'      => !empty($row['type_marketing']),
                ],
            ];
            return $notif_global_pref_cache[$userId] = $prefs;
        }
    } catch (Throwable $e) {
        // fall through to defaults
    }

    return $notif_global_pref_cache[$userId] = $defaults;
}

/** Persist global preferences and invalidate caches. */
function notif_set_global_preferences(int $userId, array $prefs): void {
    if ($userId <= 0) {
        return;
    }

    $current = notif_get_global_preferences($userId);

    $allowInApp = !empty($prefs['allow_in_app']);
    $allowEmail = !empty($prefs['allow_email']);
    $allowPush  = !empty($prefs['allow_push']);

    $types = $current['types'];
    foreach ($types as $key => $value) {
        if (array_key_exists($key, $prefs)) {
            $types[$key] = !empty($prefs[$key]);
        }
    }

    try {
        $pdo = notif_pdo();
        $sql = 'INSERT INTO notification_preferences
                (user_id, allow_in_app, allow_email, allow_push, type_task, type_note, type_system, type_password_reset, type_security, type_digest, type_marketing)
                VALUES (:u, :in_app, :email, :push, :task, :note, :system, :pwd, :security, :digest, :marketing)
                ON DUPLICATE KEY UPDATE
                  allow_in_app = VALUES(allow_in_app),
                  allow_email = VALUES(allow_email),
                  allow_push = VALUES(allow_push),
                  type_task = VALUES(type_task),
                  type_note = VALUES(type_note),
                  type_system = VALUES(type_system),
                  type_password_reset = VALUES(type_password_reset),
                  type_security = VALUES(type_security),
                  type_digest = VALUES(type_digest),
                  type_marketing = VALUES(type_marketing)';
        $pdo->prepare($sql)->execute([
            ':u'         => $userId,
            ':in_app'    => $allowInApp ? 1 : 0,
            ':email'     => $allowEmail ? 1 : 0,
            ':push'      => $allowPush ? 1 : 0,
            ':task'      => !empty($types['task']) ? 1 : 0,
            ':note'      => !empty($types['note']) ? 1 : 0,
            ':system'    => !empty($types['system']) ? 1 : 0,
            ':pwd'       => !empty($types['password_reset']) ? 1 : 0,
            ':security'  => !empty($types['security']) ? 1 : 0,
            ':digest'    => !empty($types['digest']) ? 1 : 0,
            ':marketing' => !empty($types['marketing']) ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // bubble up? swallow to avoid breaking caller
    }

    global $notif_global_pref_cache, $notif_type_pref_cache;
    unset($notif_global_pref_cache[$userId]);
    if (!empty($notif_type_pref_cache)) {
        foreach (array_keys($notif_type_pref_cache) as $key) {
            if (str_starts_with((string)$key, $userId . '|')) {
                unset($notif_type_pref_cache[$key]);
            }
        }
    }
}

/** Utility to clear the type preference cache for a user/type. */
function notif_forget_type_pref_cache(int $userId, ?string $type = null): void {
    global $notif_type_pref_cache;
    if ($type === null) {
        foreach (array_keys($notif_type_pref_cache) as $key) {
            if (str_starts_with((string)$key, $userId . '|')) {
                unset($notif_type_pref_cache[$key]);
            }
        }
        return;
    }
    $cacheKey = $userId . '|' . $type;
    unset($notif_type_pref_cache[$cacheKey]);
}


function notif_resolve_local_user_id(?int $userId): ?int {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $appsPdo = notif_pdo();

    // Fast path: the identifier is already a local user id.
    try {
        $st = $appsPdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }
    } catch (Throwable $e) {
        // If the lookup fails we keep probing using the CORE record.
    }

    // Fallback: resolve via CORE directory and match on email.
    $coreEmail = null;
    $coreRole  = null;
    try {
        if (function_exists('core_user_record')) {
            $core = core_user_record($userId);
            if ($core) {
                $coreEmail = $core['email'] ?? null;
                $coreRole  = $core['role_key'] ?? ($core['role'] ?? null);
            }
        }
    } catch (Throwable $e) {
        // ignore and continue to the provisioning path below
    }

    if (!$coreEmail) {
        return $cache[$userId] = null;
    }

    try {
        $st = $appsPdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $st->execute([':email' => $coreEmail]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['id'])) {
            return $cache[$userId] = (int)$row['id'];
        }

        // No local row with that email yet – provision a shadow account so the
        // notification rows can satisfy their foreign key.
        $role = 'user';
        $roleKey = is_string($coreRole) ? strtolower($coreRole) : '';
        if (in_array($roleKey, ['admin', 'manager', 'root'], true)) {
            $role = 'admin';
        }

        $password = password_hash(bin2hex(random_bytes(18)), PASSWORD_BCRYPT);
        $ins = $appsPdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (:email, :hash, :role, NOW())');
        $ins->execute([
            ':email' => $coreEmail,
            ':hash'  => $password,
            ':role'  => $role,
        ]);

        return $cache[$userId] = (int)$appsPdo->lastInsertId();
    } catch (Throwable $e) {
        try {
            error_log('notif_resolve_local_user_id failed for ' . $userId . ': ' . $e->getMessage());
        } catch (Throwable $_) {}
    }

    return $cache[$userId] = null;
}

/** Map a list of user identifiers (CORE or local) to local ids. */
function notif_resolve_local_user_ids(array $userIds): array {
    $out = [];
    foreach ($userIds as $uid) {
        $local = notif_resolve_local_user_id((int)$uid);
        if ($local) {
            $out[] = $local;
        }
    }
    return array_values(array_unique($out));
}

/** Upsert per-type preference (web/email/push + mute) */
function notif_set_type_pref(int $userId, string $type, array $prefs): void {
    $pdo = notif_pdo();
    $allow_web   = (int)($prefs['allow_web']   ?? 1);
    $allow_email = (int)($prefs['allow_email'] ?? 0);
    $allow_push  = (int)($prefs['allow_push']  ?? 0);
    $mute_until  = $prefs['mute_until'] ?? null;

    $sql = "INSERT INTO notification_type_prefs (user_id, notif_type, allow_web, allow_email, allow_push, mute_until)
            VALUES (:u,:t,:w,:e,:p,:m)
            ON DUPLICATE KEY UPDATE allow_web=VALUES(allow_web), allow_email=VALUES(allow_email),
                                    allow_push=VALUES(allow_push), mute_until=VALUES(mute_until)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':u'=>$userId, ':t'=>$type, ':w'=>$allow_web, ':e'=>$allow_email, ':p'=>$allow_push, ':m'=>$mute_until
    ]);

    notif_forget_type_pref_cache($userId, $type);
}

/** Get effective channel permissions for user+type (defaults if no row). */
function notif_get_type_pref(int $userId, string $type): array {
    global $notif_type_pref_cache;
    $key = $userId . '|' . $type;
    if (isset($notif_type_pref_cache[$key])) {
        return $notif_type_pref_cache[$key];
    }

    $global  = notif_get_global_preferences($userId);
    $category = notif_type_category($type);
    $categoryEnabled = $global['types'][$category] ?? true;

    $catalog = notif_type_catalog();
    $typeDefaults = $catalog[$type]['default_channels'] ?? [];

    $defaultWeb = (array_key_exists('web', $typeDefaults) ? !empty($typeDefaults['web']) : true) && $global['allow_in_app'] && $categoryEnabled;
    $defaultEmail = !empty($typeDefaults['email']) && $global['allow_email'] && $categoryEnabled;
    $defaultPush = !empty($typeDefaults['push']) && $global['allow_push'] && $categoryEnabled;

    $prefs = [
        'allow_web'   => $defaultWeb ? 1 : 0,
        'allow_email' => $defaultEmail ? 1 : 0,
        'allow_push'  => $defaultPush ? 1 : 0,
        'mute_until'  => null,
    ];

    try {
        $pdo = notif_pdo();
        $stmt = $pdo->prepare("SELECT allow_web, allow_email, allow_push, mute_until
                               FROM notification_type_prefs
                               WHERE user_id=:u AND notif_type=:t");
        $stmt->execute([':u'=>$userId, ':t'=>$type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $prefs['allow_web']   = (!empty($row['allow_web']) && $global['allow_in_app'] && $categoryEnabled) ? 1 : 0;
            $prefs['allow_email'] = (!empty($row['allow_email']) && $global['allow_email'] && $categoryEnabled) ? 1 : 0;
            $prefs['allow_push']  = (!empty($row['allow_push']) && $global['allow_push'] && $categoryEnabled) ? 1 : 0;
            $prefs['mute_until']  = $row['mute_until'] ?? null;
        }
    } catch (Throwable $e) {
        // fall back to defaults
    }

    return $notif_type_pref_cache[$key] = $prefs;
}

/** Subscribe a user to events for an entity (e.g. note.comment on note #123). */
function notif_subscribe_user(int $userId, ?string $entityType, ?int $entityId, string $event, string $channels = 'web,email'): void {
    $pdo = notif_pdo();
    $sql = "INSERT INTO notification_subscriptions (user_id, entity_type, entity_id, event, channels, is_enabled)
            VALUES (:u,:et,:eid,:ev,:ch,1)
            ON DUPLICATE KEY UPDATE channels=VALUES(channels), is_enabled=1";
    $pdo->prepare($sql)->execute([
        ':u'=>$userId, ':et'=>$entityType, ':eid'=>$entityId, ':ev'=>$event, ':ch'=>$channels
    ]);
}

/** Unsubscribe */
function notif_unsubscribe_user(int $userId, ?string $entityType, ?int $entityId, string $event): void {
    $pdo = notif_pdo();
    $pdo->prepare("UPDATE notification_subscriptions
                   SET is_enabled=0
                   WHERE user_id=:u AND entity_type <=> :et AND entity_id <=> :eid AND event=:ev")
        ->execute([':u'=>$userId, ':et'=>$entityType, ':eid'=>$entityId, ':ev'=>$event]);
}

/** Insert one notification for one user, respecting their per-type prefs and mute. */
function notif_emit(array $args): ?int {
    // $args: user_id, type, entity_type?, entity_id?, title?, body?, url?, data?, actor_user_id?
    $pdo = notif_pdo();

    $userId = (int)$args['user_id'];
    $type   = (string)$args['type'];

    $prefs  = notif_get_type_pref($userId, $type);
    $now    = new DateTimeImmutable('now');
    if (!empty($prefs['mute_until'])) {
        try {
            $mute = new DateTimeImmutable((string)$prefs['mute_until']);
            if ($mute > $now) {
                // muted: skip entirely
                return null;
            }
        } catch (Throwable $e) {}
    }

    $allow_web   = !empty($prefs['allow_web']);
    $allow_email = !empty($prefs['allow_email']);
    $allow_push  = !empty($prefs['allow_push']);

    if (!$allow_web && !$allow_email && !$allow_push) {
        return null;
    }

    $readAt = $allow_web ? null : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO notifications
          (user_id, actor_user_id, type, entity_type, entity_id, title, body, data, url, is_read, read_at)
          VALUES (:u,:a,:t,:et,:eid,:ti,:bo,:da,:url,:is_read,:read_at)");
    $stmt->execute([
        ':u'       => $userId,
        ':a'       => isset($args['actor_user_id']) ? (int)$args['actor_user_id'] : null,
        ':t'       => $type,
        ':et'      => $args['entity_type'] ?? null,
        ':eid'     => isset($args['entity_id']) ? (int)$args['entity_id'] : null,
        ':ti'      => $args['title'] ?? null,
        ':bo'      => $args['body'] ?? null,
        ':da'      => !empty($args['data']) ? json_encode($args['data'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
        ':url'     => $args['url'] ?? null,
        ':is_read' => $allow_web ? 0 : 1,
        ':read_at' => $allow_web ? null : $readAt,
    ]);
    $notifId = (int)$pdo->lastInsertId();

    // Queue background channels (email/push) if allowed for this user+type
    if ($notifId && ($allow_email || $allow_push)) {
        $ins = $pdo->prepare("INSERT INTO notification_channels_queue (notification_id, channel, status, scheduled_at)
                              VALUES (:nid, :ch, 'pending', NULL)");
        if ($allow_email) { $ins->execute([':nid'=>$notifId, ':ch'=>'email']); }
        if ($allow_push)  { $ins->execute([':nid'=>$notifId, ':ch'=>'push']); }
    }

    return $notifId;
}

/** Broadcast to many users (array of user IDs) */
function notif_broadcast(array $userIds, array $payload): array {
    $ids = [];
    foreach ($userIds as $uid) {
        $payload['user_id'] = (int)$uid;
        $id = notif_emit($payload);
        if ($id) $ids[] = $id;
    }
    return $ids;
}

/** Broadcast to subscribers of an entity+event (matches notification_subscriptions). */
function notif_broadcast_to_subscribers(string $eventType, ?string $entityType, ?int $entityId, array $payload): array {
    $pdo = notif_pdo();
    $sql = "SELECT user_id, channels
              FROM notification_subscriptions
             WHERE is_enabled=1
               AND event=:ev
               AND (entity_type <=> :et) AND (entity_id <=> :eid)";
    $st = $pdo->prepare($sql);
    $st->execute([':ev'=>$eventType, ':et'=>$entityType, ':eid'=>$entityId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $payload['user_id'] = (int)$r['user_id'];
        // Note: per-type prefs still apply inside notif_emit()
        $id = notif_emit($payload);
        if ($id) $out[] = $id;
    }
    return $out;
}

/** Unread count for the header bell */
function notif_unread_count(int $userId): int {
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:u AND is_read=0");
    $st->execute([':u'=>$userId]);
    return (int)$st->fetchColumn();
}

function notif_recent_unread(int $userId, int $limit = 3): array {
    $limit = max(1, (int)$limit);
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT id, title, body, url, created_at FROM notifications WHERE user_id = :u AND is_read = 0 ORDER BY id DESC LIMIT :lim");
    $st->bindValue(':u', $userId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function ($row) {
        return [
            'id'         => (int)($row['id'] ?? 0),
            'title'      => $row['title'] ?? '',
            'body'       => $row['body'] ?? '',
            'url'        => $row['url'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);
}

/** Paginated list */
function notif_list(int $userId, int $limit = 20, int $offset = 0): array {
    $pdo = notif_pdo();
    $st = $pdo->prepare("SELECT * FROM notifications
                         WHERE user_id=:u
                         ORDER BY id DESC
                         LIMIT :lim OFFSET :off");
    $st->bindValue(':u', $userId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Lightweight list of the latest notifications for quick previews. */
function notif_recent(int $userId, int $limit = 3): array {
    $limit = max(1, min(10, $limit));
    $pdo   = notif_pdo();
    $sql   = "SELECT id, title, body, url, is_read, created_at, type
              FROM notifications
              WHERE user_id = :u
              ORDER BY id DESC
              LIMIT :lim";
    $stmt  = $pdo->prepare($sql);
    $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id'         => (int)($row['id'] ?? 0),
            'title'      => (string)($row['title'] ?? ''),
            'body'       => (string)($row['body'] ?? ''),
            'url'        => $row['url'] ?? null,
            'is_read'    => !empty($row['is_read']) ? 1 : 0,
            'created_at' => $row['created_at'] ?? null,
            'type'       => (string)($row['type'] ?? ''),
        ];
    }, $rows);
}

function notif_mark_read(int $userId, int $notifId): void {
    $pdo = notif_pdo();
    $sql = "UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=:id AND user_id=:u";
    $pdo->prepare($sql)->execute([':id' => $notifId, ':u' => $userId]);
}
function notif_mark_unread(int $userId, int $notifId): void {
    $pdo = notif_pdo();
    $sql = "UPDATE notifications SET is_read=0, read_at=NULL WHERE id=:id AND user_id=:u";
    $pdo->prepare($sql)->execute([':id' => $notifId, ':u' => $userId]);
}
function notif_delete(int $userId, int $notifId): void {
    $pdo = notif_pdo();
    $sql = "DELETE FROM notifications WHERE id=:id AND user_id=:u";
    $pdo->prepare($sql)->execute([':id' => $notifId, ':u' => $userId]);
}
function notif_mark_all_read(int $userId): void {
    $pdo = notif_pdo();
    $sql = "UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=:u AND is_read=0";
    $pdo->prepare($sql)->execute([':u' => $userId]);
}
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

    $stmt = $pdo->prepare("
        INSERT INTO notification_devices (user_id, kind, endpoint, user_agent, created_at, last_used_at)
        VALUES (:u, 'webpush', :ep, :ua, NOW(), NOW())
        ON DUPLICATE KEY UPDATE last_used_at = NOW(), user_agent = VALUES(user_agent), endpoint = VALUES(endpoint)
    "
    );

    try {
        $stmt->execute([':u' => $userId, ':ep' => $endpoint, ':ua' => $ua]);
    } catch (Throwable $e) {
        try {
            error_log('notif_touch_web_device failed: ' . $e->getMessage());
        } catch (Throwable $_) {}
    }
}

function notif_fetch_devices(int $userId, ?string $kind = null): array {
    if ($userId <= 0) {
        return [];
    }

    try {
        $pdo = notif_pdo();
        $sql = 'SELECT id, kind, endpoint, p256dh, auth, user_agent, created_at, last_used_at
                FROM notification_devices
                WHERE user_id = :u';
        $params = [':u' => $userId];
        if ($kind !== null) {
            $sql .= ' AND kind = :k';
            $params[':k'] = $kind;
        }
        $sql .= ' ORDER BY COALESCE(last_used_at, created_at) DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function notif_fetch_push_subscriptions(int $userId): array {
    $rows = notif_fetch_devices($userId, 'webpush');
    return array_values(array_filter($rows, static function (array $row): bool {
        return !empty($row['endpoint']) && !empty($row['p256dh']) && !empty($row['auth']);
    }));
}

function notif_vapid_config(): array {
    return [
        'subject'    => defined('WEB_PUSH_VAPID_SUBJECT') ? trim((string)WEB_PUSH_VAPID_SUBJECT) : '',
        'publicKey'  => defined('WEB_PUSH_VAPID_PUBLIC_KEY') ? trim((string)WEB_PUSH_VAPID_PUBLIC_KEY) : '',
        'privateKey' => defined('WEB_PUSH_VAPID_PRIVATE_KEY') ? trim((string)WEB_PUSH_VAPID_PRIVATE_KEY) : '',
    ];
}

function notif_vapid_ready(): bool {
    $cfg = notif_vapid_config();
    return $cfg['publicKey'] !== '' && $cfg['privateKey'] !== '';
}

function notif_admin_core_user_ids(): array {
    static $cache;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $pdo = get_pdo('core');
        $sql = "SELECT u.id FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.key_slug IN ('root', 'admin')
                  AND (u.suspended_at IS NULL)";
        $ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        return $cache = array_values(array_filter(array_unique($ids)));
    } catch (Throwable $e) {
        return $cache = [];
    }
}

function notif_alert_admins(string $type, string $title, string $body, ?string $url = null, array $data = []): void {
    $coreIds = notif_admin_core_user_ids();
    if (!$coreIds) {
        return;
    }

    $localIds = notif_resolve_local_user_ids($coreIds);
    if (!$localIds) {
        return;
    }

    notif_broadcast($localIds, [
        'type'  => $type,
        'title' => $title,
        'body'  => $body,
        'url'   => $url,
        'data'  => $data,
    ]);
}

function notif_track_login(int $coreUserId, ?string $ip, ?string $userAgent): void {
    $localId = notif_resolve_local_user_id($coreUserId);
    if (!$localId) {
        return;
    }

    $ip = $ip ? trim($ip) : '';
    $userAgent = $userAgent ? substr(trim($userAgent), 0, 255) : '';
    $fingerprint = sha1($ip . '|' . $userAgent);

    try {
        $pdo = notif_pdo();
        $stmt = $pdo->prepare('SELECT id FROM user_login_fingerprints WHERE user_id = :u AND fingerprint = :f LIMIT 1');
        $stmt->execute([':u' => $localId, ':f' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $now = date('Y-m-d H:i:s');
        $binaryIp = $ip !== '' ? @inet_pton($ip) : null;

        $isNew = false;
        if ($row) {
            $upd = $pdo->prepare('UPDATE user_login_fingerprints SET last_seen_at = :now, ip = :ip, user_agent = :ua WHERE id = :id');
            $upd->execute([
                ':now' => $now,
                ':ip'  => $binaryIp,
                ':ua'  => $userAgent !== '' ? $userAgent : null,
                ':id'  => (int)$row['id'],
            ]);
        } else {
            $ins = $pdo->prepare('INSERT INTO user_login_fingerprints (user_id, fingerprint, ip, user_agent, created_at, last_seen_at) VALUES (:u, :f, :ip, :ua, :now, :now)');
            $ins->execute([
                ':u'   => $localId,
                ':f'   => $fingerprint,
                ':ip'  => $binaryIp,
                ':ua'  => $userAgent !== '' ? $userAgent : null,
                ':now' => $now,
            ]);
            $isNew = true;
        }

        if ($isNew) {
            $details = [];
            if ($ip !== '') {
                $details[] = 'IP ' . $ip;
            }
            if ($userAgent !== '') {
                $details[] = $userAgent;
            }
            $body = 'A new device just signed in to your account.';
            if ($details) {
                $body .= ' (' . implode(' • ', $details) . ')';
            }
            notif_emit([
                'user_id' => $localId,
                'type'    => 'security.login_alert',
                'title'   => 'New sign-in detected',
                'body'    => $body,
                'url'     => '/account/profile.php',
                'data'    => ['ip' => $ip, 'user_agent' => $userAgent],
            ]);
        }
    } catch (Throwable $e) {
        try { error_log('notif_track_login failed: ' . $e->getMessage()); } catch (Throwable $_) {}
    }
}

function notif_handle_log_event(string $action, ?string $entityType, ?int $entityId, array $meta, ?array $actor, ?string $ip): void {
    $action = strtolower($action);
    switch ($action) {
        case 'task.delete':
            if ($entityId) {
                $title = 'Task #' . $entityId . ' deleted';
                $parts = [];
                if (!empty($actor['email'])) {
                    $parts[] = 'by ' . $actor['email'];
                }
                if ($ip) {
                    $parts[] = 'IP ' . $ip;
                }
                $body = 'Task #' . $entityId . ' was removed.' . ($parts ? ' ' . implode(' • ', $parts) : '');
                notif_alert_admins('system.alert', $title, $body, '/task_view.php?id=' . $entityId, [
                    'entity_type' => 'task',
                    'entity_id'   => $entityId,
                    'action'      => $action,
                ]);
            }
            break;

        case 'user.create':
            if ($entityId) {
                $role = $meta['role'] ?? null;
                $body = 'A new account was created (user #' . $entityId . ').';
                if ($role) {
                    $body .= ' Role: ' . $role . '.';
                }
                notif_alert_admins('system.alert', 'New user provisioned', $body, '/admin/users.php', [
                    'entity_type' => 'user',
                    'entity_id'   => $entityId,
                    'action'      => $action,
                ]);
            }
            break;
    }
}

function notif_process_push_queue(int $limit = 25): array {
    $summary = [
        'checked' => 0,
        'sent'    => 0,
        'failed'  => 0,
        'skipped' => 0,
        'errors'  => [],
    ];

    if (!notif_vapid_ready()) {
        $summary['errors'][] = 'VAPID keys are not configured.';
        return $summary;
    }

    if (!class_exists('Minishlink\\WebPush\\WebPush')) {
        $summary['errors'][] = 'minishlink/web-push is not installed. Run "composer install" after updating composer.json.';
        return $summary;
    }

    try {
        $pdo = notif_pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, notification_id
                                FROM notification_channels_queue
                                WHERE channel = \"push\" AND status = \"pending\"
                                ORDER BY id ASC
                                LIMIT :lim FOR UPDATE SKIP LOCKED');
        $stmt->bindValue(':lim', max(1, (int)$limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows) {
            $ids = array_map('intval', array_column($rows, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $mark = $pdo->prepare("UPDATE notification_channels_queue SET status = 'sending', scheduled_at = NOW() WHERE id IN ($placeholders)");
            $mark->execute($ids);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_) {}
        }
        $summary['errors'][] = 'Failed to claim push jobs: ' . $e->getMessage();
        return $summary;
    }

    if (empty($rows)) {
        return $summary;
    }

    $auth = notif_vapid_config();

    foreach ($rows as $row) {
        $queueId = (int)$row['id'];
        $notificationId = (int)$row['notification_id'];
        $summary['checked']++;

        try {
            $pdo = notif_pdo();
            $stmt = $pdo->prepare('SELECT id, user_id, type, title, body, url, data, created_at FROM notifications WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $notificationId]);
            $notification = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$notification) {
                $skip = $pdo->prepare("UPDATE notification_channels_queue SET status='skipped', last_error='notification missing', sent_at=NOW() WHERE id=:id");
                $skip->execute([':id' => $queueId]);
                $summary['skipped']++;
                continue;
            }

            $userId = (int)$notification['user_id'];
            $type   = (string)$notification['type'];
            $pref   = notif_get_type_pref($userId, $type);
            if (empty($pref['allow_push'])) {
                $skip = $pdo->prepare("UPDATE notification_channels_queue SET status='skipped', last_error='push disabled', sent_at=NOW() WHERE id=:id");
                $skip->execute([':id' => $queueId]);
                $summary['skipped']++;
                continue;
            }

            $subscriptions = notif_fetch_push_subscriptions($userId);
            if (!$subscriptions) {
                $skip = $pdo->prepare("UPDATE notification_channels_queue SET status='skipped', last_error='no subscriptions', sent_at=NOW() WHERE id=:id");
                $skip->execute([':id' => $queueId]);
                $summary['skipped']++;
                continue;
            }

            $data = [];
            if (!empty($notification['data'])) {
                $decoded = json_decode((string)$notification['data'], true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            $payload = [
                'notification_id' => $notificationId,
                'title'           => (string)($notification['title'] ?? 'Notification'),
                'body'            => (string)($notification['body'] ?? ''),
                'url'             => $notification['url'] ?? null,
                'type'            => $type,
                'meta'            => $data,
                'timestamp'       => $notification['created_at'] ?? date('c'),
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $webPush = new Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => $auth['subject'],
                    'publicKey'  => $auth['publicKey'],
                    'privateKey' => $auth['privateKey'],
                ],
            ]);

            $hadSuccess = false;
            foreach ($subscriptions as $device) {
                try {
                    $subscription = Minishlink\WebPush\Subscription::create([
                        'endpoint' => $device['endpoint'],
                        'keys'     => [
                            'p256dh' => $device['p256dh'],
                            'auth'   => $device['auth'],
                        ],
                    ]);
                } catch (Throwable $e) {
                    // invalid subscription payload, drop device
                    $del = $pdo->prepare('DELETE FROM notification_devices WHERE id = :id');
                    $del->execute([':id' => (int)$device['id']]);
                    continue;
                }
                $webPush->queueNotification($subscription, $payloadJson, ['TTL' => 900]);
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $hadSuccess = true;
                    continue;
                }

                $reason = $report->getReason();
                $endpoint = (string)$report->getRequest()->getUri();
                if (strpos($reason, '410') !== false || strpos($reason, '404') !== false) {
                    $del = $pdo->prepare('DELETE FROM notification_devices WHERE endpoint = :ep');
                    $del->execute([':ep' => $endpoint]);
                }
            }

            if ($hadSuccess) {
                $done = $pdo->prepare("UPDATE notification_channels_queue SET status='sent', sent_at=NOW() WHERE id=:id");
                $done->execute([':id' => $queueId]);
                $summary['sent']++;
            } else {
                $fail = $pdo->prepare("UPDATE notification_channels_queue SET status='failed', attempt_count = attempt_count + 1, last_error='delivery failed', sent_at=NULL WHERE id=:id");
                $fail->execute([':id' => $queueId]);
                $summary['failed']++;
            }
        } catch (Throwable $e) {
            try {
                $pdo = notif_pdo();
                $fail = $pdo->prepare("UPDATE notification_channels_queue SET status='failed', attempt_count = attempt_count + 1, last_error=:err WHERE id=:id");
                $fail->execute([':err' => substr($e->getMessage(), 0, 240), ':id' => $queueId]);
            } catch (Throwable $_) {}
            $summary['failed']++;
        }
    }

    return $summary;
}