<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyTest extends TestCase
{
    use RefreshDatabase;

    private Account $acct;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27');
        $this->actingAs(User::factory()->create());
        $this->acct = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function id(string $main, ?string $sub = null): int
    {
        $m = Category::whereNull('parent_id')->where('name', $main)->firstOrFail();

        return $sub ? $m->children()->where('name', $sub)->firstOrFail()->id : $m->id;
    }

    private function add(string $date, int $amount, ?int $cat, string $payee = 'x'): void
    {
        Transaction::create(['account_id' => $this->acct->id, 'date' => $date, 'payee' => $payee, 'amount' => $amount, 'category_id' => $cat]);
    }

    public function test_month_columns_totals_averages_and_highlights()
    {
        $groc = $this->id('Food and Dining', 'Groceries');
        foreach ([1, 2, 3, 5, 6, 7, 8, 9, 10, 11] as $m) {
            $this->add(sprintf('2026-%02d-10', $m), -40000, $groc);
        }
        $this->add('2026-04-10', -90000, $groc); // a high month
        $this->add('2026-02-15', -1000, $this->id('Food and Dining')); // straight on the main
        $this->add('2026-01-31', 500000, $this->id('Income', 'Paycheck'));
        $this->add('2026-02-02', -20000, $this->id('Transfer', 'Credit Card Payment'));
        $this->add('2026-03-03', -700, null);
        $this->add('2025-12-31', -99999, $groc); // outside the range

        $this->get('/spending/monthly?from=2026-01&to=2026-12')->assertOk()->assertInertia(function ($p) {
            $props = $p->toArray()['props'];
            $this->assertCount(12, $props['months']);
            $this->assertSame(11, $props['counted'], 'December 2026 has not happened yet');
            $this->assertTrue($props['months'][11]['future']);

            [$income, $spending] = $props['sections'];
            $this->assertSame('Income', $income['title']);
            $this->assertSame(500000, $income['groups'][0]['values'][0]);
            $this->assertSame(['Food and Dining', 'Uncategorized'], array_column($spending['groups'], 'label'), 'transfers are left out');

            $food = $spending['groups'][0];
            $this->assertSame([40000, 41000, 40000, 90000], array_slice($food['values'], 0, 4));
            $this->assertSame(491000, $food['total']);
            $this->assertSame((int) round(491000 / 11), $food['average']);
            $this->assertSame(['Groceries', 'Other Food and Dining'], array_column($food['children'], 'label'));
            $this->assertSame('only-'.Category::where('name', 'Food and Dining')->value('id'), $food['children'][1]['filter']);
            $this->assertContains('2026-04', $food['high']);
            $this->assertNotContains('2026-01', $food['high']);

            $this->assertSame(491700, $spending['totals']['total']);
            $this->assertSame(500000 - 40000, $props['net']['values'][0]);
            $this->assertSame(500000 - 491700, $props['net']['total']);
        });
    }

    public function test_default_range_is_the_year_of_the_latest_transaction_and_range_is_capped()
    {
        $this->add('2025-06-01', -100, null);
        $this->get('/spending/monthly')->assertInertia(fn ($p) => $p->where('from', '2025-01')->where('to', '2025-12'));
        $this->get('/spending/monthly?from=2020-01&to=2026-12')->assertInertia(fn ($p) => $p->where('from', '2020-01')->where('to', '2022-12'));
        $this->get('/spending/monthly?from=2026-05&to=2026-02')->assertInertia(fn ($p) => $p->where('from', '2026-02')->where('to', '2026-05'));
    }
}
