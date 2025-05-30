<?php

declare(strict_types=1);

namespace Cx\Commands;

use Cx\Git\Files;
use Cx\Graph\Project;
use Cx\Graph\ProjectGraphFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'affected')]
final class AffectedCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('affected')
            ->setDefinition([
                new InputOption('output', mode: InputOption::VALUE_NONE),
                new InputOption('graph', mode: InputOption::VALUE_NONE, description: 'Display a graph of affected projects.'),
                new InputOption('target', 't', InputOption::VALUE_REQUIRED),
                new InputOption('base', mode: InputOption::VALUE_REQUIRED, default: 'main'),
                new InputOption('head', mode: InputOption::VALUE_REQUIRED),
                new InputOption('uncommitted', mode: InputOption::VALUE_NONE),
                new InputOption('untracked', mode: InputOption::VALUE_NONE),
                new InputOption('files', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
                new InputOption('target', 't', InputOption::VALUE_REQUIRED),
                new InputOption('project', 'p', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
                new InputOption('exclude', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectGraph = ProjectGraphFactory::createProjectGraph();

        $files = $input->getOption('files') ?: Files::diff(
            base: $input->getOption('base'),
            uncommitted: $input->getOption('uncommitted'),
            untracked: $input->getOption('untracked'),
            head: $input->getOption('head'),
        );

        $projectsWithChanges = [];

        foreach ($files as $file) {
            if (in_array($file, ['composer.json', 'composer.lock'])) {
                $projectsWithChanges = [
                    ...$projectsWithChanges,
                    ...array_values(array_map(fn (Project $project) => $project->name, $projectGraph->projects)),
                ];
            }

            foreach ($projectGraph->projects as $project) {
                if (str_starts_with($file, $project->root)) {
                    $projectsWithChanges[] = $project->name;
                }
            }
        }

        $affectedProjects = $projectGraph->affected(...$projectsWithChanges);

        if ($filteredProjects = $input->getOption('project')) {
            $affectedProjects = array_values(array_filter($affectedProjects, fn(string $project) => in_array($project, $filteredProjects)));
        }

        if ($excludedProjects = $input->getOption('exclude')) {
            $affectedProjects = array_values(array_filter($affectedProjects, fn(string $project) => ! in_array($project, $excludedProjects)));
        }

        if ($input->getOption('output')) {
            $output->write(implode(PHP_EOL, $affectedProjects));

            return self::SUCCESS;
        }

        if ($input->getOption('graph')) {
            $output->write($projectGraph->toDot($affectedProjects));

            return self::SUCCESS;
        }

        foreach ($affectedProjects as $projectName) {
            $project = $projectGraph->projects[$projectName];

            if (! in_array($input->getOption('target'), [...$project->scripts, 'install'])) {
                $output->writeln(
                    sprintf('Skipping task "%s" in "%s"', $input->getOption('target'), $project->name),
                    OutputInterface::VERBOSITY_VERBOSE,
                );

                continue;
            }

            $output->writeln(sprintf('Running task "%s" in "%s"', $input->getOption('target'), $project->name));

            $process = (new Process([
                'composer',
                "--working-dir=./{$project->root}",
                ...$input->isInteractive() ? ["--ansi"] : [],
                $input->getOption('target'),
            ]));

            $process->run(fn($type, $buffer) => $output->write($buffer));

            $process->wait();
        }

        return self::SUCCESS;
    }

    public function isProxyCommand(): bool
    {
        return true;
    }
}
