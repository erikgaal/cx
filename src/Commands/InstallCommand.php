<?php

declare(strict_types=1);

namespace Cx\Commands;

use Cx\Graph\Project;
use Cx\Graph\ProjectGraph;
use Cx\Graph\ProjectGraphFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'install')]
final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setDefinition([
            new InputOption('project', 'p', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectGraph = ProjectGraphFactory::createProjectGraph();

        $rootProject = $projectGraph->getRoot();

        $filesystem = new Filesystem();

        $projects = collect($projectGraph->projects)
            ->when(
                $input->getOption('project'),
                fn(Collection $projects) => $projects->whereIn('name', $input->getOption('project')),
            );

        $results = $projects
            ->reject(fn(Project $project) => !$input->getOption('project') && $project === $rootProject)
            ->map(function (Project $project) use ($output, $projectGraph, $filesystem) {
                $output->writeln("Installing dependencies for {$project->name}");

                if ($project->isRoot()) {
                    return $this->installRootProject($output);
                }

                return $this->installProject($filesystem, $project, $projectGraph, $output);
            });

        return $results->contains(self::FAILURE) ? self::FAILURE : self::SUCCESS;
    }

    private function installRootProject(OutputInterface $output): int
    {
        try {
            $command = ['composer', 'install'];

            $output->writeln(implode(' ', $command), OutputInterface::VERBOSITY_VERBOSE);

            (new Process($command))->mustRun(fn($_, $buf) => $output->write($buf, false, OutputInterface::VERBOSITY_VERBOSE));

            return self::SUCCESS;
        } catch (ProcessFailedException $e) {
            return self::FAILURE;
        }
    }

    private function installProject(Filesystem $filesystem, Project $project, ProjectGraph $projectGraph, OutputInterface $output): int
    {
        $filesystem->copy(
            'composer.lock',
            $projectComposerLock = $project->root . '/composer.lock',
            overwriteNewerFiles: true,
        );

        $projectComposerLockContents = json_decode($this->readComposerLockFile($projectComposerLock));

        $lockedPackages = collect($projectComposerLockContents->packages)
            ->pluck('version', 'name')
            ->merge(Arr::mapWithKeys($projectGraph->projects, fn(Project $project) => [$project->name => '*']))
            ->except($project->name);

        $replacedPackages = collect($projectComposerLockContents->packages)
            ->flatMap(function (object $package) {
                return collect($package->replace ?? [])
                    ->filter(fn(string $version) => $version === 'self.version')
                    ->mapWithKeys(fn(string $_, string $replaced) => [$replaced => $package->version]);
            });

        try {
            $command = [
                'composer',
                'update',
                ...$lockedPackages->merge($replacedPackages)->map(fn($version, $package) => "{$package}:{$version}")
            ];

            $output->writeln(implode(' ', $command), OutputInterface::VERBOSITY_VERBOSE);

            (new Process(
                $command,
                cwd: $project->root,
            ))->mustRun(fn($_, $buf) => $output->write($buf, false, OutputInterface::VERBOSITY_VERBOSE));

            return self::SUCCESS;
        } catch (ProcessFailedException $e) {
            return self::FAILURE;
        } finally {
            $filesystem->remove($projectComposerLock);
        }
    }

    /**
     * Reads and returns the content of the Composer lock file.
     *
     * @param string $filePath The path to the Composer lock file.
     * @return string The content of the lock file.
     * @throws \RuntimeException If the file cannot be read.
     */
    private function readComposerLockFile(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Failed to read Composer lock file at: $filePath");
        }

        return $content;
    }
}
