<?php
declare(strict_types=1);

if (!defined('TASK_PDF_BOOTSTRAP')) {
    require_once __DIR__ . '/helpers.php';
    require_login();
    define('TASK_PDF_BOOTSTRAP', true);
}

require_once __DIR__ . '/includes/export_tokens.php';

set_time_limit(180);

if (!defined('TASK_PDF_SOURCE')) {
    define('TASK_PDF_SOURCE', basename(__FILE__));
}

$context = defined('TASK_PDF_CONTEXT') ? (string)TASK_PDF_CONTEXT : (string)($_GET['context'] ?? 'tasks');
$ttlDays = max(1, min(365, (int)($_GET['ttl'] ?? 30)));
$qrCm    = (float)($_GET['qr_cm'] ?? 3.0);
if (!is_finite($qrCm) || $qrCm <= 0) {
    $qrCm = 3.0;
}
$qrCm     = max(1.0, min(3.0, $qrCm));
$qrSizePx = (int)round($qrCm * 37.7952755906);
$qrSizePx = max(60, min(120, $qrSizePx));

$buildingId = isset($_GET['building_id']) ? (int)$_GET['building_id'] : null;
$roomId     = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
$demoMode   = ((string)($_GET['demo'] ?? '0') === '1');

$tasks = [];

if ($demoMode) {
    $tasks = [
        [
            'id'             => 101,
            'title'          => 'Touch up paint on west wall',
            'priority'       => 'high',
            'assigned_to'    => 'Marina Flores',
            'description'    => "Scratches near door frame. Match existing eggshell finish.",
            'building_name'  => 'North Tower',
            'building_id'    => 7,
            'room_number'    => '201',
            'room_label'     => 'Conference',
        ],
        [
            'id'             => 102,
            'title'          => 'Replace damaged ceiling tile',
            'priority'       => 'mid',
            'assigned_to'    => 'Kendrick Lane',
            'description'    => "Tile cracked above workstation. Inspect for potential leak before replacing.",
            'building_name'  => 'North Tower',
            'building_id'    => 7,
            'room_number'    => '201',
            'room_label'     => 'Conference',
        ],
        [
            'id'             => 205,
            'title'          => 'Install door sweep',
            'priority'       => 'low',
            'assigned_to'    => 'Jamie Chen',
            'description'    => '',
            'building_name'  => 'North Tower',
            'building_id'    => 7,
            'room_number'    => '202',
            'room_label'     => 'Breakout',
        ],
    ];
    $buildingId = $buildingId ?: 7;
} else {
    $selectedIds = [];
    if (!empty($_REQUEST['selected'])) {
        $selectedIds = array_filter(array_map('intval', explode(',', (string)$_REQUEST['selected'])));
    }

    if ($selectedIds) {
        $tasks = fetch_tasks_by_ids($selectedIds);
        if ($buildingId) {
            $tasks = array_values(array_filter($tasks, static function ($task) use ($buildingId) {
                return (int)($task['building_id'] ?? 0) === $buildingId;
            }));
        }
        if ($roomId) {
            $tasks = array_values(array_filter($tasks, static function ($task) use ($roomId) {
                return (int)($task['room_id'] ?? 0) === $roomId;
            }));
        }
    } else {
        $filters = get_filter_values();
        if ($buildingId) {
            $filters['building_id'] = $buildingId;
        }
        if ($roomId) {
            $filters['room_id'] = $roomId;
        }
        $tasks = export_tasks($filters);
    }
}

