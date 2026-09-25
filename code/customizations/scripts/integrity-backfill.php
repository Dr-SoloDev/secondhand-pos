<?php
/**
 * integrity-backfill.php — เริ่ม hash chain ให้ผู้ขายที่ยังไม่มี (action='backfill')
 * รันครั้งเดียว/ idempotent: ข้าม entity ที่มี chain แล้ว
 *
 * Usage (จาก code/):
 *   docker compose exec -T web php /var/www/customizations/scripts/integrity-backfill.php
 *   docker compose exec -T web php /var/www/customizations/scripts/integrity-backfill.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('APP_ACCESS', true);
// host layout: code/base-pos/api  |  container layout: /var/www/html/api
$bootstrapCandidates = [
    __DIR__ . '/../../base-pos/api/autoload.php', // host
    '/var/www/html/api/autoload.php',             // container
];
$autoload = null;
foreach ($bootstrapCandidates as $c) {
    if (is_file($c)) { $autoload = $c; break; }
}
if ($autoload === null) {
    fwrite(STDERR, "ERROR: cannot locate api/autoload.php\n");
    exit(1);
}
require $autoload;
require dirname($autoload) . '/config.php';

$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::getInstance()->getConnection();
$sellerModel = new Seller();

$ids = $pdo->query('SELECT id FROM sellers ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);

$stats = ['total' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($ids as $id) {
    $id = (int)$id;
    $stats['total']++;

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM record_integrity WHERE entity_type = ? AND entity_id = ?'
    );
    $stmt->execute(['seller', $id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $stats['skipped']++;
        continue;
    }

    if ($dryRun) {
        echo "[dry-run] would create backfill chain for seller {$id}\n";
        continue;
    }

    $snapshot = $sellerModel->getById($id);
    if (!$snapshot) {
        $stats['failed']++;
        echo "[FAIL] seller {$id}: not found\n";
        continue;
    }

    $seq = IntegrityService::append('seller', $id, 'backfill', $snapshot, 'backfill', 'integrity-backfill');
    if ($seq !== false) {
        $stats['created']++;
        echo "[created] seller {$id} seq={$seq}\n";
    } else {
        $stats['failed']++;
        echo "[FAIL] seller {$id}: append failed (see error_log)\n";
    }
}

echo "\n==== Summary ====\n";
echo "total={$stats['total']} created={$stats['created']} skipped={$stats['skipped']} failed={$stats['failed']}\n";

// verify ทุก entity ที่มี chain
if (!$dryRun && $stats['created'] > 0) {
    echo "\n==== Verify ====\n";
    $bad = 0;
    $chains = $pdo->query(
        "SELECT entity_id, COUNT(*) c FROM record_integrity WHERE entity_type='seller' GROUP BY entity_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($chains as $ch) {
        $r = IntegrityService::verify('seller', (int)$ch['entity_id']);
        if (!$r['valid']) {
            $bad++;
            echo "BROKEN seller {$ch['entity_id']}: {$r['reason']} @seq {$r['broken_at']}\n";
        }
    }
    echo count($chains) . " chains, broken={$bad}\n";
}

exit($stats['failed'] > 0 ? 1 : 0);
