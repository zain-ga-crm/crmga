<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\ChangePassword;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * S-1.2's forced first-login step (crmga_Frontend_Design_Spec.docx §7): a
 * user whose password was system-generated (User::mustChangePassword())
 * cannot reach anything else in the panel until they set their own.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->mustChangePassword()
            && ! $request->is(ltrim((string) parse_url(ChangePassword::getUrl(), PHP_URL_PATH), '/'))
        ) {
            return redirect(ChangePassword::getUrl());
        }

        return $next($request);
    }
}
