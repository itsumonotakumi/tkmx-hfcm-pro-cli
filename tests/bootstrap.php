<?php

declare(strict_types=1);

// Autoloader for CLI source files.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Tkmx\\HfcmCli\\')) {
        return;
    }
    $relative = substr($class, strlen('Tkmx\\HfcmCli\\'));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Minimal WP stubs for unit tests (no WordPress loaded).
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

// ---------------------------------------------------------------------------
// WP transient / option stubs for ExecutionLock tests.
// The in-memory store is reset via TransientStore::reset() in setUp().
// ---------------------------------------------------------------------------
if (!class_exists('TransientStore')) {
    class TransientStore
    {
        public static array $transients = [];
        public static array $options    = [];

        public static function reset(): void
        {
            self::$transients = [];
            self::$options    = [];
        }
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return TransientStore::$transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        if (!($GLOBALS['_hfcm_set_transient_return'] ?? true)) {
            return false;
        }
        TransientStore::$transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset(TransientStore::$transients[$key]);
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $key, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
    {
        if (array_key_exists($key, TransientStore::$options)) {
            return false; // Already exists — atomic insert fails.
        }
        TransientStore::$options[$key] = $value;
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return TransientStore::$options[$key] ?? $default;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset(TransientStore::$options[$key]);
        return true;
    }
}

// ---------------------------------------------------------------------------
// WP capability stubs for AbstractCommand tests.
// ---------------------------------------------------------------------------
if (!isset($GLOBALS['_hfcm_current_user_can'])) {
    $GLOBALS['_hfcm_current_user_can'] = true;
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return (bool) ($GLOBALS['_hfcm_current_user_can'] ?? true);
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return (object) [
            'user_login' => $GLOBALS['_hfcm_wp_user_login'] ?? 'testuser',
        ];
    }
}

if (!function_exists('get_current_user')) {
    function get_current_user(): string
    {
        return $GLOBALS['_hfcm_unix_user'] ?? 'cli-user';
    }
}

// ---------------------------------------------------------------------------
// HFCM_Takumi_API_Audit_Logger stub for CliAudit tests.
// Recorded calls available via AuditLoggerStub::$calls; reset via ::reset().
// ---------------------------------------------------------------------------
if (!class_exists('AuditLoggerStub')) {
    class AuditLoggerStub
    {
        /** @var list<array{action: string, status: string, payload: string}> */
        public static array $calls = [];

        public static function reset(): void
        {
            self::$calls = [];
        }
    }
}

if (!class_exists('HFCM_Takumi_API_Audit_Logger')) {
    class HFCM_Takumi_API_Audit_Logger
    {
        public static function log(string $action, string $status, string $payload): void
        {
            AuditLoggerStub::$calls[] = [
                'action'  => $action,
                'status'  => $status,
                'payload' => $payload,
            ];
        }
    }
}

// set_transient stub control: set to false to simulate failure.
if (!isset($GLOBALS['_hfcm_set_transient_return'])) {
    $GLOBALS['_hfcm_set_transient_return'] = true;
}
