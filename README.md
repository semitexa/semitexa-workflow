# Semitexa Workflow

Stateful business process orchestration with code-defined state machines, transition guards, and scheduler integration.

## Purpose

Models business processes as state machines with explicit transitions. Each workflow definition declares states, allowed transitions, guard conditions, and side-effects. History is persisted via ORM and async transitions are delegated to the Scheduler.

## Role in Semitexa

Depends on Core, ORM, Tenancy, and Scheduler. Used by application modules that need formalized multi-step processes with auditable state progression and conditional branching.

## Key Features

- `#[AsWorkflowDefinition]` attribute for workflow registration
- Code-defined state machines with typed transitions
- Transition guards for conditional progression
- Side-effects and callbacks on state changes
- Full transition history via ORM
- Scheduler integration for delayed/async transitions
- Event payloads: `WorkflowCompleted`, `WorkflowFailed`, `WorkflowTransitionApplied`, `WorkflowTransitionRejected`
