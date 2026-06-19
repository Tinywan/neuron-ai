<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Tools;

use NeuronAI\Tools\Interruptable;
use NeuronAI\Tools\InterruptableTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

/**
 * Calls interrupt() twice in one invocation, to verify that the resume request
 * is consumed after the first use so the second interrupt() re-throws instead
 * of silently passing through.
 */
class DoubleInterruptTestTool extends Tool implements InterruptableTool
{
    use Interruptable;

    public function __construct()
    {
        parent::__construct('double_interrupt_tool', 'Interrupts twice');
    }

    public function __invoke(): string
    {
        $this->interrupt(new ApprovalRequest('first'));
        $this->interrupt(new ApprovalRequest('second'));

        return 'done';
    }
}
