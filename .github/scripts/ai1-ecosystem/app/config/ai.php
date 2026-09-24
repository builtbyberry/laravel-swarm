<?php

return array_replace_recursive(require base_path('vendor/laravel/ai/config/ai.php'), [
    'providers' => ['openai' => ['key' => 'c5-not-a-real-api-key']],
    'caching' => ['embeddings' => ['cache' => false]],
    'conversations' => ['generate_title' => false],
]);
