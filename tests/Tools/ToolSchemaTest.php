<?php

declare(strict_types=1);

namespace Milpa\Orchestrator\Tests\Tools;

use Milpa\EventStore\InMemoryEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tests\Fixtures\RecordingToolRegistry;
use Milpa\Orchestrator\Tests\Fixtures\StubDecisionSurfaceFactory;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\ToolScanner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves the 3 tools generate correct JSON schemas via {@see ToolScanner} — in particular that
 * `process_instantiate`'s `inputs` parameter (declared `array $inputs` with `#[Param(type:
 * 'object')]`, tool-runtime 0.6) produces schema `type: object`, NOT the `type: array` a bare
 * `array` parameter would infer (which {@see \Milpa\ToolRuntime\SchemaValidator} would then
 * require to be a list, rejecting an associative payload).
 */
final class ToolSchemaTest extends TestCase
{
    private function registry(): RecordingToolRegistry
    {
        $store = new InMemoryEventStore();
        $definitionRegistry = new ProcessDefinitionRegistry();
        $gate = new HumanGate(new StubDecisionSurfaceFactory());
        $runner = new ProcessRunner(new EventDispatcher(new NullLogger()));

        $recorder = new RecordingToolRegistry();
        $scanner = new ToolScanner($recorder);

        $scanner->scan(new ProcessInstantiateTool($store, $gate, $runner, $definitionRegistry));
        $scanner->scan(new ProcessListPendingApprovalsTool($store, $gate, $definitionRegistry));
        $scanner->scan(new ProcessSubmitDecisionTool($store, $gate, $runner, $definitionRegistry));

        return $recorder;
    }

    public function testProcessInstantiateInputsParamIsSchemaTypeObject(): void
    {
        $schema = $this->registry()->schemaFor('process_instantiate');

        $this->assertNotNull($schema);
        $this->assertSame('object', $schema['properties']['inputs']['type']);
        $this->assertSame('string', $schema['properties']['definition']['type']);
        $this->assertContains('definition', $schema['required']);
        $this->assertContains('inputs', $schema['required']);
    }

    public function testProcessListPendingApprovalsAssigneeParamIsOptionalString(): void
    {
        $schema = $this->registry()->schemaFor('process_list_pending_approvals');

        $this->assertNotNull($schema);
        $this->assertSame('string', $schema['properties']['assignee']['type']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testProcessSubmitDecisionParamsAreAllRequiredStrings(): void
    {
        $schema = $this->registry()->schemaFor('process_submit_decision');

        $this->assertNotNull($schema);
        foreach (['instance_id', 'gate_id', 'decision', 'principal'] as $param) {
            $this->assertSame('string', $schema['properties'][$param]['type']);
            $this->assertContains($param, $schema['required']);
        }
    }
}
