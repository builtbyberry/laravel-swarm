# Maintenance

Laravel Swarm's database-backed persistence uses prune-based TTL retention.

`ttlSeconds` is still applied when context, artifacts, and run history rows are
written, but database records remain queryable until you prune expired rows.

## Pruning Expired Records

Use the built-in prune command to remove expired records from the swarm
database tables:

```bash
php artisan swarm:prune
```

The command prunes the history, context, and artifact tables in bounded chunks
to avoid long-running table locks on large datasets.

Laravel Swarm protects active runs across all three persistence stores. While a
run is `running`, its history, context, and artifact rows are not pruned, even
if their retention window has elapsed.

## Scheduling

If you are using the database persistence driver in production, schedule the
prune command in Laravel's scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('swarm:prune')->daily();
```
