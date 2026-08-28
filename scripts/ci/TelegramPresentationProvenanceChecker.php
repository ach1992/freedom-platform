<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Semantic defense-in-depth for the generic non-restricted Telegram presentation boundary.
 * Runtime construction/restoration capability checks are authoritative; this checker blocks
 * equivalent dynamic resolver/callable forms before they can become production dependencies.
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
                $this->scan($relativePath, $source, $violations);
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return $violations;
    }

    /** @param list<string> $violations */
    private function scan(string $relativePath, string $source, array &$violations): void
    {
        $tokens = $this->tokens($source);
        $folded = $this->foldedStringRuns($tokens);
        $foldedRestrictedMethod = false;

        foreach (self::RESTRICTED_METHOD_PATHS as $method => $allowedPaths) {
            if (! $this->containsFolded($folded, $method)) {
                continue;
            }

            $foldedRestrictedMethod = true;
            if (! in_array($relativePath, $allowedPaths, true)) {
                $violations[] = sprintf(
                    '%s constant-folded internal Telegram presentation method %s is forbidden; use the reviewed source/factory boundary instead.',
                    $relativePath,
                    $method,
                );
            }
        }

        $foldedProtected = false;
        foreach (self::PROTECTED_SYMBOLS as $symbol) {
            if ($this->containsFolded($folded, $symbol)) {
                $foldedProtected = true;
                break;
            }
        }

        if ($foldedProtected && ! in_array($relativePath, self::INTERNAL_PATHS, true)) {
            if (! str_starts_with($relativePath, 'app/Modules/Telegram/')) {
                $violations[] = sprintf(
                    '%s constant-folded generic Telegram presentation symbol bypasses module provenance; generic non-restricted delivery is Telegram-owned.',
                    $relativePath,
                );
            } else {
                $sources = $this->reviewedSources();
                if (! in_array($relativePath, $sources, true)) {
                    $violations[] = sprintf(
                        '%s constant-folded generic Telegram presentation symbol is not from an exact reviewed Telegram source path.',
                        $relativePath,
                    );
                }
            }
        }

        $provenanceSensitive = in_array($relativePath, self::INTERNAL_PATHS, true)
            || in_array($relativePath, $this->reviewedSources(), true)
            || $foldedProtected
            || $foldedRestrictedMethod
            || $this->referencesProtectedSymbol($source);

        if (! $provenanceSensitive) {
            return;
        }

        $this->scanClosureMechanisms($relativePath, $tokens, $violations);
        if ($this->referencesContainer($source)) {
            $this->scanContainerMechanisms($relativePath, $tokens, $violations);
        }
    }

    /**
     * @param  list<array{id:int|null,text:string,line:int|null}>  $tokens
     * @param  list<string>  $violations
     */
    private function scanClosureMechanisms(string $relativePath, array $tokens, array &$violations): void
    {
        foreach ($tokens as $index => $token) {
            $id = $token['id'];
            $line = (int) ($token['line'] ?? 1);
            $text = strtolower($token['text']);

            if ($id === T_STRING
                && in_array($text, ['fromcallable', 'bind'], true)
                && ($tokens[$index - 1]['id'] ?? null) === T_DOUBLE_COLON
                && strtolower((string) ($tokens[$index - 2]['text'] ?? '')) === 'closure'
                && ($tokens[$index + 1]['text'] ?? null) === '('
            ) {
                $mechanism = $text === 'fromcallable'
                    ? 'Closure::fromCallable dynamic callable construction'
                    : 'Closure::bind scope mutation';
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids %s in production source.',
                    $relativePath,
                    $line,
                    $mechanism,
                );
            }

            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && ($tokens[$index + 1]['id'] ?? null) === T_STRING
                && in_array(strtolower((string) ($tokens[$index + 1]['text'] ?? '')), ['bindto', 'call'], true)
                && ($tokens[$index + 2]['text'] ?? null) === '('
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids Closure ->%s(...) scope mutation in production source.',
                    $relativePath,
                    $line,
                    (string) $tokens[$index + 1]['text'],
                );
            }
        }
    }

    /**
     * @param  list<array{id:int|null,text:string,line:int|null}>  $tokens
     * @param  list<string>  $violations
     */
    private function scanContainerMechanisms(string $relativePath, array $tokens, array &$violations): void
    {
        foreach ($tokens as $index => $token) {
            $id = $token['id'];
            $line = (int) ($token['line'] ?? 1);

            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                && ($tokens[$index + 1]['id'] ?? null) === T_STRING
                && in_array(strtolower((string) ($tokens[$index + 1]['text'] ?? '')), ['make', 'makewith', 'get', 'offsetget'], true)
                && ($tokens[$index + 2]['text'] ?? null) === '('
                && ! $this->staticResolutionArgument($tokens, $index + 3)
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids dynamic container %s%s(...); use a static Class::class/literal reviewed resolution.',
                    $relativePath,
                    $line,
                    $id === T_DOUBLE_COLON ? '::' : '->',
                    (string) $tokens[$index + 1]['text'],
                );
            }

            if ($id === T_STRING
                && strtolower($token['text']) === 'getinstance'
                && ($tokens[$index - 1]['id'] ?? null) === T_DOUBLE_COLON
                && ($tokens[$index + 1]['text'] ?? null) === '('
                && ($tokens[$index + 2]['text'] ?? null) === ')'
                && ($tokens[$index + 3]['text'] ?? null) === '['
                && ($tokens[$index + 4]['id'] ?? null) === T_VARIABLE
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids dynamic container ArrayAccess through getInstance()[$variable].',
                    $relativePath,
                    $line,
                );
            }

            if ($id === T_VARIABLE
                && ($tokens[$index + 1]['text'] ?? null) === '['
                && ($tokens[$index + 2]['id'] ?? null) === T_VARIABLE
            ) {
                $violations[] = sprintf(
                    '%s:%d Telegram presentation provenance forbids dynamic typed-container ArrayAccess through %s[$variable].',
                    $relativePath,
                    $line,
                    $token['text'],
                );
            }
        }
    }

    /** @param list<array{id:int|null,text:string,line:int|null}> $tokens */
    private function staticResolutionArgument(array $tokens, int $index): bool
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

    /** @return list<string> */
    private function reviewedSources(): array
    {
        $sources = $this->config['telegram_non_restricted_presentation_sources'] ?? [];
        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_filter($sources, 'is_string'));
    }

    private function referencesProtectedSymbol(string $source): bool
    {
        foreach (self::PROTECTED_SYMBOLS as $symbol) {
            if (str_contains($source, $symbol)) {
                return true;
            }
        }

        return false;
    }

    private function referencesContainer(string $source): bool
    {
        foreach (self::CONTAINER_TYPES as $type) {
            if (str_contains($source, $type)) {
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
     * @param  list<array{id:int|null,text:string,line:int|null}>  $tokens
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
            $value = $this->decodeLiteral($tokens[$index]['text']);
            if ($value === null) {
                continue;
            }

            $cursor = $index;
            $parts = 1;
            while (($tokens[$cursor + 1]['text'] ?? null) === '.'
                && ($tokens[$cursor + 2]['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING
            ) {
                $next = $this->decodeLiteral($tokens[$cursor + 2]['text']);
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

    private function decodeLiteral(string $literal): ?string
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

        return $quote === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
            : stripcslashes($body);
    }

    /** @param list<string> $folded */
    private function containsFolded(array $folded, string $needle): bool
    {
        foreach ($folded as $value) {
            if (str_contains($value, $needle)) {
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

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                continue;
            }
            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
            $files[$relativePath] = $source;
        }
        ksort($files, SORT_STRING);

        return $files;
    }
}
