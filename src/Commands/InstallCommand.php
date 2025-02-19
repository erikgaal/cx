<?php

declare(strict_types=1);

namespace Cx\Commands;

use Cx\Graph\Project;
use Cx\Graph\ProjectGraphFactory;
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
    protected function configure()
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
            ->reject(fn(Project $project) => $project === $rootProject)
            ->map(fn (Project $project) => $this->installProject($output, $project, $filesystem));

        return $results->contains(self::FAILURE) ? self::FAILURE : self::SUCCESS;
    }

    private function installProject(OutputInterface $output, mixed $project, Filesystem $filesystem): int
    {
        $output->writeln("Installing dependencies for {$project->name}");

        $filesystem->copy(
            'composer.lock',
            $projectComposerLock = $project->root . '/composer.lock',
            overwriteNewerFiles: true,
        );

        $projectComposerLockContents = json_decode(file_get_contents($projectComposerLock));

        $lockedPackages = collect($projectComposerLockContents->packages)
            ->pluck('version', 'name')
            ->except($project->name);

        $replacedPackages = collect($projectComposerLockContents->packages)
            ->flatMap(function (object $package) {
                return collect($package->replace ?? [])
                    ->filter(fn(string $version) => $version === 'self.version')
                    ->mapWithKeys(fn(string $_, string $replaced) => [$replaced => $package->version]);
            });

        try {
            $command = ['composer', 'update', ...$lockedPackages->merge($replacedPackages)->map(fn($version, $package) => "{$package}:{$version}")];

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
}
