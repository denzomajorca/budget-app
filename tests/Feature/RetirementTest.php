<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\InvestmentAccount;
use App\Models\InvestmentActivity;
use App\Models\InvestmentBalance;
use App\Models\RetirementPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Retirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAs(User::factory()->create());

        // Made-up numbers.
        $k = InvestmentAccount::create(['name' => 'Work 401(k)', 'kind' => '401k']);
        InvestmentBalance::create(['investment_account_id' => $k->id, 'date' => '2025-10-31', 'balance' => 150_000_00, 'loan_balance' => 6_000_00]);
        for ($i = 12; $i >= 1; $i--) {
            $d = Carbon::parse('2026-11-10')->subMonthsNoOverflow($i - 1)->toDateString();
            InvestmentActivity::create(['investment_account_id' => $k->id, 'date' => $d, 'kind' => 'employee', 'description' => 'Pay', 'amount' => 400_00, 'import_key' => "e$i"]);
            InvestmentActivity::create(['investment_account_id' => $k->id, 'date' => $d, 'kind' => 'employer', 'description' => 'Match', 'amount' => 100_00, 'import_key' => "r$i"]);
            InvestmentActivity::create(['investment_account_id' => $k->id, 'date' => $d, 'kind' => 'loan_repayment', 'description' => 'Loan', 'amount' => 200_00, 'import_key' => "l$i"]);
        }
        $roth = InvestmentAccount::create(['name' => 'Roth', 'kind' => 'roth_ira']);
        InvestmentBalance::create(['investment_account_id' => $roth->id, 'date' => '2026-11-01', 'balance' => 10_000_00]);

        $checking = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $food = Category::create(['name' => 'Food', 'kind' => 'expense']);
        foreach (range(1, 12) as $i) {
            Transaction::create(['account_id' => $checking->id, 'date' => Carbon::parse('2026-11-05')->subMonthsNoOverflow($i)->toDateString(), 'payee' => 'Store', 'amount' => -3000_00, 'category_id' => $food->id]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Returns equal inflation: no growth, so the arithmetic can be checked by hand. */
    private function flat(array $over = []): array
    {
        return array_merge(Retirement::defaults(Retirement::facts()), [
            'birth_year' => 1970,
            'stock_return' => 2.5,
            'bond_return' => 2.5,
            'inflation' => 2.5,
            'tax_rate' => 0,
        ], $over);
    }

    public function test_facts_come_from_the_app(): void
    {
        $f = Retirement::facts();
        // 150,000 + 12 × (400 + 100 + 200) put in since the statement.
        $this->assertSame(150_000_00 + 12 * 700_00, $f['pretax']);
        $this->assertSame(10_000_00, $f['tax_free']);
        $this->assertSame(6_000_00, $f['loan']);
        $this->assertSame(500_00, $f['saving_monthly'], 'loan repayments are not savings');
        $this->assertSame(200_00, $f['loan_payment_monthly']);
        $this->assertSame(3000_00, $f['spending_monthly']);
        $d = Retirement::defaults($f);
        $this->assertSame(500, $d['saving']);
        $this->assertSame(3000, $d['spending']);
    }

    public function test_projection_adds_savings_then_draws_down(): void
    {
        $in = $this->flat(['social_security' => 0, 'loan_payment' => 200]);
        $p = Retirement::project($in, Retirement::facts(), now());
        // About 98 months of 500 saved, plus the 6,000 loan paid back over 30 months.
        $start = 158_400 + 10_000;
        $this->assertEqualsWithDelta(($start + 98 * 500 + 6_000) * 100, $p['at_retirement'], 600_00);
        // 3,000 a month from about 229,000 lasts about 76 months: into age 71.
        $this->assertSame(71, $p['runs_out_at']);

        $plan = Retirement::plan($in, Retirement::facts());
        $this->assertSame('short', $plan['status']);
        // 36,000 a year from about 229,000 saved.
        $this->assertEqualsWithDelta(15.7, $plan['withdrawal_rate'], 0.5);
        $this->assertNotNull($plan['extra_needed']);
        // The extra amount it suggests is enough with lower returns.
        $this->assertNull(Retirement::project($in, Retirement::facts(), now(), -2.0, $plan['extra_needed'] / 100)['runs_out_at']);
    }

    public function test_social_security_covering_spending_is_on_track(): void
    {
        $plan = Retirement::plan($this->flat(['social_security' => 3000, 'ss_age' => 62]), Retirement::facts());
        $this->assertSame('on_track', $plan['status']);
        $this->assertNull($plan['expected']['runs_out_at']);
        $this->assertSame(0.0, (float) $plan['withdrawal_rate']);
    }

    public function test_tax_makes_money_run_out_sooner(): void
    {
        $f = Retirement::facts();
        $none = Retirement::project($this->flat(), $f, now());
        $taxed = Retirement::project($this->flat(['tax_rate' => 20]), $f, now());
        $this->assertLessThan($none['runs_out_at'], $taxed['runs_out_at']);
    }

    public function test_page_asks_for_birth_year_then_saves_answers(): void
    {
        $p = $this->get('/retirement')->assertOk()->viewData('page')['props'];
        $this->assertNull($p['plan']);
        $this->assertSame(500, $p['inputs']['saving']);

        $in = array_merge($p['inputs'], ['birth_year' => 1970, 'social_security' => 2100, 'stock_return' => '6.5']);
        $this->put('/retirement', $in)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(6.5, RetirementPlan::first()->inputs['stock_return']);

        $p = $this->get('/retirement')->viewData('page')['props'];
        $this->assertNotNull($p['plan']);
        $this->assertSame(2100, $p['inputs']['social_security']);
        $this->assertContains($p['plan']['status'], ['on_track', 'close', 'short']);

        $this->delete('/retirement')->assertRedirect();
        $p = $this->get('/retirement')->viewData('page')['props'];
        $this->assertSame(1970, $p['inputs']['birth_year'], 'the birth year is kept');
        $this->assertSame(0, $p['inputs']['social_security']);

        $this->put('/retirement', array_merge($in, ['retire_age' => 30]))->assertSessionHasErrors('retire_age');
    }
}
