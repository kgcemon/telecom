@extends('layouts.panel')

@section('title', 'Add Account')
@section('heading', 'Add Account')
@section('subheading', 'Creates a ' . ($level?->defaultLabel() ?? 'sub-account') . ' beneath you')

@section('content')

<form method="POST" action="{{ route('reseller.downline.store') }}" class="card max-w-2xl">
    @csrf

    <div class="card-header"><h2 class="card-title">New {{ $level?->defaultLabel() }}</h2></div>

    <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-setting-field name="username" label="Username" required
            help="Letters, numbers, dot, dash and underscore only. Cannot be changed later." />
        <x-setting-field name="name" label="Full name" required />
        <x-setting-field name="mobile" label="Mobile" required />
        <x-setting-field name="email" label="Email" type="email" />

        <x-setting-field name="password" label="Password" type="password" required
            help="At least 8 characters. Share it securely; it is not shown again." />
        <x-setting-field name="password_confirmation" label="Confirm password" type="password" required />

        <x-setting-field name="pincode" label="Transaction PIN" type="password" required
            help="Digits only. They will need it to authorise every recharge." />
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <a href="{{ route('reseller.downline.index') }}" class="btn btn-ghost">Cancel</a>
        <button class="btn btn-primary">Create account</button>
    </div>
</form>

@endsection
