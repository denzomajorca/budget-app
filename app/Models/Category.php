<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    public const KINDS = ['income' => 'Income', 'expense' => 'Spending', 'transfer' => 'Transfer between accounts'];

    /** Income first, then spending, then transfers. */
    public const KIND_ORDER = "case kind when 'income' then 0 when 'expense' then 1 else 2 end";

    protected $fillable = ['parent_id', 'name', 'kind', 'budget_hidden', 'budget_position'];

    protected function casts(): array
    {
        return ['budget_hidden' => 'boolean', 'budget_position' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('name');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** "Food and Dining › Groceries", or just the main name. */
    public function label(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /** This category's id plus its subcategories' ids, for filtering. */
    public function idsWithChildren(): array
    {
        return array_merge([$this->id], $this->children()->pluck('id')->all());
    }

    /**
     * Options for category pickers: main categories in name order, each
     * followed by its subcategories.
     *
     * @return list<array{id:int,label:string,name:string,parent_id:int|null,kind:string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::whereNull('parent_id')->with('children')->orderByRaw(self::KIND_ORDER)->orderBy('name')->get() as $main) {
            $out[] = ['id' => $main->id, 'label' => $main->name, 'name' => $main->name, 'parent_id' => null, 'kind' => $main->kind];
            foreach ($main->children as $sub) {
                $out[] = ['id' => $sub->id, 'label' => $main->name.' › '.$sub->name, 'name' => $sub->name, 'parent_id' => $main->id, 'kind' => $main->kind];
            }
        }

        return $out;
    }
}
