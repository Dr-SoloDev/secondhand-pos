# HTTPS Setup with Caddy + Let's Encrypt

This guide covers enabling HTTPS via automatic Let's Encrypt certificates managed by Caddy.

## Prerequisites

- A domain name you control (e.g. `pos.yourdomain.com`)
- A server with a public IP address
- Ports 80 and 443 open in your firewall/security group

## Steps

### 1. Point DNS A Record to Server IP

Create an A record at your DNS provider:

```
Type:  A
Name:  pos          (or @ for root domain)
Value: <your-server-public-ip>
TTL:   300 (or auto)
```

Wait for DNS propagation (usually 1–10 minutes with low TTL).

### 2. Configure Environment Variables

Copy `.env.example` to `.env` if you haven't already:

```bash
cp .env.example .env
```

Edit `.env` and set:

```env
DOMAIN=pos.yourdomain.com
ACME_EMAIL=admin@yourdomain.com
```

- `DOMAIN` must match the DNS A record you created.
- `ACME_EMAIL` is used by Let's Encrypt for expiry notifications.

### 3. Restart Services

```bash
docker compose down && docker compose up -d
```

Caddy will automatically:
- Request a certificate from Let's Encrypt for `$DOMAIN`
- Configure HTTPS with the certificate
- Redirect all HTTP (port 80) traffic to HTTPS (port 443)

### 4. Verify HTTPS is Working

```bash
curl -I https://pos.yourdomain.com/api/index.php
```

Expected response: `HTTP/2 401` (unauthenticated, but over HTTPS — correct behavior).

You can also open `https://pos.yourdomain.com` in a browser and confirm the padlock icon is shown.

## Security Headers Applied

The Caddyfile sets the following headers on all HTTPS responses:

| Header | Value |
|--------|-------|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |

## Troubleshooting

**Certificate not issued:**
- Confirm DNS A record resolves to your server: `dig pos.yourdomain.com`
- Confirm port 80 and 443 are reachable from the internet
- Check Caddy logs: `docker compose logs caddy`

**ERR_TOO_MANY_REDIRECTS:**
- Ensure the app itself does not force HTTP redirects that conflict with Caddy

**Rate limits (Let's Encrypt):**
- Let's Encrypt limits to 5 duplicate certificates per week. Use the staging ACME server for testing by adding `acme_ca https://acme-staging-v02.api.letsencrypt.org/directory` inside the global `{}` block in Caddyfile.
