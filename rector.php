<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
	->withoutParallel()
	->withPaths([
		__DIR__ . '/public_html/wp-content/plugins/akibara-business/src',
		__DIR__ . '/public_html/wp-content/plugins/akibara-search/src',
		__DIR__ . '/public_html/wp-content/plugins/akibara-rewards/src',
		__DIR__ . '/public_html/wp-content/plugins/akibara-cart-checkout-blocks/src',
		__DIR__ . '/public_html/wp-content/themes/akibara-gp/inc',
		__DIR__ . '/public_html/wp-content/mu-plugins',
	])
	->withSkip([
		'*/tests/*',
		'*/vendor/*',
		'*/node_modules/*',
		'*/dist/*',
		'*/build/*',
		__DIR__ . '/public_html/wp-content/mu-plugins/hostinger-*.php',
		\Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector::class,
		\Rector\DeadCode\Rector\ClassMethod\RemoveUselessUnionReturnDocblockRector::class,
		\Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector::class,
		\Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector::class,
		\Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector::class,
		\Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector::class,
		\Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector::class,
		\Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector::class,
		\Rector\DeadCode\Rector\If_\ReduceAlwaysFalseIfOrRector::class,
		\Rector\DeadCode\Rector\BooleanAnd\RemoveAndTrueRector::class,
		\Rector\DeadCode\Rector\Cast\RecastingRemovalRector::class,
		\Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector::class,
	])
	->withSets([
		SetList::DEAD_CODE,
		SetList::EARLY_RETURN,
	]);
