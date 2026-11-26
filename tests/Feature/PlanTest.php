<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->actingAs(User::factory()->create());
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

    private function props(): array
    {
        return $this->get('/plan')->viewData('page')['props'];
    }

    public function test_all_three_kinds_can_be_added_edited_and_removed()
    {
        $this->post('/plan', ['group' => 'bill', 'name' => 'Mortgage', 'amount' => '2,150', 'every_months' => 1, 'next_due' => '2026-12-01', 'category_id' => $this->cat('Home', 'Mortgage')->id])->assertSessionHasNoErrors();
        $this->post('/plan', ['group' => 'flex', 'name' => 'Groceries', 'amount' => '650'])->assertSessionHasNoErrors();
        $this->post('/plan', ['group' => 'goal', 'name' => 'Europe', 'amount' => '6000', 'saved' => '2400', 'due_by' => '2027-07-01'])->assertSessionHasNoErrors();
        $this->post('/plan', ['group' => 'bill', 'name' => 'x', 'amount' => '1'])->assertSessionHasErrors(['every_months', 'next_due']);
        $this->post('/plan', ['group' => 'goal', 'name' => 'x', 'amount' => '1'])->assertSessionHasErrors('due_by');
        $this->assertSame(3, PlanItem::count());

        $bill = PlanItem::where('name', 'Mortgage')->first();
        $this->put("/plan/{$bill->id}", ['name' => 'Mortgage', 'amount' => '2200', 'every_months' => 1, 'next_due' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->assertSame(['bill', 220000], [$bill->fresh()->group, $bill->fresh()->amount]);
        $this->delete("/plan/{$bill->id}");
        $this->assertSame(2, PlanItem::count());
    }

    public function test_projection_counts_bills_in_their_due_months_and_goal_set_asides()
    {
        PlanItem::create(['group' => 'bill', 'name' => 'Mortgage', 'amount' => 200000, 'every_months' => 1, 'next_due' => '2026-06-01']);
        PlanItem::create(['group' => 'bill', 'name' => 'Property tax', 'amount' => 420000, 'every_months' => 12, 'next_due' => '2025-11-30']);
        PlanItem::create(['group' => 'bill', 'name' => 'Water', 'amount' => 30000, 'every_months' => 3, 'next_due' => '2027-01-16']);
        PlanItem::create(['group' => 'flex', 'name' => 'Groceries', 'amount' => 65000]);
        PlanItem::create(['group' => 'goal', 'name' => 'Tires', 'amount' => 90000, 'saved' => 15000, 'due_by' => '2027-02-01']);

        $p = $this->props();
        $m = collect($p['months'])->keyBy('month');
        $this->assertSame('2026-11', $p['months'][0]['month']);
        $this->assertSame(200000 + 420000, $m['2026-11']['bills'], 'yearly tax rolls forward to Nov 30 2026');
        $this->assertSame(200000 + 30000, $m['2027-01']['bills']);
        $this->assertSame(200000 + 30000, $m['2027-04']['bills']);
        $this->assertSame(65000, $m['2027-03']['flex']);
        // 750 left over 3 months (Nov, Dec, Jan) = 250/month, none from February on
        $this->assertSame(25000, $m['2027-01']['goals']);
        $this->assertSame(0, $m['2027-02']['goals']);

        $tax = collect($p['bills'])->firstWhere('name', 'Property tax');
        $this->assertSame(['2026-11-30', 35000, 'Yearly'], [$tax['next_due'], $tax['per_month'], $tax['frequency']]);
        $this->assertSame(['Mortgage', 'Property tax', 'Water'], collect($p['upcoming'])->pluck('name')->unique()->sort()->values()->all());
    }

    public function test_everyday_averages_and_income_use_the_90_days_up_to_the_last_transaction()
    {
        $a = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $g = $this->cat('Food and Dining', 'Groceries');
        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-10-18'] as $d) {
            Transaction::create(['account_id' => $a->id, 'date' => $d, 'payee' => 'Publix', 'amount' => -30000, 'category_id' => $g->id]);
        }
        Transaction::create(['account_id' => $a->id, 'date' => '2026-07-01', 'payee' => 'old', 'amount' => -99999, 'category_id' => $g->id]);
        Transaction::create(['account_id' => $a->id, 'date' => '2026-10-15', 'payee' => 'Pay', 'amount' => 900000, 'category_id' => $this->cat('Income', 'Paycheck')->id]);
        PlanItem::create(['group' => 'flex', 'name' => 'Food', 'amount' => 30000, 'category_id' => $this->cat('Food and Dining')->id]);

        $p = $this->props();
        $this->assertSame('2026-10-18', $p['averagesThrough']);
        $perMonth = fn ($c) => (int) round($c * (365.25 / 12) / 90);
        $this->assertSame($perMonth(120000), $p['flex'][0]['average'], 'main category includes its subcategories');
        $this->assertSame($perMonth(900000), $p['income']);
    }
}
