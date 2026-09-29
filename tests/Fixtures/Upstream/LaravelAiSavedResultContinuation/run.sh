#!/usr/bin/env bash

set -euo pipefail

fixture_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
manifest="${fixture_dir}/manifest.json"
target_sha="$(php -r '$m = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $m["target_sha"];' "${manifest}")"
temporary_checkout=""

if [[ -n "${LARAVEL_AI_CHECKOUT:-}" ]]; then
    checkout="${LARAVEL_AI_CHECKOUT}"
else
    temporary_checkout="$(mktemp -d "${TMPDIR:-/tmp}/laravel-ai-continuation.XXXXXX")"
    checkout="${temporary_checkout}/laravel-ai"
    git clone --quiet https://github.com/laravel/ai.git "${checkout}"
fi

cleanup() {
    if [[ -n "${temporary_checkout}" && -d "${temporary_checkout}" ]]; then
        rm -rf -- "${temporary_checkout}"
    fi
}

trap cleanup EXIT

git -C "${checkout}" checkout --quiet "${target_sha}"
test "$(git -C "${checkout}" rev-parse HEAD)" = "${target_sha}"
git -C "${checkout}" apply --check "${fixture_dir}/candidate.patch"
git -C "${checkout}" apply "${fixture_dir}/candidate.patch"
cp "${fixture_dir}/composer.lock" "${checkout}/composer.lock"

composer --working-dir="${checkout}" install --prefer-dist --no-interaction --no-progress
cd "${checkout}"

if [[ "${P5B_MUTATION:-}" = "disable-ownership-check" ]]; then
    php -r '
        $path = $argv[1];
        $source = file_get_contents($path);
        $needle = "if (\$target !== null) {\n                \$this->assertContinuationOwner(\$conversationId, \$this->conversationParticipantFor(\$agent));\n            }";
        if (substr_count($source, $needle) !== 1) {
            fwrite(STDERR, "Mutation target was not exact.\n");
            exit(2);
        }
        file_put_contents($path, str_replace($needle, "if (false) {\n                \$this->assertContinuationOwner(\$conversationId, \$this->conversationParticipantFor(\$agent));\n            }", $source));
    ' "${checkout}/src/Storage/ManagesContinuations.php"

    set +e
    vendor/bin/pest tests/Feature/NativeContinuationTest.php \
        --filter="wrong participant provider revision and missing replay" --colors=never
    status=$?
    set -e

    if [[ ${status} -eq 0 ]]; then
        echo "Mutation escaped detection." >&2
        exit 1
    fi

    echo "Mutation detected by the targeted ownership proof."
    exit 0
fi

composer test:lint
composer test:types
vendor/bin/pest \
    tests/Feature/NativeContinuationTest.php \
    tests/Feature/ToolApprovalResumeTest.php \
    tests/Feature/AgentUserInteractionProtocolStreamTest.php \
    tests/Feature/Storage/DatabaseConversationStoreTest.php \
    --compact
composer test
