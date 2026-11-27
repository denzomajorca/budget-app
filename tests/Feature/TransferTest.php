<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CategoryPath;
use App\Support\TransferCategories;
use App\Support\Transfers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private Account $checking;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();
        TransferCategories::forget();
        $this->actingAs(User::factory()->create());
        $this->checking = Account::create(['name' => 'CHASE Checking', 'type' => 'checking']);
        $this->card = Account::create(['name' => 'CHASE Saphire', 'type' => 'credit_card']);
    }

    public function test_account_like_names_are_transfers()
    {
        foreach (['Checking', 'Savings', 'Credit Cards', 'Credit Card Payment', 'Transfer', 'Transfers', '[CHASE Saphire]', 'chase checking'] as $n) {
            $this->assertSame('transfer', CategoryPath::kindFor($n), $n);
        }
        foreach (['Cards and Gifts', 'Bank Fees', 'Food and Dining', 'Credit card interest'] as $n) {
            $this->assertNotSame('transfer', CategoryPath::kindFor($n), $n);
        }
        $this->assertSame('income', CategoryPath::kindFor('Payroll'));
    }

    public function test_import_files_account_categories_as_transfers_and_pairs_both_sides()
    {
        $this->post('/import', ['account_id' => $this->checking->id, 'file_name' => 'c.csv', 'rows' => [
            ['date' => '2026-10-01', 'amount' => -50000, 'payee' => 'Chase card payment', 'category' => 'Credit Cards:Saphire', 'memo' => null],
            ['date' => '2026-10-02', 'amount' => -3000, 'payee' => 'Publix', 'category' => 'Food and Dining:Groceries', 'memo' => null],
        ]])->assertSessionHasNoErrors();
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'card.csv', 'rows' => [
            ['date' => '2026-10-03', 'amount' => 50000, 'payee' => 'Payment thank you', 'category' => '', 'memo' => null],
            ['date' => '2026-10-04', 'amount' => -7000, 'payee' => 'Delta', 'category' => 'Travel', 'memo' => null],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('Transfer › Credit Card Payment', Transaction::where('payee', 'Chase card payment')->first()->category->label());
        $out = Transaction::where('payee', 'Chase card payment')->first();
        $in = Transaction::where('payee', 'Payment thank you')->first();
        $this->assertSame($in->id, $out->transfer_id);
        $this->assertSame($out->id, $in->transfer_id);
        $this->assertSame($out->category_id, $in->category_id, 'the uncategorized side is filed as the same transfer');
        $this->assertSame(0, Transfers::unpairedCount());

        $this->get('/transactions')->assertInertia(fn ($p) => $p
            ->where('transactions.data.3.transfer', 'CHASE Checking → CHASE Saphire')
            ->where('transactions.data.1.transfer', 'CHASE Checking → CHASE Saphire'));

        // Spending only counts groceries and travel.
        $this->get('/spending?type=month&key=2026-10')->assertInertia(fn ($p) => $p->where('totals.a', 10000));

        // Balances are unchanged by pairing.
        $this->assertSame(-53000, $this->checking->balance());
        $this->assertSame(43000, $this->card->balance());
    }

    public function test_unpaired_transfers_are_listed_and_far_apart_dates_do_not_pair()
    {
        $savings = Account::create(['name' => 'TFCU Savings', 'type' => 'savings']);
        $cat = Category::create(['name' => 'Savings', 'kind' => 'transfer']);
        Transaction::create(['account_id' => $this->checking->id, 'date' => '2026-10-01', 'payee' => 'To savings', 'amount' => -10000, 'category_id' => $cat->id]);
        Transaction::create(['account_id' => $savings->id, 'date' => '2026-10-20', 'payee' => 'From checking', 'amount' => 10000, 'category_id' => $cat->id]);
        $this->assertSame(0, Transfers::pair());
        $this->get('/transactions?transfers=unpaired')->assertInertia(fn ($p) => $p->where('unpaired_transfers', 2)->where('totals.count', 2));

        // Fix the date, and saving pairs it.
        $t = Transaction::where('payee', 'From checking')->first();
        $this->put('/transactions/'.$t->id, ['account_id' => $savings->id, 'date' => '2026-10-02', 'payee' => 'From checking', 'direction' => 'in', 'amount' => '100.00', 'category_id' => $cat->id])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($t->fresh()->transfer_id);

        // Moving it to a spending category unpairs both sides.
        $food = Category::where('name', 'Food and Dining')->first();
        $this->put('/transactions/'.$t->id, ['account_id' => $savings->id, 'date' => '2026-10-02', 'payee' => 'From checking', 'direction' => 'in', 'amount' => '100.00', 'category_id' => $food->id]);
        $this->assertNull($t->fresh()->transfer_id);
        $this->assertNull(Transaction::where('payee', 'To savings')->value('transfer_id'));
    }

    public function test_income_comes_first_and_transfers_last()
    {
        Category::create(['name' => 'Checking', 'kind' => 'transfer']);
        $kinds = array_values(array_unique(array_column(Category::options(), 'kind')));
        $this->assertSame(['income', 'expense', 'transfer'], $kinds);
        $this->get('/categories')->assertInertia(fn ($p) => $p->where('categories.0.name', 'Income'));
    }

    public function test_upgrade_fixes_existing_categories()
    {
        // As an older import would have left them: account names filed as spending.
        $cc = Category::create(['name' => 'Credit Cards', 'kind' => 'expense']);
        $sub = Category::create(['name' => 'Saphire', 'parent_id' => $cc->id, 'kind' => 'expense']);
        $pay = Category::create(['name' => 'Payroll', 'kind' => 'expense']);
        Transaction::create(['account_id' => $this->checking->id, 'date' => '2026-10-01', 'payee' => 'Pay card', 'amount' => -2500, 'category_id' => $sub->id]);
        Transaction::create(['account_id' => $this->card->id, 'date' => '2026-10-01', 'payee' => 'Payment', 'amount' => 2500]);

        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_11_27_000008_add_transfer_id_to_transactions.php']);
        Artisan::call('migrate');

        $this->assertSame('transfer', $cc->fresh()->kind);
        $this->assertSame('transfer', $sub->fresh()->kind);
        $this->assertSame('income', $pay->fresh()->kind);
        $this->assertNotNull(Transaction::where('payee', 'Pay card')->value('transfer_id'));
    }

    public function test_unpaired_list_suggests_a_match_you_can_pair_and_outside_accounts_drop_out()
    {
        $savings = Account::create(['name' => 'CHASE Savings', 'type' => 'savings']);
        $sav = Category::create(['name' => 'Savings', 'kind' => 'transfer']);
        $interest = Category::where('name', 'Interest')->first();
        // Other side 8 days later and filed as income, so automatic pairing skips it.
        $out = Transaction::create(['account_id' => $this->checking->id, 'date' => '2025-12-29', 'payee' => 'Transfer To Sav 12/29', 'amount' => -30000, 'category_id' => $sav->id]);
        $in = Transaction::create(['account_id' => $savings->id, 'date' => '2026-01-06', 'payee' => 'Transfer From Chk', 'amount' => 30000, 'category_id' => $interest->id]);
        $amex = Transaction::create(['account_id' => $this->checking->id, 'date' => '2026-10-01', 'payee' => 'American Express', 'amount' => -145089, 'category_id' => $sav->id]);
        $this->assertSame(0, Transfers::pair());

        $this->get('/transactions?transfers=unpaired')->assertInertia(fn ($p) => $p
            ->where('unpaired_transfers', 2)
            ->where('transactions.data.1.match.id', $in->id)
            ->where('transactions.data.1.match.account', 'CHASE Savings')
            ->where('transactions.data.0.match', null));

        $this->post('/transactions/'.$out->id.'/pair', ['other_id' => $in->id])->assertSessionHasNoErrors();
        $this->assertSame($in->id, $out->fresh()->transfer_id);
        $this->assertSame($sav->id, $in->fresh()->category_id, 'the other side becomes a transfer too');

        // Can't pair the same amount in the same direction.
        $this->post('/transactions/'.$amex->id.'/pair', ['other_id' => $out->id])->assertSessionHasErrors('other_id');

        $this->post('/transactions/bulk', ['all' => true, 'filters' => ['transfers' => 'unpaired', 'q' => 'american express'], 'action' => 'outside'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($amex->fresh()->transfer_outside);
        $this->assertSame(0, Transfers::unpairedCount());
        $this->get('/transactions?transfers=unpaired')->assertInertia(fn ($p) => $p->where('totals.count', 0));
    }
}
