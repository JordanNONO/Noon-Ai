<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Normalise un numéro : chiffres et « + » uniquement. */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $p = preg_replace('/[^\d+]/', '', $phone);

        return $p === '' ? null : $p;
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => 'required|string|max:190',
            'password' => 'required|string',
        ]);
        $id = trim($data['identifier']);
        $user = str_contains($id, '@')
            ? User::where('email', mb_strtolower($id))->first()
            : User::where('phone', self::normalizePhone($id))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['identifier' => 'Numéro, e-mail ou code incorrect.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['identifier' => 'Ce compte est désactivé. Contactez un administrateur.']);
        }

        return $this->issue($user);
    }

    public function register(Request $request)
    {
        abort_unless(Setting::get('open_registration', true), 403, 'Les inscriptions sont fermées. Demandez une invitation à un administrateur.');

        $request->merge(['phone' => self::normalizePhone($request->input('phone')), 'email' => $request->input('email') ? mb_strtolower($request->input('email')) : null]);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|required_without:email|string|max:30|unique:users,phone',
            'email' => 'nullable|required_without:phone|email|max:190|unique:users,email',
            'password' => 'required|string|min:4|max:100',
            'language' => ['nullable', Rule::exists('languages', 'code')],
        ], [
            'phone.unique' => 'Ce numéro a déjà un compte.',
            'email.unique' => 'Cet e-mail a déjà un compte.',
            'phone.required_without' => 'Indiquez un numéro de téléphone ou un e-mail.',
            'password.min' => 'Le code doit faire au moins 4 caractères.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            'language' => $data['language'] ?? 'fr',
            'role' => 'member',
        ]);

        return $this->issue($user);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return ['user' => $request->user()->load('group')->toPublic()];
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'language' => ['sometimes', Rule::exists('languages', 'code')],
            'bilingual_replies' => 'sometimes|boolean',
            'large_text' => 'sometimes|boolean',
            'voice' => 'sometimes|array',
            'voice.wake_word' => 'sometimes|boolean',
            'voice.voice' => 'sometimes|string|max:40',
            'voice.rate' => 'sometimes|integer|min:0|max:4',
            'voice.confirm_aloud' => 'sometimes|boolean',
            'voice.auto_read' => 'sometimes|boolean',
            'current_password' => 'required_with:password|string',
            'password' => 'sometimes|string|min:4|max:100',
        ]);
        if (isset($data['password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Code actuel incorrect.']);
            }
            $user->password = $data['password'];
        }
        if (isset($data['voice'])) {
            $user->voice_settings = array_merge($user->voicePrefs(), $data['voice']);
        }
        $user->fill(collect($data)->only(['name', 'language', 'bilingual_replies', 'large_text'])->all())->save();

        return ['user' => $user->fresh('group')->toPublic()];
    }

    public function options()
    {
        return [
            'open_registration' => (bool) Setting::get('open_registration', true),
            'languages' => Language::where('is_active', true)->orderBy('sort')->get()->map(fn ($l) => ['code' => $l->code, 'name' => $l->name, 'native_name' => $l->native_name]),
        ];
    }

    private function issue(User $user)
    {
        $user->update(['last_seen_at' => now()]);

        return [
            'token' => $user->createToken('noon')->plainTextToken,
            'user' => $user->load('group')->toPublic(),
        ];
    }
}
