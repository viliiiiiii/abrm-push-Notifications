<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/notifications.php';

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;
$result = notif_process_push_queue($limit);

printf("Checked: %d\nSent: %d\nSkipped: %d\nFailed: %d\n", $result['checked'], $result['sent'], $result['skipped'], $result['failed']);
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, $error . "\n");
    }
}

exit($result['failed'] > 0 ? 1 : 0);
