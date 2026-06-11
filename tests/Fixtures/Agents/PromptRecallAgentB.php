<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarm\Tests\Fixtures\Agents;

/**
 * Second concurrent recall branch worker (distinct class so the static
 * hierarchical planner accepts two recall workers in one parallel group).
 */
class PromptRecallAgentB extends PromptRecallAgent {}
