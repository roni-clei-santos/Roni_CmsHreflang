<?php
/**
 * Builds and validates the Adobe Commerce Marketplace submission ZIP archive.
 *
 * Packagist and `composer validate --strict` forbid a hard-coded "version" field in the repository's composer.json,
 * whereas the Adobe Commerce Marketplace ZIP upload validator requires `"version": "X.Y.Z"` inside the uploaded ZIP.
 * This script packages only the production module files (honoring .gitattributes export-ignore), injects the release
 * version into the packaged composer.json, and verifies the resulting archive against Marketplace requirements.
 *
 * Usage: php dev/ci/package-marketplace.php [version]
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$version = $argv[1] ?? '2.0.0';
if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "Invalid semantic version '{$version}' (expected X.Y.Z, e.g. 2.0.0).\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$composerRaw = file_get_contents($root . '/composer.json');
$composer = is_string($composerRaw) ? json_decode($composerRaw, true) : null;
if (!is_array($composer) || !is_string($composer['name'] ?? null)) {
    fwrite(STDERR, "Could not parse composer.json.\n");
    exit(1);
}

$excludedTopLevel = [
    '.git',
    '.gitattributes',
    '.githooks',
    '.github',
    '.gitignore',
    '.phpcs-cache',
    '.phpunit.cache',
    'CLAUDE.md',
    'Test',
    'build',
    'dev',
    'docs',
    'e2e',
    'eslint.config.mjs',
    'node_modules',
    'package-lock.json',
    'package.json',
    'phpcs.xml.dist',
    'phpstan.neon.dist',
    'phpunit.xml.dist',
    'setup-dev.sh',
    'test-results',
    'vendor',
];

$packagedComposer = [];
foreach ($composer as $key => $value) {
    $packagedComposer[$key] = $value;
    if ($key === 'type') {
        $packagedComposer['version'] = $version;
    }
}

$slug = str_replace('/', '-', $composer['name']);
$buildDir = $root . '/build';
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}
$zipPath = sprintf('%s/%s-%s.zip', $buildDir, $slug, $version);
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create ZIP archive at {$zipPath}.\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$added = [];
foreach ($iterator as $item) {
    if (!$item instanceof SplFileInfo || !$item->isFile()) {
        continue;
    }
    $relative = substr($item->getPathname(), strlen($root) + 1);
    $relativePosix = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $top = explode('/', $relativePosix)[0];
    if (in_array($top, $excludedTopLevel, true) || str_ends_with($relativePosix, '.DS_Store')) {
        continue;
    }
    if ($relativePosix === 'composer.json') {
        $json = json_encode($packagedComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zip->addFromString('composer.json', (string) $json . "\n");
    } else {
        $zip->addFile($item->getPathname(), $relativePosix);
    }
    $added[] = $relativePosix;
}
$zip->close();
sort($added);

// Verify the generated archive against Adobe Commerce Marketplace EQP package rules
$verify = new ZipArchive();
if ($verify->open($zipPath) !== true) {
    fwrite(STDERR, "Could not reopen {$zipPath} for verification.\n");
    exit(1);
}
$zipComposer = json_decode((string) $verify->getFromName('composer.json'), true);
$hasRegistration = $verify->locateName('registration.php') !== false;
$hasModuleXml = $verify->locateName('etc/module.xml') !== false;
$verify->close();

if (!is_array($zipComposer)
    || ($zipComposer['version'] ?? null) !== $version
    || ($zipComposer['type'] ?? null) !== 'magento2-module'
    || !$hasRegistration
    || !$hasModuleXml
) {
    fwrite(STDERR, "Marketplace package verification failed for {$zipPath}.\n");
    exit(1);
}

echo sprintf(
    "Marketplace package ready: %s (%d files, version %s, package %s)\n",
    $zipPath,
    count($added),
    $version,
    $composer['name']
);
