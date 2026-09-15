<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class CurrentOwnerAuthorizer
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement ACL-002 SEC-002 */
    public function authorize(int $administratorId): void
    {
        if ($administratorId < 1) {
            throw new AuthorizationException('Only the current Owner may perform this action.');
        }

        /** @var list<object{id:int|string,status:string,is_owner:int|bool}> $owners */
        $owners = $this->database->connection()
            ->table('administrators')
            ->where('is_owner', true)
            ->lockForUpdate()
            ->get(['id', 'status', 'is_owner'])
            ->all();

        if (count($owners) !== 1) {
            throw new RuntimeException('Owner singleton invariant is violated.');
        }

        $owner = $owners[0];
        if ((int) $owner->id !== $administratorId || $owner->status !== 'active' || ! (bool) $owner->is_owner) {
            throw new AuthorizationException('Only the current Owner may perform this action.');
        }
    }
}
