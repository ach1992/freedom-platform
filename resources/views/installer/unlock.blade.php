@extends('installer.layout')

@section('title', __('installer.title'))

@section('content')
    <h1>{{ __('installer.title') }}</h1>
    <form method="post" action="{{ route('installer.unlock.submit') }}" autocomplete="off">
        @csrf
        <label for="token">{{ __('installer.token') }}</label>
        <input id="token" name="token" type="password" minlength="64" maxlength="128" required autofocus>
        @error('token')<p class="error">{{ $message }}</p>@enderror
        <button type="submit">{{ __('installer.unlock') }}</button>
    </form>
@endsection
