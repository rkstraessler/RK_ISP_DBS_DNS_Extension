#!/usr/bin/env bash

set -euo pipefail

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${script_directory}/.." && pwd -P)"

for required_command in php bash find sort; do
    command -v "${required_command}" >/dev/null 2>&1 \
        || { printf 'Required command not found: %s\n' "${required_command}" >&2; exit 1; }
done

while IFS= read -r -d '' php_file; do
    php -l "${php_file}" >/dev/null
done < <(find \
    "${repository_root}/src" \
    "${repository_root}/scripts" \
    "${repository_root}/tests" \
    "${repository_root}/migration" \
    "${repository_root}/install" \
    "${repository_root}/packaging" \
    -type f -name '*.php' -print0)

while IFS= read -r test_file; do
    php "${test_file}"
done < <(find "${repository_root}/tests" -maxdepth 1 -type f -name '*Test.php' | sort)

while IFS= read -r -d '' shell_file; do
    bash -n "${shell_file}"
done < <(find "${repository_root}/scripts" "${repository_root}/tests" -type f -name '*.sh' -print0)

bash "${repository_root}/tests/InstallScriptTest.sh"
bash "${repository_root}/tests/UninstallScriptTest.sh"
bash "${repository_root}/tests/InstallPermissionsIntegrationTest.sh"

printf '%s\n' 'All PHP and installer checks passed.'
