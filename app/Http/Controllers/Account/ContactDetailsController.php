<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContactDetailsRequest;
use App\Services\Audit\AuditLogger;
use App\Support\Account\ContactDetails;
use Illuminate\Http\RedirectResponse;

class ContactDetailsController extends Controller
{
    public function update(UpdateContactDetailsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $values = ContactDetails::only($request->validated());
        $changed = ContactDetails::changedGroups($user, $values);

        if ($changed !== []) {
            $user->forceFill($values)->save();
            $audit->record($user, 'account.contact_details_updated', $user, ['changed' => $changed], $request);
        }

        return back()->with('status', 'contact-details-updated');
    }
}
