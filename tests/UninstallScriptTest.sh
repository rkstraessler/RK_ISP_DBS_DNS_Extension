#!/usr/bin/env bash

set -euo pipefail

test_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${test_directory}/.." && pwd -P)"
test_root="$(mktemp -d)"
interface_root="${test_root}/interface"
web_root="${interface_root}/web"
module_root="${web_root}/dbsdns"
key_file="${test_root}/security/dbsdns/credentials.key"
fake_bin="${test_root}/bin"
php_marker="${test_root}/php-invocations.txt"
runuser_marker="${test_root}/runuser-invocations.txt"

cleanup() {
    rm -rf -- "${test_root}"
}

trap cleanup EXIT
mkdir -p -- "${interface_root}/lib" "${module_root}" "$(dirname -- "${key_file}")" "${fake_bin}"
printf '%s\n' '<?php' > "${interface_root}/lib/config.inc.php"
printf '%s\n' 'module-marker' > "${module_root}/marker.txt"
printf '%s\n' 'preserved-key' > "${key_file}"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_PHP_MARKER:?}"' \
    'exit "${DBSDNS_TEST_PHP_STATUS:-0}"' > "${fake_bin}/php"
chmod +x "${fake_bin}/php"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'if [[ "$1" == "-c" && "$2" == "%U" ]]; then printf "%s\n" "ispconfig"; exit 0; fi' \
    'exec /usr/bin/stat "$@"' > "${fake_bin}/stat"
chmod +x "${fake_bin}/stat"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'if [[ "$#" -eq 0 || ("$#" -eq 1 && "$1" == "-u") ]]; then printf "%s\n" "${DBSDNS_TEST_EUID:-0}"; exit 0; fi' \
    'if [[ "$1" == "-u" && "$2" == "ispconfig" ]]; then printf "%s\n" "12345"; exit 0; fi' \
    'exec /usr/bin/id "$@"' > "${fake_bin}/id"
chmod +x "${fake_bin}/id"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_RUNUSER_MARKER:?}"' \
    '[[ "$1" == "--user" && "$2" == "ispconfig" && "$3" == "--" ]] || exit 1' \
    'shift 3' \
    'exec "$@"' > "${fake_bin}/runuser"
chmod +x "${fake_bin}/runuser"

export PATH="${fake_bin}:${PATH}"
export DBSDNS_TEST_PHP_MARKER="${php_marker}"
export DBSDNS_TEST_RUNUSER_MARKER="${runuser_marker}"
export DBSDNS_TEST_EUID=0

export DBSDNS_TEST_EUID=1000
if bash "${repository_root}/scripts/uninstall.sh" --dry-run --web-root "${web_root}" \
    > "${test_root}/non-root.out" 2>&1; then
    printf '%s\n' 'Uninstaller accepted a non-root caller.' >&2
    exit 1
fi

if ! grep -Eiq 'root|privileg' "${test_root}/non-root.out" \
    || [[ ! -f "${module_root}/marker.txt" || ! -f "${key_file}" || -e "${php_marker}" || -e "${runuser_marker}" ]]; then
    printf '%s\n' 'Non-root uninstall did not fail before state changes.' >&2
    exit 1
fi

export DBSDNS_TEST_EUID=0

bash "${repository_root}/scripts/uninstall.sh" --dry-run --web-root "${web_root}" \
    > "${test_root}/dry-run.out"

if [[ ! -f "${module_root}/marker.txt" || ! -f "${key_file}" ]] \
    || ! grep -Fq -- '--dry-run' "${php_marker}" \
    || ! grep -Fq 'would be removed' "${test_root}/dry-run.out"; then
    printf '%s\n' 'Uninstall dry-run changed state or omitted its plan.' >&2
    exit 1
fi

rm -f -- "${php_marker}" "${runuser_marker}"
bash "${repository_root}/scripts/uninstall.sh" --web-root "${web_root}" \
    > "${test_root}/uninstall.out"

if [[ -e "${module_root}" || ! -f "${key_file}" ]] \
    || ! grep -Fq 'uninstall_module_permissions.php' "${php_marker}" \
    || ! grep -Fq -- "--user ispconfig -- rm -rf -- ${module_root}" "${runuser_marker}" \
    || ! grep -Fq 'external credential key were preserved' "${test_root}/uninstall.out"; then
    printf '%s\n' 'Uninstall did not remove only the module and its assignments.' >&2
    exit 1
fi

outside_target="${test_root}/outside"
mkdir -p -- "${outside_target}"
printf '%s\n' 'must-stay' > "${outside_target}/marker.txt"
ln -s -- "${outside_target}" "${module_root}"
rm -f -- "${php_marker}" "${runuser_marker}"

if bash "${repository_root}/scripts/uninstall.sh" --web-root "${web_root}" \
    > "${test_root}/unsafe.out" 2>&1; then
    printf '%s\n' 'Uninstall accepted a symbolic-link module target.' >&2
    exit 1
fi

if [[ ! -f "${outside_target}/marker.txt" || -e "${php_marker}" || -e "${runuser_marker}" ]]; then
    printf '%s\n' 'Unsafe uninstall target was followed or changed state.' >&2
    exit 1
fi

printf '%s\n' 'Safe uninstall dry-run, cleanup and symlink rejection successfully tested.'
