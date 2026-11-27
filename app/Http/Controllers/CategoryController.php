<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Support\Transfers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        $counts = Transaction::select('category_id', DB::raw('count(*) as n'))->groupBy('category_id')->pluck('n', 'category_id');
        $mains = Category::whereNull('parent_id')->with('children')->orderByRaw(Category::KIND_ORDER)->orderBy('name')->get();

        return Inertia::render('Categories/Index', [
            'categories' => $mains->map(fn (Category $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'kind' => $m->kind,
                'count' => (int) ($counts[$m->id] ?? 0),
                'children' => $m->children->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'count' => (int) ($counts[$c->id] ?? 0),
                ])->values(),
            ]),
            'options' => Category::options(),
            'kinds' => Category::KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Category::create($data);

        return back()->with('status', 'Category added.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        if ($data['parent_id'] && $category->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => 'This category has subcategories, so it has to stay a main category.']);
        }
        DB::transaction(function () use ($category, $data) {
            $category->update($data);
            // Subcategories always share their main category's kind.
            $category->children()->update(['kind' => $category->kind]);
        });
        Transfers::pair();

        return back()->with('status', 'Category saved.');
    }

    /**
     * Deletes a category. Its transactions move to `move_to`, or become
     * uncategorized. A main category's subcategories are deleted with it
     * and their transactions move the same way.
     */
    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $ids = $category->idsWithChildren();
        $request->validate([
            'move_to' => ['nullable', 'integer', Rule::exists('categories', 'id'), Rule::notIn($ids)],
        ]);

        DB::transaction(function () use ($category, $ids, $request) {
            Transaction::whereIn('category_id', $ids)->update(['category_id' => $request->integer('move_to') ?: null]);
            Category::where('parent_id', $category->id)->delete();
            $category->delete();
        });

        return back()->with('status', 'Category deleted.');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/:/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id'), Rule::notIn([$category?->id])],
            'kind' => ['nullable', Rule::in(array_keys(Category::KINDS))],
        ], ['name.not_regex' => 'Leave out the colon; pick the main category instead.']);

        $parentId = $data['parent_id'] ?? null;
        $duplicate = Category::where('name', $data['name'])
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
            ->when($category, fn ($q) => $q->whereKeyNot($category->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'There is already a category with that name here.']);
        }

        $kind = $parentId ? Category::find($parentId)->kind : ($data['kind'] ?? 'expense');

        return ['name' => $data['name'], 'parent_id' => $parentId, 'kind' => $kind];
    }
}
