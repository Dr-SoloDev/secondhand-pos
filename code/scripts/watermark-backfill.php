<?php
/**
 * watermark-backfill.php — ใส่ watermark รูปบัตรประชาชนที่มีอยู่เดิม (ครั้งเดียว/ idempotent)
 *
 * Usage (จาก code/):
 *   php scripts/watermark-backfill.php                    # scan uploads/sellers + uploads-ui/sellers
 *   php scripts/watermark-backfill.php --dir=uploads/xxx  # เพิ่ม/แทนที่ dir
 *   php scripts/watermark-backfill.php --dry-run          # นับอย่างเดียว ไม่เขียน
 *
 * Idempotent: manifest .watermark-manifest.json บันทึกไฟล์ที่ทำแล้ว → รันซ้ำได้
 * Safety: copy ต้นฉบับไป _pre-watermark-backup/ ก่อนเขียนทับ
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require __DIR__ . '/../customizations/api/Services/ImageWatermark.php';

if (!ImageWatermark::available()) {
    fwrite(STDERR, "ERROR: GD/font ไม่พร้อม — font=" . var_export(ImageWatermark::fontPath(), true) . "\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..'); // code/
$dryRun = in_array('--dry-run', $argv, true);

$dirs = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--dir=') === 0) {
        $dirs[] = substr($arg, 6);
    }
}
if (!$dirs) {
    $dirs = ['uploads/sellers', 'uploads-ui/sellers'];
}

// ---- manifest (idempotent) ----
$manifestPath = $root . '/uploads/sellers/.watermark-manifest.json';
$manifest = [];
if (is_file($manifestPath)) {
    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (is_array($decoded)) $manifest = $decoded;
}

$stats = ['total' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0];
$failures = [];

foreach ($dirs as $dir) {
    $abs = $root . '/' . ltrim($dir, '/');
    if (!is_dir($abs)) {
        fwrite(STDERR, "skip (no dir): {$dir}\n");
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $name = strtolower($file->getFilename());
        if (!preg_match('/\.(jpe?g)$/', $name)) continue;
        // ข้าม backup dir เอง
        if (strpos($file->getPathname(), '_pre-watermark-backup') !== false) continue;

        $stats['total']++;
        $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');

        if (isset($manifest[$rel])) {
            $stats['skipped']++;
            continue;
        }
        if ($dryRun) {
            echo "[dry-run] would process: {$rel}\n";
            continue;
        }

        // รูปเล็กเกินกว่าจะวางลายได้ (เช่น placeholder 1x1) → ข้ามไม่ใช่ failure
        $probe = @imagecreatefromjpeg($file->getPathname());
        if ($probe && min(imagesx($probe), imagesy($probe)) < 160) {
            imagedestroy($probe);
            $manifest[$rel] = 'too-small:' . date('c');
            $stats['skipped']++;
            echo "[skip too-small] {$rel}\n";
            continue;
        }
        if ($probe) imagedestroy($probe);

        $result = watermarkFile($file->getPathname(), $root, $rel, ltrim($dir, '/'));
        if ($result === true) {
            $manifest[$rel] = date('c');
            $stats['done']++;
            echo "[done] {$rel}\n";
        } else {
            $stats['failed']++;
            $failures[$rel] = $result;
            echo "[FAIL] {$rel}: {$result}\n";
        }
    }
}

if (!$dryRun) {
    if (!is_dir(dirname($manifestPath))) {
        @mkdir(dirname($manifestPath), 0755, true);
    }
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo "\n==== Summary ====\n";
echo "total={$stats['total']} done={$stats['done']} skipped={$stats['skipped']} failed={$stats['failed']}\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $rel => $err) echo "  - {$rel}: {$err}\n";
}
exit($stats['failed'] > 0 ? 1 : 0);

/**
 * ใส่ watermark 1 ไฟล์ — สำรองต้นฉบับก่อน, เขียนผ่าน temp แล้ว rename
 * @return true|string true=สำเร็จ, string=เหตุผลล้มเหลว
 */
function watermarkFile(string $absPath, string $root, string $rel, string $scanDir): bool|string
{
    $src = @imagecreatefromjpeg($absPath);
    if (!$src) return 'not a loadable jpeg';

    if (!ImageWatermark::apply($src)) {
        imagedestroy($src);
        return 'apply() failed';
    }

    // backup ต้นฉบับครั้งแรก (ยังไม่มี backup) — path คงเดิมภายใต้ backup dir
    $backupRel = substr($rel, strlen($scanDir)) ?: basename($rel);
    $backupPath = $root . '/uploads/sellers/_pre-watermark-backup' . $backupRel;
    if (!is_file($backupPath)) {
        @mkdir(dirname($backupPath), 0755, true);
        if (!@copy($absPath, $backupPath)) {
            imagedestroy($src);
            return 'backup failed';
        }
    }

    $tmp = $absPath . '.wm-tmp';
    $ok = imagejpeg($src, $tmp, 85);
    imagedestroy($src);
    if (!$ok || !is_file($tmp)) {
        @unlink($tmp);
        return 'jpeg write failed';
    }
    if (!@rename($tmp, $absPath)) {
        @unlink($tmp);
        return 'rename failed';
    }
    @chmod($absPath, 0644);
    return true;
}
