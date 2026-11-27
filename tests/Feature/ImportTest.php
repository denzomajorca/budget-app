<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CategoryPath;
use App\Support\TransferCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
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

    private function rows(): array
    {
        return [
            ['date' => '2025-10-16', 'amount' => -1999, 'payee' => 'Netflix', 'category' => 'Subscriptions:Streaming', 'memo' => null],
            ['date' => '2025-10-17', 'amount' => -4510, 'payee' => 'Publix', 'category' => 'Food and Dining:Groceries', 'memo' => 'weekly'],
            ['date' => '2025-10-17', 'amount' => -4510, 'payee' => 'Publix', 'category' => 'Food and Dining:Groceries', 'memo' => 'weekly'],
            ['date' => '2025-10-18', 'amount' => -25000, 'payee' => 'Delta', 'category' => 'Travel:(blank)', 'memo' => null],
            ['date' => '2025-10-19', 'amount' => -1200, 'payee' => 'Petco', 'category' => 'Pets:Pet Food', 'memo' => null],
            ['date' => '2025-10-20', 'amount' => 50000, 'payee' => 'Payment', 'category' => '', 'memo' => null],
        ];
    }

    public function test_category_text_is_split()
    {
        $this->assertSame(['Food and Dining', 'Groceries'], CategoryPath::split('Food and Dining:Groceries'));
        $this->assertSame(['Travel', null], CategoryPath::split('Travel:(blank)'));
        $this->assertSame(['Travel', null], CategoryPath::split('Travel'));
        $this->assertNull(CategoryPath::split(''));
        $this->assertNull(CategoryPath::split('(blank)'));
        $this->assertSame('income', CategoryPath::kindFor('Salary Income'));
    }

    public function test_import_adds_rows_keeps_repeats_and_creates_missing_categories()
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'Chase.csv', 'rows' => $this->rows(), 'settings' => ['sign' => 'neg-out']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(6, Transaction::count(), 'identical rows in one file are both kept');
        $pets = Category::whereNull('parent_id')->where('name', 'Pets')->first();
        $this->assertNotNull($pets);
        $this->assertSame('Pet Food', $pets->children()->first()->name);
        $this->assertSame(Category::whereNull('parent_id')->where('name', 'Travel')->value('id'), Transaction::where('payee', 'Delta')->value('category_id'));
        $this->assertNull(Transaction::where('payee', 'Payment')->value('category_id'));
        $this->assertSame(1, Category::whereNull('parent_id')->where('name', 'Food and Dining')->count(), 'existing categories are reused, any letter case');

        $import = Import::first();
        $this->assertSame([6, 2, '2025-10-16', '2025-10-20'], [$import->rows_imported, $import->categories_created, $import->first_date->format('Y-m-d'), $import->last_date->format('Y-m-d')]);
        $this->assertSame(['sign' => 'neg-out'], $this->card->fresh()->import_settings);
    }

    public function test_preview_flags_rows_already_imported_counting_repeats()
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => array_slice($this->rows(), 0, 2)]);

        $res = $this->postJson('/import/preview', ['account_id' => $this->card->id, 'rows' => $this->rows()])->assertOk();
        $this->assertSame([true, true, false, false, false, false], $res->json('duplicates'), 'second identical Publix row is new');
        $this->assertEqualsCanonicalizing(['Pets', 'Pets › Pet Food'], $res->json('new_categories'));

        // Another account is separate
        $other = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $res = $this->postJson('/import/preview', ['account_id' => $other->id, 'rows' => $this->rows()]);
        $this->assertNotContains(true, $res->json('duplicates'));
    }

    public function test_bad_rows_are_rejected()
    {
        $rows = $this->rows();
        $rows[1]['date'] = '10/17/25';
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'x.csv', 'rows' => $rows])->assertSessionHasErrors('rows.1.date');
        $this->assertSame(0, Transaction::count());
    }

    public function test_undo_removes_only_that_imports_transactions()
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => $this->rows()]);
        Transaction::create(['account_id' => $this->card->id, 'date' => '2025-11-01', 'payee' => 'Typed in', 'amount' => -100]);

        $this->delete('/import/'.Import::first()->id)->assertRedirect('/import');
        $this->assertSame(['Typed in'], Transaction::pluck('payee')->all());
        $this->assertSame(0, Import::count());
    }

    public function test_transactions_can_be_filtered_by_import()
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => $this->rows()]);
        Transaction::create(['account_id' => $this->card->id, 'date' => '2025-11-01', 'payee' => 'Typed in', 'amount' => -100]);
        $count = $this->get('/transactions?import='.Import::first()->id)->viewData('page')['props']['totals']['count'];
        $this->assertSame(6, $count);
    }

    public function test_balance_can_be_matched_to_the_bank()
    {
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => $this->rows()]);
        // Card transactions sum: -1999-4510-4510-25000-1200+50000 = 12781. Bank says $300 owed on Oct 20.
        $this->post("/accounts/{$this->card->id}/balance", ['balance' => '300.00', 'as_of' => '2025-10-20'])->assertSessionHasNoErrors();
        $card = $this->card->fresh();
        $this->assertSame(-30000 - 12781, $card->opening_balance);
        $this->assertSame('2025-10-15', $card->opening_date->format('Y-m-d'));
        $this->assertSame(-30000, $card->balance());

        // A later transaction after the as-of date doesn't change the starting balance.
        $checking = Account::create(['name' => 'CHASE Checking', 'type' => 'checking']);
        Transaction::create(['account_id' => $checking->id, 'date' => '2026-01-01', 'payee' => 'a', 'amount' => 10000]);
        Transaction::create(['account_id' => $checking->id, 'date' => '2026-02-01', 'payee' => 'b', 'amount' => -2500]);
        $this->post("/accounts/{$checking->id}/balance", ['balance' => '1,000', 'as_of' => '2026-01-15']);
        $this->assertSame(100000 - 10000, $checking->fresh()->opening_balance);
        $this->assertSame(100000 - 2500, $checking->fresh()->balance());
    }

    public function test_import_page_loads()
    {
        $this->get('/import')->assertOk();
    }

    public function test_match_finds_the_account_that_already_has_the_rows()
    {
        $checking = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => $this->rows()]);
        Transaction::create(['account_id' => $checking->id, 'date' => '2025-09-01', 'payee' => 'Employer Payroll 1234', 'amount' => 100000]);

        $res = $this->postJson('/import/match', ['files' => [
            ['rows' => array_slice($this->rows(), 0, 4)],
            ['rows' => [['date' => '2025-11-01', 'amount' => 100000, 'payee' => 'EMPLOYER PAYROLL 9876']]],
        ]])->assertOk()->json();

        $this->assertSame(4, $res['files'][0]['exact'][$this->card->id]);
        $this->assertSame(0, $res['files'][0]['exact'][$checking->id]);
        $this->assertSame(0, $res['files'][1]['exact'][$checking->id]);
        $this->assertSame(1, $res['files'][1]['familiar'][$checking->id], 'payees are compared without numbers or case');
        $this->assertSame(0, $res['files'][1]['familiar'][$this->card->id]);
    }

    public function test_batch_imports_each_file_into_its_account_and_skips_rows_already_there()
    {
        $checking = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $this->post('/import', ['account_id' => $this->card->id, 'file_name' => 'a.csv', 'rows' => array_slice($this->rows(), 0, 2)]);

        $this->post('/import/batch', ['files' => [
            ['account_id' => $this->card->id, 'file_name' => 'card.csv', 'rows' => $this->rows()],
            ['account_id' => $checking->id, 'file_name' => 'checking.csv', 'rows' => [
                ['date' => '2025-10-20', 'amount' => -50000, 'payee' => 'Chase payment', 'category' => 'Transfer:Credit Card Payment'],
            ]],
            ['account_id' => $checking->id, 'file_name' => 'old.csv', 'rows' => [
                ['date' => '2025-10-20', 'amount' => -50000, 'payee' => 'Chase payment', 'category' => 'Transfer:Credit Card Payment'],
            ]],
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(6, Transaction::where('account_id', $this->card->id)->count(), 'the two rows already there were skipped');
        $this->assertSame(1, Transaction::where('account_id', $checking->id)->count(), 'a second file with the same rows adds nothing');
        $card = Import::where('file_name', 'card.csv')->first();
        $this->assertSame(4, $card->rows_imported);
        $this->assertSame(2, $card->rows_skipped);
        $this->assertNull(Import::where('file_name', 'old.csv')->first());

        $page = $this->get('/import?batch='.Import::where('file_name', 'card.csv')->value('id').','.Import::where('file_name', 'checking.csv')->value('id'))
            ->assertOk()->viewData('page')['props'];
        $this->assertCount(2, $page['batch']);
    }

    public function test_batch_needs_an_account_for_every_file()
    {
        $this->post('/import/batch', ['files' => [['file_name' => 'x.csv', 'rows' => $this->rows()]]])
            ->assertSessionHasErrors('files.0.account_id');
        $this->assertSame(0, Transaction::count());
    }
}
