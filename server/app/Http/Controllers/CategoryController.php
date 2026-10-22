<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = $request->user()->categories()->orderBy('sort')->orderBy('id')->get();

        return response()->json($categories->map->toApi()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'kind' => ['required', Rule::in(Category::KINDS)],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', Rule::in(Category::ICONS)],
            'color' => ['nullable', Rule::in(Category::COLORS)],
            'necessity' => ['nullable', Rule::in(Category::NECESSITIES)],
        ]);

        if (isset($data['parent_id'])) {
            $parent = $user->categories()->whereNull('parent_id')->where('kind', $data['kind'])->find($data['parent_id']);
            if (! $parent) {
                throw ValidationException::withMessages(['parent_id' => __('money.parent_must_be_top')]);
            }
            $data['necessity'] ??= $parent->necessity;
        }
        if ($data['kind'] === 'expense') {
            $data['necessity'] ??= 'flexible';
        } else {
            $data['necessity'] = null;
        }

        $data['sort'] = (int) $user->categories()->where('parent_id', $data['parent_id'] ?? null)->max('sort') + 1;
        $category = $user->categories()->create($data);

        return response()->json($category->toApi(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = $request->user()->categories()->findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'icon' => ['sometimes', 'nullable', Rule::in(Category::ICONS)],
            'color' => ['sometimes', 'nullable', Rule::in(Category::COLORS)],
            'necessity' => $category->kind === 'expense' ? ['sometimes', Rule::in(Category::NECESSITIES)] : ['prohibited'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        // A custom category must keep a name; a standard one falls back to its translation when cleared.
        if (array_key_exists('name', $data) && $data['name'] === null && $category->system_key === null) {
            unset($data['name']);
        }
        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? ($category->archived_at ?? now()) : null;
            unset($data['archived']);
        }
        $category->update($data);

        return response()->json($category->toApi());
    }

    /** Categories are archived, never deleted: old operations keep pointing at them. */
    public function destroy(Request $request, int $id): Response
    {
        $request->user()->categories()->findOrFail($id)->update(['archived_at' => now()]);

        return response()->noContent();
    }
}
