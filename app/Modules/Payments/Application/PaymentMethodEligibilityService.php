<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;

final readonly class PaymentMethodEligibilityService
{
    use PaymentMethodEligibilityManagement;
    use PaymentMethodEligibilityPersistence;
    use PaymentMethodEligibilityResolution;

    private const MANAGE_PERMISSION = 'payments.eligibility.manage';

    private const FORMULA_VERSION = 'pay-001-eligibility-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}
}
