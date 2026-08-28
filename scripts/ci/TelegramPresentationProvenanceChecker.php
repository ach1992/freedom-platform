<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Semantic defense-in-depth for the generic non-restricted Telegram presentation boundary.
 *
 * The primary ArchitectureBoundaryChecker owns the repository-wide boundary graph and exact
 * reviewed-source allowlist. This guard closes equivalent dynamic callable/container paths and
 * constant-folded protected symbol construction so the provenance rule is not dependent on one
 * spelling of a PHP/Laravel resolution mechanism.
 */
final class TelegramPresentationProvenanceChecker
{
    /** @var list<string> */
    private const PROTECTED_SYMBOLS = [
        'NonRestrictedTelegramPresentation',
        'NonRestrictedTelegramPresentationFactory',
        'NonRestrictedTelegramPresentationSource',
        'TelegramDeliveryQueueService',
    ];

    /** @var array<string,list<string>> */
    private const RESTRICTED_METHOD_PATHS = [
        'fromReviewedSource' => [
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationFactory.php',
        ],
        'restorePersisted' => [
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
            'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
        ],
    ];

    /** @var list<string> */
    private const INTERNAL_PATHS = [
        'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
        'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationFactory.php',
        'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationSource.php',
        'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
        'app/Modules/Telegram/Application/TelegramDeliveryOutboxHandler.php',
        'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
        'app/Modules/Telegram/Application/TelegramMutationRequest.php',
    ];

    /** @var list<string> */
    private const CONTAINER_TYPES = [
        'Illuminate\\Foundation\\Application',
        'Illuminate\\Contracts\\Container\\Container',
        'Illuminate\\Container\\Container',
        'Illuminate\\Support\\Facades\\App',
        'Psr\\Container\\ContainerInterface',
    ];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {}

