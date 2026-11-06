<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'is_super_admin', 'commission_percent', 'is_active', 'monthly_target'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'commission_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'monthly_target' => 'decimal:2',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role')->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && ($this->is_super_admin || $this->companies()->exists());
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->is_super_admin ? Company::all() : $this->companies;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_super_admin || $this->companies()->whereKey($tenant)->exists();
    }

    public function isAdminOf(?Company $company): bool
    {
        return $this->is_super_admin || ($company && $this->roleIn($company) === 'admin');
    }

    public function roleIn(Company $company): ?string
    {
        return $this->companies()->whereKey($company)->first()?->pivot?->role;
    }
}
