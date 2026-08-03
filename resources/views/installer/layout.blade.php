<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;margin:0;padding:2rem}
        main{max-width:42rem;margin:auto;background:#fff;border:1px solid #dfe6e9;border-radius:.75rem;padding:1.5rem}
        label,input,button{display:block;width:100%;box-sizing:border-box}input,button{padding:.75rem;margin-top:.5rem}
        button{cursor:pointer;background:#155eef;color:#fff;border:0;border-radius:.4rem}.error,.failed{color:#b42318}.passed{color:#067647}
    </style>
</head>
<body><main>@yield('content')</main></body>
</html>
