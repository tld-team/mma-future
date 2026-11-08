<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class ImportException extends \RuntimeException {
    public string $reason;
    public bool $retryable;
    public array $context;
    public function __construct(string $message, string $reason = 'invalid_package', bool $retryable = false, array $context = array()) {
        parent::__construct($message); $this->reason = $reason; $this->retryable = $retryable; $this->context = $context;
    }
}
