<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->toApi());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'locale' => ['sometimes', Rule::in(User::LOCALES)],
            'settings' => ['sometimes', 'array:'.collect(User::SETTINGS)->keys()->reject(fn ($key) => str_contains($key, '.'))->implode(',')],
            ...collect(User::SETTINGS)->mapWithKeys(fn ($rules, $key) => ["settings.{$key}" => $rules])->all(),
        ]);

        $user = $request->user();
        if (isset($data['settings'])) {
            // Settings are merged: the app sends only what changed.
            $data['settings'] = array_merge($user->settings ?? [], $data['settings']);
        }
        $user->update($data);

        return response()->json($user->toApi());
    }
}
