<?php

/**
 * This file is part of milpa/orchestrator — the generic event-sourced process engine of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/orchestrator
 */

declare(strict_types=1);

namespace Milpa\Orchestrator\Declaration;

/**
 * A graph that cannot be compiled without guessing — refused, naming the class and what to declare.
 *
 * It is the APP's mistake, and it is let out as a failure. What a CALLER got wrong — a graph nobody declared, a run
 * that is not where the call needs it — is its one subclass, {@see CallRefused}, which the operations answer.
 */
class GraphDeclarationException extends \InvalidArgumentException
{
}
