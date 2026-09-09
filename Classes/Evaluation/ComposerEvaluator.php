<?php

declare(strict_types=1);

namespace Caretaker2\Hub\Evaluation;

use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Runs composer against an instance's manifest without ever touching the
 * instance. Only composer.json, composer.lock and network access are needed.
 */
final class ComposerEvaluator
{
    private const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly string $composerBinary = 'composer',
    ) {}

    /**
     * @param array<string, mixed> $inventory
     * @throws EvaluationException
     */
    public function evaluate(int $instanceUid, int $tenant, array $inventory): EvaluationResult
    {
        $composer = $inventory['providers']['composer']['data'] ?? null;
        if (!is_array($composer) || !is_string($composer['lock'] ?? null)) {
            throw new EvaluationException('Das Inventar enthält keine composer.lock.');
        }

        $workspace = $this->prepareWorkspace($instanceUid);
        $home = $this->composerHome($tenant);

        file_put_contents($workspace . '/composer.lock', $composer['lock']);
        $stripped = $this->prepareManifest(
            is_string($composer['json'] ?? null) ? $composer['json'] : '{}',
            $inventory
        );
        file_put_contents($workspace . '/composer.json', $stripped['json']);

        $audit = $this->run(['audit', '--locked', '--format=json'], $workspace, $home);
        $outdated = $this->run(['outdated', '--locked', '--direct', '--format=json'], $workspace, $home);

        return new EvaluationResult(
            advisories: $audit['advisories'] ?? [],
            abandoned: $audit['abandoned'] ?? [],
            packages: $outdated['locked'] ?? [],
            unresolvableRepositories: $stripped['removed'],
            platform: $stripped['platform'],
        );
    }

    /**
     * Two changes to the manifest before composer sees it.
     *
     * config.platform is filled from what the instance actually runs. Without
     * it composer would judge against the hub's PHP version and report updates
     * that cannot be installed on the instance at all.
     *
     * Path repositories are dropped. They point at directories that exist on
     * the instance and not here, and composer aborts on the first one it
     * cannot resolve. The packages they provide are reported as unassessable
     * rather than silently treated as fine.
     *
     * @param array<string, mixed> $inventory
     * @return array{json: string, removed: list<string>, platform: array<string, string>}
     */
    private function prepareManifest(string $json, array $inventory): array
    {
        $manifest = json_decode($json, true);
        if (!is_array($manifest)) {
            $manifest = [];
        }

        $removed = [];
        foreach (($manifest['repositories'] ?? []) as $key => $repository) {
            if (is_array($repository) && ($repository['type'] ?? '') === 'path') {
                $removed[] = (string)($repository['url'] ?? $key);
                unset($manifest['repositories'][$key]);
            }
        }

        $platform = $this->platformFrom($inventory);
        if ($platform !== []) {
            $manifest['config']['platform'] = $platform;
        }

        return [
            'json' => (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'removed' => $removed,
            'platform' => $platform,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, string>
     */
    private function platformFrom(array $inventory): array
    {
        $php = $inventory['providers']['platform']['data']['php'] ?? null;
        if (!is_array($php)) {
            return [];
        }

        $platform = [];
        if (is_string($php['version'] ?? null)) {
            $platform['php'] = $php['version'];
        }
        foreach (($php['extensions'] ?? []) as $name => $version) {
            if (!is_string($name) || !is_string($version)) {
                continue;
            }
            $normalized = $this->normalizePackageName($name);
            $parsed = $this->versionFrom($version);
            if ($normalized !== null && $parsed !== null) {
                $platform[$normalized] = $parsed;
            }
        }

        return $platform;
    }

    /**
     * Composer rejects platform package names it cannot parse, and a single
     * bad name aborts the whole run. Agents normalise this themselves, but an
     * older one must not be able to break the evaluation.
     */
    private function normalizePackageName(string $name): ?string
    {
        $name = str_replace(' ', '-', strtolower(trim($name)));

        return preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*$/', $name) === 1 ? $name : null;
    }

    /**
     * Not every extension reports a plain version. mysqlnd answers
     * "mysqlnd 8.3.31", others return build strings, and composer rejects
     * anything it cannot parse — one such value aborts the whole run.
     *
     * A leading or embedded version is used as-is. Anything without one is
     * left out entirely rather than guessed at: composer then treats the
     * extension as absent, which is the safer error.
     */
    private function versionFrom(string $version): ?string
    {
        $version = trim($version);

        if (preg_match('/^v?(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.]+)?)$/', $version, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)*)/', $version, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     * @throws EvaluationException
     */
    private function run(array $arguments, string $workspace, string $home): array
    {
        $process = new Process(
            array_merge([$this->composerBinary], $arguments, ['--no-interaction']),
            $workspace,
            [
                // Per tenant, so that credentials for a private repository can
                // never leak into another tenant's run.
                'COMPOSER_HOME' => $home,
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_DISABLE_XDEBUG_WARN' => '1',
            ]
        );
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            throw new EvaluationException(sprintf(
                'composer %s lieferte kein JSON (Exit %d): %s',
                $arguments[0],
                (int)$process->getExitCode(),
                substr($process->getErrorOutput() ?: $output, 0, 400)
            ));
        }

        return $decoded;
    }

    private function prepareWorkspace(int $instanceUid): string
    {
        $path = Environment::getVarPath() . '/caretaker2/evaluation/' . $instanceUid;
        $this->ensureDirectory($path);

        foreach (['composer.json', 'composer.lock'] as $file) {
            if (is_file($path . '/' . $file)) {
                unlink($path . '/' . $file);
            }
        }

        return $path;
    }

    private function composerHome(int $tenant): string
    {
        $path = Environment::getVarPath() . '/caretaker2/composer-home/' . $tenant;
        $this->ensureDirectory($path);

        return $path;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new EvaluationException(sprintf('Verzeichnis %s konnte nicht angelegt werden.', $path));
        }
    }
}
