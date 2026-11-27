<?php

namespace App\Http\Controllers;

use App\Models\RetirementPlan;
use App\Support\Retirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RetirementController extends Controller
{
    public function index(): Response
    {
        $facts = Retirement::facts();
        $defaults = Retirement::defaults($facts);
        $saved = array_intersect_key(RetirementPlan::current()->inputs ?? [], $defaults);
        $inputs = self::typed($saved + $defaults);

        return Inertia::render('Retirement/Index', [
            'facts' => $facts,
            'defaults' => $defaults,
            'inputs' => $inputs,
            'plan' => Retirement::plan($inputs, $facts),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(Retirement::RULES, [
            'birth_year.between' => 'Type the year you were born, like 1965.',
        ]);
        $plan = RetirementPlan::current();
        $plan->inputs = self::typed($data);
        $plan->save();

        return back();
    }

    /** Clears your answers so the app's own numbers are used again. */
    public function reset(): RedirectResponse
    {
        $plan = RetirementPlan::current();
        $birth = $plan->inputs['birth_year'] ?? null;
        $plan->inputs = ['birth_year' => $birth];
        $plan->save();

        return back();
    }

    private static function typed(array $in): array
    {
        foreach ($in as $k => $v) {
            if ($v === null || $v === '') {
                $in[$k] = null;
            } elseif (in_array($k, ['stock_return', 'bond_return', 'inflation'], true)) {
                $in[$k] = (float) $v;
            } else {
                $in[$k] = (int) $v;
            }
        }

        return $in;
    }
}
