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

    /**
     * Repository types that need the instance's file system.
     */
    private const LOCAL_REPOSITORY_TYPES = ['path', 'artifact'];

    /**
     * Repository types that need a checkout. They stay when their URL is
     * https, which the hub can clone without the instance's credentials.
     */
    private const VCS_REPOSITORY_TYPES = [
        'vcs',
        'git',
        'github',
        'gitlab',
        'bitbucket',
        'git-bitbucket',
        'hg',
        'fossil',
        'svn',
        'perforce',
    ];

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
            throw new EvaluationException('The inventory carries no composer.lock.');
        }

        $workspace = $this->prepareWorkspace($instanceUid);
        $home = $this->composerHome($tenant);

        file_put_contents($workspace . '/composer.lock', $composer['lock']);
        $stripped = $this->prepareManifest(
            is_string($composer['json'] ?? null) ? $composer['json'] : '{}',
            $inventory
        );
        file_put_contents($workspace . '/composer.json', $stripped['json']);

        // The audit reads only the lock file and needs no repository. It is
        // kept even when the update check below breaks off, so an
        // unreachable repository never hides a security advisory.
        $audit = $this->run(['audit', '--locked', '--format=json'], $workspace, $home);

        $outdated = [];
        $updateCheckError = null;
        try {
            $outdated = $this->run(['outdated', '--locked', '--direct', '--format=json'], $workspace, $home);
        } catch (EvaluationException $e) {
            $updateCheckError = $e->getMessage();
        }

        return new EvaluationResult(
            advisories: $audit['advisories'] ?? [],
            abandoned: $audit['abandoned'] ?? [],
            packages: $outdated['locked'] ?? [],
            unresolvableRepositories: $stripped['removed'],
            platform: $stripped['platform'],
            lockedVersions: $this->lockedVersions($composer['lock']),
            updateCheckError: $updateCheckError,
        );
    }

    /**
     * Installed versions straight from the lock file, for findings that
     * have to name a version when the update check contributed nothing.
     *
     * @return array<string, string>
     */
    private function lockedVersions(string $lock): array
    {
        $decoded = json_decode($lock, true);
        if (!is_array($decoded)) {
            return [];
        }

        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (($decoded[$section] ?? []) as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                    $versions[$package['name']] = $package['version'];
                }
            }
        }

        return $versions;
    }

    /**
     * Three changes to the manifest before composer sees it.
     *
     * config.platform is filled from what the instance actually runs. Without
     * it composer would judge against the hub's PHP version and report updates
     * that cannot be installed on the instance at all.
     *
     * Path repositories and VCS repositories without an https URL are
     * dropped. Path repositories point at directories that exist on the
     * instance and not here, ssh URLs need the instance's keys, and composer
     * aborts on the first repository it cannot resolve. The packages they
     * provide are reported as unassessable rather than silently treated as
     * fine; composer lists them as up to date with no newer version matched.
     *
     * GitHub repositories are marked "no-api". Composer would otherwise ask
     * the GitHub API, which allows sixty anonymous requests an hour per
     * address, and on the first 403 or 404 it silently switches to cloning
     * git@github.com over ssh — which the hub has no key for. A plain https
     * clone needs no token for a public repository and no API quota at all.
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
        $repositories = $manifest['repositories'] ?? [];
        if (is_array($repositories)) {
            $wasList = array_is_list($repositories);
            foreach ($repositories as $key => $repository) {
                if (!is_array($repository)) {
                    continue;
                }
                if ($this->isUnresolvable($repository)) {
                    $removed[] = (string)($repository['url'] ?? $key);
                    unset($repositories[$key]);
                } elseif ($this->isGitHub($repository)) {
                    $repositories[$key]['no-api'] = true;
                }
            }
            // A list with a gap encodes as an object, which composer's schema
            // treats as named repositories and rejects.
            $manifest['repositories'] = $wasList ? array_values($repositories) : $repositories;
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
     * @param array<string, mixed> $repository
     */
    private function isUnresolvable(array $repository): bool
    {
        $type = $repository['type'] ?? '';
        if (in_array($type, self::LOCAL_REPOSITORY_TYPES, true)) {
            return true;
        }
        if (in_array($type, self::VCS_REPOSITORY_TYPES, true)) {
            $url = is_string($repository['url'] ?? null) ? $repository['url'] : '';

            return !str_starts_with(strtolower($url), 'https://');
        }

        return false;
    }

    /**
     * @param array<string, mixed> $repository
     */
    private function isGitHub(array $repository): bool
    {
        if (!in_array($repository['type'] ?? '', self::VCS_REPOSITORY_TYPES, true)) {
            return false;
        }
        $host = parse_url(is_string($repository['url'] ?? null) ? $repository['url'] : '', PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), ['github.com', 'www.github.com'], true);
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

    private function normalizePackageName(string $name): ?string
    {
        $name = str_replace(' ', '-', strtolower(trim($name)));

        return preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*$/', $name) === 1 ? $name : null;
    }

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
            // Composer boxes its errors with padding and line breaks; a
            // finding title wants one line.
            $error = trim((string)preg_replace('/\s+/', ' ', $process->getErrorOutput() ?: $output));
            throw new EvaluationException(sprintf(
                'composer %s returned no JSON (exit code %d): %s',
                $arguments[0],
                (int)$process->getExitCode(),
                substr($error, 0, 400)
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
            throw new EvaluationException(sprintf('The directory %s could not be created.', $path));
        }
    }
}
