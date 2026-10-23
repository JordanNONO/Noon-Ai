<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Membres, groupes et réglages de la communauté (administrateurs). */
class AdminController extends Controller
{
    public function members(Request $request)
    {
        $q = User::with('group')->orderBy('name');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }

        return [
            'members' => $q->get()->map->toPublic()->values(),
            'roles' => collect(User::ROLES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'groups' => Group::orderBy('name')->get()->map->toPublic()->values(),
        ];
    }

    /** Invitation : le compte est créé avec un code provisoire à transmettre à la personne. */
    public function invite(Request $request)
    {
        $request->merge(['phone' => AuthController::normalizePhone($request->input('phone')), 'email' => $request->input('email') ? mb_strtolower($request->input('email')) : null]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|required_without:email|string|max:30|unique:users,phone',
            'email' => 'nullable|required_without:phone|email|max:190|unique:users,email',
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'group_id' => 'nullable|integer|exists:groups,id',
            'language' => 'nullable|string|exists:languages,code',
        ]);
        $code = (string) random_int(100000, 999999);
        $user = User::create($data + ['password' => $code, 'language' => $data['language'] ?? 'fr']);

        return ['member' => $user->load('group')->toPublic(), 'temporary_code' => $code];
    }

    public function updateMember(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(array_keys(User::ROLES))],
            'group_id' => 'sometimes|nullable|integer|exists:groups,id',
            'is_active' => 'sometimes|boolean',
        ]);
        // Le premier administrateur garde toujours tous ses droits
        if ($user->id === User::PROTECTED_ADMIN_ID && ((isset($data['role']) && $data['role'] !== 'admin') || (isset($data['is_active']) && ! $data['is_active']))) {
            throw ValidationException::withMessages(['role' => 'Le premier administrateur ne peut pas perdre ses droits.']);
        }
        if (isset($data['role']) && $data['role'] !== 'admin' && $user->isAdmin() && User::where('role', 'admin')->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Il faut garder au moins un administrateur.']);
        }
        $user->update($data);

        return ['member' => $user->fresh('group')->toPublic()];
    }

    public function resetCode(User $user)
    {
        abort_if($user->id === User::PROTECTED_ADMIN_ID, 403, 'Le premier administrateur change son code lui-même.');
        $code = (string) random_int(100000, 999999);
        $user->update(['password' => $code]);
        $user->tokens()->delete();

        return ['temporary_code' => $code];
    }

    public function groups()
    {
        return [
            'groups' => Group::orderBy('name')->get()->map->toPublic()->values(),
            'kinds' => collect(Group::KINDS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ];
    }

    public function storeGroup(Request $request)
    {
        $data = $this->groupData($request);
        $group = Group::create($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4))]);

        return ['group' => $group->toPublic()];
    }

    public function updateGroup(Request $request, Group $group)
    {
        $group->update($this->groupData($request, true));

        return ['group' => $group->toPublic()];
    }

    public function destroyGroup(Group $group)
    {
        $group->delete();

        return response()->noContent();
    }

    public function settings()
    {
        return [
            'open_registration' => (bool) Setting::get('open_registration', true),
            'retention_days' => Setting::get('retention_days'),
        ];
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'open_registration' => 'sometimes|boolean',
            'retention_days' => 'sometimes|nullable|integer|min:7|max:3650',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, $v);
        }

        return $this->settings();
    }

    private function groupData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$req}|string|max:120",
            'kind' => [$req, Rule::in(array_keys(Group::KINDS))],
            'description' => 'sometimes|nullable|string|max:255',
            'monthly_token_budget' => 'sometimes|nullable|integer|min:0',
        ]);
    }
}
