# TKMX HFCM Pro CLI 設計書（Codexレビュー反映版）

## Context

TKMX HFCM Pro API（WordPressプラグイン、REST API）と業務ロジック等価のCLIツール。レンタルサーバーSSHから直接実行。WPブート経由で既存サービスクラスを薄くラップ。

**動機:**
- bulk-upsert等の大量同期をHTTP経由でなくサーバー直で実行
- レート制限・gzip手間・往復遅延を回避
- cron/CIフレンドリー
- ロジック二重管理ゼロ

**前提:**
- 配置: `<wordpress-root>/tkmx-hfcm-pro-cli/`
- 認証: wp-config.php 経由でWPロード後、`wp_set_current_user()` で文脈確立
- PHP 8.3 / composer不要 / root不要
- TKMX HFCM Pro API プラグイン v1.6.x 有効化済み前提

## 等価性マトリクス（API ⇔ CLI）

| 機能領域 | REST API | CLI | 備考 |
|---|---|---|---|
| CRUD ロジック | サービス層 | サービス層直叩き | **完全一致** |
| Validator | `Validator::validate_snippet_data()` | 同関数を直接呼ぶ | **完全一致** |
| Bulk-upsert / Import / Export | サービス層 | サービス層直叩き | **完全一致** |
| 認可 | REST permission callback (`manage_options`) | CLI Guard で同 capability 強制 | **同等（実装位置が違う）** |
| 認証主体 | Application Password | `wp_set_current_user()` | **代替** |
| 排他ロック | `hfcm_import_lock` transient | 同 transient を共通使用 | **完全一致（共通キー）** |
| 入力サイズ上限 | gzip 5MB圧縮 / 10MB展開 | 同閾値適用 | **完全一致** |
| 監査ログ | `Audit_Logger::log_request()` | `Audit_Logger::log('cli:<cmd>', ...)` 必須 | **代替（記録経路）** |
| Rate limiter | 60 req/min | **非適用** | ローカル実行のため意図的に省略 |
| CSRF | nonce / Application Password | **非該当** | CLI実行で攻撃面なし |

→ **「業務ロジック等価」+「認可・排他・入力防御 同等」**。Rate limiter/CSRFのみ意図的に非適用（理由併記）。

## スコープ

| CLIサブコマンド | API | 内部呼び出し先 |
|---|---|---|
| `snippets:list` | GET /snippets | `Snippet_Service::get_snippets()` |
| `snippets:get <id>` | GET /snippets/{id} | `Snippet_Service::get_snippet()` |
| `snippets:create --file=x.json` | POST /snippets | `Validator::validate_snippet_data()` + `Snippet_Service::create_snippet()` |
| `snippets:update <id> --file=x.json` | PUT /snippets/{id} | `Snippet_Service::update_snippet()` (PUT) |
| `snippets:patch <id> --file=x.json` | PATCH /snippets/{id} | `Snippet_Service::update_snippet()` (PATCH) |
| `snippets:delete --id=<id>` | DELETE /snippets/{id} | 単一削除 |
| `snippets:delete --ids=1,2,3` | DELETE /snippets | 一括削除（最大100件） |
| `snippets:bulk-upsert --file=x.json` | POST /snippets/bulk-upsert | `Bulk_Upsert_Service::process()` |
| `snippets:import --file=x.json` | POST /snippets/import | `Import_Service::import_snippets()` |
| `snippets:export [--format=json\|csv] [--out=path]` | GET /snippets/export | `Export_Service::export_*()` |

スコープ外: duplicate / flush-cache / rebuild-tag-index / health / test

## ディレクトリ構成

```
<wordpress-root>/tkmx-hfcm-pro-cli/
├── bin/
│   └── hfcm                      # 実行エントリ
├── src/
│   ├── Bootstrap.php             # wp-load探索＋ロード＋ユーザー確立
│   ├── Router.php
│   ├── Console/
│   │   ├── Args.php
│   │   ├── Output.php
│   │   └── ExitCode.php
│   ├── Commands/
│   │   ├── AbstractCommand.php   # 共通Guard・例外→exit code変換・監査記録
│   │   ├── SnippetsList.php
│   │   ├── SnippetsGet.php
│   │   ├── SnippetsCreate.php
│   │   ├── SnippetsUpdate.php
│   │   ├── SnippetsDelete.php
│   │   ├── SnippetsBulkUpsert.php
│   │   ├── SnippetsImport.php
│   │   └── SnippetsExport.php
│   └── Support/
│       ├── PayloadLoader.php     # --file/STDIN/--data + gzip + サイズ上限
│       ├── ExecutionLock.php     # hfcm_import_lock transient ラッパ
│       ├── CliAudit.php          # Audit_Logger::log('cli:<cmd>',...) ラッパ
│       └── WpErrorFormatter.php
├── config/
│   └── cli.sample.php            # HFCM_CLI_* 環境変数・user_login許可リスト
└── README.md
```

## 主要設計

