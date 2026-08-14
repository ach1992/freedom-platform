<?php

declare(strict_types=1);

namespace App\PHPStan;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;

final class DatabaseQueryBuilderExpressionTypeExtension implements ExpressionTypeResolverExtension
{
    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if (! $expr instanceof MethodCall || ! $expr->name instanceof Identifier) {
            return null;
        }

        $builderType = new ObjectType(Builder::class);
        if (! $builderType->isSuperTypeOf($scope->getType($expr->var))->yes()) {
            return null;
        }

        $rowType = new ObjectType(stdClass::class);

        return match ($expr->name->toString()) {
            'first', 'find' => TypeCombinator::union($rowType, new NullType),
            'sole' => $rowType,
            'get' => new GenericObjectType(Collection::class, [new IntegerType, $rowType]),
            default => null,
        };
    }
}
