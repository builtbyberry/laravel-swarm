# Filesystem Agent Tools

Laravel Swarm exposes Laravel AI's filesystem tools to your agents through the
`HasSwarmFilesystemTools` concern, with every operation scoped to a single
Filesystem disk you choose. An agent wired with these tools can read, write,
list, and delete files on that disk as part of a run — useful for archiving
generated artifacts, staging inputs for a worker, or letting a coordinator
inspect a working directory.

Like the [Recall / Remember memory tools](memory-recipes.md), this is an
**explicit opt-in**: granting an LLM filesystem access is a decision with real
blast radius, so the tools are disabled by default and stay inert until you name
a disk.

## Enabling the tools

Two switches under `swarm.filesystem.tools` must **both** be set:

1. `enabled` — the master switch (default `false`).
2. `disk` — the Filesystem disk every tool is bound to. It has **no default**;
   while it is null the concern returns no tools even when `enabled` is true.

```dotenv
SWARM_FILESYSTEM_TOOLS_ENABLED=true
SWARM_FILESYSTEM_TOOLS_DISK=agent-sandbox
```

Define the disk in `config/filesystems.php`, rooted in its own directory:

```php
'disks' => [
    'agent-sandbox' => [
        'driver' => 'local',
        'root' => storage_path('app/agent-sandbox'),
        'throw' => false,
    ],
],
```

> **Scope the disk to the blast radius you accept.** Whatever the disk can reach,
> an agent wired with the write/delete tools can modify or remove. Point `disk`
> at a **dedicated, sandboxed** disk — never `local` or `public`, which span your
> whole application storage.

## Attaching the tools to an agent

Add the trait and spread `swarmFilesystemTools()` into the agent's `tools()`:

```php
use BuiltByBerry\LaravelSwarm\Concerns\HasSwarmFilesystemTools;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class Archivist implements Agent, HasTools
{
    use HasSwarmFilesystemTools;
    use Promptable;

    public function instructions(): string
    {
        return 'Save the final report to reports/ and confirm the path.';
    }

    public function tools(): iterable
    {
        return [...$this->swarmFilesystemTools()];
    }
}
```

Adding the trait is safe and inert until the config is turned on, so you can wire
it ahead of enabling it app-wide.

## The tools

| Tool | Purpose |
| --- | --- |
| `ReadFile` | Read a UTF-8 text file (rejects files over 256 KB or non-UTF-8 binaries). |
| `WriteFile` | Write text contents to a path. |
| `ListFiles` | List files and directories under a path (optionally recursive). |
| `DeleteFile` | Delete a file. |
| `CopyFile` | Copy a file to a new path. |
| `FileExists` | Report whether a path exists. |
| `GetFileMetadata` | Return size, last-modified, and MIME metadata. |
| `GetFileUrl` | Return a URL for a file (for binaries too large to read inline). |

Every tool takes only **disk-relative** paths and resolves them through
`Storage::disk(...)`, so a model-supplied `..` traversal is rejected at the disk
boundary and cannot escape the disk root.

## Choosing which tools to expose

Each tool has its own toggle (all default `true` once `enabled` is true), so you
can hand an agent a read-only view by leaving the mutating tools off:

```dotenv
SWARM_FILESYSTEM_TOOLS_ENABLED=true
SWARM_FILESYSTEM_TOOLS_DISK=agent-sandbox

# Read-only agent — omit write, delete, and copy.
SWARM_FILESYSTEM_TOOLS_WRITE_FILE=false
SWARM_FILESYSTEM_TOOLS_DELETE_FILE=false
SWARM_FILESYSTEM_TOOLS_COPY_FILE=false
```

| Config key | Env var | Tool |
| --- | --- | --- |
| `read_file` | `SWARM_FILESYSTEM_TOOLS_READ_FILE` | `ReadFile` |
| `write_file` | `SWARM_FILESYSTEM_TOOLS_WRITE_FILE` | `WriteFile` |
| `list_files` | `SWARM_FILESYSTEM_TOOLS_LIST_FILES` | `ListFiles` |
| `delete_file` | `SWARM_FILESYSTEM_TOOLS_DELETE_FILE` | `DeleteFile` |
| `copy_file` | `SWARM_FILESYSTEM_TOOLS_COPY_FILE` | `CopyFile` |
| `file_exists` | `SWARM_FILESYSTEM_TOOLS_FILE_EXISTS` | `FileExists` |
| `get_file_metadata` | `SWARM_FILESYSTEM_TOOLS_GET_FILE_METADATA` | `GetFileMetadata` |
| `get_file_url` | `SWARM_FILESYSTEM_TOOLS_GET_FILE_URL` | `GetFileUrl` |

## Security checklist

- Keep `enabled` off unless an agent genuinely needs filesystem access.
- Give agents their **own** disk, rooted in a directory that holds nothing
  sensitive. Reuse of `local`/`public` is the most common over-exposure.
- Leave `write_file`, `delete_file`, and `copy_file` off for agents that only
  need to read.
- Remember these tools are governed by config, not by run scope — unlike memory,
  there is no per-run isolation. The disk boundary is the isolation.
