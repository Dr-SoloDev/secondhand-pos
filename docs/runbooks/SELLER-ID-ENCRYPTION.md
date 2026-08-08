# Seller ID Encryption Runbook

## Preconditions

- Set a dedicated `SELLER_ID_ENCRYPTION_KEY` generated with `openssl rand -hex 32`.
- Store the key outside source control and include it in the secret backup procedure.
- Put seller writes in a maintenance window for backfill/finalize.
- Create and verify a database backup before `--finalize`.

## Staged Rollout

1. Apply `069_add_seller_id_encryption.sql` before deploying code that writes encrypted IDs.
2. Deploy the dual-read/encrypted-write application.
3. Backfill while retaining legacy plaintext:

   ```bash
   docker compose exec -T web php /var/www/customizations/database/migrate-seller-id-cards.php --backfill
   docker compose exec -T web php /var/www/customizations/database/migrate-seller-id-cards.php --verify
   ```

4. Verify the external database backup can be read and is larger than the schema-only minimum.
5. Remove plaintext only after verification:

   ```bash
   docker compose exec -T web php /var/www/customizations/database/migrate-seller-id-cards.php --finalize --confirm-finalize
   docker compose exec -T web php /var/www/customizations/database/migrate-seller-id-cards.php --verify --require-finalized
   ```

## Rollback

Application rollback is compatible before `--finalize` because legacy plaintext is retained. To recreate plaintext after finalization, use the same encryption key and explicitly confirm the security downgrade:

```bash
docker compose exec -T web php /var/www/customizations/database/migrate-seller-id-cards.php --rollback --confirm-plaintext-rollback
```

Then verify the legacy application before removing the encryption columns. Never drop ciphertext or rotate the key until the restored application and database backup have both been verified.
