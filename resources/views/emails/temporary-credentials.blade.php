<x-mail::message>
# {{ $isReset ? 'Your password has been reset' : 'Welcome to Coliconstruct' }}

Hello {{ $account->fullName() }},

@if ($isReset)
An administrator has reset the password on your Coliconstruct account. Use the
temporary password below to sign in.
@else
An account has been created for you on the Coliconstruct Project Management
System. Use the details below to sign in for the first time.
@endif

**User ID:** {{ $account->user_code }}
**Email:** {{ $account->email }}
**Temporary password:** {{ $temporaryPassword }}

<x-mail::button :url="$loginUrl">
Sign in
</x-mail::button>

You will be asked to choose a new password the first time you sign in. This
temporary password stops working at that point, so there is no need to keep it.

If you were not expecting this message, please contact your administrator.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
