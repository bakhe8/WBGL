<?php
declare(strict_types=1);

namespace App\Support;

final class StabilityFreezeGate
{
    /** @var array<string, bool> */
    private array $phaseOneStepIds = [];

    /** @var array<string, bool> */
    private array $phaseOneCoverageIds = [];

    /** @var array<string, bool> */
    private array $allowedRefs = [];

    /** @var string[] */
    private array $sensitivePrefixes = [
        'api/',
        'app/',
        'partials/',
        'templates/',
        'views/',
        'public/js/',
        'public/css/',
        'database/migrations/',
    ];

    /** @var string[] */
    private array $frozenNewSurfacePrefixes = [
        'api/',
        'views/',
        'partials/',
        'templates/',
        'public/js/',
        'public/css/',
    ];

    /** @var string[] */
    private array $blockedChangeTypes = [
        'feature',
        'enhancement',
        'new-feature',
        'new_feature',
    ];

    /**
     * @param array<string, bool> $phaseOneStepIds
     * @param array<string, bool> $phaseOneCoverageIds
     */
    private function __construct(array $phaseOneStepIds, array $phaseOneCoverageIds)
    {
        $this->phaseOneStepIds = $phaseOneStepIds;
        $this->phaseOneCoverageIds = $phaseOneCoverageIds;
        $this->allowedRefs = $phaseOneStepIds + $phaseOneCoverageIds;
    }

    public static function fromManifest(array $manifest): self
    {
        $phaseOneStepIds = [];
        $phaseOneCoverageIds = [];
        $phases = $manifest['phases'] ?? [];
        if (!is_array($phases)) {
            return new self([], []);
        }

        foreach ($phases as $phase) {
            $steps = $phase['steps'] ?? [];
            if (!is_array($steps)) {
                continue;
            }

            foreach ($steps as $step) {
                $stepId = self::normalizeRef((string)($step['id'] ?? ''));
                if ($stepId === '' || !str_starts_with($stepId, 'P1-')) {
                    continue;
                }
                $phaseOneStepIds[$stepId] = true;

                $coverageIds = $step['coverage_ids'] ?? [];
                if (!is_array($coverageIds)) {
                    continue;
                }

                foreach ($coverageIds as $coverageId) {
                    $normalizedCoverageId = self::normalizeRef((string)$coverageId);
                    if ($normalizedCoverageId === '') {
                        continue;
                    }
                    $phaseOneCoverageIds[$normalizedCoverageId] = true;
                }
            }
        }

        return new self($phaseOneStepIds, $phaseOneCoverageIds);
    }

    /**
     * @param string[] $changedFiles
     * @param string[] $statusRows
     * @return array{
     *   enforced: bool,
     *   active_step: ?string,
     *   stability_refs: string[],
     *   sensitive_changes: bool,
     *   errors: string[]
     * }
     */
    public function evaluate(array $changedFiles, array $statusRows, string $prBody, ?string $activeStep): array
    {
        $normalizedActiveStep = self::normalizeRef((string)$activeStep);
        $freezeEnforced = $normalizedActiveStep !== '' && str_starts_with($normalizedActiveStep, 'P1-');
        $stabilityRefs = $this->extractStabilityRefs($prBody);
        $sensitiveChangesDetected = $this->hasSensitiveChanges($changedFiles);
        $errors = [];

        if ($freezeEnforced && $sensitiveChangesDetected) {
            $changeType = $this->extractChangeType($prBody);
            if ($changeType === null) {
                $errors[] = 'Missing CHANGE-TYPE for sensitive changes during phase-1 freeze.';
            } elseif (in_array($changeType, $this->blockedChangeTypes, true)) {
                $errors[] = 'Feature freeze violation: CHANGE-TYPE cannot be feature/enhancement during phase-1.';
            }

            if ($stabilityRefs === []) {
                $errors[] = 'Missing STABILITY-REFS for sensitive changes during phase-1 freeze.';
            } else {
                $unknownRefs = array_values(array_filter($stabilityRefs, function (string $ref): bool {
                    return !isset($this->allowedRefs[$ref]);
                }));
                if ($unknownRefs !== []) {
                    sort($unknownRefs);
                    $errors[] = 'STABILITY-REFS contain unknown IDs for phase-1 stability scope: ' . implode(', ', $unknownRefs);
                }

                $hasStepReference = false;
                foreach ($stabilityRefs as $ref) {
                    if (isset($this->phaseOneStepIds[$ref])) {
                        $hasStepReference = true;
                        break;
                    }
                }
                if (!$hasStepReference) {
                    $errors[] = 'STABILITY-REFS must include at least one phase-1 step id (P1-xx).';
                }

                $hasCoverageReference = false;
                foreach ($stabilityRefs as $ref) {
                    if (isset($this->phaseOneCoverageIds[$ref])) {
                        $hasCoverageReference = true;
                        break;
                    }
                }
                if (!$hasCoverageReference) {
                    $errors[] = 'STABILITY-REFS must include at least one phase-1 coverage id (FR/R/G/C linked to P1).';
                }
            }

            $addedFeatureSurfaces = $this->findAddedFrozenSurfaceFiles($statusRows);
            if ($addedFeatureSurfaces !== []) {
                $errors[] = 'Feature freeze violation: adding new feature-surface files is blocked during phase-1. Files: '
                    . implode(', ', $addedFeatureSurfaces);
            }
        }

        return [
            'enforced' => $freezeEnforced,
            'active_step' => $normalizedActiveStep === '' ? null : $normalizedActiveStep,
            'stability_refs' => $stabilityRefs,
            'sensitive_changes' => $sensitiveChangesDetected,
            'errors' => $errors,
        ];
    }