    /** @return list<string> */
    public function violations(): array
    {
        $violations = [];

        foreach (['app/Modules', 'app/Shared', 'routes'] as $directory) {
            foreach ($this->phpFiles($directory) as $relativePath => $source) {
                $this->scanFile($relativePath, $source, $violations);
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return $violations;
    }

    /** @param list<string> $violations */
    private function scanFile(string $relativePath, string $source, array &$violations): void
    {
        $tokens = $this->tokens($source);
        $foldedStrings = $this->foldedStringRuns($tokens);

        foreach (self::RESTRICTED_METHOD_PATHS as $method => $allowedPaths) {
            if ($this->foldedStringsContain($foldedStrings, $method)
                && ! in_array($relativePath, $allowedPaths, true)
            ) {
                $violations[] = sprintf(
                    '%s constant-folded internal Telegram presentation method %s is forbidden; use the reviewed source/factory boundary instead.',
                    $relativePath,
                    $method,
                );
            }
        }

        $usesFoldedProtectedBoundary = false;
        foreach (self::PROTECTED_SYMBOLS as $symbol) {
            if ($this->foldedStringsContain($foldedStrings, $symbol)) {
                $usesFoldedProtectedBoundary = true;
                break;
            }
        }

        if ($usesFoldedProtectedBoundary && ! in_array($relativePath, self::INTERNAL_PATHS, true)) {
            if (! str_starts_with($relativePath, 'app/Modules/Telegram/')) {
                $violations[] = sprintf(
                    '%s constant-folded generic Telegram presentation symbol bypasses module provenance; generic non-restricted delivery is Telegram-owned.',
                    $relativePath,
                );
            } else {
                $sources = $this->config['telegram_non_restricted_presentation_sources'] ?? [];
                if (! is_array($sources) || ! in_array($relativePath, $sources, true)) {
                    $violations[] = sprintf(
                        '%s constant-folded generic Telegram presentation symbol is not from an exact reviewed Telegram source path.',
                        $relativePath,
                    );
                }
            }
        }

        $this->scanDynamicCallableResolution($relativePath, $tokens, $source, $violations);
        $this->scanDynamicContainerResolution($relativePath, $tokens, $source, $violations);
    }

    /**
     * @param list<array{id:int|null,text:string,line:int|null}> $tokens
     * @param list<string> $violations
     */
    private function scanDynamicCallableResolution(
        string $relativePath,
        array $tokens,
        string $source,
        array &$violations,
    ): void {
        $aliases = $this->importAliases($source);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $id = $token['id'];
            $text = strtolower($token['text']);
            $line = (int) ($token['line'] ?? 1);

            if ($id === T_STRING
                && $text === 'fromcallable'
                && ($tokens[$index - 1]['id'] ?? null) === T_DOUBLE_COLON
                && ($tokens[$index + 1]['text'] ?? null) === '('
            ) {
                $class = $this->resolvedStaticClass($tokens, $index - 2, $aliases);
                if ($class !== null && strtolower(ltrim($class, '\\')) === 'closure') {
                    $violations[] = sprintf(
                        '%s:%d Telegram presentation provenance forbids Closure::fromCallable dynamic callable construction in production source.',
                        $relativePath,
                        $line,
                    );
                }
            }
        }
    }

    /**
     * @param list<array{id:int|null,text:string,line:int|null}> $tokens
     * @param list<string> $violations
     */
    private function scanDynamicContainerResolution(
        string $relativePath,
        array $tokens,
        string $source,
        array &$violations,
    ): void {
        if (! $this->referencesContainerType($source)) {
            return;
        }

        $aliases = $this->importAliases($source);
        [$receiverVariables, $receiverProperties] = $this->containerReceivers($source, $aliases);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $id = $token['id'];
            $line = (int) ($token['line'] ?? 1);

            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                && ($tokens[$index + 1]['id'] ?? null) === T_STRING
                && in_array(strtolower((string) ($tokens[$index + 1]['text'] ?? '')), ['make', 'makewith', 'get', 'offsetget'], true)
                && ($tokens[$index + 2]['text'] ?? null) === '('
            ) {
                $argumentIndex = $index + 3;
                if (! $this->isStaticContainerResolutionArgument($tokens, $argumentIndex)) {
                    $method = (string) $tokens[$index + 1]['text'];
                    $violations[] = sprintf(
                        '%s:%d Telegram presentation provenance forbids dynamic container %s%s(...); use a static Class::class/literal reviewed resolution.',
                        $relativePath,
                        $line,
                        $id === T_DOUBLE_COLON ? '::' : '->',
                        $method,
                    );
                }
            }

            if ($id === T_STRING
                && strtolower($token['text']) === 'getinstance'
                && ($tokens[$index - 1]['id'] ?? null) === T_DOUBLE_COLON
                && ($tokens[$index + 1]['text'] ?? null) === '('
                && ($tokens[$index + 2]['text'] ?? null) === ')'
                && ($tokens[$index + 3]['text'] ?? null) === '['
                && ($tokens[$index + 4]['id'] ?? null) === T_VARIABLE
            ) {
                $class = $this->resolvedStaticClass($tokens, $index - 2, $aliases);
                if ($class !== null && $this->isContainerClass($class)) {
                    $violations[] = sprintf(
                        '%s:%d Telegram presentation provenance forbids dynamic container ArrayAccess through %s::getInstance()[$variable].',
                        $relativePath,
                        $line,
                        ltrim($class, '\\'),
                    );
                }
            }

            if ($id === T_VARIABLE
                && isset($receiverVariables[$token['text']])
                && ($tokens[$index + 1]['text'] ?? null) === '['
                && ($tokens[$index + 2]['id'] ?? null) === T_VARIABLE
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids dynamic container ArrayAccess through %s[$variable].',
                    $relativePath,
                    $line,
                    $token['text'],
                );
            }

