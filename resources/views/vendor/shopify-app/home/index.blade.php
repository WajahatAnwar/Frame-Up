@extends('shopify-app::layouts.default')

@section('styles')
    @include('shopify-app::partials.laravel_skeleton_css')
    <style>
        .diagnostic { max-width: 42rem; margin: 3rem auto; padding: 2rem; }
        .diagnostic button { padding: .65rem 1rem; cursor: pointer; }
        .diagnostic [role="status"] { margin-top: 1rem; min-height: 1.5rem; }
    </style>
@endsection

@section('content')
    <div class="diagnostic">
        <h1>{{ config('shopify-app.app_name') }}</h1>
        <p>Embedded Shopify application connected to <strong>{{ $shop->name }}</strong>.</p>
        <h2>Temporary authentication check</h2>
        <p>Verifies a fresh Shopify App Bridge session token against the Laravel API.</p>
        <button id="temporary-auth-check" type="button">Test authenticated route</button>
        <div id="temporary-auth-result" role="status" aria-live="polite"></div>
    </div>
@endsection

@section('scripts')
    <script>
        (() => {
            const button = document.getElementById('temporary-auth-check');
            const result = document.getElementById('temporary-auth-result');

            button.addEventListener('click', async () => {
                button.disabled = true;
                result.textContent = 'Checking…';
                try {
                    const token = await window.shopify.sessionToken.getToken();
                    const response = await fetch('/api/temporary-auth-check', {
                        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
                        credentials: 'same-origin',
                    });
                    if (response.status === 401 || response.status === 403) throw new Error('Shopify authentication was rejected. Reload the app and try again.');
                    if (response.status === 419) throw new Error('The session expired. Reload the app and try again.');
                    if (response.status === 429) throw new Error('Too many checks. Wait a moment and try again.');
                    if (!response.ok) throw new Error('The authentication check failed.');
                    const payload = await response.json();
                    result.textContent = payload.ok ? `Success: ${payload.message}` : 'The authentication check failed.';
                } catch (error) {
                    result.textContent = error instanceof TypeError ? 'Network error. Check the app connection.' : error.message;
                    if (window.console && window.console.debug) window.console.debug('Temporary auth check failed.', { status: result.textContent });
                } finally {
                    button.disabled = false;
                }
            });
        })();
    </script>
@endsection
