<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\ToolInterrupt;

use function is_callable;

/**
 * Gives a tool the ability to request an interruption from inside its
 * __invoke() method, mirroring the Workflow+Node interrupt experience.
 *
 * Usage:
 *
 * class MyTool extends Tool implements InterruptableTool
 * {
 *     use Interruptable;
 *
 *     public function __invoke(string $query): string
 *     {
 *         $decision = $this->interrupt(new ApprovalRequest("Approve {$query}?"));
 *         // On the first run interrupt() throws and pauses the agent.
 *         // On resume it returns the request (with the user's decision) instead.
 *         return $this->doWork($decision);
 *     }
 * }
 */
trait Interruptable
{
    protected ?InterruptRequest $resumeRequest = null;

    public function setResumeRequest(?InterruptRequest $request): static
    {
        $this->resumeRequest = $request;
        return $this;
    }

    /**
     * Request an interruption.
     *
     * On the first run this throws and halts execution. On resume (after the
     * agent is restarted with the user's decision) it returns the request,
     * so execution continues on the line after the call.
     *
     * @template T of InterruptRequest
     * @param T $request
     * @return T|null
     * @throws ToolInterrupt
     */
    protected function interrupt(InterruptRequest $request): ?InterruptRequest
    {
        return $this->interruptIf(true, $request);
    }

    /**
     * Request an interruption only when the condition is met.
     *
     * @template T of InterruptRequest
     * @param T $request
     * @return T|null
     * @throws ToolInterrupt
     */
    protected function interruptIf(callable|bool $condition, InterruptRequest $request): ?InterruptRequest
    {
        if ($this->resumeRequest instanceof InterruptRequest) {
            $request = $this->resumeRequest;
            // Clear after use so a subsequent interrupt() in the same call re-throws.
            $this->resumeRequest = null;
            return $request;
        }

        $shouldInterrupt = is_callable($condition) ? $condition() : $condition;

        if ($shouldInterrupt) {
            throw new ToolInterrupt($request);
        }

        return null;
    }
}
