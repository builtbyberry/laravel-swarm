# Sequential Content Pipeline

Shows the core Swarm mental model: a reusable workflow class returns the agents
that participate, and each agent receives the previous agent's output.

Use this pattern when every step should always run in the same order.

This example teaches:

- how to define a swarm;
- how Laravel AI agents plug into the swarm;
- how sequential handoff works;
- how to pass plain structured input.

## Files To Create

### `app/Ai/Swarms/ContentPipeline.php`

```php
<?php

namespace App\Ai\Swarms;

use App\Ai\Agents\ArticleEditor;
use App\Ai\Agents\ArticlePlanner;
use App\Ai\Agents\ArticleResearcher;
use App\Ai\Agents\ArticleWriter;
use BuiltByBerry\LaravelSwarm\Attributes\Topology;
use BuiltByBerry\LaravelSwarm\Concerns\Runnable;
use BuiltByBerry\LaravelSwarm\Contracts\Swarm;
use BuiltByBerry\LaravelSwarm\Enums\Topology as TopologyEnum;

#[Topology(TopologyEnum::Sequential)]
class ContentPipeline implements Swarm
{
    use Runnable;

    public function agents(): array
    {
        return [
            new ArticlePlanner,
            new ArticleResearcher,
            new ArticleWriter,
            new ArticleEditor,
        ];
    }
}
```

### `app/Ai/Agents/ArticlePlanner.php`

This agent shows the explicit Laravel AI provider/model attribute pattern.
Other agents may use the same attributes or rely on your app's Laravel AI
defaults.

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5-20251001')]
class ArticlePlanner implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Create a concise article plan with sections, audience, and angle.';
    }
}
```

### `app/Ai/Agents/ArticleResearcher.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ArticleResearcher implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Add supporting research, examples, and technical details to the article plan.';
    }
}
```

### `app/Ai/Agents/ArticleWriter.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ArticleWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Write the article draft from the researched outline.';
    }
}
```

### `app/Ai/Agents/ArticleEditor.php`

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class ArticleEditor implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Edit the draft for clarity, correctness, and Laravel developer tone.';
    }
}
```

## Run It

```php
use App\Ai\Swarms\ContentPipeline;

$response = ContentPipeline::make()->prompt([
    'topic' => 'Laravel queue visibility timeouts',
    'audience' => 'intermediate Laravel developers',
    'format' => '1200-word article',
]);

$response->output;
$response->steps;
```

## What Happened

The original task goes to `ArticlePlanner`. Its output becomes the input for
`ArticleResearcher`. That output becomes the input for `ArticleWriter`, and the
writer's draft becomes the input for `ArticleEditor`.

The final editor output is `$response->output`. The intermediate handoffs are
available in `$response->steps`.

The array task is plain data, so the same input shape can be used with `prompt()`,
`queue()`, `stream()`, or `dispatchDurable()` when the topology supports it.
