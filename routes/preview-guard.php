<?php

use App\Http\Controllers\PreviewGuardController;
use Illuminate\Support\Facades\Route;

// Traefik ForwardAuth target. Registered outside the `web` group on purpose: it is called for
// every request to a protected app and must not start sessions or touch the app's cookies.
Route::get('/preview-guard/verify', [PreviewGuardController::class, 'check'])->name('preview-guard.verify');
