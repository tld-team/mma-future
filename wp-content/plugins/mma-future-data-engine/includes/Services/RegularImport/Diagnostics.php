<?php
namespace MMAF\DataEngine\Services\RegularImport;

/** Stores a bounded, safe description of failures outside the rolled-back batch. */
final class Diagnostics {
    private const MAX_BYTES = 65536;
    private const MAX_HISTORY = 20;

    public static function record(array $job, \Throwable $error, int $attempt): array {
        $context = $error instanceof ImportException ? $error->context : array();
        $id = 'diag-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $entry = array_filter(array(
            'diagnostic_id' => $id,
            'at' => gmdate('c'),
            'job_id' => (int) $job['id'],
            'phase' => (string) $job['phase'],
            'attempt' => $attempt,
            'reason' => $error instanceof ImportException ? $error->reason : self::reason($error),
            'message' => self::message($error->getMessage()),
            'batch' => self::batch($context),
            'record' => self::recordContext($context),
            'next_step' => self::nextStep($error),
        ), static fn($value) => $value !== null && $value !== array());
        $json = wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) {
            $entry['record'] = array('note' => 'Failure context exceeded the diagnostic size limit.');
            $entry['message'] = self::message((string) ($entry['message'] ?? 'Import failed.'));
        }
        $path = Storage::job((int) $job['id']) . '/diagnostics';
        if (!is_dir($path) && !wp_mkdir_p($path)) { throw new \RuntimeException('Could not create private import diagnostics.'); }
        chmod($path, 0700);
        Storage::write($path . '/' . $id . '.json', $entry);
        $entry['file'] = 'diagnostics/' . $id . '.json';
        return $entry;
    }

    public static function history(int $job): array {
        $path = Storage::job($job) . '/diagnostics';
        if (!is_dir($path)) { return array(); }
        $files = glob($path . '/diag-*.json') ?: array(); rsort($files, SORT_STRING);
        $out = array();
        foreach (array_slice($files, 0, self::MAX_HISTORY) as $file) {
            try { $entry=Storage::read($file);$entry['file']='diagnostics/'.basename($file);$out[]=$entry; } catch (\Throwable $ignored) { $out[] = array('file' => 'diagnostics/'.basename($file), 'message' => 'Diagnostic file is unreadable.'); }
        }
        return $out;
    }

    public static function retryable(\Throwable $error): bool {
        if ($error instanceof ImportException) { return $error->retryable; }
        $message = strtolower($error->getMessage());
        foreach (array('deadlock', 'lock wait timeout', 'server has gone away', 'lost connection', 'try restarting transaction', 'connection refused') as $needle) {
            if (str_contains($message, $needle)) { return true; }
        }
        return false;
    }

    public static function reason(\Throwable $error): string {
        return self::retryable($error) ? 'transient_database' : 'execution_error';
    }

    private static function batch(array $context): ?array {
        $batch = $context['batch'] ?? null;
        if (!is_array($batch)) { return null; }
        return array_intersect_key($batch, array_flip(array('id', 'kind', 'batch_index', 'payload_hash', 'row_count')));
    }

    private static function recordContext(array $context): ?array {
        $record = $context['record'] ?? null;
        if (!is_array($record)) { return null; }
        return array_intersect_key($record, array_flip(array('item_type', 'source_id', 'action', 'error_message', 'identity_hash')));
    }

    private static function message(string $message): string {
        $message = preg_replace('/\s+/', ' ', $message) ?: 'Import failed.';
        return substr($message, 0, 1000);
    }

    private static function nextStep(\Throwable $error): string {
        return self::retryable($error) ? 'The worker will retry this transient failure automatically.' : 'Review the diagnostic before resuming this import.';
    }
}
