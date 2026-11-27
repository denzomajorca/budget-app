<?php

namespace Tests\Feature;

use App\Models\InvestmentAccount;
use App\Models\InvestmentActivity;
use App\Models\InvestmentBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvestmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-27 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function props(string $q = ''): array
    {
        return $this->get('/investments'.$q)->assertOk()->viewData('page')['props'];
    }

    private function k401(): InvestmentAccount
    {
        return InvestmentAccount::create(['name' => 'John Hancock 401(k)', 'kind' => '401k', 'institution' => 'John Hancock']);
    }

    private function jhRows(): array
    {
        return [
            ['date' => '2026-10-01', 'kind' => 'employer', 'description' => 'Contribution - Employer · JH Multimanager Growth LS', 'fund' => 'JH Multimanager Growth LS', 'amount' => 7231, 'units' => 1.566, 'price' => 46.177, 'ref' => '3618425826'],
            ['date' => '2026-10-01', 'kind' => 'employee', 'description' => 'Contribution - Employee · JH Multimanager Growth LS', 'fund' => 'JH Multimanager Growth LS', 'amount' => 7231, 'units' => 1.566, 'price' => 46.177, 'ref' => '3618425826'],
            ['date' => '2026-10-01', 'kind' => 'loan_repayment', 'description' => 'Loan repayment - Loan #3', 'amount' => 24161, 'ref' => '3618425827'],
            ['date' => '2026-09-16', 'kind' => 'employee', 'description' => 'Contribution - Employee · JH Multimanager Growth LS', 'fund' => 'JH Multimanager Growth LS', 'amount' => 7000, 'units' => 1.6, 'price' => 43.75, 'ref' => '3617118838'],
        ];
    }

    public function test_page_shows_empty_state(): void
    {
        $p = $this->props();
        $this->assertCount(0, $p['accounts']);
    }

    public function test_import_activity_skips_rows_already_there(): void
    {
        $a = $this->k401();
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'jh.csv', 'type' => 'activity', 'rows' => $this->jhRows()])->assertSessionHasNoErrors();
        $this->assertSame(4, InvestmentActivity::count());

        $more = $this->jhRows();
        $more[] = ['date' => '2026-10-16', 'kind' => 'employee', 'description' => 'Contribution - Employee · JH Multimanager Growth LS', 'fund' => 'JH Multimanager Growth LS', 'amount' => 7231, 'units' => 1.5, 'price' => 48.2, 'ref' => '3619'];
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'jh2.csv', 'type' => 'activity', 'rows' => $more])
            ->assertSessionHas('status', 'Imported 1 rows, skipped 4 already here.');
        $this->assertSame(5, InvestmentActivity::count());

        $p = $this->props('?tf=all');
        $this->assertSame(7231 + 7000 + 7231, $p['activity']['by_kind']['employee']);
        $this->assertSame(7231, $p['activity']['by_kind']['employer']);
        $this->assertSame(24161, $p['activity']['by_kind']['loan_repayment']);
        $this->assertSame(3, $p['activity']['pay_dates']);
        $fund = $p['activity']['funds'][0];
        $this->assertSame('JH Multimanager Growth LS', $fund['name']);
        $this->assertEqualsWithDelta(1.566 * 2 + 1.6 + 1.5, $fund['units'], 0.0001);
        $this->assertSame(48.2, $fund['last_price']);
    }

    public function test_identical_rows_in_one_file_are_kept(): void
    {
        $a = $this->k401();
        $row = ['date' => '2026-10-01', 'kind' => 'buy', 'description' => 'YOU BOUGHT', 'fund' => 'FXAIX', 'amount' => -50000, 'units' => 2, 'price' => 250];
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'f.csv', 'type' => 'activity', 'rows' => [$row, $row]]);
        $this->assertSame(2, InvestmentActivity::count());
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'f.csv', 'type' => 'activity', 'rows' => [$row, $row]]);
        $this->assertSame(2, InvestmentActivity::count());
    }

    public function test_undo_import_removes_its_rows(): void
    {
        $a = $this->k401();
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'jh.csv', 'type' => 'activity', 'rows' => $this->jhRows()]);
        $import = $a->imports()->first();
        $this->delete('/investments/import/'.$import->id);
        $this->assertSame(0, InvestmentActivity::count());
    }

    public function test_growth_between_two_balances_subtracts_money_put_in(): void
    {
        $a = $this->k401();
        InvestmentBalance::create(['investment_account_id' => $a->id, 'date' => '2025-12-31', 'balance' => 100_000_00]);
        InvestmentBalance::create(['investment_account_id' => $a->id, 'date' => '2026-10-31', 'balance' => 120_000_00]);
        InvestmentActivity::create(['investment_account_id' => $a->id, 'date' => '2026-03-01', 'kind' => 'employee', 'description' => 'x', 'amount' => 8_000_00]);
        InvestmentActivity::create(['investment_account_id' => $a->id, 'date' => '2026-03-01', 'kind' => 'employer', 'description' => 'x', 'amount' => 2_000_00]);
        // Inside the account, not money put in.
        InvestmentActivity::create(['investment_account_id' => $a->id, 'date' => '2026-03-01', 'kind' => 'earnings', 'description' => 'x', 'amount' => 500_00]);
        // Before the starting balance, so already in it.
        InvestmentActivity::create(['investment_account_id' => $a->id, 'date' => '2025-12-15', 'kind' => 'employee', 'description' => 'x', 'amount' => 1_000_00]);

        $p = $this->props('?tf=ytd');
        $row = $p['rows'][0];
        $this->assertSame(100_000_00, $row['start']);
        $this->assertSame(10_000_00, $row['put_in']);
        $this->assertSame(10_000_00, $row['gain']);
        $this->assertEqualsWithDelta(10_000 / 105_000, $row['return'], 0.0001);
        $this->assertSame(120_000_00, $p['summary']['value']);
        $this->assertSame(10_000_00, $p['summary']['gain']);
        $this->assertNotEmpty($p['chart']);
        $this->assertSame(120_000_00, end($p['chart'])['value']);
        $this->assertSame(110_000_00, end($p['chart'])['basis']);
    }

    public function test_one_balance_asks_for_a_second(): void
    {
        $a = $this->k401();
        $this->post('/investments/balances', ['account_id' => $a->id, 'date' => '2026-10-31', 'balance' => '$95,000.50'])->assertSessionHasNoErrors();
        $this->post('/investments/balances', ['account_id' => $a->id, 'date' => '2026-10-31', 'balance' => '96000'])->assertSessionHasNoErrors();
        $this->assertSame(1, InvestmentBalance::count());
        $p = $this->props();
        $this->assertSame('second_balance', $p['rows'][0]['needs']);
        $this->assertSame(96_000_00, $p['summary']['value']);
        $this->assertNull($p['summary']['gain']);
        $this->post('/investments/balances', ['account_id' => $a->id, 'date' => '2027-01-01', 'balance' => '1'])->assertSessionHasErrors('date');
    }

    public function test_holdings_file_saves_holdings_and_a_balance(): void
    {
        $f = InvestmentAccount::create(['name' => 'Fidelity brokerage', 'kind' => 'brokerage', 'institution' => 'Fidelity']);
        $rows = [
            ['symbol' => 'FXAIX', 'name' => 'FIDELITY 500 INDEX FUND', 'quantity' => 30, 'price' => 220.5, 'value' => 661500, 'cost' => 600000],
            ['symbol' => 'SPAXX', 'name' => 'HELD IN MONEY MARKET', 'value' => 338500],
        ];
        $this->post('/investments/import', ['account_id' => $f->id, 'file_name' => 'Portfolio_Positions.csv', 'type' => 'holdings', 'as_of' => '2026-11-27', 'rows' => $rows])
            ->assertSessionHasNoErrors();
        $p = $this->props();
        $this->assertSame(1_000_000, $p['holdings']['accounts'][0]['total']);
        $this->assertEqualsWithDelta(0.6615, $p['holdings']['accounts'][0]['rows'][0]['share'], 0.0001);
        $this->assertSame(1_000_000, InvestmentBalance::first()->balance);
        $this->assertSame('holdings', InvestmentBalance::first()->source);

        // Same day again replaces, not doubles.
        $this->post('/investments/import', ['account_id' => $f->id, 'file_name' => 'Portfolio_Positions.csv', 'type' => 'holdings', 'as_of' => '2026-11-27', 'rows' => $rows]);
        $this->assertSame(2, $f->holdings()->count());
        $this->assertSame(1, InvestmentBalance::count());

        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('investments', 1_000_000)->where('netWorth', 1_000_000));
    }

    public function test_typed_activity_uses_the_right_sign(): void
    {
        $f = InvestmentAccount::create(['name' => 'Fidelity', 'kind' => 'brokerage']);
        $this->post('/investments/activity', ['account_id' => $f->id, 'date' => '2026-11-20', 'kind' => 'deposit', 'amount' => '-10,000'])->assertSessionHasNoErrors();
        $this->post('/investments/activity', ['account_id' => $f->id, 'date' => '2026-11-21', 'kind' => 'withdrawal', 'amount' => '200'])->assertSessionHasNoErrors();
        $this->assertSame([1_000_000, -20000], InvestmentActivity::orderBy('date')->pluck('amount')->all());
    }

    public function test_deleting_an_account_removes_everything(): void
    {
        $a = $this->k401();
        $this->post('/investments/import', ['account_id' => $a->id, 'file_name' => 'jh.csv', 'type' => 'activity', 'rows' => $this->jhRows()]);
        InvestmentBalance::create(['investment_account_id' => $a->id, 'date' => '2026-10-31', 'balance' => 5]);
        $this->delete('/investments/accounts/'.$a->id)->assertRedirect('/investments');
        $this->assertSame(0, InvestmentActivity::count());
        $this->assertSame(0, InvestmentBalance::count());
    }

    public function test_new_account_grows_from_zero(): void
    {
        $f = InvestmentAccount::create(['name' => 'Fidelity', 'kind' => 'brokerage', 'started_empty' => true]);
        InvestmentActivity::create(['investment_account_id' => $f->id, 'date' => '2026-11-20', 'kind' => 'deposit', 'description' => 'EFT', 'amount' => 10_000_00]);
        InvestmentActivity::create(['investment_account_id' => $f->id, 'date' => '2026-11-21', 'kind' => 'buy', 'description' => 'YOU BOUGHT', 'amount' => -5_000_00]);
        $p = $this->props();
        $this->assertSame('balance', $p['rows'][0]['needs']);

        InvestmentBalance::create(['investment_account_id' => $f->id, 'date' => '2026-11-27', 'balance' => 10_122_86]);
        $row = $this->props()['rows'][0];
        $this->assertTrue($row['opened']);
        $this->assertSame(0, $row['start']);
        $this->assertSame(10_000_00, $row['put_in']);
        $this->assertSame(122_86, $row['gain']);
    }
}