            if ($id === T_VARIABLE
                && $token['text'] === '$this'
                && ($tokens[$index + 1]['id'] ?? null) === T_OBJECT_OPERATOR
                && ($tokens[$index + 2]['id'] ?? null) === T_STRING
                && isset($receiverProperties[$tokens[$index + 2]['text']])
                && ($tokens[$index + 3]['text'] ?? null) === '['
                && ($tokens[$index + 4]['id'] ?? null) === T_VARIABLE
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids dynamic container ArrayAccess through $this->%s[$variable].',
                    $relativePath,
                    $line,
                    $tokens[$index + 2]['text'],
                );
            }
        }
    }

    /**
     * @param list<array{id:int|null,text:string,line:int|null}> $tokens
     */
    private function isStaticContainerResolutionArgument(array $tokens, int $index): bool
    {
        $argument = $tokens[$index] ?? null;
        if ($argument === null) {
            return false;
        }

        if ($argument['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return true;
        }

        return in_array($argument['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && ($tokens[$index + 1]['id'] ?? null) === T_DOUBLE_COLON
            && ($tokens[$index + 2]['id'] ?? null) === T_CLASS;
    }

    private function referencesContainerType(string $source): bool
    {
        foreach (self::CONTAINER_TYPES as $type) {
            if ($this->containsCodeTokenText($source, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $aliases
     * @return array{array<string,true>,array<string,true>}
     */
    private function containerReceivers(string $source, array $aliases): array
    {
        $variables = [];
        $properties = [];
        $typeNames = [];

        foreach (self::CONTAINER_TYPES as $fqcn) {
            $separator = strrpos($fqcn, '\\');
            $typeNames[] = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
            $typeNames[] = '\\'.$fqcn;
            foreach ($aliases as $alias => $target) {
                if (strcasecmp(ltrim($target, '\\'), $fqcn) === 0) {
                    $typeNames[] = $alias;
                }
            }
        }

        foreach (array_values(array_unique($typeNames)) as $typeName) {
            $pattern = '/(?<![A-Za-z0-9_\\\\])'.preg_quote($typeName, '/').'\\s+\\$([A-Za-z_][A-Za-z0-9_]*)/';
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $name) {
                if (! is_string($name)) {
                    continue;
                }
                $variables['$'.$name] = true;
                $properties[$name] = true;
            }
        }

        return [$variables, $properties];
    }

    /** @return array<string,string> */
    private function importAliases(string $source): array
    {
        $aliases = [];
        preg_match_all(
            '/\\buse\\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\\s+as\\s+([A-Za-z_][A-Za-z0-9_]*))?\\s*;/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $fqcn = ltrim((string) ($match[1] ?? ''), '\\');
            if ($fqcn === '') {
                continue;
            }
            $alias = (string) ($match[2] ?? '');
            if ($alias === '') {
                $separator = strrpos($fqcn, '\\');
                $alias = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
            }
            $aliases[$alias] = $fqcn;
        }

        return $aliases;
    }

    /**
     * @param list<array{id:int|null,text:string,line:int|null}> $tokens
     * @param array<string,string> $aliases
     */
    private function resolvedStaticClass(array $tokens, int $index, array $aliases): ?string
    {
        $token = $tokens[$index] ?? null;
        if ($token === null || ! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $name = ltrim($token['text'], '\\');
        if (str_contains($name, '\\')) {
            return $name;
        }

        return $aliases[$name] ?? $name;
    }

    private function isContainerClass(string $class): bool
    {
        $class = ltrim($class, '\\');
        foreach (self::CONTAINER_TYPES as $type) {
            if (strcasecmp($class, $type) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{id:int|null,text:string,line:int|null}> */
    private function tokens(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
                if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }
                $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line];
            } else {
                $tokens[] = ['id' => null, 'text' => $token, 'line' => null];
            }
        }

        return $tokens;
    }

    /**
     * @param list<array{id:int|null,text:string,line:int|null}> $tokens
     * @return list<string>
     */
    private function foldedStringRuns(array $tokens): array
    {
        $folded = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (($tokens[$index]['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = $this->decodeStringLiteral($tokens[$index]['text']);
            if ($value === null) {
                continue;
            }

            $cursor = $index;
            $parts = 1;
            while (($tokens[$cursor + 1]['text'] ?? null) === '.'
                && ($tokens[$cursor + 2]['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING
            ) {
                $next = $this->decodeStringLiteral($tokens[$cursor + 2]['text']);
                if ($next === null) {
                    break;
                }
                $value .= $next;
                $parts++;
                $cursor += 2;
            }

            if ($parts > 1) {
                $folded[] = $value;
                $index = $cursor;
            }
        }

        return array_values(array_unique($folded));
    }

    private function decodeStringLiteral(string $literal): ?string
    {
        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }

        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }

    /** @param list<string> $foldedStrings */
    private function foldedStringsContain(array $foldedStrings, string $needle): bool
    {
        foreach ($foldedStrings as $value) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsCodeTokenText(string $source, string $needle): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string,string> */
    private function phpFiles(string $relativeDirectory): iterable
    {
        $directory = $this->root.'/'.$relativeDirectory;
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $source = file_get_contents($path);
            if (! is_string($source)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($path, strlen($this->root) + 1));
            $files[$relativePath] = $source;
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
