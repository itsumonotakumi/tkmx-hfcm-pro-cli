# TKMX HFCM Pro CLI

[TKMX HFCM Pro API](https://github.com/your-org/tkmx-hfcm-pro-api) WordPressプラグイン用のコマンドラインインターフェース。  
bulk-upsert・import・export等の大量操作をサーバー上で直実行 — HTTPラウンドトリップなし、レート制限なし。

## 動作要件

- PHP 8.3+
- TKMX HFCM Pro API プラグイン v1.6.x が有効化済みのWordPress
- サーバーへのSSHアクセス
- `posix` PHP拡張モジュール（設定ファイル所有者検証用）

## インストール

```bash
# WordPressルートディレクトリ内に配置
cd /path/to/wordpress
git clone https://github.com/your-org/tkmx-hfcm-pro-cli.git

# 実行可能にする
chmod +x tkmx-hfcm-pro-cli/bin/hfcm

# サンプル設定をコピーして編集
cp tkmx-hfcm-pro-cli/config/cli.sample.php tkmx-hfcm-pro-cli/config/cli.local.php
chmod 0600 tkmx-hfcm-pro-cli/config/cli.local.php
# cli.local.php を編集して HFCM_CLI_DEFAULT_USER に管理者のログイン名を設定
```

## 設定

`config/cli.local.php` を編集：

```php
define('HFCM_CLI_DEFAULT_USER', 'admin');  // manage_options 権限を持つ WP ユーザー
```

または環境変数で設定：

```bash
export HFCM_CLI_DEFAULT_USER=admin
```

**セキュリティ**: `config/cli.local.php` は実行ユーザーに所有され、パーミッションが `0600` である必要があります。  
異なる権限の場合は exit 4 (FORBIDDEN) で終了します。

## 使用方法

```bash
cd /path/to/wordpress
./tkmx-hfcm-pro-cli/bin/hfcm <command> [options]
```

### コマンド一覧

#### スニペット一覧表示

```bash
./bin/hfcm snippets:list
./bin/hfcm snippets:list --format=table
./bin/hfcm snippets:list --page=2 --per_page=50 --orderby=name --order=ASC
./bin/hfcm snippets:list --status=active --search=header
```

#### スニペットを取得

```bash
./bin/hfcm snippets:get 42
./bin/hfcm snippets:get --id=42
```

#### スニペットを作成

```bash
./bin/hfcm snippets:create --file=snippet.json
./bin/hfcm snippets:create --data='{"name":"Header GA","snippet":"<script>...</script>","location":"header","status":"active"}'
cat snippet.json | ./bin/hfcm snippets:create --file=-
```

#### スニペットを更新（PUT — 完全置換）

```bash
./bin/hfcm snippets:update 42 --file=snippet.json
```

#### スニペットを部分更新（PATCH — 差分更新）

```bash
./bin/hfcm snippets:update 42 --mode=patch --data='{"status":"inactive"}'
```

#### スニペットを削除

```bash
# 単一削除
./bin/hfcm snippets:delete --id=42

# 一括削除（最大100件）
./bin/hfcm snippets:delete --ids=1,2,3,42
```

#### 一括 upsert

```bash
./bin/hfcm snippets:bulk-upsert --file=payload.json
./bin/hfcm snippets:bulk-upsert --file=payload.json.gz   # gzip は自動判定
cat payload.json | ./bin/hfcm snippets:bulk-upsert --file=-
```

#### インポート

```bash
./bin/hfcm snippets:import --file=export.json
./bin/hfcm snippets:import --file=export.json.gz
```

#### エクスポート

```bash
# JSON を標準出力に
./bin/hfcm snippets:export

# CSV をファイルに
./bin/hfcm snippets:export --format=csv --out=backup.csv

# JSON をファイルに
./bin/hfcm snippets:export --format=json --out=backup.json
```

### 共通オプション

| オプション | 説明 |
|--------|------|
| `--format=json\|table\|csv` | 出力形式（デフォルト: `json`） |
| `--pretty` | JSON 出力を見やすく整形 |
| `--quiet` | STDERR メッセージを抑制 |
| `--file=<path>` | ファイルからペイロードを読み込み（`.gz` は自動判定） |
| `--file=-` | STDIN からペイロードを読み込み |
| `--data=<json>` | JSON ペイロードをインラインで指定 |
| `--out=<path>` | 出力をファイルに保存（エクスポート時のみ） |
| `--as=<user_login>` | 特定の WP ユーザーとして実行（`HFCM_CLI_ALLOW_AS=1` が必要） |
| `--help` | ヘルプを表示 |

### ユーザー成り済まし

```bash
# ユーザー成り済ましを有効化（明示的オプトイン、全使用は監査ログに記録）
HFCM_CLI_ALLOW_AS=1 ./bin/hfcm snippets:list --as=editor

# 非管理者ユーザーは書き込みコマンドで拒否される（exit 4）
HFCM_CLI_ALLOW_AS=1 ./bin/hfcm snippets:create --as=subscriber --data='...'
# → exit 4: Insufficient permissions
```

### ペイロードサイズ上限（REST API と同じ）

| タイプ | 上限 |
|------|------|
| 圧縮入力（`.gz`） | 5 MB |
| 非圧縮 / 展開後 | 10 MB |

上限を超過 → exit 1 + `payload_too_large` エラー。

## Exit コード

| コード | 意味 |
|------|------|
| 0 | 成功 |
| 1 | 検証エラー / 未検出 / ペイロード超過 |
| 2 | wp-load.php が見つからない |
| 3 | TKMX HFCM Pro API プラグインが非活性 |
| 4 | 権限拒否 / 認証失敗 |
| 64 | 使用方法エラー（無効な引数） |
| 70 | 内部エラー |
| 75 | 一時的な障害（別の import/upsert 実行中） |

## CLI と REST API の相互排他性

`bulk-upsert` と `import` は `hfcm_import_lock` transient（TTL 5分）を取得します。これは REST API でも使用される同じキーです。CLI と REST は相互に排他的で、一方が実行中なら他方は exit 75 で終了します。

### 永続オブジェクトキャッシュ環境での注意（Redis Object Cache など）

永続オブジェクトキャッシュ（Redis・Memcached等）が有効な場合、REST層で呼び出される `set_transient()` がキャッシュのみに書き込まれ、**`wp_options` テーブルに書き込まれない** 可能性があります。一方、CLI では `add_option()` を使用するため常にデータベースに直接書き込みます。キャッシュが DB へのフォールバックを行わない場合、REST層は `get_transient()` 経由でCLIロックを見つけることができません。

**推奨対応**: Redis Object Cache 等の環境では、REST層（TKMX-HFCM-Pro-API）も `add_option` ベースのアトミックロックを使用するよう設定してください（別途PRで対応予定）。その前まで、永続キャッシュ環境では CLI と REST のbulk操作の並行実行を避けてください。

## 監査ログ

全 CLI 呼び出しは `wp_hfcm_takumi_audit_logs` に以下の情報とともに記録されます：
- `endpoint`: `cli:<command>`
- `user_login`: 実行中の WP ユーザー
- `--as` 使用時の成り済まし詳細

## テスト実行

PHPUnit 10+ を別途インストール（CLI 運用には composer 不要）：

```bash
# PHPUnit をグローバルまたはローカルにインストール
composer global require phpunit/phpunit:^10

# テストを実行（WordPress 不要 — WP_Error はスタブ化）
phpunit --configuration phpunit.xml

# またはローカルの phpunit phar を使用
php phpunit.phar --configuration phpunit.xml
```

テスト対象: `Args`、`Output`、`ExitCode`、`WpErrorFormatter`、`PayloadLoader`（gzip・サイズ上限を含む）。

## トラブルシューティング

**`Error: wp-load.php not found`**  
CLI は WordPress ルートディレクトリ内またはその近傍に配置される必要があります。最大3階層親方向を検索します。

**`Error: TKMX HFCM Pro API plugin is not active`**  
WordPress 管理画面でプラグインを有効化するか、WP-CLI で実行: `wp plugin activate hfcm-pro-takumi-api`。

**`Error: config/cli.local.php must have mode 0600`**  
以下を実行: `chmod 0600 config/cli.local.php`

**`Error: config/cli.local.php must be owned by the current user`**  
ファイルが CLI 実行ユーザーに所有されていることを確認: `chown $(whoami) config/cli.local.php`

**Exit 75（ロック取得中）**  
別の `bulk-upsert` または `import` が実行中（CLI または REST 経由）です。少し待ってからリトライしてください。

**リリース直後のタイミング注意**  
WP プラグインは毎回ロード時に `check_and_upgrade()` を実行します。プラグインアップデート直後、最初の CLI 呼び出しが遅くなる可能性があります。プラグインアップグレード後の CLI 実行はメンテナンスタイム帯にスケジュールしてください。
