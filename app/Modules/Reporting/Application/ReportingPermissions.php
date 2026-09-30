<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final class ReportingPermissions
{
    public const VIEW = 'reports.view';

    public const EXPORT = 'reports.export';

    public const DELIVER = 'reports.deliver';

    public const SCHEDULE = 'reports.schedule';

    private function __construct() {}
}
