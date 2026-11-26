<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SpendingTest extends TestCase
{
    use RefreshDatabase;

    private Account $acct;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->actingAs(User::factory()->create());
        $this->acct = Account::create(['name' => 'Card', 'type' => 'credit_card']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function props(string $query): array
    {
        return $this->get('/spending?'.$query)->viewData('page')['props'];
    }

    public function test_month_compared_with_previous_month_by_category_and_subcategory()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $r = $this->cat('Food and Dining', 'Restaurants');
        $this->spend('2026-09-05', 10000, $g);
        $this->spend('2026-09-25', 3000, $r);
        $this->spend('2026-10-05', 12000, $g);
        $this->spend('2026-10-31', 1000, $r);
        $this->spend('2026-10-10', 5000, $this->cat('Travel'));
        $this->spend('2026-11-01', 7700, $g); // last transaction: November is the default period

        $p = $this->props('type=month&key=2026-10&cmp=prev');
        $this->assertSame(['October 2026', 'September 2026'], [$p['a']['label'], $p['b']['label']]);
        $this->assertNull($p['partial']);
        $food = collect($p['groups'])->firstWhere('name', 'Food and Dining');
        $this->assertSame([13000, 13000], [$food['a'], $food['b']]);
        $this->assertSame(['Groceries', 12000, 10000], [$food['rows'][0]['name'], $food['rows'][0]['a'], $food['rows'][0]['b']]);
        $travel = collect($p['groups'])->firstWhere('name', 'Travel');
        $this->assertSame(['only-'.$this->cat('Travel')->id, 'Other Travel'], [$travel['rows'][0]['category'], $travel['rows'][0]['name']]);
        $this->assertSame(18000, $p['totals']['a']);

        $this->assertSame('2026-11', $this->props('')['period']);
    }

    public function test_unfinished_period_is_compared_like_for_like_up_to_the_last_transaction()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $this->spend('2025-10-10', 1000, $g);
        $this->spend('2025-10-25', 9000, $g); // after the 18th: left out of the comparison
        $this->spend('2026-10-18', 2000, $g); // imports stop here

        $p = $this->props('type=month&key=2026-10&cmp=ly');
        $this->assertSame('2026-10-18', $p['partial']);
        $this->assertSame(['October 2025', '2025-10-01', '2025-10-18'], [$p['b']['label'], $p['b']['from'], $p['b']['to']]);
        $this->assertSame([2000, 1000], [$p['totals']['a'], $p['totals']['b']]);
    }

    public function test_quarter_and_year()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $this->spend('2026-04-02', 100, $g);
        $this->spend('2026-01-15', 200, $g);
        $this->spend('2025-02-15', 400, $g);
        $this->spend('2026-11-20', 1, $g);

        $q = $this->props('type=quarter&key=2026-Q2&cmp=prev');
        $this->assertSame(['Q2 2026', 'Q1 2026', '2026-06-30', 100, 200], [$q['a']['label'], $q['b']['label'], $q['a']['to'], $q['totals']['a'], $q['totals']['b']]);

        $y = $this->props('type=year&key=2025');
        $this->assertSame(['2025', '2024', 'ly', 400, false], [$y['a']['label'], $y['b']['label'], $y['cmp'], $y['totals']['a'], $y['b']['hasData']]);
    }

    public function test_income_and_transfers_are_left_out_and_refunds_net()
    {
        $g = $this->cat('Food and Dining', 'Groceries');
        $this->spend('2026-10-05', 5000, $g);
        Transaction::create(['account_id' => $this->acct->id, 'date' => '2026-10-06', 'payee' => 'refund', 'amount' => 1000, 'category_id' => $g->id]);
        Transaction::create(['account_id' => $this->acct->id, 'date' => '2026-10-06', 'payee' => 'pay', 'amount' => 300000, 'category_id' => $this->cat('Income', 'Paycheck')->id]);
        $this->spend('2026-10-07', 70000, $this->cat('Transfer', 'Credit Card Payment'));
        $this->assertSame(4000, $this->props('type=month&key=2026-10')['totals']['a']);
    }

    public function test_main_category_only_filter_on_transactions()
    {
        $this->spend('2026-10-10', 5000, $this->cat('Travel'));
        $this->spend('2026-10-11', 9000, $this->cat('Travel', 'Hotel'));
        $id = $this->cat('Travel')->id;
        $this->assertSame(2, $this->get("/transactions?category=$id")->viewData('page')['props']['totals']['count']);
        $this->assertSame(1, $this->get("/transactions?category=only-$id")->viewData('page')['props']['totals']['count']);
    }
}
