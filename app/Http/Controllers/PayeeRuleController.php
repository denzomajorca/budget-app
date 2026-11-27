<?php

namespace App\Http\Controllers;

use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Support\Transfers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayeeRuleController extends Controller
{
    /**
     * Save "payee contains X → category". Transactions still waiting for
     * review that match are filed straight away.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'match' => ['required', 'string', 'min:2', 'max:100'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
        ]);
        $match = PayeeRule::normalize($data['match']);
        PayeeRule::updateOrCreate(['match' => $match], ['category_id' => $data['category_id']]);

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $match).'%';
        $n = Transaction::where('needs_review', true)->whereNull('transfer_id')
            ->whereRaw('lower(payee) like ?', [$like])
            ->update(['category_id' => $data['category_id'], 'needs_review' => false]);
        Transfers::pair();

        return back()->with('status', 'Rule saved. Future imports will use it'.($n ? ', and '.($n === 1 ? '1 more transaction' : $n.' more transactions').' waiting for review got the same category.' : '.'));
    }

    public function destroy(PayeeRule $payeeRule): RedirectResponse
    {
        $payeeRule->delete();

        return back()->with('status', 'Rule removed.');
    }
}
