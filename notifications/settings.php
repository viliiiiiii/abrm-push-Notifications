<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';
require_login();

$me = current_user();
$userId = (int)($me['id'] ?? 0);
$notificationUserId = $userId ? notif_resolve_local_user_id($userId) : null;

if (!$notificationUserId) {
    $title = 'Notification Settings';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="container"><div class="card">';
    echo '<h1>Notification Settings</h1>';
    echo '<p>We could not load your notification profile. Please contact an administrator.</p>';
    echo '</div></div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$typesCatalog = notif_type_catalog();
$globalPrefs  = notif_get_global_preferences($notificationUserId);
$typePrefs    = [];
$errors       = [];
$success      = '';
$intent       = $_POST['intent'] ?? 'preferences';

if (is_post()) {
    if (!verify_csrf_token($_POST[CSRF_TOKEN_NAME] ?? null)) {
        $errors[] = 'We could not verify that request. Please try again.';
    } else {
        if ($intent === 'preferences') {
            $allowInApp = !empty($_POST['allow_in_app']);
            $allowEmail = !empty($_POST['allow_email']);
            $allowPush  = !empty($_POST['allow_push']);

            $categoryToggles = $globalPrefs['types'];
            foreach ($categoryToggles as $key => $value) {
                $categoryToggles[$key] = !empty($_POST['types'][$key]);
            }

            $pendingGlobal = [
                'allow_in_app' => $allowInApp,
                'allow_email'  => $allowEmail,
                'allow_push'   => $allowPush,
            ] + $categoryToggles;

            foreach ($typesCatalog as $type => $meta) {
                $prefInput = $_POST['pref'][$type] ?? [];
                $update = [
                    'allow_web'   => !empty($prefInput['allow_web']) ? 1 : 0,
                    'allow_email' => !empty($prefInput['allow_email']) ? 1 : 0,
                    'allow_push'  => !empty($prefInput['allow_push']) ? 1 : 0,
                    'mute_until'  => null,
                ];
                $muteInput = trim((string)($prefInput['mute_until'] ?? ''));
                if ($muteInput !== '') {
                    try {
                        $dt = new DateTimeImmutable($muteInput);
                        $update['mute_until'] = $dt->format('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                        $errors[] = 'Invalid snooze time for ' . ($meta['label'] ?? $type) . '.';
                    }
                }
                if (!$errors) {
                    notif_set_type_pref($notificationUserId, $type, $update);
                }
            }

            if (!$errors) {
                notif_set_global_preferences($notificationUserId, $pendingGlobal);
                $success = 'Notification preferences updated.';
            }
        } elseif ($intent === 'device-delete') {
            $deviceId = (int)($_POST['device_id'] ?? 0);
            if ($deviceId > 0) {
                try {
                    $pdo = notif_pdo();
                    $stmt = $pdo->prepare('DELETE FROM notification_devices WHERE id = :id AND user_id = :uid');
                    $stmt->execute([':id' => $deviceId, ':uid' => $notificationUserId]);
                    $success = 'Device removed.';
                } catch (Throwable $e) {
                    $errors[] = 'Failed to remove that device.';
                }
            }
        }
    }

    $globalPrefs = notif_get_global_preferences($notificationUserId);
}

foreach ($typesCatalog as $type => $meta) {
    $typePrefs[$type] = notif_get_type_pref($notificationUserId, $type);
}

$devices = notif_fetch_devices($notificationUserId);
$vapidConfigured = notif_vapid_ready();

$title = 'Notification Settings';
include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="breadcrumbs" aria-label="Breadcrumb">
    <ol>
      <li><a href="/index.php">Dashboard</a></li>
      <li><a href="/account/profile.php">Account</a></li>
      <li aria-current="page">Notification Settings</li>
    </ol>
  </div>

  <?php if ($success): ?>
    <div class="flash flash-success"><?php echo sanitize($success); ?></div>
  <?php endif; ?>
  <?php foreach ($errors as $error): ?>
    <div class="flash flash-error"><?php echo sanitize($error); ?></div>
  <?php endforeach; ?>

  <section class="card">
    <form method="post">
      <input type="hidden" name="intent" value="preferences">
      <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
      <header class="card-header">
        <h1>Notification Settings</h1>
        <p class="card-subtitle">Choose which channels we use and customise each notification type.</p>
      </header>

      <div class="card-body">
        <h2>Channels</h2>
        <div class="grid three">
          <label class="switch">
            <input type="checkbox" name="allow_in_app" value="1" <?php echo $globalPrefs['allow_in_app'] ? 'checked' : ''; ?>>
            <span class="switch-label">In-app alerts</span>
            <span class="switch-help">Show notifications inside the app.</span>
          </label>
          <label class="switch">
            <input type="checkbox" name="allow_email" value="1" <?php echo $globalPrefs['allow_email'] ? 'checked' : ''; ?>>
            <span class="switch-label">Email</span>
            <span class="switch-help">Send email copies when available.</span>
          </label>
          <label class="switch">
            <input type="checkbox" name="allow_push" value="1" <?php echo $globalPrefs['allow_push'] ? 'checked' : ''; ?>>
            <span class="switch-label">Push</span>
            <span class="switch-help">Send push notifications to browsers/devices.</span>
          </label>
        </div>

        <h2>Categories</h2>
        <div class="grid three">
          <?php foreach ($globalPrefs['types'] as $category => $enabled): ?>
            <label class="switch">
              <input type="checkbox" name="types[<?php echo sanitize($category); ?>]" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
              <span class="switch-label"><?php echo sanitize(ucwords(str_replace('_', ' ', $category))); ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <h2>Per-notification controls</h2>
        <div class="table-responsive">
          <table class="table table-tight">
            <thead>
              <tr>
                <th scope="col">Notification</th>
                <th scope="col">Channels</th>
                <th scope="col">Snooze until</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($typesCatalog as $type => $meta):
                $pref = $typePrefs[$type] ?? ['allow_web' => 1, 'allow_email' => 0, 'allow_push' => 0, 'mute_until' => null];
                $muteValue = '';
                if (!empty($pref['mute_until'])) {
                    try {
                        $dt = new DateTimeImmutable($pref['mute_until']);
                        $muteValue = $dt->format('Y-m-d\TH:i');
                    } catch (Throwable $e) {
                        $muteValue = '';
                    }
                }
              ?>
                <tr>
                  <th scope="row">
                    <div class="notif-label"><?php echo sanitize($meta['label'] ?? $type); ?></div>
                    <?php if (!empty($meta['description'])): ?>
                      <div class="notif-desc"><?php echo sanitize($meta['description']); ?></div>
                    <?php endif; ?>
                  </th>
                  <td class="notif-channels">
                    <label><input type="checkbox" name="pref[<?php echo sanitize($type); ?>][allow_web]" value="1" <?php echo !empty($pref['allow_web']) ? 'checked' : ''; ?>> In-app</label>
                    <label><input type="checkbox" name="pref[<?php echo sanitize($type); ?>][allow_email]" value="1" <?php echo !empty($pref['allow_email']) ? 'checked' : ''; ?>> Email</label>
                    <label><input type="checkbox" name="pref[<?php echo sanitize($type); ?>][allow_push]" value="1" <?php echo !empty($pref['allow_push']) ? 'checked' : ''; ?>> Push</label>
                  </td>
                  <td>
                    <input type="datetime-local" name="pref[<?php echo sanitize($type); ?>][mute_until]" value="<?php echo sanitize($muteValue); ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <footer class="card-footer">
        <button type="submit" class="btn primary">Save changes</button>
      </footer>
    </form>
  </section>

  <section class="card">
    <header class="card-header">
      <h2>Push status</h2>
      <p class="card-subtitle">Manage browser subscriptions and background push.</p>
    </header>
    <div class="card-body">
      <?php if (!$vapidConfigured): ?>
        <p class="muted">VAPID keys are not configured. Push notifications are currently disabled.</p>
      <?php endif; ?>
      <p data-push-status aria-live="polite">Push notifications: <strong data-push-status-text>Checking…</strong></p>
      <div class="button-row">
        <button type="button" class="btn" data-push-action="enable">Enable push</button>
        <button type="button" class="btn secondary" data-push-action="disable">Disable push</button>
      </div>
    </div>
  </section>

  <section class="card">
    <header class="card-header">
      <h2>Devices</h2>
      <p class="card-subtitle">Browsers and apps registered to receive notifications.</p>
    </header>
    <div class="card-body" data-push-devices-region>
      <p class="muted" data-push-empty <?php echo $devices ? 'hidden' : ''; ?>>No devices are currently registered.</p>
      <ul class="device-list" data-push-device-list <?php echo $devices ? '' : 'hidden'; ?>>
        <?php foreach ($devices as $device): ?>
          <li data-device-id="<?php echo (int)$device['id']; ?>">
            <div class="device-meta">
              <strong><?php echo sanitize($device['kind']); ?></strong>
              <?php if (!empty($device['user_agent'])): ?>
                <span><?php echo sanitize($device['user_agent']); ?></span>
              <?php endif; ?>
              <?php if (!empty($device['last_used_at'])): ?>
                <span>Last used <?php echo sanitize($device['last_used_at']); ?></span>
              <?php endif; ?>
            </div>
            <form method="post" class="inline-form" onsubmit="return confirm('Remove this device?');">
              <input type="hidden" name="intent" value="device-delete">
              <input type="hidden" name="device_id" value="<?php echo (int)$device['id']; ?>">
              <input type="hidden" name="<?php echo CSRF_TOKEN_NAME; ?>" value="<?php echo csrf_token(); ?>">
              <button type="submit" class="btn link">Remove</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
