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
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json($user->toApi());
    }
}
