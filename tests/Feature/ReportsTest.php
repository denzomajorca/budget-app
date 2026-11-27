<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\InvestmentAccount;
use App\Models\InvestmentBalance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Account $checking;

    private Account $card;

    private Category $salary;

    private Category $groceries;

    private Category $dining;

    private Category $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 10:00:00');
        $this->actingAs(User::factory()->create());
        $this->checking = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $this->card = Account::create(['name' => 'CHASE Saphire', 'type' => 'credit_card']);
        $income = Category::firstOrCreate(['name' => 'Income', 'parent_id' => null], ['kind' => 'income']);
        $this->salary = Category::create(['name' => 'Paycheck '.uniqid(), 'parent_id' => $income->id, 'kind' => 'income']);
        $food = Category::create(['name' => 'Food Test', 'kind' => 'expense']);
        $this->groceries = Category::create(['name' => 'Groceries', 'parent_id' => $food->id, 'kind' => 'expense']);
        $this->dining = Category::create(['name' => 'Restaurants', 'parent_id' => $food->id, 'kind' => 'expense']);
        $this->transfer = Category::create(['name' => 'Transfer Test', 'kind' => 'transfer']);

        foreach (['2026-09', '2026-10', '2026-11'] as $m) {
            $this->tx($this->checking, "$m-01", 'Employer', 500000, $this->salary);
            $this->tx($this->card, "$m-05", 'Publix', -60000, $this->groceries);
            $this->tx($this->card, "$m-09", 'publix ', -20000, $this->groceries);
            $this->tx($this->card, "$m-12", 'Chili’s', -15000, $this->dining);
            // Paying the card is a transfer: not spending.
            $this->tx($this->checking, "$m-20", 'Card payment', -95000, $this->transfer);
            $this->tx($this->card, "$m-20", 'Payment', 95000, $this->transfer);
        }
        $this->tx($this->card, '2025-11-05', 'Publix', -70000, $this->groceries);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(Account $a, string $date, string $payee, int $amount, ?Category $c): void
    {
        Transaction::create(['account_id' => $a->id, 'date' => $date, 'payee' => $payee, 'amount' => $amount, 'category_id' => $c?->id]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_cash_flow_leaves_out_transfers(): void
    {
        $p = $this->props('/reports/cash-flow');
        $nov = collect($p['rows'])->firstWhere('month', '2026-11');
        $this->assertSame(500000, $nov['income']);
        $this->assertSame(95000, $nov['spending']);
        $this->assertSame(405000, $nov['net']);
        $this->assertSame(1_500_000, $p['totals']['income']);
        $this->assertSame(3 * 95000, $p['totals']['spending']);
        $this->assertCount(12, $p['rows']);
        $this->assertCount(24, $this->props('/reports/cash-flow?months=24')['rows']);
    }

    public function test_net_worth_adds_investments_and_subtracts_debts(): void
    {
        $k = InvestmentAccount::create(['name' => '401(k)', 'kind' => '401k']);
        InvestmentBalance::create(['investment_account_id' => $k->id, 'date' => '2026-09-30', 'balance' => 300_000_00]);
        $p = $this->props('/reports/net-worth');
        $now = $p['now'];
        // Checking: 3 × (5,000 − 950); card: −700 from last year's groceries.
        $this->assertSame(3 * 405000, $now['cash']);
        $this->assertSame(-70000, $now['debts']);
        $this->assertSame(300_000_00, $now['investments']);
        $this->assertSame(3 * 405000 - 70000 + 300_000_00, $now['net']);
        $this->assertNotNull($p['emergency']['months']);
    }

    public function test_budget_vs_actual_per_line(): void
    {
        Budget::create(['category_id' => $this->groceries->id, 'amount' => 75000]);
        Budget::create(['category_id' => $this->dining->id, 'amount' => 20000]);
        $p = $this->props('/reports/budget?year=2026');
        $this->assertCount(11, $p['months']);
        $g = collect($p['lines'])->firstWhere('category_id', $this->groceries->id);
        $this->assertSame(11 * 75000, $g['budget']);
        $this->assertSame(3 * 80000, $g['total']);
        $this->assertSame(3, $g['over']);
        // Least under budget first (dining: 11 × 200 budgeted, 450 spent).
        $this->assertSame($this->dining->id, $p['lines'][0]['category_id']);
    }

    public function test_payees_group_spellings(): void
    {
        $p = $this->props('/reports/payees');
        $this->assertSame('Publix', $p['rows'][0]['payee']);
        $this->assertSame(6, $p['rows'][0]['count']);
        $this->assertSame(240000, $p['rows'][0]['total']);
        $this->assertSame('Food Test', $p['rows'][0]['category']);
        $this->assertNull(collect($p['rows'])->firstWhere('payee', 'Card payment'));
    }

    public function test_year_in_review(): void
    {
        $p = $this->props('/reports/year?year=2026');
        $this->assertTrue($p['partial']);
        $this->assertSame(1_500_000, $p['current']['income']);
        $this->assertSame(285000, $p['current']['spending']);
        // Last year's same months: only the November groceries.
        $this->assertSame(70000, $p['previous']['spending']);
        $this->assertSame('Food Test', $p['categories'][0]['name']);
        $this->assertSame(70000, $p['categories'][0]['previous']);
        $this->assertCount(12, $p['months']);
        $this->props('/reports/year?year=2025');
    }
}