### 1. ブート (`src/Bootstrap.php`)

- `__DIR__/../../wp-load.php` を require_once（見つからない場合 親方向に最大3階層探索）→ 失敗 exit 2
- ロード後 `is_plugin_active('hfcm-pro-takumi-api/hfcm-pro-takumi-api.php')` 確認 → 非活性 exit 3
- **メンテ警告**: `check_and_upgrade()` が毎ロードで走るためマイグレーション直後は重い。`HFCM_CLI_SKIP_UPGRADE_CHECK=1` ではスキップ不可だが、READMEに「リリース直後のCLI実行はメンテ時間帯で」と明記
- **タイムアウト**: `set_time_limit(0)` をデフォルト適用（cron-friendly）、メモリは `WP_MAX_MEMORY_LIMIT` を尊重しつつ最低 `256M` を確認
- **ユーザー確立（強化版）**:
  - デフォルト動作: `HFCM_CLI_DEFAULT_USER` 環境変数 or `config/cli.local.php` で固定ユーザー指定
  - `--as=<user_login>` は **デフォルト無効**。`HFCM_CLI_ALLOW_AS=1` 環境変数で明示有効化した場合のみ受理
  - `--as` 受理時は監査ログに `actor=$ENV_USER, impersonated=$as` を必ず記録
  - `config/cli.local.php` のファイル権限 0600 / 所有者 = 実行ユーザー を Bootstrap で検証、不一致なら exit 4
  - `wp_set_current_user()` 後に `current_user_can('manage_options')` 検査 → 不所持 exit 4

### 2. AbstractCommand（共通Guard）

```
run(Args):
  1. CliAudit::start($command, $args_redacted)  // 開始ログ
  2. current_user_can('manage_options') 検査 (list/getは 'read')
     → false なら exit 4
  3. write系コマンドは ExecutionLock::acquire() で hfcm_import_lock 取得
     → 取得失敗時 exit 75 (EX_TEMPFAIL)
  4. try { 実処理 } catch { ... } finally { ExecutionLock::release() }
  5. CliAudit::finish($exit_code, $summary)
```

排他ロックの共通キーは REST側 (`class-rest-api.php:1075`, `1125`) と同一の `hfcm_import_lock`。CLI と REST 相互排他成立。

### 3. 入力ロード (`src/Support/PayloadLoader.php`)

優先順:
1. `--data=<json>` インライン
2. `--file=<path>`（`.gz` は gzdecode 透過）
3. STDIN（`-` 指定時）

**サイズ上限（REST層と同値）:**
- 圧縮入力 ≤ 5 MB
- 展開後 ≤ 10 MB
- 超過時 exit 1 + `payload_too_large` エラーコード
- gzip bomb 対策: `gzdecode` 前にファイルサイズ検査、展開中もストリーム長監視（一時的に大きい場合は中断）

### 4. 引数 (`src/Console/Args.php`)

- `--key=value` / `--key value` / `-k value` / positional / `--flag`
- 共通フラグ: `--format=json|table|csv` / `--pretty` / `--quiet` / `--verbose` / `--as=<user>` / `--file=<path>` / `--data=<json>` / `--out=<path>`

### 5. 出力 (`src/Console/Output.php`)

- 標準: APIレスポンス互換 `{success, data, meta}` を STDOUT
- table: list系のみ
- エラー: JSON STDOUT + 人間可読 STDERR

### 6. エラーコード規約（WP_Error → exit code マッピング）

| WP_Error code / 状況 | HTTP status | CLI exit code |
|---|---|---|
| 成功 | 200/201 | 0 |
| validation 失敗 (`invalid_*`) | 400 | 1 |
| `not_found` | 404 | 1 |
| `payload_too_large` | 413 | 1 |
| `bulk_*_too_large` | 400 | 1 |
| wp-load 失敗 | - | 2 |
| プラグイン非活性 | - | 3 |
| 権限なし / 未認証 (`rest_forbidden`等) | 401/403 | 4 |
| 排他ロック取得失敗 | - | 75 |
| 引数不正 | - | 64 |
| 内部例外 / 500系 | 500 | 70 |

`WpErrorFormatter::toExitCode(WP_Error)` で集約変換。

### 7. 監査ログ (`src/Support/CliAudit.php`)

- 既存 `HFCM_Takumi_API_Audit_Logger::log()` をCLIから直呼び
- 記録項目: `actor` (UNIX user + WP user_login), `command`, `args`（sensitive redact）, `payload_meta`（bytes, sha256）, `result`, `duration_ms`, `exit_code`
- bulk-upsert/import の本体ペイロードは記録しない（REST側と同じ方針）
- `--as` 使用時は impersonation 情報を必須記録

### 8. クラス参照（既存・再利用）

