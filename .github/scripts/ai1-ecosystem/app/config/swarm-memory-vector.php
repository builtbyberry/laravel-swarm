<?php

return array_replace_recursive(require base_path('vendor/builtbyberry/laravel-swarm-memory-vector/config/swarm-memory-vector.php'), [
    'enabled' => true, 'driver' => 'scan', 'connection' => 'sqlite',
    'embedding' => ['provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 16],
    'on_embedding_failure' => 'throw',
]);
