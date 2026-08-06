<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

/**
 * Checks a parsed XLIFF file before its content is written into the database.
 *
 * A file coming back from a translator is trusted blindly otherwise: a target that lost its
 * "%s" crashes the string at render time, a target longer than the column allows is cut off by
 * the database, and unbalanced markup breaks the page it lands on. None of that is visible in the
 * import result, which only counts inserts and updates.
 *
 * The findings are returned structured rather than as ready made sentences, so the labels stay in
 * the XLF files and the service itself needs no localization stack.
 */
class TranslationQaService
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /**
     * Elements that never carry a closing tag, so they must not be counted as unbalanced.
     */
    protected const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * XLIFF 2.x states that mean nobody has looked at the entry yet.
     */
    protected const UNFINISHED_STATES = [XlfService::STATE_INITIAL];

    /**
     * @param array $entries output of XlfService::parse()
     * @return array{findings: array<int, array{key: string, severity: string, type: string, arguments: array}>, failedKeys: array<int, string>, errors: int, warnings: int, checked: int}
     */
    public function check(array $entries): array
    {
        $findings = [];

        foreach ($entries as $key => $entry) {
            $key = (string)$key;
            $source = (string)($entry['source'] ?? '');
            $target = (string)($entry['target'] ?? '');

            foreach ($this->checkEntry($source, $target, $entry) as $finding) {
                $findings[] = ['key' => $key] + $finding;
            }
        }

        $failedKeys = [];
        $errors = 0;
        $warnings = 0;
        foreach ($findings as $finding) {
            if ($finding['severity'] === self::SEVERITY_ERROR) {
                $errors++;
                $failedKeys[$finding['key']] = $finding['key'];
            } else {
                $warnings++;
            }
        }

        return [
            'findings' => $findings,
            'failedKeys' => array_values($failedKeys),
            'errors' => $errors,
            'warnings' => $warnings,
            'checked' => count($entries),
        ];
    }

    /**
     * Merges the result of a second file into the result of the ones checked before, so an import
     * of several files or of a zip reports one list.
     */
    public function merge(array $carry, array $result): array
    {
        if (empty($carry)) {
            return $result;
        }

        return [
            'findings' => array_merge($carry['findings'], $result['findings']),
            'failedKeys' => array_values(array_unique(array_merge($carry['failedKeys'], $result['failedKeys']))),
            'errors' => $carry['errors'] + $result['errors'],
            'warnings' => $carry['warnings'] + $result['warnings'],
            'checked' => $carry['checked'] + $result['checked'],
        ];
    }

    /**
     * @return array<int, array{severity: string, type: string, arguments: array}>
     */
    protected function checkEntry(string $source, string $target, array $entry): array
    {
        $findings = [];

        if (trim($source) !== '' && trim($target) === '') {
            // the import skips those, so nothing is overwritten, but the translator should know
            return [$this->finding(self::SEVERITY_WARNING, 'emptyTarget', [])];
        }

        if (trim($target) === '') {
            return [];
        }

        $maxLength = (int)($entry['maxLength'] ?? 0);
        if ($maxLength > 0) {
            $length = mb_strlen($target);
            if ($length > $maxLength) {
                $findings[] = $this->finding(self::SEVERITY_ERROR, 'maxLengthExceeded', [$length, $maxLength]);
            }
        }

        $missing = $this->placeholderDifference($source, $target);
        $added = $this->placeholderDifference($target, $source);
        if (!empty($missing) || !empty($added)) {
            $findings[] = $this->finding(self::SEVERITY_ERROR, 'placeholderMismatch', [
                implode(', ', $missing) ?: '-',
                implode(', ', $added) ?: '-',
            ]);
        }

        if (!$this->markupIsBalanced($target)) {
            $findings[] = $this->finding(self::SEVERITY_ERROR, 'markupUnbalanced', []);
        } else {
            $lost = $this->tagDifference($source, $target);
            $gained = $this->tagDifference($target, $source);
            if (!empty($lost) || !empty($gained)) {
                $findings[] = $this->finding(self::SEVERITY_WARNING, 'markupMismatch', [
                    implode(', ', $lost) ?: '-',
                    implode(', ', $gained) ?: '-',
                ]);
            }
        }

        if (trim($source) !== '' && $source === $target) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, 'identicalToSource', []);
        }

        $state = (string)($entry['state'] ?? '');
        if (in_array($state, self::UNFINISHED_STATES, true)) {
            $findings[] = $this->finding(self::SEVERITY_WARNING, 'notTranslated', []);
        } elseif ($state !== '' && !XlfService::isApprovedState($state)) {
            // translated but nobody has reviewed it, which for static strings means TYPO3 keeps
            // showing the source until somebody does
            $findings[] = $this->finding(self::SEVERITY_WARNING, 'notApproved', []);
        }

        return $findings;
    }

    protected function finding(string $severity, string $type, array $arguments): array
    {
        return ['severity' => $severity, 'type' => $type, 'arguments' => $arguments];
    }

    /**
     * Placeholders of $a that $b does not have, counted, so a string losing one of its two "%s"
     * is reported as well.
     *
     * @return array<int, string>
     */
    protected function placeholderDifference(string $a, string $b): array
    {
        $inA = $this->countValues($this->extractPlaceholders($a));
        $inB = $this->countValues($this->extractPlaceholders($b));

        $missing = [];
        foreach ($inA as $placeholder => $count) {
            $difference = $count - ($inB[$placeholder] ?? 0);
            for ($i = 0; $i < $difference; $i++) {
                $missing[] = (string)$placeholder;
            }
        }

        return $missing;
    }

    /**
     * sprintf placeholders and the brace style used by Fluid and by javascript translations.
     *
     * @return array<int, string>
     */
    protected function extractPlaceholders(string $text): array
    {
        // an escaped percent is a literal, it must not look like a placeholder
        $text = str_replace('%%', '', $text);

        $found = [];

        if (preg_match_all('/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $text, $matches)) {
            $found = array_merge($found, $matches[0]);
        }

        if (preg_match_all('/\{[a-zA-Z0-9_.]+\}/', $text, $matches)) {
            $found = array_merge($found, $matches[0]);
        }

        return $found;
    }

    /**
     * Names of the elements in $a that $b does not have, counted.
     *
     * @return array<int, string>
     */
    protected function tagDifference(string $a, string $b): array
    {
        $inA = $this->countValues($this->extractTags($a));
        $inB = $this->countValues($this->extractTags($b));

        $missing = [];
        foreach ($inA as $tag => $count) {
            $difference = $count - ($inB[$tag] ?? 0);
            for ($i = 0; $i < $difference; $i++) {
                $missing[] = '<' . $tag . '>';
            }
        }

        return $missing;
    }

    /**
     * @return array<int, string> lowercased names of the opening tags, in order
     */
    protected function extractTags(string $text): array
    {
        if (!preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $text, $matches)) {
            return [];
        }

        return array_map('strtolower', $matches[1]);
    }

    /**
     * Whether every element the target opens is closed again, in the right order.
     */
    protected function markupIsBalanced(string $text): bool
    {
        if (!preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', $text, $matches, PREG_SET_ORDER)) {
            return true;
        }

        $stack = [];
        foreach ($matches as $match) {
            $isClosing = $match[1] === '/';
            $name = strtolower($match[2]);
            $selfClosing = str_ends_with(rtrim($match[3]), '/');

            if (in_array($name, self::VOID_ELEMENTS, true) || $selfClosing) {
                continue;
            }

            if ($isClosing) {
                if (array_pop($stack) !== $name) {
                    return false;
                }
                continue;
            }

            $stack[] = $name;
        }

        return empty($stack);
    }

    /**
     * @param array<int, string> $values
     * @return array<string, int>
     */
    protected function countValues(array $values): array
    {
        $counted = [];
        foreach ($values as $value) {
            $counted[$value] = ($counted[$value] ?? 0) + 1;
        }

        return $counted;
    }
}
