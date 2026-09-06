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

namespace Milpa\Orchestrator\Tests\Declaration\Fixtures;

/** The editor's options. Its cases ARE the options of the gate that offers them. */
enum EditorCall: string
{
    case PublishAsIs = 'publish_as_is';
    case OneMoreRound = 'one_more_round';
    case Abandon = 'abandon';
}
