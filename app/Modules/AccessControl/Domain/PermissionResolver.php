<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Domain;

final readonly class PermissionResolver
{
    /**
     * Explicit deny always wins. Explicit allow overrides inherited role absence.
     * Inherit delegates to the union of active role grants.
     *
     * @param  iterable<PermissionEffect>  $overrides
     *
     * @requirement ACL-001 ACL-002 SEC-002
     */
    public function allows(bool $roleAllows, iterable $overrides): bool
    {
        $explicitAllow = false;

        foreach ($overrides as $effect) {
            if ($effect === PermissionEffect::Deny) {
                return false;
            }

            if ($effect === PermissionEffect::Allow) {
                $explicitAllow = true;
            }
        }

        return $explicitAllow || $roleAllows;
    }
}
