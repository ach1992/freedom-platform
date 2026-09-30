<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final class OperationsPermissions
{
    public const VIEW = 'operations.view';

    public const ALERTS_MANAGE = 'operations.alerts.manage';

    public const ACTIONS_EXECUTE = 'operations.actions.execute';

    private function __construct() {}
}
