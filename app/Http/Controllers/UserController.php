<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\RoleManagementGuard;
use App\Security\Roles;
use App\Services\Auth\SsoSignIn;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * The Users screen: other people's accounts. Users are never deleted — every row in the
 * system is attributed to one (spec I4) — they are deactivated. Every role assignment goes
 * through RoleManagementGuard.
 */
class UserController extends Controller
{
    public function __construct(private RoleManagementGuard $guard) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = in_array($request->query('status'), ['active', 'inactive', 'pending'], true) ? $request->query('status') : null;

        $users = User::with('roles')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $pendingCount = $request->user()->can('users.edit') ? User::where('status', 'pending')->count() : 0;

        return view('users.index', compact('users', 'search', 'status', 'pendingCount'));
    }

    public function create(Request $request)
    {
        return view('users.create', ['roles' => $this->guard->assignableRoleNames($request->user())]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8|confirmed',
            'status' => 'required|in:active,inactive',
            'roles' => 'array',
            'roles.*' => ['string', Rule::in(Roles::ALL)],
        ]);
        $roles = array_values(array_unique($data['roles'] ?? []));
        foreach ($roles as $role) {
            abort_unless($this->guard->canAssignRole($request->user(), $role), 403, __('You may not assign this role.'));
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
        ]);
        $user->syncRoles($roles);

        return redirect()->route('users.index')->with('success', __('User created.'));
    }

    public function show(User $user)
    {
        return view('users.show', ['user' => $user->load('roles')]);
    }

    public function edit(Request $request, User $user)
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403);

        return view('users.edit', [
            'user' => $user->load('roles'),
            'roles' => $this->guard->assignableRoleNames($request->user(), $user),
            'ssoIdentities' => $user->ssoIdentities()->get(),
            'ssoEnabled' => (bool) config('sso.enabled'),
            'ssoDomains' => app(SsoSignIn::class)->allowedDomains(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
            'password' => 'nullable|string|min:8|confirmed',
            // 'pending' only for an account that already is: an active account is never
            // pushed back into a state that only ever meant "just arrived".
            'status' => ['required', $user->isPending() ? 'in:active,inactive,pending' : 'in:active,inactive'],
            'roles' => 'array',
            'roles.*' => ['string', Rule::in(Roles::ALL)],
        ]);
        $roles = array_values(array_unique($data['roles'] ?? []));
        foreach ($roles as $role) {
            abort_unless($this->guard->canAssignRole($request->user(), $role, $user), 403, __('You may not assign this role.'));
        }
        $this->guard->assertKeepsAdmins($user, $roles, $data['status']);

        $approving = $user->isPending() && $data['status'] === 'active';

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
        ]);
        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }
        if ($approving) {
            $user->forceFill(['approved_at' => now(), 'approved_by' => $request->user()->id])->save();
        }
        $user->syncRoles($roles);

        if ($approving) {
            app(NotificationService::class)->send(
                $user,
                __('Your account is ready'),
                __(':name approved your account. You can sign in now.', ['name' => $request->user()->name]),
                ['type' => 'success', 'category' => NotificationType::USER_APPROVED, 'icon' => 'person-check'],
            );

            return redirect()->route('users.index')->with('success', __(':name approved.', ['name' => $user->name]));
        }

        return redirect()->route('users.index')->with('success', __('User updated.'));
    }
}
