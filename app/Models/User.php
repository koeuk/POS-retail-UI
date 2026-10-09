<?php

namespace App\Models;

use App\Enums\Action;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable, RecordsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'store_id',
        'vendor_id',
        'is_active',
    ];

    /**
     * Columns the audit trail records changes to.
     *
     * `password` and `remember_token` are deliberately absent — the log
     * records that an account changed, never the credential itself. Role and
     * permission edits ARE recorded: they are the escalation path, so they
     * are the entries an audit exists to catch.
     */
    protected array $auditable = [
        'name',
        'email',
        'role',
        'permissions',
        'store_id',
        'vendor_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Access-shaped edits belong in the access log, not the generic model
     * log — an admin auditing "who was given what" filters on one name and
     * gets every grant, revoke and role change without wading through name
     * and email edits.
     *
     * Spatie calls this after the change set is built, so `isDirty` still
     * reflects the edit being recorded.
     */
    public function getLogNameToUse(): ?string
    {
        return $this->isDirty(['role', 'permissions', 'is_active'])
            ? Activity::LOG_ACCESS
            : Activity::LOG_MODEL;
    }

    /**
     * Role default, unless this user carries an override for the key.
     * Admins always pass — the shop cannot be locked out of its own admin.
     */
    public function hasPermission(Permission $permission): bool
    {
        if ($this->role === Role::Admin) {
            return true;
        }

        $override = $this->permissions[$permission->value] ?? null;

        if ($override === null) {
            return $permission->defaultFor($this->role);
        }

        // A per-action map means the area is reachable — the actions inside
        // it decide the rest. Every action off is the same as no access.
        if (is_array($override)) {
            return in_array(true, array_map(fn ($v) => (bool) $v, $override), true);
        }

        return (bool) $override;
    }

    /**
     * May this user perform one particular action inside an area?
     *
     * Deliberately NOT named can() — that is Laravel's own Authorizable
     * method, which every $this->authorize() call and policy gate routes
     * through. Shadowing it with a different signature breaks all of them.
     *
     * The area gate comes first — someone who cannot open Products cannot
     * edit one either. Beyond that, the stored override for a key is read
     * one of two ways:
     *
     *   true / false  → the whole area, every action (the original shape,
     *                   still what a role default resolves to)
     *   {"view": true, "delete": false}
     *                 → per action; an action the map omits falls back to
     *                   the area's own answer, so a partial map is safe.
     */
    public function mayDo(Permission $permission, Action $action): bool
    {
        if ($this->role === Role::Admin) {
            return true;
        }

        if (! $this->hasPermission($permission)) {
            return false;
        }

        $override = $this->permissions[$permission->value] ?? null;

        if ($override === null) {
            return $permission->defaultActionFor($this->role, $action);
        }

        if (! is_array($override)) {
            return true; // plain grant: the whole area
        }

        return (bool) ($override[$action->value] ?? true);
    }

    /**
     * Every permission resolved for this user.
     *
     * Each key carries both the area answer and the per-action breakdown,
     * so the Staff dialog and `auth.can` render from one shape:
     * `['products' => ['allowed' => true, 'actions' => ['view' => true, …]]]`
     */
    public function effectivePermissions(): array
    {
        return collect(Permission::cases())
            ->mapWithKeys(fn (Permission $p) => [$p->value => [
                'allowed' => $this->hasPermission($p),
                'actions' => collect(Action::cases())
                    ->mapWithKeys(fn (Action $a) => [$a->value => $this->mayDo($p, $a)])
                    ->all(),
            ]])
            ->all();
    }

    /**
     * Just the actions, as `{products: {view: true, delete: false}}` —
     * what the frontend hides buttons with.
     */
    public function actionMatrix(): array
    {
        return collect(Permission::cases())
            ->mapWithKeys(fn (Permission $p) => [$p->value => collect(Action::cases())
                ->mapWithKeys(fn (Action $a) => [$a->value => $this->mayDo($p, $a)])
                ->all()])
            ->all();
    }

    /** Just the area flags, as {key: bool} — what the nav renders from. */
    public function permissionFlags(): array
    {
        return collect(Permission::cases())
            ->mapWithKeys(fn (Permission $p) => [$p->value => $this->hasPermission($p)])
            ->all();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The supplier a `vendor`-role account belongs to. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'cashier_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
