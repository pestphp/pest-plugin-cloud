<?php

declare(strict_types=1);

use Pest\PestCloud\CloudRun;

function callMethod(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($object, $method);

    return $reflection->invoke($object, ...$args);
}

describe('loadConfig', function () {
    it('returns defaults when no config file exists', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $result = callMethod(new CloudRun, 'loadConfig', $dir);

            expect($result)->toBe([
                'respectGitignore' => true,
                'exclude' => [],
            ]);
        } finally {
            rmdir($dir);
        }
    });

    it('reads pest.cloud.json when present', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/pest.cloud.json', json_encode([
            'respectGitignore' => false,
            'exclude' => ['vendor', '*.log'],
        ]));

        try {
            $result = callMethod(new CloudRun, 'loadConfig', $dir);

            expect($result)->toBe([
                'respectGitignore' => false,
                'exclude' => ['vendor', '*.log'],
            ]);
        } finally {
            unlink($dir.'/pest.cloud.json');
            rmdir($dir);
        }
    });

    it('returns defaults for invalid JSON', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/pest.cloud.json', 'not valid json');

        try {
            $result = callMethod(new CloudRun, 'loadConfig', $dir);

            expect($result)->toBe([
                'respectGitignore' => true,
                'exclude' => [],
            ]);
        } finally {
            unlink($dir.'/pest.cloud.json');
            rmdir($dir);
        }
    });

    it('merges partial config with defaults', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/pest.cloud.json', json_encode([
            'exclude' => ['node_modules'],
        ]));

        try {
            $result = callMethod(new CloudRun, 'loadConfig', $dir);

            expect($result)->toBe([
                'respectGitignore' => true,
                'exclude' => ['node_modules'],
            ]);
        } finally {
            unlink($dir.'/pest.cloud.json');
            rmdir($dir);
        }
    });
});

describe('applyExclusions', function () {
    it('returns all files when no exclusions', function () {
        $files = ['src/Foo.php', 'tests/Bar.php'];

        $result = callMethod(new CloudRun, 'applyExclusions', $files, []);

        expect($result)->toBe($files);
    });

    it('excludes files matching glob patterns', function () {
        $files = ['src/Foo.php', 'logs/app.log', 'logs/error.log'];

        $result = callMethod(new CloudRun, 'applyExclusions', $files, ['*.log']);

        expect($result)->toBe(['src/Foo.php']);
    });

    it('excludes files by directory prefix', function () {
        $files = ['src/Foo.php', 'vendor/autoload.php', 'vendor/bin/pest'];

        $result = callMethod(new CloudRun, 'applyExclusions', $files, ['vendor']);

        expect($result)->toBe(['src/Foo.php']);
    });

    it('supports multiple exclusion patterns', function () {
        $files = ['src/Foo.php', 'vendor/autoload.php', 'storage/app.log', 'tests/Unit.php'];

        $result = callMethod(new CloudRun, 'applyExclusions', $files, ['vendor', 'storage']);

        expect($result)->toBe(['src/Foo.php', 'tests/Unit.php']);
    });

    it('excludes by basename match', function () {
        $files = ['src/Foo.php', 'src/.env', 'config/.env'];

        $result = callMethod(new CloudRun, 'applyExclusions', $files, ['.env']);

        expect($result)->toBe(['src/Foo.php']);
    });
});

describe('getAllFiles', function () {
    it('lists all files recursively', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir.'/sub', recursive: true);

        file_put_contents($dir.'/root.txt', 'root');
        file_put_contents($dir.'/sub/nested.txt', 'nested');

        try {
            $result = callMethod(new CloudRun, 'getAllFiles', $dir);

            sort($result);
            expect($result)->toBe(['root.txt', 'sub/nested.txt']);
        } finally {
            unlink($dir.'/sub/nested.txt');
            unlink($dir.'/root.txt');
            rmdir($dir.'/sub');
            rmdir($dir);
        }
    });
});

describe('createTarball', function () {
    it('creates a valid gzipped tarball', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/file.txt', 'hello');

        try {
            $tarballPath = callMethod(new CloudRun, 'createTarball', $dir, ['file.txt']);

            expect($tarballPath)->toEndWith('.tar.gz')
                ->and(file_exists($tarballPath))->toBeTrue()
                ->and(filesize($tarballPath))->toBeGreaterThan(0);
        } finally {
            if (isset($tarballPath) && file_exists($tarballPath)) {
                unlink($tarballPath);
            }

            unlink($dir.'/file.txt');
            rmdir($dir);
        }
    });

    it('includes only specified files', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/included.txt', 'yes');
        file_put_contents($dir.'/excluded.txt', 'no');

        try {
            $tarballPath = callMethod(new CloudRun, 'createTarball', $dir, ['included.txt']);

            $phar = new PharData($tarballPath);
            $files = [];

            foreach ($phar as $file) {
                $files[] = $file->getFilename();
            }

            expect($files)->toBe(['included.txt']);
        } finally {
            if (isset($tarballPath) && file_exists($tarballPath)) {
                unlink($tarballPath);
            }

            unlink($dir.'/included.txt');
            unlink($dir.'/excluded.txt');
            rmdir($dir);
        }
    });
});

describe('handle', function () {
    it('returns 1 when no API token is set', function () {
        $original = $_SERVER['PEST_CLOUD_TOKEN'] ?? null;
        unset($_SERVER['PEST_CLOUD_TOKEN']);

        try {
            $result = (new CloudRun)->handle([]);

            expect($result)->toBe(1);
        } finally {
            if ($original !== null) {
                $_SERVER['PEST_CLOUD_TOKEN'] = $original;
            }
        }
    });

    it('returns 1 when all files are excluded', function () {
        $dir = sys_get_temp_dir().'/pest_test_'.bin2hex(random_bytes(4));
        mkdir($dir);

        file_put_contents($dir.'/pest.cloud.json', json_encode([
            'respectGitignore' => false,
            'exclude' => ['*'],
        ]));
        file_put_contents($dir.'/test.txt', 'hello');

        $originalToken = $_SERVER['PEST_CLOUD_TOKEN'] ?? null;
        $_SERVER['PEST_CLOUD_TOKEN'] = 'test-token';
        $originalDir = getcwd();
        chdir($dir);

        try {
            $result = (new CloudRun)->handle([]);

            expect($result)->toBe(1);
        } finally {
            chdir($originalDir);

            if ($originalToken !== null) {
                $_SERVER['PEST_CLOUD_TOKEN'] = $originalToken;
            } else {
                unset($_SERVER['PEST_CLOUD_TOKEN']);
            }

            unlink($dir.'/pest.cloud.json');
            unlink($dir.'/test.txt');
            rmdir($dir);
        }
    });
});
