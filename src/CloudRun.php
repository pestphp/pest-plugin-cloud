<?php

declare(strict_types=1);

namespace Pest\PestCloud;

use CURLFile;
use Phar;
use PharData;
use RuntimeException;

/**
 * @internal
 */
class CloudRun
{
    private const int MAX_TARBALL_SIZE = 50 * 1024 * 1024;

    private const int MAX_RETRIES = 3;

    /**
     * @param  array<int, string>  $arguments
     */
    public function handle(array $arguments): int
    {
        $projectPath = (string) getcwd();
        $config = $this->loadConfig($projectPath);

        $apiUrl = is_string($_SERVER['PEST_CLOUD_URL'] ?? null)
            ? $_SERVER['PEST_CLOUD_URL']
            : 'https://cloud.pestphp.com';

        $apiToken = is_string($_SERVER['PEST_CLOUD_TOKEN'] ?? null)
            ? $_SERVER['PEST_CLOUD_TOKEN']
            : '';

        if ($apiToken === '') {
            fwrite(STDERR, "Error: No API token configured. Set the PEST_CLOUD_TOKEN environment variable.\n");

            return 1;
        }

        $pestArguments = implode(' ', $arguments);

        $files = $config['respectGitignore']
            ? $this->getGitTrackedFiles($projectPath)
            : $this->getAllFiles($projectPath);

        $files = $this->applyExclusions($files, $config['exclude']);

        if ($files === []) {
            fwrite(STDERR, "Error: No files to include in the tarball.\n");

            return 1;
        }

        $tarballPath = $this->createTarball($projectPath, $files);

        try {
            $size = filesize($tarballPath);

            if ($size === false || $size > self::MAX_TARBALL_SIZE) {
                fwrite(STDERR, "Error: Project tarball exceeds the 50 MB limit. Add more exclusions to pest.cloud.json.\n");

                return 1;
            }

            $response = $this->upload($apiUrl, $apiToken, $tarballPath, $pestArguments);

            echo "Run created successfully.\n";
            echo "ID: {$response['id']}\n";
            echo "Status: {$response['status']}\n";

            return 0;
        } finally {
            if (file_exists($tarballPath)) {
                unlink($tarballPath);
            }
        }
    }

    /**
     * @return array{respectGitignore: bool, exclude: list<string>}
     */
    private function loadConfig(string $projectPath): array
    {
        $defaults = [
            'respectGitignore' => true,
            'exclude' => [],
        ];

        $configPath = $projectPath.'/pest.cloud.json';

        if (! file_exists($configPath)) {
            return $defaults;
        }

        $content = file_get_contents($configPath);

        if ($content === false) {
            return $defaults;
        }

        $config = json_decode($content, true);

        if (! is_array($config)) {
            return $defaults;
        }

        /** @var array{respectGitignore?: bool, exclude?: list<string>} $config */

        return [
            'respectGitignore' => $config['respectGitignore'] ?? true,
            'exclude' => $config['exclude'] ?? [],
        ];
    }

    /**
     * @return list<string>
     */
    private function getGitTrackedFiles(string $projectPath): array
    {
        $command = 'cd '.escapeshellarg($projectPath).' && git ls-files --cached --others --exclude-standard';
        $output = [];
        $resultCode = 0;

        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {
            return $this->getAllFiles($projectPath);
        }

        return array_values(array_filter(
            $output,
            fn (string $file): bool => $file !== '' && is_file($projectPath.'/'.$file),
        ));
    }

    /**
     * @return list<string>
     */
    private function getAllFiles(string $projectPath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($projectPath) + 1);
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $exclusions
     * @return list<string>
     */
    private function applyExclusions(array $files, array $exclusions): array
    {
        if ($exclusions === []) {
            return $files;
        }

        return array_values(array_filter($files, function (string $file) use ($exclusions): bool {
            foreach ($exclusions as $pattern) {
                if (fnmatch($pattern, $file) || fnmatch($pattern, basename($file)) || str_starts_with($file, rtrim($pattern, '/').'/')) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param  list<string>  $files
     */
    private function createTarball(string $projectPath, array $files): string
    {
        $tarPath = sys_get_temp_dir().'/pest_cloud_'.bin2hex(random_bytes(8)).'.tar';
        $tarballPath = $tarPath.'.gz';

        $phar = new PharData($tarPath);

        foreach ($files as $file) {
            $fullPath = $projectPath.'/'.$file;

            if (is_file($fullPath)) {
                $phar->addFile($fullPath, $file);
            }
        }

        $phar->compress(Phar::GZ);

        if (file_exists($tarPath)) {
            unlink($tarPath);
        }

        return $tarballPath;
    }

    /**
     * @return array{id: string, status: string}
     */
    private function upload(string $apiUrl, string $apiToken, string $tarballPath, string $pestArguments): array
    {
        $url = rtrim($apiUrl, '/').'/api/run';
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->doUpload($url, $apiToken, $tarballPath, $pestArguments);
            } catch (RuntimeException $e) {
                $lastException = $e;

                if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), '422')) {
                    throw $e;
                }

                if ($attempt < self::MAX_RETRIES) {
                    sleep(2 ** ($attempt - 1));
                }
            }
        }

        throw $lastException;
    }

    /**
     * @return array{id: string, status: string}
     */
    private function doUpload(string $url, string $apiToken, string $tarballPath, string $pestArguments): array
    {
        $ch = curl_init($url);

        $postFields = [
            'tarball' => new CURLFile($tarballPath, 'application/gzip', 'project.tar.gz'),
        ];

        if ($pestArguments !== '') {
            $postFields['pest_arguments'] = $pestArguments;
        }

        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiToken,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Network error: '.$error);
        }

        /** @var array{id?: string, status?: string, message?: string}|null $body */
        $body = json_decode((string) $response, true);

        if ($httpCode === 401) {
            throw new RuntimeException('Authentication failed (401): Check your API token.');
        }

        if ($httpCode === 422) {
            $message = is_array($body) ? ($body['message'] ?? 'Validation error') : 'Validation error';

            throw new RuntimeException('Validation error (422): '.$message);
        }

        if ($httpCode >= 500) {
            throw new RuntimeException("Server error ({$httpCode}): The service may be temporarily unavailable.");
        }

        if ($httpCode !== 201 || ! is_array($body) || ! isset($body['id'], $body['status'])) {
            throw new RuntimeException("Unexpected response (HTTP {$httpCode}): ".$response);
        }

        return ['id' => $body['id'], 'status' => $body['status']];
    }
}
