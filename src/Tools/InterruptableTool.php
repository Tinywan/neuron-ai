<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Marker for tools that can request an interruption from inside their execution.
 *
 * Combine with the {@see Interruptable} trait, which provides the `interrupt()`
 * and `interruptIf()` methods. ToolNode uses this interface to detect the
 * capability and inject the user's decision when resuming.
 */
interface InterruptableTool extends ToolInterface
{
    /**
     * Inject the resume request (the user's decision) before re-executing the
     * tool after an interruption. Set to null on the initial run.
     */
    public function setResumeRequest(?InterruptRequest $request): static;
}
