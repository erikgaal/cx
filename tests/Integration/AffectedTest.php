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

final class AffectedTest extends TestCase
{
    private Filesystem $filesystem;
    private string $workdir;

    public function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->workdir = __DIR__ . '/storage/' . uniqid());

        $this->composer(['init', '--name=example/my-project'], cwd: $this->workdir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->workdir);
    }

    #[Test]
    public function all_projects_are_affected_with_composer_changes(): void
    {
        // Create a dependency
        $this->filesystem->mkdir($dependency = $this->workdir . '/libs/my-dependency');
        $this->composer(['init', '--name=example/my-dependency'], cwd: $dependency);

        $this->assertEquals("example/my-dependency\nexample/my-project", $this->cx(['affected', '--output', '--files=composer.json'], cwd: $this->workdir));
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
}
