<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\Interruptable;
use NeuronAI\Tools\InterruptableTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

class InterruptableTestTool extends Tool implements InterruptableTool
{
    use Interruptable;

    public int $invocations = 0;

    public bool $postInterruptCodeRan = false;

    public function __construct(string $name = 'interruptable_tool')
    {
        parent::__construct($name, 'A tool that interrupts');
    }

    public function __invoke(): string
    {
        $this->invocations++;

        $decision = $this->interrupt(
            new ApprovalRequest('approve?', [new Action('go', 'Go')])
        );

        // Only reachable after resume (interrupt() returned instead of throwing).
        $this->postInterruptCodeRan = true;

        return $decision->getAction('go')?->isApproved() ? 'approved' : 'rejected';
    }
}
