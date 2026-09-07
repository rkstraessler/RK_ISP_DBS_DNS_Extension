#!/usr/bin/env bash

set -euo pipefail

script_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${script_directory}/.." && pwd -P)"
ispconfig_web_root='/usr/local/ispconfig/interface/web'
dry_run=0

show_usage() {
    printf '%s\n' 'Usage: bash scripts/install.sh [--dry-run] [--web-root PATH]'
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
    printf '%s\n' 'The ISPConfig web root must be an absolute path.' >&2
    exit 2
fi

if [[ "${ispconfig_web_root}" == '/' || ! -d "${ispconfig_web_root}" ]]; then
    printf '%s\n' 'The ISPConfig web root must be an existing, dedicated directory.' >&2
    exit 2
fi

ispconfig_web_root="$(cd -- "${ispconfig_web_root}" && pwd -P)"
module_source="${repository_root}/src/dbsdns"
module_target="${ispconfig_web_root}/dbsdns"
ispconfig_interface_root="$(cd -- "${ispconfig_web_root}/.." && pwd -P)"
ispconfig_install_root="$(cd -- "${ispconfig_interface_root}/.." && pwd -P)"
migration_directory="${repository_root}/migration/ispconfig-3.3.1p1"
restore_manifest="${migration_directory}/restore-manifest.sh"
upstream_fixture_directory="${migration_directory}/upstream/dns"
core_free_marker="${module_target}/.core-integration-none"
version_file="${ispconfig_interface_root}/lib/config.inc.php"
database_name=''
schema_file="${module_source}/sql/dbsdns_zone_cache.sql"
schema_migration_file="${module_source}/sql/migrations/001_dbsdns_zone_cache_utf8mb4_unicode_ci.sql"
settings_schema_migration_file="${module_source}/sql/migrations/002_dbsdns_settings.sql"
schema_validator="${module_source}/install_schema.php"
security_directory="${ispconfig_install_root}/security"
secret_directory="${ispconfig_install_root}/security/dbsdns"
secret_key_file="${secret_directory}/credentials.key"
module_permission_installer="${module_source}/install_module_permissions.php"
obsolete_menu_target="${ispconfig_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php"

if [[ -L "${module_target}" || ( -e "${module_target}" && ! -d "${module_target}" ) ]]; then
    fail 'The DBS DNS module target is unsafe; no files were changed.'
fi

for required_command in sha256sum grep php cp mkdir date rm mv mktemp awk dirname chmod chown stat id runuser find sort cmp; do
    command -v "${required_command}" >/dev/null 2>&1 \
        || fail "Required command not found: ${required_command}"
done

[[ "$(id -u)" == '0' ]] || fail 'Run this installer as root, including --dry-run.'

if [[
    ! -d "${module_source}"
    || ! -f "${module_source}/zone_list.php"
    || ! -f "${module_source}/zone_view.php"
    || ! -f "${module_source}/record_edit.php"
    || ! -f "${module_source}/record_delete.php"
    || ! -f "${module_source}/.core-integration-none"
]]; then
    fail 'The repository does not contain the core-free DBS DNS module.'
fi

if [[
    ! -f "${schema_file}"
    || ! -f "${schema_migration_file}"
    || ! -f "${settings_schema_migration_file}"
    || ! -f "${schema_validator}"
]]; then
    fail 'The repository does not contain the DBS cache and settings schema installer.'
fi

if [[ ! -f "${version_file}" || ! -f "${ispconfig_interface_root}/lib/app.inc.php" ]]; then
    fail 'The ISPConfig version file was not found.'
fi

if [[ ! -f "${restore_manifest}" ]]; then
    fail 'The one-time ISPConfig 3.3.1p1 restore manifest is missing.'
fi

# shellcheck disable=SC1090
source "${restore_manifest}"

if ! grep -Fq "define('ISPC_APP_VERSION', '${ISPC_EXPECTED_VERSION}')" "${version_file}"; then
    fail "ISPConfig ${ISPC_EXPECTED_VERSION} is required; the installed version marker differs."
fi

printf 'ISPConfig version: %s\n' "${ISPC_EXPECTED_VERSION}"
printf 'Verified upstream commit: %s\n' "${ISPC_EXPECTED_COMMIT}"
printf 'Module: %s -> %s\n' "${module_source}" "${module_target}"

core_names=(
    'dns_soa_list.php'
    'dns_soa_edit.php'
    'dns_a_edit.php'
    'dns_rr_del.php'
    'dns_edit_base.php'
)
core_targets=(
    "${ispconfig_web_root}/dns/dns_soa_list.php"
    "${ispconfig_web_root}/dns/dns_soa_edit.php"
    "${ispconfig_web_root}/dns/dns_a_edit.php"
    "${ispconfig_web_root}/dns/dns_rr_del.php"
    "${ispconfig_web_root}/dns/dns_edit_base.php"
)
core_original_hashes=(
    "${DNS_SOA_LIST_ORIGINAL_SHA256}"
    "${DNS_SOA_EDIT_ORIGINAL_SHA256}"
    "${DNS_A_EDIT_ORIGINAL_SHA256}"
    "${DNS_RR_DEL_ORIGINAL_SHA256}"
    "${DNS_EDIT_BASE_ORIGINAL_SHA256}"
)
core_previous_hashes=(
    "${DNS_SOA_LIST_PREVIOUS_DBS_SHA256}"
    "${DNS_SOA_EDIT_PREVIOUS_DBS_SHA256}"
    "${DNS_A_EDIT_PREVIOUS_DBS_SHA256}"
    "${DNS_RR_DEL_PREVIOUS_DBS_SHA256}"
    "${DNS_EDIT_BASE_PREVIOUS_DBS_SHA256}"
)
core_current_hashes=(
    "${DNS_SOA_LIST_CURRENT_DBS_SHA256}"
    "${DNS_SOA_EDIT_CURRENT_DBS_SHA256}"
    "${DNS_A_EDIT_CURRENT_DBS_SHA256}"
    "${DNS_RR_DEL_CURRENT_DBS_SHA256}"
    "${DNS_EDIT_BASE_CURRENT_DBS_SHA256}"
)
core_original_sources=()
core_states=()
core_restore_indexes=()
unknown_core_files=()
core_migration_required=0
core_verification_required=0
obsolete_menu_migration_required=0

if [[ -f "${core_free_marker}" ]]; then
    printf '%s\n' 'Core integration: none'
    printf '%s\n' 'Core migration: already completed; no ISPConfig core file is managed.'
else
    core_verification_required=1
    if [[ ! -d "${ispconfig_web_root}/dns" || ! -d "${ispconfig_web_root}/dns/lib/menu.d" ]]; then
        fail "ISPConfig DNS module not found below: ${ispconfig_web_root}"
    fi

    for core_index in "${!core_names[@]}"; do
        core_target="${core_targets[$core_index]}"
        original_source="${upstream_fixture_directory}/${core_names[$core_index]}"
        core_original_sources[$core_index]="${original_source}"

        if [[ ! -f "${core_target}" || ! -f "${original_source}" ]]; then
            fail "Required migration file not found: ${core_names[$core_index]}"
        fi

        source_hash="$(sha256sum "${original_source}" | awk '{print $1}')"

        if [[ "${source_hash}" != "${core_original_hashes[$core_index]}" ]]; then
            fail "Verified upstream restore source failed checksum validation: ${core_names[$core_index]}"
        fi

        core_hash="$(sha256sum "${core_target}" | awk '{print $1}')"

        if [[ "${core_hash}" == "${core_original_hashes[$core_index]}" ]]; then
            core_states[$core_index]='upstream-original'
        elif [[
            -n "${core_previous_hashes[$core_index]}"
            && "${core_hash}" == "${core_previous_hashes[$core_index]}"
        ]]; then
            core_states[$core_index]='previous-dbs-patched'
            core_restore_indexes+=("${core_index}")
            core_migration_required=1
        elif [[
            -n "${core_current_hashes[$core_index]}"
            && "${core_hash}" == "${core_current_hashes[$core_index]}"
        ]]; then
            core_states[$core_index]='current-dbs-patched'
            core_restore_indexes+=("${core_index}")
            core_migration_required=1
        else
            core_states[$core_index]='unknown'
            unknown_core_files+=("${core_names[$core_index]}")
        fi
    done

    if [[ -f "${obsolete_menu_target}" ]]; then
        obsolete_menu_hash="$(sha256sum "${obsolete_menu_target}" | awk '{print $1}')"

        if [[ "${obsolete_menu_hash}" == "${OBSOLETE_DNS_MENU_SHA256}" ]]; then
            obsolete_menu_migration_required=1
            core_migration_required=1
        else
            unknown_core_files+=('dns/lib/menu.d/dbsdns_provider_zones.menu.php')
        fi
    fi

    printf '%s\n' 'One-time core migration state:'

    for core_index in "${!core_names[@]}"; do
        printf '  %s: %s\n' "${core_names[$core_index]}" "${core_states[$core_index]}"
    done

    if ((${#unknown_core_files[@]} > 0)); then
        printf '%s\n' 'Core migration: blocked'
        fail "Unknown ISPConfig DNS core state for ${unknown_core_files[*]}; no files were changed."
    fi

    if ((core_migration_required == 1)); then
        printf '%s\n' 'Core migration: restore known DBS changes to verified upstream originals'
    else
        printf '%s\n' 'Core migration: no core changes required'
    fi
fi

if ! php -r 'exit(function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") && function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_decrypt") && function_exists("random_bytes") ? 0 : 1);'; then
    fail 'The PHP Sodium extension with XChaCha20-Poly1305 support is required; no files were changed.'
fi

if ! php -r 'exit(class_exists("SoapClient") ? 0 : 1);'; then
    fail 'The PHP SOAP extension is required; no files were changed.'
fi

if [[ -L "${security_directory}" || ! -d "${security_directory}" ]]; then
    fail 'The ISPConfig security directory is missing or unsafe; no files were changed.'
fi

install_root_permissions="$(stat -c '%a' "${ispconfig_install_root}")"
if [[ "$(stat -c '%U' "${ispconfig_install_root}")" != 'root' ]] \
    || [[ ! "${install_root_permissions}" =~ ^[0-7]{3,4}$ ]] \
    || (( (8#${install_root_permissions} & 8#022) != 0 )); then
    fail 'The ISPConfig install root must be root-owned and not writable by group or other users.'
fi

# ISPConfig 3.3.1p1 runs the panel as the non-root owner of its protected
# interface tree and grants that same group read access to the security tree.
panel_user="$(stat -c '%U' "${version_file}")"
panel_group="$(stat -c '%G' "${version_file}")"
interface_owner="$(stat -c '%U' "${ispconfig_interface_root}")"
security_owner="$(stat -c '%U' "${security_directory}")"
security_group="$(stat -c '%G' "${security_directory}")"
security_permissions="$(stat -c '%a' "${security_directory}")"

if [[
    -z "${panel_user}"
    || -z "${panel_group}"
    || "${panel_user}" == 'UNKNOWN'
    || "${panel_group}" == 'UNKNOWN'
    || "${panel_user}" == 'root'
    || "${panel_group}" == 'root'
    || "${interface_owner}" != "${panel_user}"
    || "${security_owner}" != 'root'
    || "${security_group}" != "${panel_group}"
]] || ! id -u "${panel_user}" >/dev/null 2>&1; then
    fail 'The ISPConfig panel runtime ownership could not be verified from its protected interface configuration.'
fi

if [[ ! "${security_permissions}" =~ ^[0-7]{3,4}$ ]] \
    || (( (8#${security_permissions} & 8#022) != 0 )); then
    fail 'The ISPConfig security directory must not be writable by group or other users.'
fi

# ISPConfig 3.3.1p1 installer_base.lib.php uses ispconfig:ispconfig and
# chmod -R 750 for the interface. Some deployments use 755/644 instead.
# Inherit the native DNS module's read/traverse semantics, stripping group/
# other writes, special bits and executable bits from ordinary files.
native_module="${ispconfig_web_root}/dns"
native_page="${native_module}/dns_soa_list.php"
for native_path in "${native_module}" "${native_page}"; do
    if [[ -L "${native_path}" || ! -e "${native_path}" ]] \
        || [[ "$(stat -c '%U' "${native_path}")" != "${panel_user}" ]] \
        || [[ "$(stat -c '%G' "${native_path}")" != "${panel_group}" ]]; then
        fail 'The native ISPConfig DNS module ownership could not be verified.'
    fi
done
native_directory_mode="$(stat -c '%a' "${native_module}")"
native_file_mode="$(stat -c '%a' "${native_page}")"
if [[ ! "${native_directory_mode}" =~ ^[0-7]{3,4}$ || ! "${native_file_mode}" =~ ^[0-7]{3,4}$ ]] \
    || (( (8#${native_directory_mode} & 8#750) != 8#750 )) \
    || (( (8#${native_file_mode} & 8#640) != 8#640 )); then
    fail 'The native ISPConfig DNS module lacks the required group read/traverse permissions.'
fi
printf -v module_directory_mode '%03o' "$((8#${native_directory_mode} & 8#755))"
printf -v module_file_mode '%03o' "$((8#${native_file_mode} & 8#644))"
printf 'Web module permissions: %s:%s directories %s, files %s (native DNS reference).\n' \
    "${panel_user}" "${panel_group}" "${module_directory_mode}" "${module_file_mode}"

# Only the declared, secret-free release payload may enter the webroot.
module_manifest="${repository_root}/install/module-files.list"
if [[ -L "${module_source}" || ! -f "${module_manifest}" ]] \
    || [[ -n "$(find "${module_source}" ! -type d ! -type f -print -quit)" ]]; then
    fail 'The module source is unsafe or its file manifest is missing.'
fi
if ! cmp -s <(LC_ALL=C sort "${module_manifest}") \
    <(find "${module_source}" -type f -printf '%P\n' | LC_ALL=C sort); then
    fail 'The module source differs from its complete release file manifest.'
fi
while IFS= read -r -d '' source_php; do
    php -l "${source_php}" >/dev/null || fail 'The module source failed PHP syntax validation.'
done < <(find "${module_source}" -type f \( -name '*.php' -o -name '*.lng' \) -print0)

if [[ "$(stat -c '%d' "${security_directory}")" != "$(stat -c '%d' "${ispconfig_web_root}")" ]]; then
    fail 'The protected staging directory and webroot must be on the same filesystem for atomic activation.'
fi
[[ -w "${security_directory}" && -w "${ispconfig_web_root}" ]] \
    || fail 'The staging directory or webroot is not writable by the installer.'

if [[ -L "${secret_directory}" || ( -e "${secret_directory}" && ! -d "${secret_directory}" ) ]]; then
    fail 'The DBS credential key directory is unsafe; no files were changed.'
fi

secret_key_state='missing'

if [[ -L "${secret_key_file}" ]]; then
    fail 'The DBS credential key path must not be a symbolic link; no files were changed.'
elif [[ -e "${secret_key_file}" ]]; then
    if [[ ! -f "${secret_key_file}" ]] \
        || ! php -r '$key = @file_get_contents($argv[1]); exit(is_string($key) && strlen($key) === 32 ? 0 : 1);' "${secret_key_file}"; then
        fail 'The existing DBS credential key is invalid; no files were changed.'
    fi

    secret_key_state='valid'

    if ! runuser --user "${panel_user}" -- test -r "${secret_key_file}"; then
        fail 'The existing DBS credential key is not readable by the ISPConfig panel runtime user; no files were changed.'
    fi
fi

database_name="$(php "${schema_validator}" --interface-root "${ispconfig_interface_root}" --database-name)" \
    || fail 'The ISPConfig database name could not be determined safely.'

if [[ ! "${database_name}" =~ ^[A-Za-z0-9_]+$ ]]; then
    fail 'The ISPConfig database name is invalid.'
fi

printf 'Database: %s\n' "${database_name}"

schema_validation_output=''
schema_validation_status=0
schema_state='unknown'

if schema_validation_output="$(php "${schema_validator}" --interface-root "${ispconfig_interface_root}" 2>&1)"; then
    schema_state='correct'
else
    schema_validation_status=$?

    case "${schema_validation_status}" in
        10)
            schema_state='missing'
            ;;
        11)
            schema_state='wrong-collation'
            ;;
        12)
            printf '%s\n' "${schema_validation_output}" >&2
            fail 'The existing DBS cache schema is structurally incompatible; no files were changed.'
            ;;
        *)
            printf '%s\n' "${schema_validation_output}" >&2
            fail 'DBS cache schema inspection failed; no files were changed.'
            ;;
    esac
fi

printf 'Database schema state: %s\n' "${schema_state}"

php "${module_permission_installer}" --interface-root "${ispconfig_interface_root}" --preflight \
    || fail 'Module-permission transaction preflight failed; no files or database tables were changed.'

show_manual_schema_command() {
    printf '%s\n' 'Run this command on the ISPConfig server, then rerun the installer:' >&2

    if [[ "$1" == 'missing' ]]; then
        printf 'mariadb %s < %s\n' "${database_name}" "${schema_file}" >&2
    fi

    printf 'mariadb %s < %s\n' "${database_name}" "${schema_migration_file}" >&2
    printf 'mariadb %s < %s\n' "${database_name}" "${settings_schema_migration_file}" >&2
}

if [[ "${schema_state}" != 'correct' ]]; then
    if ! command -v mariadb >/dev/null 2>&1; then
        show_manual_schema_command "${schema_state}"
        fail 'The local privileged MariaDB client is unavailable; no files were changed.'
    fi

    if ! mariadb --batch --skip-column-names --database="${database_name}" --execute='SELECT 1' >/dev/null 2>&1; then
        show_manual_schema_command "${schema_state}"
        fail 'The local privileged MariaDB connection is unavailable; no files were changed.'
    fi

    if ((dry_run == 1)); then
        printf 'Dry run: the privileged installer would apply the required DBS cache/settings schema files.\n'
    fi
fi

if [[ "${schema_state}" == 'correct' ]]; then
    settings_secret_state="$(php "${schema_validator}" --interface-root "${ispconfig_interface_root}" --settings-secret-state)" \
        || fail 'DBS credential state inspection failed; no files were changed.'

    if [[ "${secret_key_state}" == 'missing' && "${settings_secret_state}" == 'configured' ]]; then
        fail 'Encrypted DBS credentials exist but their external key is missing; restore the key before reinstalling.'
    fi
fi

if ((dry_run == 1)); then
    printf '%s\n' 'Core integration target: none'
    printf 'Credential key state: %s for panel runtime %s:%s; dry run makes no key changes.\n' "${secret_key_state}" "${panel_user}" "${panel_group}"
    printf '%s\n' 'Dry run: source and target inspected; staging/copy/runtime verification runs only during installation.'
    printf '%s\n' 'Dry run complete; no files, keys, module permissions or database tables were changed.'
    exit 0
fi

module_stage=''
module_workspace=''
module_previous=''
module_previous_created=0
module_activated=0
module_deployment_complete=0
persistent_changes_started=0
permission_sync_in_progress=0

cleanup_module_stage() {
    if [[ -n "${module_workspace}" && "${module_workspace}" == "${security_directory}/.dbsdns-stage-"* ]]; then
        rm -rf -- "${module_workspace}"
    fi
}

rollback_module_deployment() {
    # A signal can arrive immediately after mv returns. Flags are set before
    # each rename; an absent backup means the original module never moved.
    if ((module_activated == 0)) && [[ ! -d "${module_previous}" ]]; then
        module_previous_created=0
        return 0
    fi
    if [[ -L "${module_target}" || -f "${module_target}" ]]; then
        rm -f -- "${module_target}" || return 1
    elif [[ -d "${module_target}" ]]; then
        rm -rf -- "${module_target}" || return 1
    fi

    if ((module_previous_created == 1)) && [[ -d "${module_previous}" && ! -L "${module_previous}" ]]; then
        mv -T -- "${module_previous}" "${module_target}" || return 1
        module_previous_created=0
    fi

    module_activated=0
}

cleanup_module_deployment() {
    exit_status=$?
    if ((permission_sync_in_progress == 1)); then
        # The CLI may already have committed when it or this shell is signalled.
        # Keep the verified new module available for either DB outcome, and the
        # previous module protected for recovery. Never turn this into a blank
        # module by guessing that the database transaction was rolled back.
        printf 'ERROR: Module-permission synchronization was interrupted; the verified module remains active. Recovery workspace: %s. Rerun the installer.\n' "${module_workspace}" >&2
        return "${exit_status}"
    fi
    if ((
        module_deployment_complete == 0
        && (module_activated == 1 || module_previous_created == 1)
    )); then
        if ! rollback_module_deployment; then
            printf 'ERROR: Automatic module rollback failed; previous module retained at %s.\n' "${module_previous}" >&2
            return 1
        fi
    fi

    cleanup_module_stage
    if ((exit_status != 0 && persistent_changes_started == 1)); then
        printf '%s\n' 'Installation failed. Any completed schema/key changes are retained safely; correct the cause and rerun the installer.' >&2
    fi
    return "${exit_status}"
}

trap cleanup_module_deployment EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

module_workspace="$(mktemp -d "${security_directory}/.dbsdns-stage-XXXXXX")" \
    || fail 'The DBS DNS staging directory could not be created.'

if [[ "${module_workspace}" != "${security_directory}/.dbsdns-stage-"* || ! -d "${module_workspace}" || -L "${module_workspace}" ]]; then
    fail 'The DBS DNS staging directory is unsafe.'
fi
chmod 0700 "${module_workspace}"
module_stage="${module_workspace}/module"
module_previous="${module_workspace}/previous"
mkdir -- "${module_stage}"

# Root reads the trusted release even when its parent is /root (0700).
# The root-owned private container prevents traversal or replacement while
# copying and normalizing its module child, and never becomes a web directory.
if ! cp -R --no-dereference -- "${module_source}/." "${module_stage}/"; then
    fail 'The DBS DNS module could not be copied into its staging directory.'
fi

if [[ -n "$(find "${module_stage}" ! -type d ! -type f -print -quit)" ]] \
    || ! cmp -s <(LC_ALL=C sort "${module_manifest}") \
        <(find "${module_stage}" -type f -printf '%P\n' | LC_ALL=C sort); then
    fail 'The staged module is unsafe or incomplete.'
fi
while IFS= read -r module_file; do
    cmp -s "${module_source}/${module_file}" "${module_stage}/${module_file}" \
        || fail 'A staged module file differs from its release source.'
done < "${module_manifest}"
find "${module_stage}" -type d -exec chmod "${module_directory_mode}" {} +
find "${module_stage}" -type f -exec chmod "${module_file_mode}" {} +
chown -R -- "${panel_user}:${panel_group}" "${module_stage}"

# Check the child as the actual runtime without opening the staging container
# to it: root enters first, then runuser preserves that working directory.
if ! (cd -- "${module_stage}" && runuser --user "${panel_user}" -- bash -c '
    test -x . || exit 1
    while IFS= read -r -d "" directory; do test -x "$directory" || exit 1; done < <(find . -type d -print0)
    while IFS= read -r -d "" file; do test -r "$file" || exit 1; done < <(find . -type f -print0)
'); then
    fail 'The staged module is not readable/traversable by the ISPConfig runtime.'
fi
printf '%s\n' 'Module staging verified; starting persistent installation changes.'
persistent_changes_started=1

if [[ "${schema_state}" != 'correct' ]]; then
    if [[ "${schema_state}" == 'missing' ]] && ! mariadb "${database_name}" < "${schema_file}"; then
        show_manual_schema_command "${schema_state}"
        fail 'Privileged DBS cache/settings schema creation failed; no module or core files were changed.'
    fi
    if ! mariadb "${database_name}" < "${schema_migration_file}" \
        || ! mariadb "${database_name}" < "${settings_schema_migration_file}"; then
        show_manual_schema_command "${schema_state}"
        fail 'Privileged DBS cache/settings schema migration failed; no module or core files were changed.'
    fi
fi

php "${schema_validator}" --interface-root "${ispconfig_interface_root}" \
    || fail 'DBS cache/settings schema validation failed; no module or core files were changed.'

settings_secret_state="$(php "${schema_validator}" --interface-root "${ispconfig_interface_root}" --settings-secret-state)" \
    || fail 'DBS credential state inspection failed; no module or core files were changed.'

if [[ "${secret_key_state}" == 'missing' && "${settings_secret_state}" == 'configured' ]]; then
    fail 'Encrypted DBS credentials exist but their external key is missing; restore the key before reinstalling.'
fi

mkdir -p -- "${secret_directory}"
chown "root:${panel_group}" "${secret_directory}"
chmod 0750 "${secret_directory}"

if [[ -L "${secret_key_file}" || ( -e "${secret_key_file}" && ! -f "${secret_key_file}" ) ]]; then
    fail 'The DBS credential key path became unsafe before installation.'
fi

if [[ "${secret_key_state}" == 'missing' ]]; then
    if ! (umask 077 && php -r '
        $path = $argv[1];
        $handle = @fopen($path, "x+b");
        if($handle === false) exit(1);
        $key = random_bytes(32);
        $written = fwrite($handle, $key);
        $flushed = fflush($handle);
        fclose($handle);
        if($written !== 32 || !$flushed) { @unlink($path); exit(1); }
    ' "${secret_key_file}"); then
        fail 'The DBS credential key could not be created safely.'
    fi
fi

chown "root:${panel_group}" "${secret_key_file}"
chmod 0640 "${secret_key_file}"

if ! php -r '$key = @file_get_contents($argv[1]); exit(is_string($key) && strlen($key) === 32 ? 0 : 1);' "${secret_key_file}"; then
    fail 'The DBS credential key failed validation.'
fi

if ! runuser --user "${panel_user}" -- test -r "${secret_key_file}"; then
    fail 'The DBS credential key is not readable by the ISPConfig panel runtime user.'
fi

backup_directory="${ispconfig_interface_root}/dbsdns-backups/$(date -u +%Y%m%dT%H%M%SZ)-$$"
backup_created=0

ensure_backup_directory() {
    if ((backup_created == 0)); then
        mkdir -p -- "${backup_directory}"
        backup_created=1
    fi
}

restore_core_backups() {
    for restore_index in "${core_restore_indexes[@]}"; do
        backup_source="${backup_directory}/dns/${core_names[$restore_index]}"

        if [[ -f "${backup_source}" ]]; then
            cp -p -- "${backup_source}" "${core_targets[$restore_index]}"
        fi
    done
}

if ((${#core_restore_indexes[@]} > 0)); then
    ensure_backup_directory
    mkdir -p -- "${backup_directory}/dns"

    for core_index in "${core_restore_indexes[@]}"; do
        cp -p -- "${core_targets[$core_index]}" "${backup_directory}/dns/${core_names[$core_index]}"
    done

    for core_index in "${core_restore_indexes[@]}"; do
        if ! cp -- "${core_original_sources[$core_index]}" "${core_targets[$core_index]}"; then
            restore_core_backups
            fail 'ISPConfig core restore failed; all core files changed in this run were restored.'
        fi
    done

    for core_index in "${!core_names[@]}"; do
        verified_hash="$(sha256sum "${core_targets[$core_index]}" | awk '{print $1}')"

        if [[ "${verified_hash}" != "${core_original_hashes[$core_index]}" ]]; then
            restore_core_backups
            fail 'Restored ISPConfig DNS files failed checksum verification; all core files changed in this run were restored.'
        fi
    done
fi

if ((core_verification_required == 1 && obsolete_menu_migration_required == 1)); then
    ensure_backup_directory
    mkdir -p -- "${backup_directory}/obsolete/dns-menu"
    cp -p -- "${obsolete_menu_target}" "${backup_directory}/obsolete/dns-menu/"
    rm -f -- "${obsolete_menu_target}"
fi

if [[ -L "${module_target}" || ( -e "${module_target}" && ! -d "${module_target}" ) ]]; then
    fail 'The DBS DNS module target became unsafe before activation.'
fi

if [[ -d "${module_target}" ]]; then
    module_previous_created=1
    mv -T -- "${module_target}" "${module_previous}" \
        || fail 'The previous DBS DNS module could not be prepared for rollback.'
fi

module_activated=1
if ! mv -T -- "${module_stage}" "${module_target}"; then
    rollback_module_deployment
    fail 'The staged DBS DNS module could not be activated.'
fi

module_stage=''

if [[ ! -f "${core_free_marker}" ]]; then
    rollback_module_deployment
    fail 'The installed module is missing its core-free state marker; the previous module was restored.'
fi

if ((core_verification_required == 1)); then
    for core_index in "${!core_names[@]}"; do
        verified_hash="$(sha256sum "${core_targets[$core_index]}" | awk '{print $1}')" \
            || {
                rollback_module_deployment
                fail "ISPConfig core checksum failed after module installation: ${core_names[$core_index]}"
            }

        if [[ "${verified_hash}" != "${core_original_hashes[$core_index]}" ]]; then
            rollback_module_deployment
            fail "ISPConfig core verification failed after module installation: ${core_names[$core_index]}"
        fi
    done
fi

permission_sync_in_progress=1
permission_status=0
php "${module_permission_installer}" --interface-root "${ispconfig_interface_root}" || permission_status=$?
if ((permission_status > 128)); then
    fail 'Module-permission synchronization was interrupted before its result could be confirmed.'
fi
if ((permission_status == 0)); then
    module_deployment_complete=1
fi
permission_sync_in_progress=0
if ((permission_status != 0)); then
    fail 'ISPConfig module permissions could not be synchronized; restoring the previous module.'
fi

# Permission synchronization is the final persistent operation. Cleanup failure
# must not roll back a verified module after the permissions have been committed.
cleanup_module_stage
trap - EXIT INT TERM

printf '%s\n' 'DBS DNS module installed; cache/settings schema, credential key and module permissions verified.'
printf '%s\n' 'Core integration: none'

if ((backup_created == 1)); then
    printf 'Backup: %s\n' "${backup_directory}"
fi

printf '%s\n' 'Sign out and sign in again to rebuild the ISPConfig module and plugin caches.'
