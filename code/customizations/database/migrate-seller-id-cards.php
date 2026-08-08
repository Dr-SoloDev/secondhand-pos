#!/usr/bin/env php
<?php
define('APP_ACCESS', true);
$hostBaseApi = __DIR__ . '/../../base-pos/api';
$baseApi = is_file($hostBaseApi . '/config.php') ? $hostBaseApi : '/var/www/html/api';
require_once $baseApi . '/config.php';
require_once $baseApi . '/autoload.php';

$mode = $argv[1] ?? '';
$allowedModes = ['--backfill', '--verify', '--finalize', '--rollback'];
if (!in_array($mode, $allowedModes, true)) {
    fwrite(STDERR, "Usage: php migrate-seller-id-cards.php --backfill|--verify|--finalize|--rollback [confirmation]\n");
    exit(2);
}

$db = Database::getInstance();

function sellerSecurityRows($db)
{
    return $db->fetchAll(
        "SELECT id, id_card, id_card_encrypted, id_card_search_hash, id_card_key_version
         FROM sellers
         WHERE id_card IS NOT NULL OR id_card_encrypted IS NOT NULL
         ORDER BY id ASC"
    ) ?: [];
}

function verifySellerSecurityRows($db, $requireNoPlaintext = false)
{
    $errors = [];
    $encrypted = 0;
    $plaintext = 0;
    foreach (sellerSecurityRows($db) as $row) {
        if (!empty($row['id_card'])) $plaintext++;
        if (empty($row['id_card_encrypted'])) {
            if (!empty($row['id_card'])) $errors[] = "seller {$row['id']}: missing ciphertext";
            continue;
        }
        $encrypted++;
        try {
            $decrypted = SellerIdCipher::decrypt($row['id_card_encrypted']);
            if (!empty($row['id_card']) && !hash_equals((string)$row['id_card'], $decrypted)) {
                $errors[] = "seller {$row['id']}: plaintext mismatch";
            }
            if (!hash_equals(SellerIdCipher::searchHash($decrypted), (string)$row['id_card_search_hash'])) {
                $errors[] = "seller {$row['id']}: search hash mismatch";
            }
            if ((int)$row['id_card_key_version'] !== SellerIdCipher::KEY_VERSION) {
                $errors[] = "seller {$row['id']}: key version mismatch";
            }
        } catch (Throwable $error) {
            $errors[] = "seller {$row['id']}: " . $error->getMessage();
        }
    }
    if ($requireNoPlaintext && $plaintext > 0) $errors[] = "{$plaintext} plaintext seller IDs remain";
    return ['encrypted' => $encrypted, 'plaintext' => $plaintext, 'errors' => $errors];
}

if ($mode === '--backfill') {
    $rows = $db->fetchAll(
        "SELECT id, id_card, notes FROM sellers
         WHERE id_card IS NOT NULL AND id_card <> ''
           AND id_card_encrypted IS NULL
         ORDER BY id ASC"
    ) ?: [];
    $encryptedCount = 0;
    $db->beginTransaction();
    try {
        foreach ($rows as $row) {
            if (!preg_match('/^\d{13}$/', (string)$row['id_card'])) {
                if (($row['notes'] ?? '') === 'system placeholder สำหรับ stock transfer') {
                    $db->query('UPDATE sellers SET id_card = NULL WHERE id = ?', [$row['id']]);
                    continue;
                }
                throw new RuntimeException("seller {$row['id']}: malformed legacy ID card");
            }
            $idCard = SellerIdCipher::normalize($row['id_card']);
            $db->query(
                "UPDATE sellers
                 SET id_card_encrypted = ?, id_card_search_hash = ?, id_card_key_version = ?
                 WHERE id = ? AND id_card_encrypted IS NULL",
                [SellerIdCipher::encrypt($idCard), SellerIdCipher::searchHash($idCard), SellerIdCipher::KEY_VERSION, $row['id']]
            );
            $encryptedCount++;
        }
        $db->commit();
        echo "Backfilled {$encryptedCount} seller ID cards; plaintext retained for staged verification.\n";
    } catch (Throwable $error) {
        $db->rollBack();
        fwrite(STDERR, "Backfill failed and was rolled back: {$error->getMessage()}\n");
        exit(1);
    }
    exit(0);
}

if ($mode === '--verify') {
    $result = verifySellerSecurityRows($db, in_array('--require-finalized', $argv, true));
    echo "Encrypted: {$result['encrypted']}; plaintext: {$result['plaintext']}; errors: " . count($result['errors']) . "\n";
    foreach ($result['errors'] as $error) fwrite(STDERR, $error . "\n");
    exit($result['errors'] ? 1 : 0);
}

if ($mode === '--finalize') {
    if (!in_array('--confirm-finalize', $argv, true)) {
        fwrite(STDERR, "Finalize requires --confirm-finalize after a verified database backup.\n");
        exit(2);
    }
    $verification = verifySellerSecurityRows($db, false);
    if ($verification['errors']) {
        foreach ($verification['errors'] as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    $db->query(
        "UPDATE sellers SET id_card = NULL
         WHERE id_card IS NOT NULL AND id_card_encrypted IS NOT NULL"
    );
    $verification = verifySellerSecurityRows($db, true);
    if ($verification['errors']) {
        foreach ($verification['errors'] as $error) fwrite(STDERR, $error . "\n");
        exit(1);
    }
    echo "Finalized seller ID encryption; plaintext remaining: 0.\n";
    exit(0);
}

if (!in_array('--confirm-plaintext-rollback', $argv, true)) {
    fwrite(STDERR, "Rollback recreates plaintext and requires --confirm-plaintext-rollback.\n");
    exit(2);
}
$rows = sellerSecurityRows($db);
$db->beginTransaction();
try {
    foreach ($rows as $row) {
        $plaintext = !empty($row['id_card_encrypted'])
            ? SellerIdCipher::decrypt($row['id_card_encrypted'])
            : $row['id_card'];
        if (!$plaintext) continue;
        $db->query(
            "UPDATE sellers
             SET id_card = ?, id_card_encrypted = NULL,
                 id_card_search_hash = NULL, id_card_key_version = NULL
             WHERE id = ?",
            [$plaintext, $row['id']]
        );
    }
    $db->commit();
    echo "Rolled back " . count($rows) . " seller ID rows to plaintext.\n";
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, "Rollback failed and was reverted: {$error->getMessage()}\n");
    exit(1);
}
