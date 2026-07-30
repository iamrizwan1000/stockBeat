<?php

namespace App\Actions\Admin;

/**
 * Tails `storage/logs/laravel.log` for the admin Logs page — lets an admin
 * grab a recent error to paste elsewhere without SSH/DB access. Reads at
 * most `$maxBytes` from the end of the file via `fseek`, never the whole
 * file, so this stays fast and low-memory even once the log has grown to
 * gigabytes (the app uses the `single` log driver — one ever-growing file,
 * no daily rotation, per `config/logging.php`).
 *
 * Returns the raw tail as one string rather than splitting into discrete
 * "entries" — Laravel's default formatter spreads an exception's stack
 * trace across many lines under one log entry, and naively treating each
 * line as its own entry would shred those apart.
 */
class ReadRecentLogAction
{
    /**
     * @return array{content: string, path: string, size_bytes: int, truncated: bool, exists: bool}
     */
    public function handle(int $maxBytes = 200_000): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return ['content' => '', 'path' => $path, 'size_bytes' => 0, 'truncated' => false, 'exists' => false];
        }

        $size = filesize($path) ?: 0;
        $readBytes = min($size, $maxBytes);

        $handle = fopen($path, 'r');
        fseek($handle, -$readBytes, SEEK_END);
        $content = fread($handle, $readBytes) ?: '';
        fclose($handle);

        $truncated = $readBytes < $size;

        if ($truncated) {
            // We started reading mid-line — drop the partial first line so
            // the visible content always starts at a real log entry boundary.
            $firstNewline = strpos($content, "\n");
            $content = $firstNewline === false ? '' : substr($content, $firstNewline + 1);
        }

        return [
            'content' => $content,
            'path' => $path,
            'size_bytes' => $size,
            'truncated' => $truncated,
            'exists' => true,
        ];
    }
}
