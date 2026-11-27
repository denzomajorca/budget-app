<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private Account $acct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->acct = Account::create(['name' => 'CHASE Saphire', 'type' => 'credit_card']);
    }

    private function cat(string $main, ?string $sub = null): Category
    {
        $m = Category::whereNull('parent_id')->where('name', $main)->firstOrFail();

        return $sub ? $m->children()->where('name', $sub)->firstOrFail() : $m;
    }

    private function spend(string $date, int $cents, Category $c): void
    {
        Transaction::create(['account_id' => $this->acct->id, 'date' => $date, 'payee' => 'x', 'amount' => -$cents, 'category_id' => $c->id]);
    }

    private function group(array $props, string $name): array
    {
        return collect($props['groups'])->firstWhere('name', $name);
    }

    public function test_budgets_are_set_per_subcategory_and_mains_add_them_up()
    {
        $groceries = $this->cat('Food and Dining', 'Groceries');
        $restaurants = $this->cat('Food and Dining', 'Restaurants');
        $this->put('/budgets', ['category_id' => $groceries->id, 'amount' => '600'])->assertSessionHasNoErrors();
        $this->put('/budgets', ['category_id' => $restaurants->id, 'amount' => '$250.50'])->assertSessionHasNoErrors();

        $this->spend('2026-10-03', 20000, $groceries);
        $this->spend('2026-10-20', 45000, $groceries);
        $this->spend('2026-10-05', 30000, $restaurants);
        Transaction::create(['account_id' => $this->acct->id, 'date' => '2026-10-06', 'payee' => 'refund', 'amount' => 5000, 'category_id' => $restaurants->id]);
        $this->spend('2026-11-02', 99900, $groceries); // other month

        $props = $this->get('/budgets?month=2026-10')->viewData('page')['props'];
        $food = $this->group($props, 'Food and Dining');
        $this->assertSame(60000 + 25050, $food['budget']);
        $this->assertSame(65000 + 25000, $food['spent'], 'refund counts against spending');
        $row = collect($food['rows'])->firstWhere('name', 'Groceries');
        $this->assertSame([60000, 65000], [$row['budget'], $row['spent']]);
        $this->assertSame(60000 + 25050, $props['totals']['budget']);
    }

    public function test_spending_filed_under_a_main_category_gets_its_own_row()
    {
        $this->spend('2026-10-10', 12000, $this->cat('Travel'));
        $props = $this->get('/budgets?month=2026-10')->viewData('page')['props'];
        $travel = $this->group($props, 'Travel');
        $this->assertSame('Other Travel', collect($travel['rows'])->last()['name']);
        $this->assertSame(12000, $travel['spent']);
        $this->assertSame(12000, $props['totals']['unbudgeted']);
    }

    public function test_clearing_an_amount_removes_the_budget_and_income_cannot_be_budgeted()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $this->put('/budgets', ['category_id' => $g->id, 'amount' => '100']);
        $this->put('/budgets', ['category_id' => $g->id, 'amount' => '']);
        $this->assertSame(0, Budget::count());

        $this->put('/budgets', ['category_id' => $this->cat('Income', 'Paycheck')->id, 'amount' => '100'])->assertSessionHasErrors('amount');
        $this->put('/budgets', ['category_id' => $g->id, 'amount' => 'abc'])->assertSessionHasErrors('amount');
    }

    public function test_empty_budgets_can_be_filled_from_three_month_averages()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $r = $this->cat('Food and Dining', 'Restaurants');
        $this->spend('2026-08-10', 50000, $g);
        $this->spend('2026-09-10', 40000, $g);
        $this->spend('2026-10-10', 40100, $g);
        $this->spend('2026-07-10', 99999, $g); // older than 3 months: ignored
        $this->spend('2026-10-11', 9000, $r);
        Budget::create(['category_id' => $r->id, 'amount' => 20000]);

        $this->post('/budgets/fill', ['month' => '2026-11'])->assertSessionHasNoErrors();
        $this->assertSame(43400, Budget::where('category_id', $g->id)->value('amount'), '130100/3 = 433.67 rounded up to 434');
        $this->assertSame(20000, Budget::where('category_id', $r->id)->value('amount'), 'existing budgets are left alone');
    }

    public function test_dashboard_shows_budget_and_overspending()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        Budget::create(['category_id' => $g->id, 'amount' => 10000]);
        $this->spend(now()->startOfMonth()->toDateString(), 15000, $g);
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p
            ->where('budget.budgeted', 10000)
            ->where('budget.over.0.name', 'Food and Dining › Groceries')
            ->where('budget.over.0.over', 5000));
    }

    public function test_bad_month_falls_back_to_this_month()
    {
        $this->get('/budgets?month=2026-13')->assertInertia(fn (Assert $p) => $p->component('Budgets/Index')->where('month', now()->format('Y-m')));
    }

    public function test_flat_lines_in_your_order_with_hidden_choices_and_unchanged_totals()
    {
        $groceries = $this->cat('Food and Dining', 'Groceries');
        $gas = $this->cat('Auto and Transport', 'Gas');
        $taxes = $this->cat('Taxes'); // a main category with no subcategories
        $this->put('/budgets', ['category_id' => $groceries->id, 'amount' => '600']);
        $this->spend('2026-10-03', 20000, $groceries);
        $this->spend('2026-10-04', 5000, $gas);

        $props = $this->get('/budgets?month=2026-10')->viewData('page')['props'];
        $names = array_column($props['lines'], 'name');
        $this->assertContains('Taxes', $names, 'a main without subcategories is a line itself');
        $this->assertNotContains('Food and Dining', $names, 'mains with subcategories are not lines');
        $line = collect($props['lines'])->firstWhere('name', 'Groceries');
        $this->assertSame('Food and Dining', $line['parent']);
        $this->assertNull($line['hidden']);

        $this->post('/budgets/layout', ['order' => [$taxes->id, $gas->id, $groceries->id]])->assertSessionHasNoErrors();
        $this->post('/budgets/layout', ['hidden' => [$gas->id => true]])->assertSessionHasNoErrors();

        $props = $this->get('/budgets?month=2026-10')->viewData('page')['props'];
        $this->assertSame(['Taxes', 'Gas', 'Groceries'], array_slice(array_column($props['lines'], 'name'), 0, 3));
        $this->assertTrue(collect($props['lines'])->firstWhere('name', 'Gas')['hidden']);
        $this->assertSame(25000, $props['totals']['spent'], 'hidden lines still count in the totals');
        $this->assertSame(60000, $props['totals']['budget']);
    }
}
