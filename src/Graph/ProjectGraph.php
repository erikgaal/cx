<?php

declare(strict_types=1);

namespace Cx\Graph;

use Fhaculty\Graph\Graph;
use Fhaculty\Graph\Vertex;
use Graphp\Algorithms\Search\Base;
use Graphp\Algorithms\Search\BreadthFirst;
use Graphp\GraphViz\GraphViz;
use RuntimeException;

final class ProjectGraph
{
    public function __construct(
        /** @var array<string, Project> */
        public readonly array $projects,
        public readonly Graph $graph,
    ) {}

    public function getRoot(): Project
    {
        foreach ($this->projects as $project) {
            if ($project->isRoot()) {
                return $project;
            }
        }

        throw new RuntimeException('No root project found');
    }

    /**
     * @param string ...$projectsWithChanges
     * @return list<string>
     */
    public function affected(string ...$projectsWithChanges): array
    {
        $affectedProjects = [];

        foreach ($projectsWithChanges as $projectWithChanges) {
            if (! $vertex = $this->getProjectVertex($projectWithChanges)) {
                continue;
            }

            $search = (new BreadthFirst($vertex))->setDirection(Base::DIRECTION_REVERSE);

            $affectedProjects = array_unique([
                ...$affectedProjects,
                ...$search->getVertices()->getIds(),
            ]);
        }

        sort($affectedProjects);

        return $affectedProjects;
    }

    /**
     * @param list<string> $affectedProjects
     */
    public function toMermaid(array $affectedProjects = []): string
    {
        $mermaid = "graph TD";

        if (count($affectedProjects) > 0) {
            $mermaid .= PHP_EOL . "    classDef affected fill:pink";
        }

        foreach ($this->graph->getVertices() as $project) {
            if (in_array($project->getId(), $affectedProjects)) {
                $mermaid .= PHP_EOL . "    {$project->getId()}:::affected";
            }
        }

        foreach ($this->graph->getEdges() as $dependency) {
            /** @var \Fhaculty\Graph\Edge\Directed $dependency */
            $mermaid .= PHP_EOL . "    {$dependency->getVertexStart()->getId()} --> {$dependency->getVertexEnd()->getId()}";
        }

        return $mermaid;
    }

    /**
     * @param list<string> $affectedProjects
     */
    public function toDot(array $affectedProjects = []): string
    {
        $graph = $this->graph->createGraphClone();

        if (count($affectedProjects) > 0) {
            foreach ($graph->getVertices() as $vertex) {
                if (in_array($vertex->getId(), $affectedProjects)) {
                    $vertex->setAttribute('graphviz.fillcolor', 'pink');
                    $vertex->setAttribute('graphviz.style', 'filled');
                }
            }
        }

        return (new GraphViz())->createScript($graph);
    }

    private function getProjectVertex(string $projectName): ?Vertex
    {
        return $this->graph->hasVertex($projectName)
            ? $this->graph->getVertex($projectName)
            : null;
    }
}
