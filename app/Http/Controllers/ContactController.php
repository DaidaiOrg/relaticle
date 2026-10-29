<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Plan;
use App\Features\Billing;
use App\Http\Requests\ContactRequest;
use App\Mail\NewContactSubmissionMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final readonly class ContactController
{
    public function show(Request $request): View
    {
        $requestedPlan = $request->query('plan');
        $plan = is_string($requestedPlan) ? Plan::tryFrom($requestedPlan) : null;

        return view('contact', [
            'inquiryPlan' => $plan?->isManaged() === true && Feature::active(Billing::class) ? $plan : null,
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, company: ?string, message: string} $data */
        $data = $request->validated();

        Mail::to(config('relaticle.contact.email'))->send(new NewContactSubmissionMail($data));

        return to_route('contact')->with('success', 'Thanks for reaching out! We\'ll get back to you soon.');
    }
}
