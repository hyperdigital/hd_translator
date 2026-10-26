<?php
// USE cannot be used, because of duplicated namings in cached files
//use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
//use TYPO3\CMS\Core\Core\Environment;

defined('TYPO3') or die();

(function () {
    // The docheader button is added through the ModifyButtonBarEvent listener registered in
    // Configuration/Services.yaml. The former getButtonsHook was removed in TYPO3 v12.

    \Hyperdigital\HdTranslator\Helpers\TranslationHelper::setupTranslation();

    $GLOBALS['TYPO3_CONF_VARS']['BE']['stylesheets']['hd_translator'] = 'EXT:hd_translator/Resources/Public/Css/Backend/';

    // Counter of the request throttle. It has to survive between requests, which rules out the
    // transient backend; losing it is otherwise harmless and only grants somebody another minute.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][\Hyperdigital\HdTranslator\Services\RequestThrottleService::CACHE] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => 120],
        'groups' => ['system'],
    ];

    $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['_hdtranslator_fetchSupportedLanguages'] =
        \Hyperdigital\HdTranslator\Eid\DeeplApiEid::class . '::fetchSupportedLanguages';

    $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['_hdtranslator_translate'] =
        \Hyperdigital\HdTranslator\Eid\DeeplApiEid::class . '::translate';
})();
