@extends('installer.layout')

@section('title', __('installer.preflight'))

@section('content')
    <h1>{{ __('installer.preflight') }}</h1>
    <ul>
        @foreach ($checks as $name => $passed)
            <li class="{{ $passed ? 'passed' : 'failed' }}">
                {{ __('installer.checks.'.$name) }}: {{ $passed ? __('installer.passed') : __('installer.failed') }}
            </li>
        @endforeach
    </ul>

    <h2>{{ __('installer.php_runtimes') }}</h2>
    @foreach ($runtimes as $runtime)
        <section>
            <h3>{{ __('installer.runtime.'.$runtime['name']) }}</h3>
            <dl>
                <dt>{{ __('installer.binary') }}</dt>
                <dd><code>{{ $runtime['binary'] }}</code></dd>
                <dt>{{ __('installer.version') }}</dt>
                <dd>{{ $runtime['php_version'] ?? __('installer.not_available') }}</dd>
                <dt>{{ __('installer.sapi') }}</dt>
                <dd>{{ $runtime['sapi'] ?? __('installer.not_available') }}</dd>
                <dt>{{ __('installer.ini_file') }}</dt>
                <dd><code>{{ $runtime['ini_file'] ?? __('installer.not_available') }}</code></dd>
                <dt>{{ __('installer.timezone') }}</dt>
                <dd>{{ $runtime['timezone'] ?? __('installer.not_available') }}</dd>
                <dt>{{ __('installer.missing_extensions') }}</dt>
                <dd>{{ $runtime['missing_extensions'] === [] ? __('installer.none') : implode(', ', $runtime['missing_extensions']) }}</dd>
                <dt>{{ __('installer.disabled_functions') }}</dt>
                <dd>{{ $runtime['disabled_functions'] === [] ? __('installer.none') : implode(', ', $runtime['disabled_functions']) }}</dd>
                <dt>{{ __('installer.opcache') }}</dt>
                <dd>{{ $runtime['opcache_enabled'] === null ? __('installer.not_available') : ($runtime['opcache_enabled'] ? __('installer.enabled') : __('installer.disabled')) }}</dd>
                <dt>{{ __('installer.runtime_status') }}</dt>
                <dd>{{ $runtime['passed'] ? __('installer.passed') : __('installer.failed') }}</dd>
                @if ($runtime['error'] !== null)
                    <dt>{{ __('installer.error_code') }}</dt>
                    <dd><code>{{ $runtime['error'] }}</code></dd>
                @endif
            </dl>
        </section>
    @endforeach
@endsection
