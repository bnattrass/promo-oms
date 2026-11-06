<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'app:user';

    protected $description = 'Create a user and give them access to a company';

    public function handle(): int
    {
        $company = Company::find(select('Company', Company::pluck('name', 'id')->all()));
        $email = text('Email', required: true, validate: ['email' => 'email']);
        $user = User::firstOrNew(['email' => $email]);
        $user->name = text('Name', default: $user->name ?? '', required: true);
        if (! $user->exists || $this->confirm('Change password?', false)) {
            $user->password = password('Password', required: true, validate: ['password' => 'min:8']);
        }
        $user->commission_percent = (float) text('Commission % of GP', default: (string) ($user->commission_percent ?? 0));
        $user->save();

        $role = select('Role', ['admin' => 'Admin', 'sales' => 'Sales', 'art' => 'Art department', 'production' => 'Production', 'accounts' => 'Accounts'], default: 'sales');
        $user->companies()->syncWithoutDetaching([$company->id => ['role' => $role]]);

        $this->info("{$user->email} can now log in to {$company->name}.");

        return self::SUCCESS;
    }
}
