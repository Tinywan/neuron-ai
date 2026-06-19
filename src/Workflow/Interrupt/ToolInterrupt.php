<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

use NeuronAI\Exceptions\WorkflowException;

/**
 * Internal bridge exception thrown by an interruptable tool to request an
 * interruption from inside its __invoke() execution.
 *
 * Unlike {@see WorkflowInterrupt}, it carries only the request: a tool has no
 * access to the node, state, or event. ToolNode catches it and translates it
 * into a fully-populated WorkflowInterrupt. It is never persisted and never
 * reaches user code.
 */
class ToolInterrupt extends WorkflowException
{
    public function __construct(protected InterruptRequest $request)
    {
        parent::__construct($request->getMessage());
    }

    public function getRequest(): InterruptRequest
    {
        return $this->request;
    }
}
