@extends('layouts.panel')

@section('title', 'Profile')
@section('heading', 'Profile')
@section('subheading', 'Your contact details')

@section('content')

<form method="POST" action="{{ route('admin.profile.update') }}" class="card max-w-2xl">
    @csrf @method('PUT')

    <div class="card-header">
        <div>
            <h2 class="card-title">{{ $user->username }}</h2>
            <p class="text-xs text-subtle">{{ $user->level()?->defaultLabel() }} · account #{{ $user->id }}</p>
        </div>
    </div>

    <div class="card-body grid gap-5 sm:grid-cols-2">
        <x-setting-field name="name" label="Full name" :value="$user->name" required />
        <x-setting-field name="mobile" label="Mobile" :value="$user->mobile" required
            help="Used for one-time codes and for support to reach you." />
        <x-setting-field name="email" label="Email" type="email" :value="$user->email" class="sm:col-span-2" />
    </div>

    <div class="card-header justify-end border-t border-b-0">
        <button class="btn btn-primary">Save profile</button>
    </div>
</form>

@endsection
