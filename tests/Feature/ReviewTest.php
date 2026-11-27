<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransferCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();
        TransferCategories::forget();
        $this->actingAs(User::factory()->create());
        $this->card = Account::create(['name' => 'CHASE Saphire', 'type' => 'credit_card']);
    }

    private function cat(string $main, ?string $sub = null): Category
    {
        $m = Category::whereNull('parent_id')->where('name', $main)->firstOrFail();

        return $sub ? $m->children()->where('name', $sub)->firstOrFail() : $m;
    }

    private function import(array $rows): void
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'f.csv', 'rows' => $rows])->assertSessionHasNoErrors();
    }

    public function test_imports_land_to_review_and_filters_count_them()
    {
        $this->import([
            ['date' => '2026-10-01', 'amount' => -3000, 'payee' => 'PUBLIX #1234', 'category' => '', 'memo' => null],
            ['date' => '2026-10-02', 'amount' => -2000, 'payee' => 'Shell', 'category' => 'Auto and Transport:Gas', 'memo' => null],
        ]);
        Transaction::create(['account_id' => $this->card->id, 'date' => '2026-10-03', 'payee' => 'Typed by hand', 'amount' => -100]);

        $this->get('/transactions')->assertInertia(fn ($p) => $p->where('to_review', 2)->where('uncategorized', 2));
        $this->get('/transactions?review=1')->assertInertia(fn ($p) => $p->where('totals.count', 2)->where('transactions.data.0.needs_review', true));
        $this->get('/transactions?review=1&category=none')->assertInertia(fn ($p) => $p->where('totals.count', 1));
    }

    public function test_setting_a_category_on_ticked_rows_marks_them_reviewed()
    {
        $this->import([
            ['date' => '2026-10-01', 'amount' => -3000, 'payee' => 'Publix', 'category' => '', 'memo' => null],
            ['date' => '2026-10-02', 'amount' => -4000, 'payee' => 'Publix', 'category' => '', 'memo' => null],
            ['date' => '2026-10-03', 'amount' => -5000, 'payee' => 'Delta', 'category' => '', 'memo' => null],
        ]);
        $ids = Transaction::where('payee', 'Publix')->pluck('id')->all();
        $groceries = $this->cat('Food and Dining', 'Groceries');

        $this->post('/transactions/bulk', ['ids' => $ids, 'action' => 'category', 'category_id' => $groceries->id])
            ->assertSessionHas('status', 'Category set on 2 transactions.');
        $this->assertSame(2, Transaction::where('category_id', $groceries->id)->where('needs_review', false)->count());
        $this->assertTrue(Transaction::where('payee', 'Delta')->value('needs_review'));

        // "Mark all shown as reviewed" uses the filters on screen.
        $this->post('/transactions/bulk', ['all' => true, 'filters' => ['review' => '1', 'q' => 'delta'], 'action' => 'reviewed'])
            ->assertSessionHas('status', '1 transaction marked reviewed.');
        $this->assertSame(0, Transaction::where('needs_review', true)->count());
    }

    public function test_editing_a_transaction_marks_it_reviewed()
    {
        $this->import([['date' => '2026-10-01', 'amount' => -3000, 'payee' => 'Publix', 'category' => '', 'memo' => null]]);
        $t = Transaction::first();
        $this->put('/transactions/'.$t->id, ['account_id' => $this->card->id, 'date' => '2026-10-01', 'payee' => 'Publix', 'direction' => 'out', 'amount' => '30.00', 'category_id' => null])
            ->assertSessionHasNoErrors();
        $this->assertFalse($t->fresh()->needs_review);
    }

    public function test_payee_rules_file_waiting_rows_and_future_imports()
    {
        $groceries = $this->cat('Food and Dining', 'Groceries');
        $this->import([
            ['date' => '2026-10-01', 'amount' => -3000, 'payee' => 'PUBLIX #1234 TAMPA', 'category' => '', 'memo' => null],
            ['date' => '2026-10-02', 'amount' => -4000, 'payee' => 'Publix #99', 'category' => 'Shopping', 'memo' => null],
        ]);

        $this->post('/payee-rules', ['match' => '  Publix ', 'category_id' => $groceries->id])->assertSessionHasNoErrors();
        $this->assertSame('publix', PayeeRule::first()->match);
        $this->assertSame(2, Transaction::where('category_id', $groceries->id)->where('needs_review', false)->count(), 'rows waiting for review are filed');

        // Future imports: the rule beats the file's category, and the longest rule wins.
        $streaming = $this->cat('Subscriptions', 'Streaming');
        $this->post('/payee-rules', ['match' => 'publix pharmacy', 'category_id' => $this->cat('Health', 'Pharmacy')->id]);
        $this->post('/payee-rules', ['match' => 'netflix', 'category_id' => $streaming->id]);
        $this->import([
            ['date' => '2026-11-01', 'amount' => -1500, 'payee' => 'NETFLIX.COM', 'category' => 'Entertainment', 'memo' => null],
            ['date' => '2026-11-02', 'amount' => -900, 'payee' => 'Publix Pharmacy 0421', 'category' => '', 'memo' => null],
            ['date' => '2026-11-03', 'amount' => -100, 'payee' => 'Other shop', 'category' => '', 'memo' => null],
        ]);
        $this->assertSame($streaming->id, Transaction::where('payee', 'NETFLIX.COM')->value('category_id'));
        $this->assertSame($this->cat('Health', 'Pharmacy')->id, Transaction::where('payee', 'Publix Pharmacy 0421')->value('category_id'));
        $this->assertNull(Transaction::where('payee', 'Other shop')->value('category_id'));
        $this->assertTrue(Transaction::where('payee', 'NETFLIX.COM')->value('needs_review'), 'still shown for a quick check');

        $this->delete('/payee-rules/'.PayeeRule::where('match', 'netflix')->value('id'));
        $this->assertSame(2, PayeeRule::count());
    }

    public function test_moving_one_side_of_a_transfer_to_spending_lets_go_of_the_pair()
    {
        $checking = Account::create(['name' => 'CHASE Checking', 'type' => 'checking']);
        $tr = $this->cat('Transfer');
        $a = Transaction::create(['account_id' => $checking->id, 'date' => '2026-10-01', 'payee' => 'Pay card', 'amount' => -500, 'category_id' => $tr->id]);
        $b = Transaction::create(['account_id' => $this->card->id, 'date' => '2026-10-01', 'payee' => 'Payment', 'amount' => 500, 'category_id' => $tr->id]);
        $a->update(['transfer_id' => $b->id]);
        $b->update(['transfer_id' => $a->id]);

        $this->post('/transactions/bulk', ['ids' => [$a->id], 'action' => 'category', 'category_id' => $this->cat('Shopping')->id]);
        $this->assertNull($a->fresh()->transfer_id);
        $this->assertNull($b->fresh()->transfer_id);
    }
}
