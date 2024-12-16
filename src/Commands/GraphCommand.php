<?php

declare(strict_types=1);

namespace Cx\Commands;

use Composer\Command\BaseCommand;
use Composer\Console\Input\InputOption;
use Cx\Git\Files;
use Cx\Graph\ProjectGraphFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GraphCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('graph')
            ->setDefinition([
                new InputOption('affected', mode: InputOption::VALUE_NONE),
                new InputOption('base', null, InputOption::VALUE_OPTIONAL, default: 'main'),
                new InputOption('head', null, InputOption::VALUE_OPTIONAL),
                new InputOption('uncommitted', null, InputOption::VALUE_NONE),
                new InputOption('untracked', null, InputOption::VALUE_NONE),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectGraph = ProjectGraphFactory::createProjectGraph();

        if ($input->getOption('affected')) {
            $files = Files::diff(
                base: $input->getOption('base'),
                uncommitted: $input->getOption('uncommitted'),
                untracked: $input->getOption('untracked'),
                head: $input->getOption('head'),
            );

            $changedProjects = [];

            foreach ($files as $file) {
                foreach ($projectGraph->projects as $project) {
                    if (str_starts_with($file, $project->root)) {
                        $changedProjects[] = $project->name;
                    }
                }
            }

            $affectedProjects = $projectGraph->affected(...$changedProjects);
        }

        $output->write($projectGraph->toDot($affectedProjects ?? []));

        return self::SUCCESS;
    }
}
