<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['en', 'sw'])],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        $returnTo = $data['return_to'] ?? '/';
        if (! str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/';
        }

        return redirect()->to($returnTo);
    }
}
