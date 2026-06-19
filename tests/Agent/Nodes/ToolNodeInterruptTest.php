<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tests\Agent\Tools\DoubleInterruptTestTool;
use NeuronAI\Tests\Agent\Tools\InterruptableTestTool;
use NeuronAI\Tests\Agent\Tools\RecordableTool;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class ToolNodeInterruptTest extends TestCase
{
    private function buildEvent(array $tools): ToolCallEvent
    {
        return new ToolCallEvent(
            new ToolCallMessage(null, $tools),
            new AIInferenceEvent('instructions', $tools)
        );
    }

    private function state(): AgentState
    {
        $state = new AgentState();
        // Required so the interrupt can be serialized (persistence round-trip).
        $state->set('__workflowId', 'test-workflow');
        return $state;
    }

    private function consume(ToolNode $node, ToolCallEvent $event, AgentState $state): void
    {
        foreach ($node($event, $state) as $_) {
            // prevent rector from removing the consumer loop
        }
    }

    /**
     * Drive a full interrupt/resume cycle on the serialized interrupt, mirroring
     * what the workflow executor does. Returns the resumed ToolCallEvent.
     */
    private function resume(WorkflowInterrupt $interrupt, InterruptRequest $request): ToolCallEvent
    {
        /** @var WorkflowInterrupt $restored */
        $restored = unserialize(serialize($interrupt));

        $node = $restored->getNode();
        $event = $restored->getEvent();
        $state = $restored->getState();
        $this->assertInstanceOf(ToolNode::class, $node);
        $this->assertInstanceOf(ToolCallEvent::class, $event);
        $this->assertInstanceOf(AgentState::class, $state);

        $node->setWorkflowContext($state, $event, $request);
        $this->consume($node, $event, $state);

        return $event;
    }

    public function test_tool_interrupts_on_first_run(): void
    {
        $tool = new InterruptableTestTool();
        $tool->setCallId('call_1')->setInputs([]);

        $state = $this->state();
        $event = $this->buildEvent([$tool]);
        $node = new ToolNode();
        $node->setWorkflowContext($state, $event);

        $caught = null;
        try {
            $this->consume($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the tool to interrupt');
        $this->assertInstanceOf(ApprovalRequest::class, $caught->getRequest());
        $this->assertFalse($tool->postInterruptCodeRan, 'Code after interrupt() must not run on the first pass');
    }

    public function test_tool_resumes_with_user_decision_after_persistence(): void
    {
        $tool = new InterruptableTestTool();
        $tool->setCallId('call_1')->setInputs([]);

        $state = $this->state();
        $event = $this->buildEvent([$tool]);
        $node = new ToolNode();
        $node->setWorkflowContext($state, $event);

        $caught = null;
        try {
            $this->consume($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);

        $request = $caught->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $request->getAction('go')->approve();

        $resumedEvent = $this->resume($caught, $request);

        $resumedTool = $resumedEvent->toolCallMessage->getTools()[0];
        $this->assertInstanceOf(InterruptableTestTool::class, $resumedTool);
        $this->assertSame('approved', $resumedTool->getResult());
        $this->assertTrue($resumedTool->postInterruptCodeRan, 'Post-interrupt code must run on resume');
    }

    public function test_tool_call_message_is_not_recorded_twice_on_resume(): void
    {
        $tool = new InterruptableTestTool();
        $tool->setCallId('call_1')->setInputs([]);

        $state = $this->state();
        $event = $this->buildEvent([$tool]);
        $node = new ToolNode();
        $node->setWorkflowContext($state, $event);

        $caught = null;
        try {
            $this->consume($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);

        // One step (the tool-call message) recorded on the first pass.
        $this->assertCount(1, $state->getSteps());

        $request = $caught->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $request->getAction('go')->approve();

        /** @var WorkflowInterrupt $restored */
        $restored = unserialize(serialize($caught));
        $resumedState = $restored->getState();
        $this->assertInstanceOf(AgentState::class, $resumedState);

        $resumedNode = $restored->getNode();
        $resumedEvent = $restored->getEvent();
        $this->assertInstanceOf(ToolNode::class, $resumedNode);
        $this->assertInstanceOf(ToolCallEvent::class, $resumedEvent);
        $resumedNode->setWorkflowContext($resumedState, $resumedEvent, $request);
        $this->consume($resumedNode, $resumedEvent, $resumedState);

        // Still one step: the resume pass must not re-add the tool-call message.
        $this->assertCount(1, $resumedState->getSteps());
    }

    public function test_completed_tool_is_not_re_executed_on_resume(): void
    {
        $plain = new RecordableTool();
        $plain->setCallId('call_plain')->setInputs([]);
        $inter = new InterruptableTestTool();
        $inter->setCallId('call_inter')->setInputs([]);

        $state = $this->state();
        $event = $this->buildEvent([$plain, $inter]);
        $node = new ToolNode();
        $node->setWorkflowContext($state, $event);

        $caught = null;
        try {
            $this->consume($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught);
        $this->assertSame(1, $plain->executions, 'Plain tool ran once before the interrupt');

        $request = $caught->getRequest();
        $this->assertInstanceOf(ApprovalRequest::class, $request);
        $request->getAction('go')->approve();

        $resumedEvent = $this->resume($caught, $request);

        $tools = $resumedEvent->toolCallMessage->getTools();
        $this->assertInstanceOf(RecordableTool::class, $tools[0]);
        $this->assertSame(1, $tools[0]->executions, 'Completed tool must not re-execute on resume');
        $this->assertSame('approved', $tools[1]->getResult());
    }

    public function test_second_interrupt_re_throws_after_first_resume(): void
    {
        $tool = new DoubleInterruptTestTool();
        $tool->setCallId('call_1')->setInputs([]);

        $state = $this->state();
        $event = $this->buildEvent([$tool]);
        $node = new ToolNode();
        $node->setWorkflowContext($state, $event);

        // First pass: interrupts at the first call.
        $first = null;
        try {
            $this->consume($node, $event, $state);
        } catch (WorkflowInterrupt $e) {
            $first = $e;
        }
        $this->assertNotNull($first);
        $this->assertSame('first', $first->getRequest()->getMessage());

        // Resume with the first request consumed: the second interrupt must re-throw,
        // not silently pass through.
        $second = null;
        try {
            $this->resume($first, $first->getRequest());
        } catch (WorkflowInterrupt $e) {
            $second = $e;
        }
        $this->assertNotNull($second, 'Second interrupt() must throw after the first resume request is consumed');
        $this->assertSame('second', $second->getRequest()->getMessage());
    }
}
