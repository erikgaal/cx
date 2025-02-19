<?php

declare(strict_types=1);

namespace Cx\Tests\Integration;

use Cx\Commands\InstallCommand;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class InstallTest extends TestCase
{
    private Filesystem $filesystem;
    private string $workdir;

    public function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->workdir = __DIR__ . '/storage/' . uniqid());

        $this->composer(['init', '--name=example/my-project'], cwd: $this->workdir);
        $this->composer(['config', 'allow-plugins.wikimedia/composer-merge-plugin', 'true'], cwd: $this->workdir);
        $this->composer(['config', 'extra.merge-plugin.include', '--json', '["libs/*/composer.json"]'], cwd: $this->workdir);
        $this->composer(['require', 'wikimedia/composer-merge-plugin'], cwd: $this->workdir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->workdir);
    }

    #[Test]
    public function it_installs_packages_in_projects(): void
    {
        // Create a dependency
        $this->filesystem->mkdir($dependency = $this->workdir . '/libs/my-dependency');
        $this->composer(['init', '--name=example/my-dependency'], cwd: $dependency);

        // Require a package in dependency (don't install)
        $this->composer(['require', 'brick/math:^0.12.0', '--no-install'], cwd: $dependency);

        // Lock the package version in the root project
        $this->composer(['update', 'brick/math:0.12.0'], cwd: $this->workdir);
        $this->assertEquals('0.12.0', $this->getInstalledPackageVersion('brick/math', $this->workdir));

        // Install using Cx
        $this->cx(['install'], cwd: $this->workdir);

        $this->assertEquals('0.12.0', $this->getInstalledPackageVersion('brick/math', $dependency));
    }

    #[Test]
    public function dependency_binaries_are_available(): void
    {
        // Create a dependency
        $this->filesystem->mkdir($dependency = $this->workdir . '/libs/my-dependency');
        $this->composer(['init', '--name=example/my-dependency'], cwd: $dependency);

        // Require a package in dependency (don't install)
        $this->composer(['require', 'phpstan/phpstan', '--no-install'], cwd: $dependency);

        // Lock packages in the root project
        $this->composer(['update'], cwd: $this->workdir);

        // Install using Cx
        $this->cx(['install'], cwd: $this->workdir);

        $this->assertFileExists($dependency . '/vendor/bin/phpstan');
    }

    #[Test]
    public function replaced_packages_keep_their_versions(): void
    {
        // Create a dependency
        $this->filesystem->mkdir($dependency = $this->workdir . '/libs/my-dependency');
        $this->composer(['init', '--name=example/my-dependency'], cwd: $dependency);

        // Require a package in dependency (don't install)
        $this->composer(['require', 'illuminate/support:^11.20', '--no-install'], cwd: $dependency);

        // Lock the package version in the root project
        $this->composer(['require', 'laravel/framework:^11.20', '--no-install'], cwd: $this->workdir);
        $this->composer(['update', 'laravel/framework:11.20.0', '-w'], cwd: $this->workdir);
        $this->cx(['install'], cwd: $this->workdir);

        $this->assertEquals(null, $this->getInstalledPackageVersion('laravel/framework', $dependency));
        $this->assertEquals('v11.20.0', $this->getInstalledPackageVersion('illuminate/support', $dependency));
    }

    private function composer(array $command, string $cwd = null): string
    {
        return $this->exec(['composer', ...$command], cwd: $cwd);
    }

    private function cx(array $command, string $cwd = null): string
    {
        return $this->exec([__DIR__ . '/../../cx', ...$command], cwd: $cwd);
    }

    private function exec(array $command, string $cwd = null): string
    {
        $outputBuffer = '';
        $process = (new Process([...$command], cwd: $cwd));
        $process->mustRun(function ($_, $buf) use (&$outputBuffer) {
            return $outputBuffer .= $buf;
        });

        return $outputBuffer;
    }

    private function getInstalledPackageVersion(string $package, string $cwd = null): ?string
    {
        try {
            $versions = json_decode($this->composer(['show', $package, '--format=json'], cwd: $cwd), associative: true)['versions'];

            return $versions[0];
        } catch (ProcessFailedException) {
            return null;
        }
    }
}
