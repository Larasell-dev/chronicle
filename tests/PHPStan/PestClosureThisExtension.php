<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\PHPStan;

use Larasell\Chronicle\Tests\TestCase;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\FunctionParameterClosureThisExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Pest declares $this in test closures as TestCall, but at runtime it is the TestCase that
 * tests/Pest.php binds them to. This tells PHPStan the runtime type, so it checks the
 * $this->get(), $this->app and $this->actingAs() calls in tests/Feature.
 */
final class PestClosureThisExtension implements FunctionParameterClosureThisExtension
{
    private const FUNCTIONS = ['it', 'test', 'beforeEach', 'afterEach'];

    public function isFunctionSupported(FunctionReflection $functionReflection, ParameterReflection $parameter): bool
    {
        return in_array($functionReflection->getName(), self::FUNCTIONS, true) && $parameter->getName() === 'closure';
    }

    public function getClosureThisTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        ParameterReflection $parameter,
        Scope $scope,
    ): Type {
        return new ObjectType(TestCase::class);
    }
}
