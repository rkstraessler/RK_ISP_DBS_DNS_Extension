#!/usr/bin/env bash

set -euo pipefail

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${script_directory}/.." && pwd -P)"
ispconfig_web_root='/usr/local/ispconfig/interface/web'
dry_run=0

show_usage() {
    printf '%s\n' 'Usage: bash scripts/uninstall.sh [--dry-run] [--web-root PATH]'
}

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

while (($# > 0)); do
    case "$1" in
        --dry-run)
            dry_run=1
            shift
            ;;
        --web-root)
            if (($# < 2)); then
                show_usage >&2
                exit 2
            fi
            ispconfig_web_root="$2"
            shift 2
            ;;
        --help|-h)
            show_usage
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n' "$1" >&2
            show_usage >&2
            exit 2
            ;;
    esac
done

ispconfig_web_root="${ispconfig_web_root%/}"

if [[ -z "${ispconfig_web_root}" || "${ispconfig_web_root}" != /* ]]; then
    fail 'The ISPConfig web root must be an absolute path.'
fi

if [[ "${ispconfig_web_root}" == '/' || ! -d "${ispconfig_web_root}" ]]; then
    fail 'The ISPConfig web root must be an existing, dedicated directory.'
fi

ispconfig_web_root="$(cd -- "${ispconfig_web_root}" && pwd -P)"
ispconfig_interface_root="$(cd -- "${ispconfig_web_root}/.." && pwd -P)"
version_file="${ispconfig_interface_root}/lib/config.inc.php"
module_target="${ispconfig_web_root}/dbsdns"
permission_cleaner="${repository_root}/src/dbsdns/uninstall_module_permissions.php"

for required_command in php rm stat id runuser; do
    command -v "${required_command}" >/dev/null 2>&1 \
        || fail "Required command not found: ${required_command}"
done

[[ "$(id -u)" == '0' ]] || fail 'Run this uninstaller as root, including --dry-run.'

if [[ ! -f "${version_file}" || ! -f "${permission_cleaner}" ]]; then
    fail 'ISPConfig or the DBS DNS uninstaller is incomplete.'
fi

if [[ -L "${module_target}" || ( -e "${module_target}" && ! -d "${module_target}" ) ]]; then
    fail 'The DBS DNS module path is unsafe and was not removed.'
fi

panel_user="$(stat -c '%U' "${version_file}")"
interface_owner="$(stat -c '%U' "${ispconfig_interface_root}")"

if [[
    -z "${panel_user}"
    || "${panel_user}" == 'UNKNOWN'
    || "${panel_user}" == 'root'
    || "${interface_owner}" != "${panel_user}"
]] || ! id -u "${panel_user}" >/dev/null 2>&1; then
    fail 'The ISPConfig panel runtime ownership could not be verified.'
fi

permission_arguments=(--interface-root "${ispconfig_interface_root}")

if ((dry_run == 1)); then
    permission_arguments+=(--dry-run)
fi

php "${permission_cleaner}" "${permission_arguments[@]}" \
    || fail 'ISPConfig module permissions could not be cleaned up.'

if ((dry_run == 1)); then
    printf 'Dry run: module directory %s would be removed.\n' "${module_target}"
    printf '%s\n' 'Database tables, settings and the external credential key would be preserved.'
    exit 0
fi

if [[ -d "${module_target}" ]]; then
    runuser --user "${panel_user}" -- rm -rf -- "${module_target}" \
        || fail 'The DBS DNS module directory could not be removed.'
fi

printf '%s\n' 'DBS DNS module and its module assignments were removed.'
printf '%s\n' 'Database tables, settings and the external credential key were preserved.'
printf '%s\n' 'Sign out and sign in again to rebuild the ISPConfig module and plugin caches.'
