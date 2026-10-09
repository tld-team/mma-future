<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class ImportException extends \RuntimeException {
    public string $reason;
    public bool $retryable;
    public function __construct(string $message, string $reason = 'invalid_package', bool $retryable = false) {
        parent::__construct($message); $this->reason = $reason; $this->retryable = $retryable;
    }
}
