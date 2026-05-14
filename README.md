# TKMX HFCM Pro CLI

Command-line interface for [TKMX HFCM Pro API](https://github.com/your-org/tkmx-hfcm-pro-api) WordPress plugin.  
Executes bulk operations (bulk-upsert, import, export) directly on the server — no HTTP round-trips, no rate limits.

## Requirements

- PHP 8.3+
- WordPress with TKMX HFCM Pro API plugin v1.6.x active
- SSH access to the server
- `posix` PHP extension (for config file owner validation)

## Installation

```bash
# Place inside WordPress root
cd /path/to/wordpress
git clone https://github.com/your-org/tkmx-hfcm-pro-cli.git

# Make executable
chmod +x tkmx-hfcm-pro-cli/bin/hfcm

# Copy and configure
cp tkmx-hfcm-pro-cli/config/cli.sample.php tkmx-hfcm-pro-cli/config/cli.local.php
chmod 0600 tkmx-hfcm-pro-cli/config/cli.local.php
# Edit cli.local.php and set HFCM_CLI_DEFAULT_USER to your admin login
```

## Configuration

Edit `config/cli.local.php`:

```php
define('HFCM_CLI_DEFAULT_USER', 'admin');  // WP user login with manage_options
```

Or use environment variables:

```bash
export HFCM_CLI_DEFAULT_USER=admin
```

**Security**: `config/cli.local.php` must be owned by the executing user and have mode `0600`.  
Any other permission causes exit 4 (FORBIDDEN).

## Usage

```bash
cd /path/to/wordpress
./tkmx-hfcm-pro-cli/bin/hfcm <command> [options]
```

### Commands

#### List snippets

```bash
./bin/hfcm snippets:list
./bin/hfcm snippets:list --format=table
./bin/hfcm snippets:list --page=2 --per_page=50 --orderby=name --order=ASC
./bin/hfcm snippets:list --status=active --search=header
```

#### Get a snippet

```bash
./bin/hfcm snippets:get 42
./bin/hfcm snippets:get --id=42
```

#### Create a snippet

```bash
./bin/hfcm snippets:create --file=snippet.json
./bin/hfcm snippets:create --data='{"name":"Header GA","snippet":"<script>...</script>","location":"header","status":"active"}'
cat snippet.json | ./bin/hfcm snippets:create --file=-
```

#### Update a snippet (PUT — full replace)

```bash
./bin/hfcm snippets:update 42 --file=snippet.json
```

#### Patch a snippet (PATCH — partial update)

```bash
./bin/hfcm snippets:update 42 --mode=patch --data='{"status":"inactive"}'
```

#### Delete snippet(s)

```bash
# Single delete
./bin/hfcm snippets:delete --id=42

# Bulk delete (max 100 IDs)
./bin/hfcm snippets:delete --ids=1,2,3,42
```

#### Bulk upsert

```bash
./bin/hfcm snippets:bulk-upsert --file=payload.json
./bin/hfcm snippets:bulk-upsert --file=payload.json.gz   # gzip transparent
cat payload.json | ./bin/hfcm snippets:bulk-upsert --file=-
```

#### Import

```bash
./bin/hfcm snippets:import --file=export.json
./bin/hfcm snippets:import --file=export.json.gz
```

#### Export

```bash
# JSON to STDOUT
./bin/hfcm snippets:export

# CSV to file
./bin/hfcm snippets:export --format=csv --out=backup.csv

# JSON to file
./bin/hfcm snippets:export --format=json --out=backup.json
```

### Common Options

| Option | Description |
|--------|-------------|
| `--format=json\|table\|csv` | Output format (default: `json`) |
| `--pretty` | Pretty-print JSON output |
| `--quiet` | Suppress STDERR messages |
| `--file=<path>` | Load payload from file (`.gz` auto-detected) |
| `--file=-` | Load payload from STDIN |
| `--data=<json>` | Inline JSON payload |
| `--out=<path>` | Write output to file (export only) |
| `--as=<user_login>` | Run as a specific WP user (requires `HFCM_CLI_ALLOW_AS=1`) |
| `--help` | Show help |

### Impersonation

```bash
# Enable impersonation (opt-in, every use is audit-logged)
HFCM_CLI_ALLOW_AS=1 ./bin/hfcm snippets:list --as=editor

# Non-admin users are rejected for write commands (exit 4)
HFCM_CLI_ALLOW_AS=1 ./bin/hfcm snippets:create --as=subscriber --data='...'
# → exit 4: Insufficient permissions
```

### Payload Size Limits (same as REST API)

| Type | Limit |
|------|-------|
| Compressed input (`.gz`) | 5 MB |
| Uncompressed / decompressed | 10 MB |

Exceeding limits → exit 1 + `payload_too_large` error.

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Validation / not found / payload too large |
| 2 | wp-load.php not found |
| 3 | TKMX HFCM Pro API plugin not active |
| 4 | Permission denied / authentication failure |
| 64 | Usage error (invalid arguments) |
| 70 | Internal error |
| 75 | Temporary failure (another import/upsert is running) |

## Mutual Exclusion with REST API

`bulk-upsert` and `import` acquire the `hfcm_import_lock` transient (TTL 5 min), the same key used by the REST API. CLI and REST are mutually exclusive — if one is running the other exits with code 75.

## Audit Logging

Every CLI invocation is recorded in `wp_hfcm_takumi_audit_logs` with:
- `endpoint`: `cli:<command>`
- `user_login`: active WP user
- Impersonation details when `--as` is used

## Running Tests

PHPUnit 10+ must be installed separately (composer is not required for CLI operation):

```bash
# Install PHPUnit globally or locally
composer global require phpunit/phpunit:^10

# Run tests (no WordPress needed — WP_Error is stubbed)
phpunit --configuration phpunit.xml

# Or with a local phpunit phar
php phpunit.phar --configuration phpunit.xml
```

Tests cover: `Args`, `Output`, `ExitCode`, `WpErrorFormatter`, `PayloadLoader` (including gzip and size limits).

## Troubleshooting

**`Error: wp-load.php not found`**  
The CLI must be placed inside or adjacent to the WordPress root. It searches up to 3 parent directories.

**`Error: TKMX HFCM Pro API plugin is not active`**  
Activate the plugin in WordPress admin or via WP-CLI: `wp plugin activate hfcm-pro-takumi-api`.

**`Error: config/cli.local.php must have mode 0600`**  
Run: `chmod 0600 config/cli.local.php`

**`Error: config/cli.local.php must be owned by the current user`**  
Ensure the file is owned by the user running the CLI: `chown $(whoami) config/cli.local.php`

**Exit 75 (lock busy)**  
Another `bulk-upsert` or `import` is running (via CLI or REST). Wait and retry.

**Release timing note**  
The WP plugin runs `check_and_upgrade()` on every load. Immediately after a plugin update, the first CLI invocation may be slower. Schedule maintenance-window CLI runs after plugin upgrades.
