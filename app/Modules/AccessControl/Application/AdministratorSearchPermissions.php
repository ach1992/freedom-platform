<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

final class AdministratorSearchPermissions
{
    public const ORDER = 'administration.search.orders';

    public const PAYMENT = 'administration.search.payments';

    public const PAYMENT_EVIDENCE = 'administration.search.payment_evidence';

    public const SERVICE = 'administration.search.services';

    private function __construct() {}
}
