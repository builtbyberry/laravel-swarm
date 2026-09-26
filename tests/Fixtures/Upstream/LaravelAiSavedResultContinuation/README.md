# Laravel AI saved-result continuation candidate

This fixture is an isolated, unapplied upstream candidate. Laravel Swarm does
not load it at runtime. It pins Laravel AI `1.x` commit
`a117adfe4e07696b7ffdf76c3c0b2effc0f0139f`, applies `candidate.patch`, installs
the committed dependency lock, and runs the upstream proof and regression
suites with controlled HTTP responses only.

Run the exact proof from the Laravel Swarm repository root:

```bash
bash tests/Fixtures/Upstream/LaravelAiSavedResultContinuation/run.sh
```

Prove that the ownership assertions discriminate a defect:

```bash
P5B_MUTATION=disable-ownership-check \
  bash tests/Fixtures/Upstream/LaravelAiSavedResultContinuation/run.sh
```

The mutation run succeeds only when the targeted upstream test fails. Neither
path uses a paid provider, modifies an installed dependency, or publishes the
candidate upstream.

Hosted proof runs the exact candidate on the PHP 8.4 and 8.5 versions supported
by Laravel Swarm. This fixture does not claim compatibility with PHP versions
outside Swarm's supported range.
