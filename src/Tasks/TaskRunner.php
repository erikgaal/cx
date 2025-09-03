<?php

declare(strict_types=1);

namespace Cx\Tasks;

use Cx\Graph\Project;
use Cx\Tasks\Renderer\Renderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class TaskRunner
{
    /** @var list<Task> */
    private array $finishedTasks;

    public function __construct(
        private readonly Renderer $renderer,
    ) {}

    /**
     * Runs the task execution process.
     * 
     * @param TaskRunnerOptions $options Configuration options for running tasks.
     * 
     * @return int Exit code (0 for success, 1 for failure).
     */
    public function run(TaskRunnerOptions $options): int
    {
        $tasks = $this->prepareTasks($options);
        $this->finishedTasks = [];

        if ($tasks->isEmpty()) {
            $this->renderer->nothingToRun($options, $tasks);

            return 1;
        }

        $this->renderer->started($options, $tasks);

        do {
            $this->renderer->tick($options, $tasks);

            if (($tasksToStart = ($options->parallel - $tasks->running()->count())) > 0) {
                $tasks->ready()->take($tasksToStart)->each(function (Task $task) use ($options) {
                    $task->start();
                    $this->renderer->taskStarted($options, $task);
                });
            }

            $tasks
                ->finished()
                ->each(function (Task $task) use ($options) {
                    if (in_array($task, $this->finishedTasks)) {
                        return;
                    }

                    $this->finishedTasks[] = $task;
                    $this->renderer->taskFinished($options, $task);
                });

            if ($tasks->remaining()->isEmpty()) {
                break;
            }

            usleep(200 * 1000);
        } while (true);

        $this->renderer->finished($options, $tasks);

        return $tasks->failed()->isEmpty() ? 0 : 1;
    }

    /**
     * Prepares the collection of tasks to run based on the given options.
     * 
     * @param TaskRunnerOptions $options Configuration options specifying task execution.
     * 
     * @return TaskCollection Collection of tasks to be executed.
     */
    private function prepareTasks(TaskRunnerOptions $options): TaskCollection
    {
        $projects = $options->projectGraph->projects;

        if ($options->projects) {
            $projects = array_values(
                array_filter($projects, fn(Project $project) => in_array($project->name, $options->projects)),
            );
        }

        $tasksToRun = collect($projects)
            ->flatMap(fn(Project $project)
                => collect([...$project->scripts, 'install', 'validate'])
                ->filter(fn(string $script) => Str::is($options->targets, $script))
                ->map(fn(string $script) => [$project, $script]));

        return new TaskCollection($tasksToRun->map(function (array $task) {
            [$project, $task] = $task;

            return new Task(
                project: $project,
                target: $task,
                process: new Process([
                    'composer',
                    '--ansi',
                    $task,
                ], realpath($project->root) ?: null),
            );
        }));
    }
}