- `HFCM_Takumi_API_Snippet_Service`
- `HFCM_Takumi_API_Bulk_Upsert_Service`
- `HFCM_Takumi_API_Import_Service`
- `HFCM_Takumi_API_Export_Service`
- `HFCM_Takumi_API_Validator`
- `HFCM_Takumi_API_Audit_Logger`
- `HFCM_Takumi_API_Tag_Index`（bulk-upsert経由間接利用）

サービス層は `current_user_can()` を内部で呼ばない → CLI側 AbstractCommand の Guard が認可境界。REST層の permission callback と機能対称。

## エントリポイント (`bin/hfcm`)

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../src/Bootstrap.php';
spl_autoload_register(function ($cls) {
    $p = str_replace(['Tkmx\\HfcmCli\\', '\\'], ['', '/'], $cls);
    $f = __DIR__ . '/../src/' . $p . '.php';
    if (is_file($f)) require $f;
});
\Tkmx\HfcmCli\Bootstrap::init($argv);
exit((new \Tkmx\HfcmCli\Router())->dispatch($argv));
```

## 使い方サンプル

```bash
# 一覧
./bin/hfcm snippets:list --format=table

# bulk-upsert（gzip透過）
./bin/hfcm snippets:bulk-upsert --file=payload.json.gz

# STDIN
cat payload.json | ./bin/hfcm snippets:bulk-upsert --file=-

# エクスポート
./bin/hfcm snippets:export --format=csv --out=backup.csv

# 削除
./bin/hfcm snippets:delete --id=42
./bin/hfcm snippets:delete --ids=1,2,3

# impersonation（明示オプトイン必須）
HFCM_CLI_ALLOW_AS=1 ./bin/hfcm snippets:list --as=admin
```

## 重要ファイル参照

- `/home/isabro/dev/TKMX-HFCM-Pro-API/hfcm-pro-takumi-api.php` (entry, autoloader, check_and_upgrade)
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-rest-api.php` (排他ロック・入力上限の参考実装位置: 1075, 1088, 1125, 1134, 1332, 1534, 1546, 1568, 1608, 1704)
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-snippet-service.php`
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-bulk-upsert-service.php`
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-import-service.php`
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-export-service.php`
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-validator.php`
- `/home/isabro/dev/TKMX-HFCM-Pro-API/includes/class-audit-logger.php`

## 設計判断と理由

- **wp-load 採用** — Validator/bulk-upsert/tag-index 冪等性ロジックを再実装するとAPI乖離リスク。CLI = 業務ロジック等価が要件
- **composer 不使用** — レンタルサーバーで composer 実行不可ケース回避。配置のみで動作
- **`--as` デフォルト無効** — 共有環境なりすまし防止。`HFCM_CLI_ALLOW_AS=1` + 監査必須
- **排他ロックREST共通** — `hfcm_import_lock` 共有でCLI/REST相互排他
- **入力上限REST一致** — 圧縮5MB/展開10MB
- **rate limiter 非適用** — ローカル実行で攻撃面なし。等価性マトリクスに明記
- **`snippets:delete` を `--id`/`--ids` で分離** — 単一/一括の運用誤操作回避

## 検証手順

### 正常系
1. `tkmx-hfcm-pro-cli/` を wordpress 直下に設置
2. `php bin/hfcm snippets:list --format=table` で一覧出力
3. テストJSONで `snippets:create` → `snippets:get <id>` 一致
4. bulk-upsert 同一payload 2回連続 → 2回目 `unchanged` のみ（冪等）
5. `snippets:export --format=json --out=tmp.json` → API exportと snippets[] 同内容
6. `snippets:delete --id=<id>` → 直後の `snippets:get` で `not_found`

### 異常系・防御
7. 非adminユーザー（`HFCM_CLI_ALLOW_AS=1 --as=editor`）で manage系 → exit 4
8. `name` 欠落 payload で create → exit 1 + WP_Error整形JSON
9. **大容量**: 6 MB の gz 入力 → exit 1 + `payload_too_large`
10. **gzip bomb**: 展開後 > 10 MB 想定の細工 gz → 展開中断 + exit 1
11. **壊れたgz**: 末尾切れ gz → exit 1 + `invalid_gzip`
12. **`--as` 無効状態**: `HFCM_CLI_ALLOW_AS` 未設定で `--as=admin` → exit 64

### 並行・ロック
13. `bulk-upsert` 2プロセス同時起動 → 2本目 exit 75 (ロック取得失敗)
14. REST `bulk-upsert` 実行中にCLI `bulk-upsert` → CLI側 exit 75（相互排他）
15. CLI実行中に SIGTERM → ロック確実解放（finally 経路検証）

### 監査
16. 各種CLI実行後 `wp_hfcm_takumi_audit_logs` に `cli:<command>` レコードが追加されていること
17. `--as` 使用時は impersonation 情報がレコードに含まれること
18. REST + CLI を交互実行し、監査ログが時系列で揃うこと

### config セキュリティ
19. `config/cli.local.php` を 0644 にして実行 → Bootstrap が拒否（exit 4）
20. config所有者を別UIDにして実行 → 拒否
