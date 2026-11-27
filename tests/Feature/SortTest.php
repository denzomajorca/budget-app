<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $a = Account::create(['name' => 'TFCU Checking', 'type' => 'checking']);
        $b = Account::create(['name' => 'CHASE Sapphire', 'type' => 'credit_card']);
        $sub = fn ($m, $s) => Category::whereNull('parent_id')->where('name', $m)->first()->children()->where('name', $s)->first()->id;
        foreach ([
            [$a, '2026-10-01', 'Publix', -3000, $sub('Food and Dining', 'Groceries')],
            [$b, '2026-10-03', 'Delta', -25000, $sub('Travel', 'Airfare')],
            [$a, '2026-10-02', 'Amazon', -1500, null],
            [$b, '2026-10-04', 'Starbucks', -500, $sub('Food and Dining', 'Coffee Shops')],
        ] as [$acct, $d, $p, $amt, $cat]) {
            Transaction::create(['account_id' => $acct->id, 'date' => $d, 'payee' => $p, 'amount' => $amt, 'category_id' => $cat]);
        }
    }

    private function payees(string $query): array
    {
        $payees = null;
        $this->get('/transactions?'.$query)->assertOk()->assertInertia(function ($p) use (&$payees) {
            $payees = array_column($p->toArray()['props']['transactions']['data'], 'payee');
        });

        return $payees;
    }

    public function test_each_column_sorts_both_ways()
    {
        $this->assertSame(['Starbucks', 'Delta', 'Amazon', 'Publix'], $this->payees(''), 'newest first by default');
        $this->assertSame(['Publix', 'Amazon', 'Delta', 'Starbucks'], $this->payees('sort=date&dir=asc'));
        $this->assertSame(['Amazon', 'Delta', 'Publix', 'Starbucks'], $this->payees('sort=payee&dir=asc'));
        $this->assertSame(['Starbucks', 'Publix', 'Delta', 'Amazon'], $this->payees('sort=payee&dir=desc'));
        $this->assertSame(['Delta', 'Publix', 'Amazon', 'Starbucks'], $this->payees('sort=amount&dir=asc'), 'biggest spending first');
        // Uncategorized first, then Food and Dining › Coffee Shops, › Groceries, then Travel.
        $this->assertSame(['Amazon', 'Starbucks', 'Publix', 'Delta'], $this->payees('sort=category&dir=asc'));
        $this->assertSame(['Delta', 'Publix', 'Starbucks', 'Amazon'], $this->payees('sort=category&dir=desc'));
        $this->assertSame(['Starbucks', 'Delta', 'Amazon', 'Publix'], $this->payees('sort=account&dir=asc'), 'CHASE before TFCU, newest first within');
    }

    public function test_sorting_works_with_filters_and_search()
    {
        $this->assertSame(['Publix', 'Starbucks'], $this->payees('sort=payee&dir=asc&category='.Category::where('name', 'Food and Dining')->value('id')));
        $this->assertSame(['Starbucks', 'Delta'], $this->payees('sort=amount&dir=desc&account='.Account::where('name', 'CHASE Sapphire')->value('id')));
        $this->assertSame(['Amazon', 'Delta', 'Starbucks'], $this->payees('sort=payee&dir=asc&q=a'));
        $this->get('/transactions?sort=nonsense')->assertSessionHasErrors('sort');
    }
}
