<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\Tool;

/**
 * A plain (non-interruptable) tool that records how many times it executed,
 * used to assert it is not re-executed when a later tool in the batch
 * interrupts and the node resumes.
 */
class RecordableTool extends Tool
{
    public int $executions = 0;

    public function __construct(string $name = 'recordable_tool')
    {
        parent::__construct($name, 'Records executions');
    }

    public function __invoke(): string
    {
        $this->executions++;
        return "run-{$this->executions}";
    }
}