usort($tasks, static function (array $a, array $b): int {
    $aBuilding = mb_strtolower((string)($a['building_name'] ?? ''));
    $bBuilding = mb_strtolower((string)($b['building_name'] ?? ''));
    $cmp = strcmp($aBuilding, $bBuilding);
    if ($cmp !== 0) {
        return $cmp;
    }

    $aRoom = mb_strtolower(format_room_display($a));
    $bRoom = mb_strtolower(format_room_display($b));
    $cmp = strcmp($aRoom, $bRoom);
    if ($cmp !== 0) {
        return $cmp;
    }

    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

$taskCount = count($tasks);

$pdo = $demoMode ? null : get_pdo();

$headerBuilding = summarise_single_value(
    $tasks,
    static function (array $task): ?string {
        $name = trim((string)($task['building_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        if (isset($task['building_id'])) {
            return 'Building #' . (int)$task['building_id'];
        }
        return null;
    }
);
if ($headerBuilding === '—' && $buildingId) {
    $headerBuilding = $demoMode ? 'Building #' . $buildingId : lookup_building_label($pdo, $buildingId) ?? $headerBuilding;
}
if ($headerBuilding === '—') {
    $headerBuilding = $buildingId ? 'Building #' . $buildingId : 'All Buildings';
}

$headerRoom = summarise_single_value(
    $tasks,
    static function (array $task): ?string {
        $label = format_room_display($task);
        return $label === '—' ? null : $label;
    }
);
if ($headerRoom === '—' && $roomId) {
    $headerRoom = $demoMode ? lookup_room_label_demo($roomId) : lookup_room_label($pdo, $roomId) ?? $headerRoom;
}
if ($headerRoom === '—') {
    $headerRoom = $roomId ? 'Room #' . $roomId : 'All Rooms';
}

$taskIds    = array_column($tasks, 'id');
$publicUrls = [];
$qrMap      = [];

if ($demoMode) {
    foreach ($tasks as $task) {
        $tid = (int)$task['id'];
        $url = 'https://example.com/tasks/' . $tid;
        $publicUrls[$tid] = $url;
        $qr = qr_data_uri($url, $qrSizePx);
        if ($qr) {
            $qrMap[$tid] = $qr;
        }
    }
} elseif ($taskIds) {
    ensure_public_task_token_tables($pdo);
    $existing = fetch_valid_task_tokens($pdo, $taskIds);
    $baseUrl  = base_url_for_pdf();

    foreach ($tasks as $task) {
        $tid = (int)$task['id'];
        $tok = $existing[$tid] ?? insert_task_token($pdo, $tid, $ttlDays);
        $token = is_string($tok['token']) ? $tok['token'] : (string)$tok['token'];
        $url   = $baseUrl . '/public_task_photos.php?t=' . rawurlencode($token);
        $publicUrls[$tid] = $url;
        $qr = qr_data_uri($url, $qrSizePx);
        if ($qr) {
            $qrMap[$tid] = $qr;
        }
    }
}

$downloadName = 'tasks-export.pdf';
if ($context === 'building' && $buildingId) {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($headerBuilding));
    $slug = trim($slug ?: 'building');
    $downloadName = 'building-' . $slug . '-tasks.pdf';
} elseif ($context === 'room' && $roomId) {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($headerRoom));
    $slug = trim($slug ?: 'room');
    $downloadName = 'room-' . $slug . '-tasks.pdf';
}

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Task export</title>
<style>
  @page { size: A4 landscape; margin: 5mm 5mm 7mm 5mm; }
  body {
    font-family: "Inter", "Helvetica Neue", Arial, sans-serif;
    font-size: 10pt;
    color: #0f172a;
    margin: 0;
  }
  .report-shell {
    background: linear-gradient(135deg, rgba(226, 232, 240, 0.8), rgba(191, 219, 254, 0.35));
    border: 0.4mm solid rgba(148, 163, 184, 0.3);
    border-radius: 4mm;
    padding: 4mm 5mm;
    margin-bottom: 4.5mm;
  }
  .report-header {
    display: flex;
    justify-content: space-between;
    align-items: stretch;
    gap: 6mm;
  }
  .report-header .info {
    display: flex;
    flex-direction: column;
    gap: 2mm;
    flex: 1 1 auto;
  }
  .report-header .info .primary-label {
    font-size: 16pt;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: 0.04em;
  }
  .report-header .info .meta {
    display: flex;
    flex-direction: column;
    gap: 1.6mm;
    font-size: 9.4pt;
    color: #334155;
  }
  .report-header .summary {
    flex: 0 0 auto;
    text-align: right;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 3mm;
  }
  .summary .task-count {
    font-size: 15pt;
    font-weight: 800;
    color: #1e293b;
    white-space: nowrap;
  }
  .summary .top-qr {
    display: inline-flex;
    align-items: center;
    gap: 2.2mm;
    justify-content: flex-end;
  }
  .summary .top-qr img {
    width: 3cm;
    height: 3cm;
    object-fit: contain;
    border: 0.35mm solid rgba(100, 116, 139, 0.35);
    border-radius: 2.2mm;
    background: #ffffff;
  }
  .summary .top-qr span {
    font-size: 7.5pt;
    color: #0f172a;
    max-width: 32mm;
    word-break: break-word;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  thead th {
    background: #dbeafe;
    color: #0f172a;
    font-size: 8.6pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    border: 0.3mm solid #bfdbfe;
    padding: 2.2mm 1.8mm;
    text-align: left;
  }
  tbody td {
    border: 0.3mm solid #cbd5f5;
    padding: 1.8mm 1.8mm;
    vertical-align: top;
    font-size: 8.8pt;
  }
  tbody tr:nth-child(odd) {
    background: rgba(226, 232, 240, 0.35);
  }
  td.id-col {
    width: 15mm;
    font-weight: 700;
  }
  td.priority-col {
    width: 23mm;
  }
  td.assigned-col {
    width: 32mm;
  }
  .desc-col {
    width: auto;
  }
  .desc-content {
    display: flex;
    gap: 3mm;
    align-items: flex-start;
  }
  .desc-text {
    flex: 1 1 auto;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .desc-text .empty {
    color: #94a3b8;
    font-style: italic;
  }
  .qr-block {
    flex: 0 0 auto;
    text-align: center;
  }
  .qr-block a {
    text-decoration: none;
    color: #1e293b;
  }
  .qr-block img {
    width: 3cm;
    height: 3cm;
    object-fit: contain;
    border: 0.3mm solid #cbd5f5;
    border-radius: 2mm;
    background: #ffffff;
  }
  .qr-caption {
    margin-top: 1mm;
    font-size: 7.5pt;
    word-break: break-all;
  }
  .no-tasks {
    margin-top: 25mm;
    text-align: center;
    font-size: 14pt;
    color: #334155;
  }
</style>
</head>
<body>
  <div class="report-shell">
    <div class="report-header">
      <div class="info">
        <div class="primary-label"><?php echo htmlspecialchars($headerBuilding, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="meta">
          <span>Room: <?php echo htmlspecialchars($headerRoom, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
      <div class="summary">
        <div class="task-count"><?php echo (int)$taskCount; ?> tasks</div>
        <?php if (!empty($qrMap)): ?>
          <?php
            $firstQrTask = null;
            foreach ($qrMap as $qrTaskId => $_qr) {
                $firstQrTask = $qrTaskId;
                break;
            }
          ?>
          <?php if ($firstQrTask !== null && !empty($publicUrls[$firstQrTask])): ?>
            <a class="top-qr" href="<?php echo htmlspecialchars($publicUrls[$firstQrTask], ENT_QUOTES, 'UTF-8'); ?>">
              <span>Scan for latest photos</span>
              <img src="<?php echo $qrMap[$firstQrTask]; ?>" alt="Task <?php echo (int)$firstQrTask; ?> quick access QR">
            </a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

<?php if ($taskCount === 0): ?>
  <div class="no-tasks">No tasks found for this export.</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Title</th>
        <th>Priority</th>
        <th>Assigned To</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($tasks as $task): ?>
      <?php
        $tid       = (int)$task['id'];
        $priority  = priority_label($task['priority']);
        $assigned  = trim((string)($task['assigned_to'] ?? ''));
        $assigned  = $assigned !== '' ? $assigned : '—';
        $title     = trim((string)($task['title'] ?? ''));
        $title     = $title !== '' ? $title : '—';
        $desc      = trim((string)($task['description'] ?? ''));
        $descHtml  = $desc !== '' ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') : '<span class="empty">No description provided</span>';
        $publicUrl = $publicUrls[$tid] ?? '';
        $qrData    = $qrMap[$tid] ?? null;
      ?>
      <tr>
        <td class="id-col">#<?php echo $tid; ?></td>
        <td><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="priority-col"><?php echo htmlspecialchars($priority, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="assigned-col"><?php echo htmlspecialchars($assigned, ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="desc-col">
          <div class="desc-content">
            <div class="desc-text"><?php echo $descHtml; ?></div>
            <?php if ($publicUrl): ?>
              <div class="qr-block">
                <a href="<?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php if ($qrData): ?>
                    <img src="<?php echo $qrData; ?>" alt="QR code for task <?php echo $tid; ?>">
                  <?php endif; ?>
                  <div class="qr-caption"><?php echo htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8'); ?></div>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

if ((string)($_GET['debug_html'] ?? '0') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    return;
}

$pdfFile  = tempnam(sys_get_temp_dir(), 'wkpdf_') . '.pdf';
$htmlFile = tempnam(sys_get_temp_dir(), 'wkhtml_') . '.html';
file_put_contents($htmlFile, $html);

$wkhtml = '/usr/local/bin/wkhtmltopdf';
if (!is_executable($wkhtml)) {
    $wkhtml = '/usr/bin/wkhtmltopdf';
}
if (!is_executable($wkhtml)) {
    $wkhtml = 'wkhtmltopdf';
}

$cmd = sprintf(
    '%s --quiet --encoding utf-8 --print-media-type ' .
    '--margin-top 5mm --margin-right 5mm --margin-bottom 7mm --margin-left 5mm ' .
    '--page-size A4 --orientation Landscape ' .
    '--footer-right "Page [page] of [toPage]" --footer-font-size 9 ' .
    '%s %s 2>&1',
    escapeshellarg($wkhtml),
    escapeshellarg($htmlFile),
    escapeshellarg($pdfFile)
);

$out = [];
$ret = 0;
exec($cmd, $out, $ret);

@unlink($htmlFile);

if ($ret !== 0 || !file_exists($pdfFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "wkhtmltopdf failed (code $ret)\nCommand:\n$cmd\n\nOutput:\n" . implode("\n", $out);
    return;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);

@unlink($pdfFile);

function format_room_display(array $task): string
{
    $number = trim((string)($task['room_number'] ?? ''));
    $label  = trim((string)($task['room_label'] ?? ''));

    if ($number === '' && $label === '') {
        return '—';
    }
    if ($number !== '' && $label !== '') {
        return $number . ' - ' . $label;
    }
    return $number !== '' ? $number : $label;
}

function summarise_single_value(array $tasks, callable $extractor): string
{
    $values = [];
    foreach ($tasks as $task) {
        $value = $extractor($task);
        if ($value === null || $value === '') {
            $value = '—';
        }
        $values[$value] = true;
    }

    if (!$values) {
        return '—';
    }
    if (count($values) === 1) {
        return (string)array_key_first($values);
    }
    return 'Multiple';
}

function lookup_building_label(PDO $pdo, int $buildingId): ?string
{
    $stmt = $pdo->prepare('SELECT name FROM buildings WHERE id = ? LIMIT 1');
    $stmt->execute([$buildingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['name']) && trim((string)$row['name']) !== '') {
        return (string)$row['name'];
    }
    return 'Building #' . $buildingId;
}

function lookup_room_label(PDO $pdo, int $roomId): ?string
{
    $stmt = $pdo->prepare('SELECT room_number, label FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Room #' . $roomId;
    }
    $display = trim((string)($row['room_number'] ?? ''));
    $label   = trim((string)($row['label'] ?? ''));
    if ($display !== '' && $label !== '') {
        return $display . ' - ' . $label;
    }
    if ($display !== '') {
        return $display;
    }
    if ($label !== '') {
        return $label;
    }
    return 'Room #' . $roomId;
}

function lookup_room_label_demo(int $roomId): string
{
    if ($roomId === 201) {
        return '201 - Conference';
    }
    if ($roomId === 202) {
        return '202 - Breakout';
    }
    return 'Room #' . $roomId;
}

function ensure_public_task_token_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_task_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id BIGINT UNSIGNED NOT NULL,
            token VARBINARY(32) NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            use_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            UNIQUE KEY uniq_token (token),
            INDEX idx_task_exp (task_id, expires_at),
            INDEX idx_exp (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS public_token_hits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_id BIGINT UNSIGNED NOT NULL,
            task_id BIGINT UNSIGNED NOT NULL,
            ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip VARBINARY(16) NULL,
            ua VARCHAR(255) NULL,
            INDEX idx_token (token_id),
            INDEX idx_task (task_id),
            CONSTRAINT fk_hits_token FOREIGN KEY (token_id) REFERENCES public_task_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function fetch_valid_task_tokens(PDO $pdo, array $taskIds): array
{
    if (!$taskIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
    $sql = "SELECT t1.*
            FROM public_task_tokens t1
            JOIN (
                SELECT task_id, MAX(id) AS max_id
                FROM public_task_tokens
                WHERE revoked = 0 AND expires_at > NOW() AND task_id IN ($placeholders)
                GROUP BY task_id
            ) t2 ON t1.id = t2.max_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($taskIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['task_id']] = $row;
    }
    return $out;
}

function insert_task_token(PDO $pdo, int $taskId, int $ttlDays): array
{
    $token  = random_token();
    $expiry = (new DateTimeImmutable('now'))->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');
    $stmt   = $pdo->prepare('INSERT INTO public_task_tokens (task_id, token, expires_at) VALUES (:task, :tok, :exp)');
    $stmt->execute([
        ':task' => $taskId,
        ':tok'  => $token,
        ':exp'  => $expiry,
    ]);

    $id = (int)$pdo->lastInsertId();
    return [
        'id'          => $id,
        'task_id'     => $taskId,
        'token'       => $token,
        'expires_at'  => $expiry,
        'revoked'     => 0,
        'use_count'   => 0,
        'last_used_at'=> null,
    ];
}