    /**
     * @return string[]
     */
    private function extractStabilityRefs(string $prBody): array
    {
        if (!preg_match('/^\s*STABILITY-REFS\s*:\s*(.+)$/im', $prBody, $matches)) {
            return [];
        }

        $rawRefs = str_replace(['|', ';'], ',', (string)$matches[1]);
        $tokens = preg_split('/[\s,]+/', $rawRefs) ?: [];
        $refs = [];
        foreach ($tokens as $token) {
            $normalized = self::normalizeRef($token);
            if ($normalized === '') {
                continue;
            }
            $refs[$normalized] = true;
        }

        $result = array_keys($refs);
        sort($result);
        return $result;
    }

    private function extractChangeType(string $prBody): ?string
    {
        if (!preg_match('/^\s*CHANGE-TYPE\s*:\s*(.+)$/im', $prBody, $matches)) {
            return null;
        }

        $rawType = trim((string)$matches[1]);
        if ($rawType === '') {
            return null;
        }

        $parts = preg_split('/[\s,;|]+/', strtolower($rawType)) ?: [];
        if ($parts === []) {
            return null;
        }

        $first = trim((string)$parts[0]);
        if ($first === '') {
            return null;
        }

        return str_replace('_', '-', $first);
    }

    /**
     * @param string[] $changedFiles
     */
    private function hasSensitiveChanges(array $changedFiles): bool
    {
        foreach ($changedFiles as $file) {
            $normalizedFile = self::normalizePath((string)$file);
            if ($normalizedFile === '') {
                continue;
            }
            foreach ($this->sensitivePrefixes as $prefix) {
                if (str_starts_with($normalizedFile, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string[] $statusRows
     * @return string[]
     */
    private function findAddedFrozenSurfaceFiles(array $statusRows): array
    {
        $violations = [];
        foreach ($statusRows as $statusRow) {
            $line = trim((string)$statusRow);
            if ($line === '') {
                continue;
            }

            $parts = strpos($line, "\t") !== false
                ? explode("\t", $line)
                : preg_split('/\s+/', $line, 3);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }

            $statusCode = strtoupper((string)$parts[0]);
            $statusType = $statusCode === '' ? '' : $statusCode[0];
            if (!in_array($statusType, ['A', 'R', 'C'], true)) {
                continue;
            }

            $candidatePath = (string)($parts[count($parts) - 1] ?? '');
            $normalizedPath = self::normalizePath($candidatePath);
            if ($normalizedPath === '') {
                continue;
            }

            foreach ($this->frozenNewSurfacePrefixes as $prefix) {
                if (str_starts_with($normalizedPath, $prefix)) {
                    $violations[$normalizedPath] = true;
                    break;
                }
            }
        }

        $files = array_keys($violations);
        sort($files);
        return $files;
    }

    private static function normalizePath(string $value): string
    {
        return trim(str_replace('\\', '/', $value), " \t\n\r\0\x0B");
    }

    private static function normalizeRef(string $value): string
    {
        $normalized = strtoupper(trim($value));
        return trim($normalized, " \t\n\r\0\x0B,.;|");
    }
}
