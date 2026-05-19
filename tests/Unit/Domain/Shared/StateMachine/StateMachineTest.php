<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\StateMachine;

use App\Domain\Shared\StateMachine\InvalidTransition;
use App\Domain\Shared\StateMachine\State;
use App\Domain\Shared\StateMachine\StateMachine;
use Tests\Support\UnitTestCase;

final class StateMachineTest extends UnitTestCase
{
    private function invoiceMachine(): StateMachine
    {
        return new StateMachine('Invoice', [
            'draft' => ['approved', 'cancelled'],
            'approved' => ['posted', 'cancelled'],
            'posted' => ['paid', 'voided'],
            'paid' => [],
            'cancelled' => [],
            'voided' => [],
        ]);
    }

    public function test_allowed_transitions_succeed_silently(): void
    {
        $machine = $this->invoiceMachine();

        $machine->transition('draft', 'approved');
        $machine->transition('approved', 'posted');
        $machine->transition('posted', 'paid');

        $this->assertTrue(true, 'no exceptions thrown');
    }

    public function test_disallowed_transition_throws_with_explanation(): void
    {
        $machine = $this->invoiceMachine();

        try {
            $machine->transition('draft', 'paid');
            $this->fail('Expected InvalidTransition');
        } catch (InvalidTransition $e) {
            $this->assertStringContainsString('Invoice', $e->getMessage());
            $this->assertStringContainsString('draft -> paid', $e->getMessage());
            $this->assertStringContainsString('approved, cancelled', $e->getMessage());
        }
    }

    public function test_transition_from_unknown_state_throws(): void
    {
        $machine = $this->invoiceMachine();

        $this->expectException(InvalidTransition::class);
        $machine->transition('unknown', 'draft');
    }

    public function test_can_transition_returns_bool(): void
    {
        $machine = $this->invoiceMachine();

        $this->assertTrue($machine->canTransition('draft', 'approved'));
        $this->assertFalse($machine->canTransition('paid', 'draft'));
    }

    public function test_allowed_from_lists_targets(): void
    {
        $machine = $this->invoiceMachine();

        $this->assertSame(['approved', 'cancelled'], $machine->allowedFrom('draft'));
        $this->assertSame([], $machine->allowedFrom('paid'));
        $this->assertSame([], $machine->allowedFrom('unknown'));
    }

    public function test_is_terminal_for_leaf_state(): void
    {
        $machine = $this->invoiceMachine();

        $this->assertTrue($machine->isTerminal('paid'));
        $this->assertTrue($machine->isTerminal('cancelled'));
        $this->assertFalse($machine->isTerminal('draft'));
    }

    public function test_accepts_state_implementing_objects(): void
    {
        $machine = $this->invoiceMachine();

        $draft = new class implements State {
            public function stateName(): string
            {
                return 'draft';
            }
        };
        $approved = new class implements State {
            public function stateName(): string
            {
                return 'approved';
            }
        };

        $machine->transition($draft, $approved);
        $this->assertTrue(true);
    }

    public function test_terminal_state_cannot_transition_anywhere(): void
    {
        $machine = $this->invoiceMachine();

        $this->assertFalse($machine->canTransition('paid', 'draft'));
        $this->assertFalse($machine->canTransition('paid', 'approved'));
        $this->assertFalse($machine->canTransition('paid', 'cancelled'));
    }
}
