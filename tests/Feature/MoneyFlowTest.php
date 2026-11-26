<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MoneyFlowTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create());
    }

    private function cat(string $main, ?string $sub = null): Category
    {
        $m = Category::whereNull('parent_id')->where('name', $main)->firstOrFail();

        return $sub ? $m->children()->where('name', $sub)->firstOrFail() : $m;
    }

    public function test_pages_need_sign_in()
    {
        foreach (['/accounts', '/transactions', '/categories'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_default_categories_are_created_by_the_migration()
    {
        $this->assertSame('income', $this->cat('Income')->kind);
        $this->assertSame('expense', $this->cat('Food and Dining', 'Groceries')->kind);
        $this->assertSame('transfer', $this->cat('Transfer', 'Credit Card Payment')->kind);
    }

    public function test_accounts_can_be_added_and_balances_follow_transactions()
    {
        $this->signIn();
        $this->post('/accounts', ['name' => 'Checking', 'type' => 'checking', 'opening_balance' => '1,000.00', 'opening_date' => '2026-01-01'])->assertRedirect();
        $this->post('/accounts', ['name' => 'Sapphire', 'type' => 'credit_card', 'opening_balance' => '250'])->assertRedirect();

        $checking = Account::where('name', 'Checking')->first();
        $card = Account::where('name', 'Sapphire')->first();
        $this->assertSame(100000, $checking->opening_balance);
        $this->assertSame(-25000, $card->opening_balance, 'what you owe on a card is stored as negative');

        $this->post('/transactions', ['account_id' => $checking->id, 'date' => '2026-02-01', 'payee' => 'Publix', 'direction' => 'out', 'amount' => '45.10', 'category_id' => $this->cat('Food and Dining', 'Groceries')->id])->assertSessionHasNoErrors();
        $this->post('/transactions', ['account_id' => $checking->id, 'date' => '2026-02-02', 'payee' => 'Paycheck', 'direction' => 'in', 'amount' => '2000'])->assertSessionHasNoErrors();

        $this->get('/accounts')->assertInertia(fn (Assert $p) => $p->component('Accounts/Index')
            ->where('accounts.0.name', 'Checking')->where('accounts.0.balance', 100000 - 4510 + 200000)
            ->where('accounts.1.balance', -25000));

        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('netWorth', 100000 - 4510 + 200000 - 25000));
    }

    public function test_bad_input_is_rejected()
    {
        $this->signIn();
        $this->post('/accounts', ['name' => '', 'type' => 'boat', 'opening_balance' => 'lots'])->assertSessionHasErrors(['name', 'type', 'opening_balance']);
        $a = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $this->post('/transactions', ['account_id' => $a->id, 'date' => '26/11/2026', 'payee' => 'x', 'direction' => 'sideways', 'amount' => '1.234'])
            ->assertSessionHasErrors(['date', 'direction', 'amount']);
    }

    public function test_transactions_can_be_edited_and_deleted()
    {
        $this->signIn();
        $a = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $t = Transaction::create(['account_id' => $a->id, 'date' => '2026-03-01', 'payee' => 'Shell', 'amount' => -3000]);

        $this->put("/transactions/{$t->id}", ['account_id' => $a->id, 'date' => '2026-03-02', 'payee' => 'Shell Oil', 'direction' => 'out', 'amount' => '31.00', 'memo' => 'gas'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame([-3100, 'Shell Oil', '2026-03-02'], [$t->amount, $t->payee, $t->date->format('Y-m-d')]);

        $this->delete("/transactions/{$t->id}")->assertRedirect();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_transaction_filters_include_subcategories_and_dates()
    {
        $this->signIn();
        $a = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $b = Account::create(['name' => 'Card', 'type' => 'credit_card']);
        $groceries = $this->cat('Food and Dining', 'Groceries');
        $food = $this->cat('Food and Dining');
        Transaction::create(['account_id' => $a->id, 'date' => '2026-10-05', 'payee' => 'Publix', 'amount' => -5000, 'category_id' => $groceries->id]);
        Transaction::create(['account_id' => $b->id, 'date' => '2026-10-20', 'payee' => 'Chipotle', 'amount' => -1500, 'category_id' => $this->cat('Food and Dining', 'Restaurants')->id]);
        Transaction::create(['account_id' => $a->id, 'date' => '2026-11-02', 'payee' => 'Publix', 'amount' => -2000, 'category_id' => $groceries->id]);
        Transaction::create(['account_id' => $a->id, 'date' => '2026-10-10', 'payee' => 'Refund', 'amount' => 900]);

        $count = fn (array $q) => $this->get('/transactions?'.http_build_query($q))->viewData('page')['props']['totals']['count'];

        $this->assertSame(3, $count(['category' => $food->id]), 'main category includes its subcategories');
        $this->assertSame(2, $count(['category' => $groceries->id]));
        $this->assertSame(2, $count(['category' => $food->id, 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $this->assertSame(1, $count(['category' => 'none']));
        $this->assertSame(1, $count(['account' => $b->id]));
        $this->assertSame(2, $count(['q' => 'publix']));

        $this->get('/transactions?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p
            ->where('totals.in', 900)->where('totals.out', -6500)->has('transactions.data', 3));
    }

    public function test_categories_can_be_added_renamed_and_deleted_with_their_transactions_moved()
    {
        $this->signIn();
        $food = $this->cat('Food and Dining');
        $this->post('/categories', ['name' => 'Bakery', 'parent_id' => $food->id])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => 'Bakery', 'parent_id' => $food->id])->assertSessionHasErrors('name');
        $this->post('/categories', ['name' => 'Pets:Food'])->assertSessionHasErrors('name');

        $bakery = Category::where('name', 'Bakery')->first();
        $this->assertSame('expense', $bakery->kind);
        $this->put("/categories/{$bakery->id}", ['name' => 'Bakeries', 'parent_id' => $food->id])->assertSessionHasNoErrors();

        $a = Account::create(['name' => 'Checking', 'type' => 'checking']);
        $t = Transaction::create(['account_id' => $a->id, 'date' => '2026-03-01', 'payee' => 'Panera', 'amount' => -900, 'category_id' => $bakery->id]);
        $restaurants = $this->cat('Food and Dining', 'Restaurants');
        $this->delete("/categories/{$bakery->id}", ['move_to' => $restaurants->id])->assertSessionHasNoErrors();
        $this->assertSame($restaurants->id, $t->fresh()->category_id);

        // A main category with subcategories: everything inside goes, transactions become uncategorized.
        $travel = $this->cat('Travel');
        $t->update(['category_id' => $this->cat('Travel', 'Hotel')->id]);
        $this->delete("/categories/{$travel->id}")->assertSessionHasNoErrors();
        $this->assertNull($t->fresh()->category_id);
        $this->assertSame(0, Category::where('name', 'Hotel')->count());
    }

    public function test_a_main_category_with_subcategories_cannot_be_moved_inside_another()
    {
        $this->signIn();
        $this->put('/categories/'.$this->cat('Travel')->id, ['name' => 'Travel', 'parent_id' => $this->cat('Home')->id])->assertSessionHasErrors('parent_id');
        $this->put('/categories/'.$this->cat('Travel')->id, ['name' => 'Travel', 'parent_id' => $this->cat('Travel', 'Hotel')->id])->assertSessionHasErrors('parent_id');
    }

    public function test_deleting_an_account_removes_its_transactions()
    {
        $this->signIn();
        $a = Account::create(['name' => 'Old', 'type' => 'savings']);
        Transaction::create(['account_id' => $a->id, 'date' => '2026-03-01', 'payee' => 'x', 'amount' => 100]);
        $this->delete("/accounts/{$a->id}")->assertRedirect();
        $this->assertDatabaseCount('transactions', 0);
    }
}
