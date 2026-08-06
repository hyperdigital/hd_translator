<?php
declare(strict_types=1);

use Hyperdigital\HdTranslator\Reaction\ImportTranslationReaction;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * EXT:reactions is a suggestion, not a dependency. Registering the reaction from the yaml file
 * would make the container fail to build wherever that extension is not installed, because the
 * class implements an interface that would not exist then.
 *
 * The container is built from an empty Symfony builder, so the only thing that can be asked at
 * this point is whether the interface is autoloadable at all, which is exactly the condition.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    if (!interface_exists(\TYPO3\CMS\Reactions\Reaction\ReactionInterface::class)) {
        return;
    }

    $configurator->services()
        ->set(ImportTranslationReaction::class)
        ->autowire()
        ->public()
        ->tag('reactions.reaction');
};
