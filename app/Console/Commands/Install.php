<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * First-run setup for a production install.
 *
 * `migrate` builds the schema but leaves the reference data empty, and roles are
 * not optional: registration assigns the owner role by slug, so on an unseeded
 * database every new business gets a null role and an account that can do
 * nothing. `db:seed` is not the answer either — the default seeder also creates
 * the demo company and a platform admin whose password is literally "password".
 *
 * This command is the production path: reference data, then one real
 * administrator with a password you choose. It is safe to run twice.
 */
class Install extends Command
{
    protected $signature = 'opes:install
        {--admin-email= : Email for the first platform administrator}
        {--admin-name= : Display name for that administrator}
        {--admin-password= : Their password; omit to be prompted (safer — it stays out of shell history)}
        {--skip-admin : Only seed reference data}';

    protected $description = 'Prepare a fresh production database: reference data and the first administrator';

    public function handle(): int
    {
        $this->components->info('Preparing this install.');

        // Roles and permissions are reference data every company depends on.
        // The seeder is idempotent, so re-running costs nothing.
        $this->components->task('Seeding roles and permissions', function () {
            $this->callSilent('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

            return Role::where('slug', Role::OWNER)->exists();
        });

        if (Role::where('slug', Role::OWNER)->doesntExist()) {
            $this->components->error('The owner role is still missing — registration would break. Not continuing.');

            return self::FAILURE;
        }

        if ($this->option('skip-admin')) {
            $this->newLine();
            $this->components->info('Reference data is in place. No administrator was created.');

            return self::SUCCESS;
        }

        return $this->createAdmin();
    }

    protected function createAdmin(): int
    {
        $email = $this->option('admin-email') ?: $this->ask('Administrator email');
        $name = $this->option('admin-name') ?: $this->ask('Administrator name', 'Administrator');

        // Prompting is the default because a password passed as an option is
        // recorded in shell history and in the process list.
        $password = $this->option('admin-password') ?: $this->secret('Administrator password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'name' => ['required', 'string', 'max:255'],
                // An admin can suspend companies and change plans; a weak
                // password here is the whole platform's weak password.
                'password' => ['required', Password::min(12)->letters()->numbers()->symbols()->uncompromised()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $existing = PlatformAdmin::where('email', $email)->exists();

        if ($existing && ! $this->confirm("An administrator with {$email} already exists. Reset their password?", false)) {
            $this->components->warn('Left unchanged.');

            return self::SUCCESS;
        }

        // forceFill because email_verified_at is deliberately not fillable, and
        // `db:seed` only gets away with it by unguarding every model — which a
        // command run against live data has no business doing.
        PlatformAdmin::firstOrNew(['email' => $email])->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'role' => PlatformAdmin::ROLE_ADMIN,
        ])->save();

        $this->newLine();
        $this->components->info($existing ? "Password reset for {$email}." : "Administrator {$email} created.");
        $this->components->warn('Sign in at /admin/login and enrol two-factor authentication straight away.');

        return self::SUCCESS;
    }
}
