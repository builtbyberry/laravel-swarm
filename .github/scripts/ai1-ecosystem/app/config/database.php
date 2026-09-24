<?php

return ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => database_path('database.sqlite'), 'prefix' => '', 'foreign_key_constraints' => true]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true]];
