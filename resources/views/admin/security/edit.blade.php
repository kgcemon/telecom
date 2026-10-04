@extends('layouts.panel')

@section('title', 'Password & PIN')
@section('heading', 'Password & PIN')
@section('subheading', 'Changing either requires your current password')

@section('content')

<div class="grid gap-4 lg:grid-cols-2">

    <form method="POST" action="{{ route('admin.security.password') }}" class="card">
        @csrf @method('PUT')
        <div class="card-header"><h2 class="card-title">Change Password</h2></div>
        <div class="card-body space-y-4">
            <x-setting-field name="current_password" label="Current password" type="password" required />
            <x-setting-field name="password" label="New password" type="password" required
                :help="$security->requiresStrongPassword()
                    ? 'At least 8 characters, mixing letters, numbers and a symbol.'
                    : 'At least 8 characters.'" />
            <x-setting-field name="password_confirmation" label="Confirm new password" type="password" required />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary">Change password</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.security.pin') }}" class="card">
        @csrf @method('PUT')
        <div class="card-header"><h2 class="card-title">Change Transaction PIN</h2></div>
        <div class="card-body space-y-4">
            <x-setting-field name="current_password" label="Current password" type="password" required
                help="Your password, not your PIN." />
            <x-setting-field name="pin" label="New PIN" type="password" required
                :help="'Digits only, at least ' . $security->minimumPinLength() . '. Repeated digits and simple runs such as 1234 are rejected.'" />
            <x-setting-field name="pin_confirmation" label="Confirm new PIN" type="password" required />
        </div>
        <div class="card-header justify-end border-t border-b-0">
            <button class="btn btn-primary">Change PIN</button>
        </div>
    </form>
</div>

@endsection
