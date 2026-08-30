<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

final class CriticalMariaDbLifecycleContractChecker
{
    /** @var list<string> */
    private const REQUIRED_RULES = [
        'metadata_evidence',
        'install_upgrade_fencing',
        'interrupted_reentry',
        'rollback_preflight',
        'ddl_toctou',
        'dependency_checks',
        'postflight_readiness',
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
        $surfaces = $this->config['critical_mariadb_authority_surfaces'] ?? null;
        if (! is_array($surfaces)) {
            return ['Critical MariaDB lifecycle contract configuration is missing.'];
        }
        if (count($surfaces) < 2) {
            $violations[] = 'Critical MariaDB lifecycle contract must cover at least two representative authority surfaces.';
        }

        $migrationPaths = [];
        foreach ($surfaces as $surfaceId => $surface) {
            if (! is_string($surfaceId) || preg_match('/^[a-z0-9_]+$/', $surfaceId) !== 1 || ! is_array($surface)) {
                $violations[] = 'Critical MariaDB lifecycle surface entries require a stable lowercase identifier and array contract.';

                continue;
            }

            $migration = $surface['migration'] ?? null;
            if (! is_string($migration) || ! $this->isRepositoryPhpPath($migration) || ! is_file($this->root.'/'.$migration)) {
                $violations[] = sprintf('%s lifecycle contract references a missing or invalid migration.', $surfaceId);
            } else {
                $migrationPaths[$migration] = true;
            }

            $rules = $surface['rules'] ?? null;
            if (! is_array($rules)) {
                $violations[] = sprintf('%s lifecycle contract is missing its rule map.', $surfaceId);

                continue;
            }

            foreach (array_diff(array_keys($rules), self::REQUIRED_RULES) as $unexpectedRule) {
                $violations[] = sprintf('%s lifecycle contract declares unknown rule %s.', $surfaceId, (string) $unexpectedRule);
            }

            foreach (self::REQUIRED_RULES as $ruleName) {
                $rule = $rules[$ruleName] ?? null;
                if (! is_array($rule)) {
                    $violations[] = sprintf('%s lifecycle contract is missing required rule %s.', $surfaceId, $ruleName);

                    continue;
                }

                $strategy = $rule['strategy'] ?? null;
                if (! is_string($strategy)
                    || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $strategy) !== 1
                    || in_array($strategy, ['none', 'na', 'n_a', 'not_applicable'], true)) {
                    $violations[] = sprintf('%s lifecycle rule %s requires an explicit machine-readable strategy.', $surfaceId, $ruleName);
                }

                $evidence = $rule['evidence'] ?? null;
                if (! is_array($evidence) || $evidence === []) {
                    $violations[] = sprintf('%s lifecycle rule %s requires at least one concrete evidence symbol.', $surfaceId, $ruleName);

                    continue;
                }

                foreach ($evidence as $index => $item) {
                    if (! is_array($item)) {
                        $violations[] = sprintf('%s lifecycle rule %s evidence #%d is malformed.', $surfaceId, $ruleName, $index + 1);

                        continue;
                    }
                    $file = $item['file'] ?? null;
                    $symbol = $item['symbol'] ?? null;
                    if (! is_string($file) || ! $this->isRepositoryPhpPath($file) || ! is_file($this->root.'/'.$file)) {
                        $violations[] = sprintf('%s lifecycle rule %s evidence #%d references a missing or invalid PHP file.', $surfaceId, $ruleName, $index + 1);

                        continue;
                    }
                    if (! is_string($symbol) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $symbol) !== 1) {
                        $violations[] = sprintf('%s lifecycle rule %s evidence #%d has an invalid symbol.', $surfaceId, $ruleName, $index + 1);

                        continue;
                    }
                    if (! in_array($symbol, $this->declaredFunctions($file), true)) {
                        $violations[] = sprintf('%s lifecycle rule %s evidence symbol %s is not a declared function/method in %s.', $surfaceId, $ruleName, $symbol, $file);
                    }
                }
            }
        }

        if (count($migrationPaths) < 2) {
            $violations[] = 'Critical MariaDB lifecycle contract must cover at least two distinct authority migrations.';
        }

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return $violations;
    }

    private function isRepositoryPhpPath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && str_ends_with($path, '.php');
    }

    /** @return list<string> */
    private function declaredFunctions(string $relativePath): array
    {
        $source = file_get_contents($this->root.'/'.$relativePath);
        if (! is_string($source)) {
            return [];
        }

        $tokens = token_get_all($source);
        $symbols = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($candidate === '&' || (is_array($candidate) && in_array($candidate[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))) {
                    continue;
                }
                if (is_array($candidate) && $candidate[0] === T_STRING) {
                    $symbols[] = $candidate[1];
                }
                break;
            }
        }

        return array_values(array_unique($symbols));
    }
}
