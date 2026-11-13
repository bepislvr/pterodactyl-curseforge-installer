<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpackinstaller;

/**
 * Outcome of CurseForgeClient::checkIds(). `status` says what happened; `message` says what CurseForge returned.
 * Only STATUS_OK means the IDs were confirmed. AUTH, UNREACHABLE, HTTP_ERROR and NO_KEY mean they could not be
 * checked; MISMATCH and UNEXPECTED mean CurseForge answered with something other than what the code expects.
 */
final class IdCheckResult
{
    public const OK = 'ok';
    public const MISMATCH = 'mismatch';
    public const UNEXPECTED = 'unexpected';
    public const AUTH = 'auth';
    public const UNREACHABLE = 'unreachable';
    public const HTTP_ERROR = 'http_error';
    public const NO_KEY = 'no_key';

    private function __construct(public readonly string $status, public readonly string $message)
    {
    }

    public static function ok(string $message): self
    {
        return new self(self::OK, $message);
    }

    public static function mismatch(string $message): self
    {
        return new self(self::MISMATCH, $message);
    }

    public static function unexpected(string $message): self
    {
        return new self(self::UNEXPECTED, $message);
    }

    public static function failed(string $status, string $message): self
    {
        return new self($status, $message);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /** True when CurseForge answered and the IDs were checked (confirmed or not). */
    public function wasChecked(): bool
    {
        return in_array($this->status, [self::OK, self::MISMATCH, self::UNEXPECTED], true);
    }

    /** @return array{status:string,message:string,ok:bool} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'message' => $this->message, 'ok' => $this->isOk()];
    }
}
