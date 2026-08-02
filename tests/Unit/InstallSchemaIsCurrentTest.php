<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The phpMyAdmin import file, held to the migrations it claims to be.
 *
 * The deployment guide tells people to import database/schema/opes360-install.sql
 * into an empty database. When that file was maintained by hand it fell behind
 * the migrations, and the only symptom was a missing column on somebody's first
 * day — with nothing to suggest the import was the cause.
 *
 * This does not prove the SQL is correct; `php artisan opes:export-schema`
 * generates it from the real migrations against a real MySQL, which does. What
 * this catches is the file not having been regenerated after a schema change,
 * which is the failure that actually happens.
 */
class InstallSchemaIsCurrentTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../database/schema/opes360-install.sql';

    private const MIGRATIONS = __DIR__.'/../../database/migrations';

    public function test_the_install_schema_exists(): void
    {
        $this->assertFileExists(
            self::SCHEMA,
            'The deployment guide points at this file. Run: php artisan opes:export-schema'
        );
    }

    public function test_every_migration_is_recorded_as_already_applied(): void
    {
        $sql = file_get_contents(self::SCHEMA);

        foreach (glob(self::MIGRATIONS.'/*.php') as $migration) {
            $name = basename($migration, '.php');

            $this->assertStringContainsString($name, $sql, sprintf(
                "Migration [%s] is missing from the install schema, so a fresh import would\n".
                'run it against tables that do not exist. Run: php artisan opes:export-schema',
                $name
            ));
        }
    }

    /**
     * Every table a migration creates has to be in the dump. Catches the case
     * where the migrations row was written but the DDL was not — a dump taken
     * against a database that had been migrated but then partly dropped.
     */
    public function test_the_tables_the_migrations_create_are_all_present(): void
    {
        $sql = file_get_contents(self::SCHEMA);
        $declared = [];

        foreach (glob(self::MIGRATIONS.'/*.php') as $migration) {
            preg_match_all("/Schema::create\('([a-z_0-9]+)'/", file_get_contents($migration), $matches);
            $declared = [...$declared, ...$matches[1]];
        }

        $this->assertNotEmpty($declared, 'No Schema::create calls found — the parser is looking in the wrong place.');

        foreach (array_unique($declared) as $table) {
            $this->assertStringContainsString("CREATE TABLE `{$table}`", $sql, sprintf(
                'Table [%s] is missing from the install schema. Run: php artisan opes:export-schema',
                $table
            ));
        }
    }

    /**
     * Registration assigns the owner role by looking it up, so a database with
     * no roles gives every business that signs up an account that can do
     * nothing. That is why the reference data ships inside the import.
     */
    public function test_the_roles_and_permissions_ship_with_it(): void
    {
        $sql = file_get_contents(self::SCHEMA);

        $this->assertStringContainsString('INSERT INTO `roles`', $sql);
        $this->assertStringContainsString('INSERT INTO `permissions`', $sql);
        $this->assertStringContainsString('owner', $sql);
    }

    /** No businesses, no users, no demo data — install.php creates the admin. */
    public function test_it_carries_no_tenant_data(): void
    {
        $sql = file_get_contents(self::SCHEMA);

        foreach (['INSERT INTO `companies`', 'INSERT INTO `users`', 'INSERT INTO `documents`'] as $leak) {
            $this->assertStringNotContainsString($leak, $sql, "The install schema is carrying real data ({$leak}).");
        }
    }
}
