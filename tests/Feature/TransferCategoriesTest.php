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

class TransferCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private Account $tfcu;

    private Account $chase;

    private Account $savings;

    private Account $sapphire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        TransferCategories::forget();
        $this->tfcu = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $this->chase = Account::create(['name' => 'CHASE Checking', 'type' => 'checking']);
        $this->savings = Account::create(['name' => 'CHASE Savings', 'type' => 'savings']);
        $this->sapphire = Account::create(['name' => 'CHASE Sapphire', 'type' => 'credit_card']);
    }

    private function group(string $main, string $sub): Category
    {
        $m = Category::firstOrCreate(['name' => $main, 'parent_id' => null], ['kind' => 'transfer']);

        return Category::create(['name' => $sub, 'parent_id' => $m->id, 'kind' => 'transfer']);
    }

    private function txn(Account $a, string $date, string $payee, int $amount, ?Category $c): Transaction
    {
        return Transaction::create(['account_id' => $a->id, 'date' => $date, 'payee' => $payee, 'amount' => $amount, 'category_id' => $c?->id]);
    }

    private function label(Transaction $t): ?string
    {
        return $t->fresh()->category?->label();
    }

    public function test_old_groups_are_sorted_into_card_payment_savings_and_room2go_spending()
    {
        $tfcuChk = $this->group('Checking', 'TFCU Checking');
        $chaseChk = $this->group('Checking', 'Chase Checking');
        $chaseSav = $this->group('Savings', 'Chase Savings');
        $cards = $this->group('Credit Cards', 'Freedom');
        $room = $this->group('Credit Cards', 'Room2Go');
        $oldCcp = Category::where('name', 'Credit Card Payment')->first(); // seeded under Transfer

        $amex = $this->txn($this->tfcu, '2026-10-01', 'American Express', -145089, $tfcuChk);
        $toSav = $this->txn($this->chase, '2025-12-29', 'Transfer To Sav 12/29', -30000, $chaseChk);
        $fromChk = $this->txn($this->savings, '2026-10-05', 'Transfer From Chk', 30000, $chaseSav);
        $freedom = $this->txn($this->chase, '2026-10-10', 'CHASE CREDIT CRD EPAY', -5000, $cards);
        $thanks = $this->txn($this->sapphire, '2026-10-16', 'Payment Thank You-Mobile', 200983, $oldCcp);
        $roomRow = $this->txn($this->tfcu, '2026-10-20', 'ROOMS TO GO', -15000, $room);
        // A paired transfer keeps its pair.
        $out = $this->txn($this->chase, '2026-10-14', 'Online payment', -200983, $chaseChk);
        $out->update(['transfer_id' => $thanks->id]);
        $thanks->update(['transfer_id' => $out->id]);
        PayeeRule::create(['match' => 'american express', 'category_id' => $tfcuChk->id]);
        PayeeRule::create(['match' => 'rooms to go', 'category_id' => $room->id]);

        TransferCategories::reorganize();

        $this->assertSame('Transfer › Credit Card Payment', $this->label($amex));
        $this->assertSame('Transfer › Savings', $this->label($toSav));
        $this->assertSame('Transfer › Savings', $this->label($fromChk));
        $this->assertSame('Transfer › Credit Card Payment', $this->label($freedom));
        $this->assertSame('Transfer › Credit Card Payment', $this->label($out), 'paired with a card account');
        $this->assertSame($thanks->id, $out->fresh()->transfer_id);
        $this->assertSame('Loan Payment › Room2Go', $this->label($roomRow));
        $this->assertSame('expense', $roomRow->fresh()->category->kind);

        $this->assertSame('Transfer › Credit Card Payment', PayeeRule::where('match', 'american express')->first()->category->label());
        $this->assertSame('Loan Payment › Room2Go', PayeeRule::where('match', 'rooms to go')->first()->category->label());

        // Old groups are gone; only Transfer is left as a transfer main category.
        $this->assertSame(['Transfer'], Category::whereNull('parent_id')->where('kind', 'transfer')->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Credit Card Payment', 'Savings'], Category::where('parent_id', Category::where('name', 'Transfer')->value('id'))->pluck('name')->all());
    }

    public function test_future_imports_land_in_the_new_categories()
    {
        $this->post('/import', ['account_id' => $this->tfcu->id, 'file_name' => 't.csv', 'rows' => [
            ['date' => '2026-11-01', 'amount' => -50000, 'payee' => 'American Express', 'category' => 'Checking:TFCU Checking', 'memo' => null],
            ['date' => '2026-11-02', 'amount' => -30000, 'payee' => 'Transfer To Sav', 'category' => 'Checking:TFCU Checking', 'memo' => null],
            ['date' => '2026-11-03', 'amount' => -15000, 'payee' => 'Rooms To Go', 'category' => 'Credit Cards:Room2Go', 'memo' => null],
            ['date' => '2026-11-04', 'amount' => -2000, 'payee' => 'Publix', 'category' => 'Food and Dining:Groceries', 'memo' => null],
        ]])->assertSessionHasNoErrors();

        $labels = Transaction::orderBy('date')->get()->map(fn ($t) => $t->category?->label())->all();
        $this->assertSame(['Transfer › Credit Card Payment', 'Transfer › Savings', 'Loan Payment › Room2Go', 'Food and Dining › Groceries'], $labels);
        $this->assertNull(Category::where('name', 'Checking')->first(), 'old groups are not made again');
        $this->assertNull(Category::where('name', 'Credit Cards')->first());

        $res = $this->postJson('/import/preview', ['account_id' => $this->tfcu->id, 'rows' => [
            ['date' => '2026-11-05', 'amount' => -1, 'payee' => 'x', 'category' => 'Savings:Chase Savings', 'memo' => null],
        ]])->assertOk();
        $this->assertSame([], $res->json('new_categories'));
    }
}
