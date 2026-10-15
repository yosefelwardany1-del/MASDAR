<?php

namespace App\Http\Controllers;

use App\Security\RoleManagementGuard;
use App\Security\Roles;
use App\Security\Screens;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The role matrix: the ten fixed roles (spec §10.2 — never a composite role per
 * combination, so roles are not created or deleted here) × the screens, each at none /
 * view / edit / approve. Levels are cumulative; RoleManagementGuard decides who may change
 * what.
 */
class RoleController extends Controller
{
    public function __construct(private RoleManagementGuard $guard) {}

    public function index(Request $request)
    {
        $roles = Role::withCount('users')->get()->sortBy(fn (Role $r) => array_search($r->name, Roles::ALL, true))->values();

        return view('roles.index', [
            'roles' => $roles,
            'locked' => RoleManagementGuard::SYSTEM_ROLES,
            'canEdit' => fn (Role $role) => $request->user()->can('roles.edit') && $this->guard->canEditRole($request->user(), $role),
        ]);
    }

    public function show(Request $request, Role $role)
    {
        return view('roles.show', [
            'role' => $role,
            'screens' => $this->matrixScreens(),
            'levels' => $this->levelsOf($role),
            'canEdit' => $request->user()->can('roles.edit') && $this->guard->canEditRole($request->user(), $role),
        ]);
    }

    public function edit(Request $request, Role $role)
    {
        abort_unless($this->guard->canEditRole($request->user(), $role), 403);

        return view('roles.edit', ['role' => $role, 'screens' => $this->matrixScreens(), 'levels' => $this->levelsOf($role)]);
    }

    public function update(Request $request, Role $role)
    {
        abort_unless($this->guard->canEditRole($request->user(), $role), 403);

        $submitted = (array) $request->input('levels', []);
        $screens = $this->matrixScreens();
        $errors = [];
        $desired = [];
        foreach ($submitted as $screen => $level) {
            if (! isset($screens[$screen]) || ! in_array($level, array_merge(['none'], $screens[$screen]['levels']), true)) {
                $errors["levels.{$screen}"] = __('That level does not exist for this screen.');

                continue;
            }
            $desired[$screen] = $level === 'none' ? [] : Screens::grantsFor($screen, $level);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        abort_unless($this->guard->canChangeGrants($request->user(), $role, $desired), 403, __('You may not grant or remove permissions you do not hold.'));

        $keep = $role->permissions->pluck('name')
            ->reject(fn (string $p) => array_key_exists(strstr($p, '.', true), $desired))
            ->all();
        $role->syncPermissions(array_merge($keep, ...array_values($desired)));

        return redirect()->route('roles.show', $role)->with('success', __('Role updated.'));
    }

    /** Screens that carry permissions (sign-in-only screens are not in the matrix). */
    private function matrixScreens(): array
    {
        return array_filter(Screens::all(), fn (array $screen) => $screen['levels'] !== []);
    }

    /** @return array<string, string> screen => none|view|edit|approve */
    private function levelsOf(Role $role): array
    {
        $held = $role->permissions->pluck('name')->all();
        $levels = [];
        foreach ($this->matrixScreens() as $key => $screen) {
            $levels[$key] = 'none';
            foreach (Screens::LEVELS as $level) {
                if (in_array("{$key}.{$level}", $held, true)) {
                    $levels[$key] = $level;
                }
            }
        }

        return $levels;
    }
}
