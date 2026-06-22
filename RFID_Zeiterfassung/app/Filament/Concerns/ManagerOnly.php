<?php

namespace App\Filament\Concerns;

/**
 * Restricts a Filament resource or page to HR/Admin (people managers).
 * Apply to resources/pages that employees must not see.
 */
trait ManagerOnly
{
    public static function canAccess(): bool
    {
        return auth()->user()?->canManagePeople() ?? false;
    }
}
