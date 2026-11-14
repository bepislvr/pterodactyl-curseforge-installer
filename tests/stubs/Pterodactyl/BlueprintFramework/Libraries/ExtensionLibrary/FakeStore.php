<?php

namespace Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary;

/** Test stub: in-memory stand-in for Blueprint's database, shared by the admin and client libraries. */
trait FakeStore
{
    public function dbGet(string $table, string $record, mixed $default = null): mixed
    {
        return FakeDb::$data["$table::$record"] ?? $default;
    }

    public function dbSet(string $table, string $record, mixed $value): void
    {
        FakeDb::$data["$table::$record"] = $value;
    }

    public function dbForget(string $table, string $record): bool
    {
        $existed = array_key_exists("$table::$record", FakeDb::$data);
        unset(FakeDb::$data["$table::$record"]);

        return $existed;
    }
}
