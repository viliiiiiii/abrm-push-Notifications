<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';
require_login();

$apps = get_pdo();
$core = get_pdo('core');

$movementId = isset($_GET['movement_id']) ? (int)$_GET['movement_id'] : 0;
if ($movementId <= 0) { http_response_code(400); echo 'Missing movement_id'; exit; }

$stmt = $apps->prepare(
    "SELECT m.*, i.name AS item_name, i.sku
     FROM inventory_movements m
     JOIN inventory_item i ON i.id = m.item_id
     WHERE m.id = ?"
);
$stmt->execute([$movementId]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); echo 'Movement not found'; exit; }

$src = '—'; $tgt = '—';
if (!empty($m['source_sector_id'])) {
    $s = $core->prepare('SELECT name FROM sectors WHERE id = ?'); $s->execute([(int)$m['source_sector_id']]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) $src = (string)$row['name'];
}
if (!empty($m['target_sector_id'])) {
    $s = $core->prepare('SELECT name FROM sectors WHERE id = ?'); $s->execute([(int)$m['target_sector_id']]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) $tgt = (string)$row['name'];
}

$token = null;
try {
    $st = $apps->prepare('SELECT token FROM inventory_public_tokens WHERE movement_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $st->execute([$movementId]); if ($r = $st->fetch(PDO::FETCH_ASSOC)) $token = (string)$r['token'];
} catch (Throwable $e) {}

$base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
$signUrl = $token ? $base . '/inventory.php?action=public_sign&token=' . urlencode($token) : '';

function pdf_escape_t(string $text): string {
    return strtr($text, ["\\"=>"\\\\","("=>"\\(",")"=>"\\)","\r"=>" ","\n"=>" "]);
}

function build_transfer_pdf(array $m, string $src, string $tgt, string $signUrl): string {
    $ref = 'TRF-' . str_pad((string)$m['id'], 6, '0', STR_PAD_LEFT);
    $when = (string)($m['ts'] ?? '');
    $item = (string)($m['item_name'] ?? '');
    $sku  = (string)($m['sku'] ?? '');
    $dir  = strtoupper((string)($m['direction'] ?? ''));
    $amt  = (int)($m['amount'] ?? 0);
    $rsn  = (string)($m['reason'] ?? ($m['notes'] ?? ''));

    $lines = ["Inventory Transfer Form","Reference: $ref"];
    if ($when !== '') $lines[] = "Date/Time: $when";
    $lines[] = "";
    $lines[] = "Item: $item";
    if ($sku !== '') $lines[] = "SKU: $sku";
    $lines[] = "Direction: $dir";
    $lines[] = "Quantity: $amt";
    $lines[] = "From sector: $src";
    $lines[] = "To sector:   $tgt";
    if ($rsn !== '') $lines[] = "Reason: $rsn";
    $lines[] = "";
    if ($signUrl !== '') { $lines[] = "Digital signing link:"; $lines[] = $signUrl; $lines[] = ""; }
    $lines[] = "Signatures:";
    $lines[] = "Source: _____________________________    Date: ____________";
    $lines[] = "Target: _____________________________    Date: ____________";

    $pageW=595; $pageH=842; $marginX=50; $y=780; $lineH=16; $font=11;
    $content = "";
    foreach ($lines as $line) {
        $content .= "BT /F1 $font Tf $marginX $y Td (" . pdf_escape_t($line) . ") Tj ET\n";
        $y -= $lineH; if ($y < 60) break;
    }

    $pdf = "%PDF-1.4\n";
    $objs = [];
    $objs[] = "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n";
    $objs[] = "2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n";
    $objs[] = "4 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj\n";
    $len = strlen($content);
    $objs[] = "5 0 obj <</Length $len>> stream\n$content\nendstream endobj\n";
    $objs[] = "3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 $pageW $pageH] /Resources <</Font <</F1 4 0 R>>>> /Contents 5 0 R>> endobj\n";

    $out = $pdf; $offsets = [0];
    foreach ($objs as $obj) { $offsets[] = strlen($out); $out .= $obj; }
    $xrefPos = strlen($out); $count = count($offsets);

    $out .= "xref\n0 $count\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) { $out .= sprintf("%010d 00000 n \n", $offsets[$i]); }
    $out .= "trailer <</Size $count /Root 1 0 R>>\nstartxref\n$xrefPos\n%%EOF";

    return $out;
}

$pdf = build_transfer_pdf($m, $src, $tgt, $signUrl);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="transfer-' . $movementId . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
