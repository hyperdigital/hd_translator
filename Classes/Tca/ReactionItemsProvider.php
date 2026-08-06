<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tca;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Fills the site selector of the import reaction, so an endpoint can be confined to one site.
 */
class ReactionItemsProvider
{
    public function getSites(array &$configuration): void
    {
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $identifier => $site) {
            $title = $site->getConfiguration()['websiteTitle'] ?? '';
            $configuration['items'][] = [
                'label' => $title !== '' ? ($title . ' [' . $identifier . ']') : $identifier,
                'value' => $identifier,
            ];
        }
    }
}
