<?php

declare(strict_types=1);

namespace Tkmx\HfcmCli\Support;

use Tkmx\HfcmCli\Console\ExitCode;

class WpErrorFormatter
{
    /**
     * Convert WP_Error to a plain array.
     * @param \WP_Error $error
     * @return array{code: string, message: string, data: mixed}
     */
    public static function toArray(\WP_Error $error): array
    {
        return [
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'data'    => $error->get_error_data(),
        ];
    }

    /**
     * Map WP_Error code to CLI exit code.
     * @param \WP_Error $error
     */
    public static function toExitCode(\WP_Error $error): int
    {
        $code = $error->get_error_code();

        // Permission/auth errors.
        if (in_array($code, ['rest_forbidden', 'rest_not_logged_in', 'rest_cannot_edit'], true)) {
            return ExitCode::FORBIDDEN;
        }

        // Validation / not-found / payload size.
        if (
            str_starts_with($code, 'invalid_') ||
            $code === 'not_found' ||
            $code === 'payload_too_large' ||
            str_starts_with($code, 'bulk_') ||
            $code === 'import_too_large' ||
            $code === 'missing_'
        ) {
            return ExitCode::ERROR;
        }

        // Internal / DB errors.
        if (str_starts_with($code, 'db_') || $code === 'internal_error') {
            return ExitCode::INTERNAL;
        }

        // HTTP status-based fallback.
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $status = (int) $data['status'];
            if ($status === 401 || $status === 403) {
                return ExitCode::FORBIDDEN;
            }
            if ($status >= 400 && $status < 500) {
                return ExitCode::ERROR;
            }
            if ($status >= 500) {
                return ExitCode::INTERNAL;
            }
        }

        return ExitCode::ERROR;
    }
}
