<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use DomainException;

enum CatalogState: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /** @requirement CAT-001 CAT-002 */
    public function assertCanTransitionTo(self $target): void
    {
        $allowed = match ($this) {
            self::Draft => in_array($target, [self::Active, self::Archived], true),
            self::Active => $target === self::Archived,
            self::Archived => false,
        };

        if (! $allowed) {
            throw new DomainException('Catalog state transition is not allowed.');
        }
    }
}
