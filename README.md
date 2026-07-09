<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Orchestrator

> **Event-sourced process orchestration** for the Milpa PHP framework: **everything is a process**, a process is a state machine, and **state is a projection of an append-only log**. Human gates carry live decision surfaces whose options map **1:1** to the process's own transitions; self-approval is refused by construction; three MCP tools drive it all. The greenhouse (`example-agent-ready-blog`) proved the loop before this package froze the contracts.

[![CI](https://github.com/getmilpa/orchestrator/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/orchestrator/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/orchestrator.svg)](https://packagist.org/packages/milpa/orchestrator)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/orchestrator/)

`milpa/orchestrator` is the process engine of the Milpa family. It takes a `milpa/workflow`
state machine — states, transitions, and human gates — and runs it as an **event-sourced
process**: nothing stores `current_state`, every read replays an append-only log through a pure
reducer, and human decision points surface as `milpa/live` components whose options can never
drift from the transitions they actually resolve. It has **no ORM, no HTTP kernel, no product
coupling** — the domain (a blog post, an invoice, a support ticket) lives entirely in the
consumer's decision-surface factory and its `process.terminal` listener.

## Install

```bash
composer require milpa/orchestrator
```

## The thesis

Two ideas hold the whole engine together:

1. **Everything is a process, and a process is a state machine.** A `ProcessDefinition` is a set
   of `milpa/workflow` `StateDefinition`s wired by `TransitionDefinition`s, exactly one marked
   initial. A state whose outgoing transitions carry a `GateDefinition` is a **human decision
   point**; every other state advances automatically.
2. **State is a projection of the log, never a stored field.** Starting or advancing a process
   only ever *appends events* to an `EventStoreInterface`. The current state is whatever the pure
   `Reducer` folds those events into — recomputed fresh on every read. Two handles built from the
   same instance id over the same log always agree, and neither can cache a stale answer.

Everything else — the auto-advancing runner, the human gate, the three MCP tools — is built on
those two invariants.

## Quick example

Define a three-state publishing process — `draft → review → published`, with a human gate on
`review` and a `reject` transition that loops back to `draft` for revision:

```php
use Milpa\Orchestrator\ProcessDefinition;
use Milpa\Workflow\Entities\GateDefinition;
use Milpa\Workflow\Entities\StateDefinition;
use Milpa\Workflow\Entities\TransitionDefinition;
use Milpa\Workflow\Enums\ApprovalPolicy;

$draft     = (new StateDefinition())->setDomain('publish_post')->setCode('draft')->setLabel('Draft')->setIsInitial(true);
$review    = (new StateDefinition())->setDomain('publish_post')->setCode('review')->setLabel('In review');
$published = (new StateDefinition())->setDomain('publish_post')->setCode('published')->setLabel('Published')->setIsTerminal(true);

$gate = (new GateDefinition())
    ->setDomain('publish_post')->setCode('review_gate')->setName('Editorial review')
    ->setRequesterRole('author')->setApproverRole('editor')
    ->setApprovalPolicy(ApprovalPolicy::SINGLE);

$submit  = (new TransitionDefinition())->setDomain('publish_post')->setCode('submit')->setFromState($draft)->setToState($review);
$approve = (new TransitionDefinition())->setDomain('publish_post')->setCode('approve')->setFromState($review)->setToState($published);
$reject  = (new TransitionDefinition())->setDomain('publish_post')->setCode('reject')->setFromState($review)->setToState($draft);
$approve->addGateDefinition($gate);   // both outcomes share the SAME gate: one checkpoint,
$reject->addGateDefinition($gate);    // two options (approve | reject)

$definition = new ProcessDefinition([$draft, $review, $published], [$submit, $approve, $reject]);
```

Wire the engine and expose it through the three tools:

```php
use Milpa\EventStore\FileEventStore;
use Milpa\Eventing\EventDispatcher;
use Milpa\Orchestrator\HumanGate;
use Milpa\Orchestrator\ProcessDefinitionRegistry;
use Milpa\Orchestrator\ProcessRunner;
use Milpa\Orchestrator\Tools\ProcessInstantiateTool;
use Milpa\Orchestrator\Tools\ProcessListPendingApprovalsTool;
use Milpa\Orchestrator\Tools\ProcessSubmitDecisionTool;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Psr\Log\NullLogger;

$store      = new FileEventStore('/tmp/posts.jsonl');   // the append-only log
$dispatcher = new EventDispatcher(new NullLogger());    // milpa/events
$dispatcher->subscribe('process.terminal', function (string $name, array $payload): void {
    // Reaching `published` runs the real domain effect HERE — the engine itself touches no
    // domain entity. $payload = {instance_id, final_state, context}.
});

$registry = new ProcessDefinitionRegistry();
$registry->register('publish_post', $definition);

$gate   = new HumanGate(new YourDecisionSurfaceFactory());   // a milpa/live surface, consumer-supplied
$runner = new ProcessRunner($dispatcher);

$instantiate = new ProcessInstantiateTool($store, $gate, $runner, $registry);
$instantiate->setCurrentContext(ToolContext::mcp('req-1', 'agent:author', ['*']));
$list   = new ProcessListPendingApprovalsTool($store, $gate, $registry);
$submit = new ProcessSubmitDecisionTool($store, $gate, $runner, $registry);
```

Now drive the loop — instantiate, hit the gate, submit a decision, reach terminal, and prove the
state was never stored by replaying it from a fresh log:

```php
use Milpa\Orchestrator\ProcessInstance;

// 1. Instantiate — auto-advances draft --submit--> review and PARKS at the human gate.
$started    = $instantiate->instantiate('publish_post', ['post_id' => 42]);
$instanceId = $started->data['instance_id'];
$started->data['current_state'];   // 'review' — the runner stopped at the gate, not past it

// 2. The gate is pending; its options are projected 1:1 from the process's OWN transitions.
$pending = $list->list()->data['pending'][0];
$pending['assignee'];   // 'editor'
$pending['options'];    // ['approve', 'reject']
$gateId  = $pending['gate_id'];

// 3. An editor — NOT the author — resolves it. Self-approval is refused by construction:
//    submitting as 'agent:author' here returns error SELF_APPROVAL_FORBIDDEN instead.
$done = $submit->submit($instanceId, $gateId, 'approve', 'human:editor');
$done->data['current_state'];   // 'published' — auto-advanced past the gate to terminal;
                                //  `process.terminal` fired exactly once.

// 4. State is a projection: a FRESH store + handle over the SAME log reconstructs it, no cache.
$replayed = new ProcessInstance($instanceId, $definition);
$replayed->currentState(new FileEventStore('/tmp/posts.jsonl'));   // 'published'
```

Had the editor chosen `reject`, the runner would have driven `review --reject--> draft
--submit--> review` and re-opened a fresh gate — the revise-and-resubmit loop, all inside that
one `process_submit_decision` call. (This exact loop is exercised end to end in
`tests/ProcessLoopTest.php`.)

## Composes the family

The orchestrator writes almost no primitives of its own — it *composes* the packages below the
process tier and adds only the folding, running, and gating that turn them into a process engine:

| Package | Role in a process |
|---------|-------------------|
| [`milpa/event-store`](https://packagist.org/packages/milpa/event-store) | **The log.** Every start, transition, gate opening, and decision is an `Event` appended to an `EventStoreInterface`. The engine stores nothing else. |
| [`milpa/workflow`](https://packagist.org/packages/milpa/workflow) | **The gates + self-approval rule.** `ProcessDefinition` is built from workflow `StateDefinition`/`TransitionDefinition`/`GateDefinition`; `HumanGate` delegates the D9 anti-self-approval check to workflow's `GateServiceInterface` rather than reimplementing it. |
| [`milpa/live`](https://packagist.org/packages/milpa/live) | **The decision surfaces.** A `DecisionSurfaceInterface` is a `milpa/live` component whose `options()` must equal the gate's transitions 1:1 — `PendingDecision`'s constructor enforces that invariant, so a stale artifact fails loudly instead of offering actions the gate does not have. |
| [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) | **The three MCP tools.** `process_instantiate`, `process_list_pending_approvals`, and `process_submit_decision` are `#[Tool]`-attributed methods that run through the tool-runtime pipeline like any other agent-callable tool. |
| [`milpa/events`](https://packagist.org/packages/milpa/events) | **The reducer/terminal seam.** The reference `MilpaEventDispatcherInterface` implementation; `ProcessRunner` dispatches `process.terminal` through it exactly once per instance, where a consumer runs whatever domain effect reaching a terminal state should trigger. |

Because the engine is domain-agnostic, the two things it does **not** own are exactly the two a
consumer supplies: a `DecisionSurfaceFactoryInterface` (what a gate's surface renders for its
domain) and a `process.terminal` listener (what reaching a terminal state *does*).

## Proven in a greenhouse

Before these contracts were frozen, the whole loop was grown and validated inside
**`example-agent-ready-blog`** — the family's greenhouse — as a real `PublishPostProcess`: an
agent drafts a post, submits it, a human editor approves or rejects it through a live decision
surface, and reaching `published` actually publishes the post via a `process.terminal` listener.
This package is that proven loop, lifted out domain-free: the greenhouse kept the blog; the
orchestrator kept the engine.

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.5**
- [`milpa/event-store`](https://packagist.org/packages/milpa/event-store) **^0.1**
- [`milpa/workflow`](https://packagist.org/packages/milpa/workflow) **^0.1.2**
- [`milpa/events`](https://packagist.org/packages/milpa/events) **^0.2**
- [`milpa/live`](https://packagist.org/packages/milpa/live) **^0.1**
- [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) **^0.5.1**

## Documentation

**Full API reference: [getmilpa.github.io/orchestrator](https://getmilpa.github.io/orchestrator/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=orchestrator)**.
