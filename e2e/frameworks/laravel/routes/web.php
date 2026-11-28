<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

use function Runio\ScriptUi\respond;

/*
 * A login for the tests that needs no table of users: who is logged in is a
 * name in the session. $request->user() hands out a user object of Laravel
 * for it, as it does in an application with a table.
 */
Auth::viaRequest('name-in-session', static function (Request $request): ?GenericUser {
    $name = $request->hasSession() ? $request->session()->get('test_user') : null;

    return is_string($name) ? new GenericUser(['id' => $name]) : null;
});
config(['auth.guards.web' => ['driver' => 'name-in-session']]);

Gate::define('open-tool', static fn (GenericUser $user, string $tool): bool => $tool !== 'payroll' || $user->getAuthIdentifier() === 'ava');

Route::get('/login-as/{name}', static function (Request $request, string $name) {
    $request->session()->put('test_user', $name);

    return redirect('/admin/tools');
});

Route::get('/logout', static function (Request $request) {
    $request->session()->invalidate();

    return redirect('/admin/tools');
});

/*
 * The tools, as the README shows it for Laravel.
 */
Route::match(['get', 'post'], '/admin/tools/{tool?}', static function (Request $request) {
    $page = respond(
        base_path('tools'),
        base: '/admin/tools',
        auth: static fn () => $request->user(),
        allow: static fn ($user, string $tool): bool => Gate::forUser($user)->allows('open-tool', $tool),
        variables: ['app' => 'Laravel ' . app()->version()],
        session: $request->session(),
    );

    return response($page->body, $page->status, $page->headers);
})->block();
