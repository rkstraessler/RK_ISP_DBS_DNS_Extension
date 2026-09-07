#!/usr/bin/env bash

set -euo pipefail

test_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${test_directory}/.." && pwd -P)"
test_root="$(mktemp -d)"
test_interface_root="${test_root}/interface"
test_web_root="${test_interface_root}/web"
fake_bin="${test_root}/bin"
php_marker="${test_root}/php-invocations.txt"
mariadb_marker="${test_root}/mariadb-invocations.txt"
permission_marker="${test_root}/permission-invocations.txt"
schema_state_file="${test_root}/schema-state.txt"
secret_state_file="${test_root}/secret-state.txt"
secret_key_file="${test_root}/security/dbsdns/credentials.key"

cleanup() {
    rm -rf -- "${test_root}"
}

trap cleanup EXIT

# shellcheck disable=SC1090
source "${repository_root}/migration/ispconfig-3.3.1p1/restore-manifest.sh"

core_names=(dns_soa_list.php dns_soa_edit.php dns_a_edit.php dns_rr_del.php dns_edit_base.php)
original_hashes=(
    "${DNS_SOA_LIST_ORIGINAL_SHA256}"
    "${DNS_SOA_EDIT_ORIGINAL_SHA256}"
    "${DNS_A_EDIT_ORIGINAL_SHA256}"
    "${DNS_RR_DEL_ORIGINAL_SHA256}"
    "${DNS_EDIT_BASE_ORIGINAL_SHA256}"
)
current_hashes=(
    "${DNS_SOA_LIST_CURRENT_DBS_SHA256}"
    "${DNS_SOA_EDIT_CURRENT_DBS_SHA256}"
    "${DNS_A_EDIT_CURRENT_DBS_SHA256}"
    "${DNS_RR_DEL_CURRENT_DBS_SHA256}"
    "${DNS_EDIT_BASE_CURRENT_DBS_SHA256}"
)

mkdir -p -- "${fake_bin}"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_PHP_MARKER:?}"' \
    'if [[ "$1" == "-r" && "${2:-}" == *"sodium_crypto_aead_xchacha20poly1305_ietf_encrypt"* ]]; then' \
    '    exit "${DBSDNS_TEST_SODIUM_STATUS:-0}"' \
    'fi' \
    'if [[ "$1" == "-r" && "${2:-}" == *"class_exists(\"SoapClient\")"* ]]; then' \
    '    exit "${DBSDNS_TEST_SOAP_STATUS:-0}"' \
    'fi' \
    'if [[ "$1" == "-r" && "${2:-}" == *"fopen(\$path"* ]]; then' \
    '    printf "%032d" 0 > "${3:?}"' \
    '    exit 0' \
    'fi' \
    'case "$1" in' \
    '    */install_schema.php)' \
    '        if [[ " $* " == *" --database-name "* ]]; then' \
    '            printf "%s\n" "dbispconfig_test"' \
    '            exit 0' \
    '        fi' \
    '        if [[ " $* " == *" --settings-secret-state "* ]]; then' \
    '            cat "${DBSDNS_TEST_SECRET_STATE_FILE:?}"' \
    '            exit 0' \
    '        fi' \
    '        state="$(<"${DBSDNS_TEST_SCHEMA_STATE_FILE:?}")"' \
    '        case "${state}" in' \
    '            correct) printf "%s\n" "DBS-DNS-Cache-Schema ist bereit."; exit 0 ;;' \
    '            missing) printf "%s\n" "DBS-DNS-Cache-Tabelle fehlt." >&2; exit 10 ;;' \
    '            wrong-collation) exit 11 ;;' \
    '            incompatible) exit 12 ;;' \
    '        esac' \
    '        ;;' \
    '    */install_module_permissions.php)' \
    '        printf "%s\n" "DBS-DNS-Modulberechtigungen: 2 aktualisiert, 1 unverändert."' \
    '        exit "${DBSDNS_TEST_PERMISSION_STATUS:-0}"' \
    '        ;;' \
    'esac' \
    'exit 0' > "${fake_bin}/php"
chmod +x "${fake_bin}/php"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_MARIADB_MARKER:?}"' \
    'exit 0' > "${fake_bin}/mariadb"
chmod +x "${fake_bin}/mariadb"

real_stat="$(command -v stat)"
real_id="$(command -v id)"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'if [[ "$1" == "-c" && "$2" == "%U" ]]; then' \
    '    if [[ "$3" == "${DBSDNS_TEST_SECURITY_DIRECTORY:?}" ]]; then printf "%s\n" "root"; else printf "%s\n" "ispconfig"; fi' \
    '    exit 0' \
    'fi' \
    'if [[ "$1" == "-c" && "$2" == "%G" ]]; then printf "%s\n" "ispconfig"; exit 0; fi' \
    "exec ${real_stat} \"\$@\"" > "${fake_bin}/stat"
chmod +x "${fake_bin}/stat"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'if [[ "$1" == "-u" && "$2" == "ispconfig" ]]; then printf "%s\n" "12345"; exit 0; fi' \
    "exec ${real_id} \"\$@\"" > "${fake_bin}/id"
chmod +x "${fake_bin}/id"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "chown %s\n" "$*" >> "${DBSDNS_TEST_PERMISSION_MARKER:?}"' \
    'exit 0' > "${fake_bin}/chown"
chmod +x "${fake_bin}/chown"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "runuser %s\n" "$*" >> "${DBSDNS_TEST_PERMISSION_MARKER:?}"' \
    '[[ "$1" == "--user" && "$2" == "ispconfig" && "$3" == "--" ]] || exit 1' \
    'shift 3' \
    'exec "$@"' > "${fake_bin}/runuser"
chmod +x "${fake_bin}/runuser"

export PATH="${fake_bin}:${PATH}"
export DBSDNS_TEST_PHP_MARKER="${php_marker}"
export DBSDNS_TEST_MARIADB_MARKER="${mariadb_marker}"
export DBSDNS_TEST_SCHEMA_STATE_FILE="${schema_state_file}"
export DBSDNS_TEST_SECRET_STATE_FILE="${secret_state_file}"
export DBSDNS_TEST_PERMISSION_MARKER="${permission_marker}"
export DBSDNS_TEST_SECURITY_DIRECTORY="${test_root}/security"
export DBSDNS_TEST_SODIUM_STATUS=0
export DBSDNS_TEST_SOAP_STATUS=0

reset_layout() {
    rm -rf -- "${test_interface_root}"
    rm -rf -- "${test_root}/security"
    mkdir -p -- "${test_web_root}/dns/lib/menu.d" "${test_interface_root}/lib" "${test_root}/security"

    for core_name in "${core_names[@]}"; do
        cp -- "${repository_root}/migration/ispconfig-3.3.1p1/upstream/dns/${core_name}" \
            "${test_web_root}/dns/${core_name}"
    done

    printf "%s\n" "<?php define('ISPC_APP_VERSION', '3.3.1p1');" \
        > "${test_interface_root}/lib/config.inc.php"
    printf '%s\n' 'correct' > "${schema_state_file}"
    printf '%s\n' 'empty' > "${secret_state_file}"
    rm -f -- "${php_marker}" "${mariadb_marker}" "${permission_marker}"
}

assert_original_core() {
    local message="$1"

    for core_index in "${!core_names[@]}"; do
        actual_hash="$(sha256sum "${test_web_root}/dns/${core_names[$core_index]}" | awk '{print $1}')"

        if [[ "${actual_hash}" != "${original_hashes[$core_index]}" ]]; then
            printf '%s: %s\n' "${message}" "${core_names[$core_index]}" >&2
            exit 1
        fi
    done
}

apply_legacy_fixture() {
    patch --batch --fuzz=0 --forward -p1 -d "${test_web_root}" \
        < "${repository_root}/tests/fixtures/legacy-dbs-core/$1" >/dev/null
}

run_installer() {
    bash "${repository_root}/scripts/install.sh" --web-root "${test_web_root}" "$@"
}

# Required PHP extensions fail during preflight before database, key or module writes.
reset_layout
export DBSDNS_TEST_SOAP_STATUS=1

if run_installer --dry-run > "${test_root}/missing-soap.out" 2>&1; then
    printf '%s\n' 'Installer accepted a PHP runtime without SOAP.' >&2
    exit 1
fi

if ! grep -Fq 'PHP SOAP extension is required' "${test_root}/missing-soap.out" \
    || [[ -e "${secret_key_file}" || -e "${test_web_root}/dbsdns" || -e "${mariadb_marker}" ]]; then
    printf '%s\n' 'Missing SOAP did not fail before all installation writes.' >&2
    exit 1
fi

export DBSDNS_TEST_SOAP_STATUS=0
reset_layout
export DBSDNS_TEST_SODIUM_STATUS=1

if run_installer --dry-run > "${test_root}/missing-sodium.out" 2>&1; then
    printf '%s\n' 'Installer accepted a PHP runtime without Sodium.' >&2
    exit 1
fi

if ! grep -Fq 'PHP Sodium extension' "${test_root}/missing-sodium.out" \
    || [[ -e "${secret_key_file}" || -e "${test_web_root}/dbsdns" || -e "${mariadb_marker}" ]]; then
    printf '%s\n' 'Missing Sodium did not fail before all installation writes.' >&2
    exit 1
fi

export DBSDNS_TEST_SODIUM_STATUS=0

# A: A fresh upstream installation is inspected without changing core or module.
reset_layout
run_installer --dry-run > "${test_root}/fresh-dry-run.out"
assert_original_core 'Fresh dry-run changed an upstream core file'

if [[ -e "${test_web_root}/dbsdns" ]]; then
    printf '%s\n' 'Fresh dry-run installed the module.' >&2
    exit 1
fi

if [[ -e "${secret_key_file}" ]]; then
    printf '%s\n' 'Fresh dry-run created the credential key.' >&2
    exit 1
fi

if ! grep -Fq 'Core integration target: none' "${test_root}/fresh-dry-run.out"; then
    printf '%s\n' 'Fresh dry-run did not report the core-free target.' >&2
    exit 1
fi

rm -f -- "${php_marker}" "${mariadb_marker}"
run_installer > "${test_root}/fresh-install.out"
assert_original_core 'Fresh installation changed an upstream core file'

if [[
    ! -f "${test_web_root}/dbsdns/.core-integration-none"
    || ! -f "${test_web_root}/dbsdns/zone_list.php"
    || ! -f "${test_web_root}/dbsdns/record_edit.php"
    || -e "${test_web_root}/dbsdns/integration/dns_soa_list.inc.php"
]]; then
    printf '%s\n' 'Fresh installation did not deploy only the core-free module.' >&2
    exit 1
fi

if [[ ! -f "${secret_key_file}" || "$(wc -c < "${secret_key_file}")" -ne 32 ]]; then
    printf '%s\n' 'Fresh installation did not create the 32-byte external credential key.' >&2
    exit 1
fi

if [[
    "$(stat -c '%a' "${test_root}/security/dbsdns")" != '750'
    || "$(stat -c '%a' "${secret_key_file}")" != '640'
]] || ! grep -Fq "chown root:ispconfig ${test_root}/security/dbsdns" "${permission_marker}" \
    || ! grep -Fq "chown root:ispconfig ${secret_key_file}" "${permission_marker}" \
    || ! grep -Fq "runuser --user ispconfig -- test -r ${secret_key_file}" "${permission_marker}"; then
    printf '%s\n' 'Credential key ownership, restrictive modes or panel-runtime readability were not enforced.' >&2
    exit 1
fi

first_key_hash="$(sha256sum "${secret_key_file}" | awk '{print $1}')"

if ! grep -Fq 'install_module_permissions.php' "${php_marker}" \
    || ! grep -Fq 'Core integration: none' "${test_root}/fresh-install.out"; then
    printf '%s\n' 'Fresh installation did not synchronize module permissions or report no core integration.' >&2
    exit 1
fi

if [[ -d "${test_interface_root}/dbsdns-backups" ]]; then
    printf '%s\n' 'Fresh installation created an unnecessary core backup.' >&2
    exit 1
fi

# A failed permission synchronization restores the previous module atomically.
printf '%s\n' 'previous-module-marker' > "${test_web_root}/dbsdns/previous-module-marker.txt"
export DBSDNS_TEST_PERMISSION_STATUS=1

if run_installer > "${test_root}/permission-failure.out" 2>&1; then
    printf '%s\n' 'Installer accepted a failed module-permission synchronization.' >&2
    exit 1
fi

export DBSDNS_TEST_PERMISSION_STATUS=0

if [[ ! -f "${test_web_root}/dbsdns/previous-module-marker.txt" ]] \
    || find "${test_web_root}" -maxdepth 1 -name '.dbsdns-*' -print -quit | grep -q .; then
    printf '%s\n' 'Failed staged deployment did not restore the previous module cleanly.' >&2
    exit 1
fi

# C: Reinstallation uses the marker and manages no core file.
rm -f -- "${php_marker}" "${mariadb_marker}"
run_installer > "${test_root}/repeat.out"
assert_original_core 'Repeated installation changed an upstream core file'

if [[ "$(sha256sum "${secret_key_file}" | awk '{print $1}')" != "${first_key_hash}" ]]; then
    printf '%s\n' 'Repeated installation replaced the credential key.' >&2
    exit 1
fi

if ! grep -Fq 'no ISPConfig core file is managed' "${test_root}/repeat.out"; then
    printf '%s\n' 'Repeated installation still manages core patch state.' >&2
    exit 1
fi

printf '%s\n' '// foreign post-migration customization' >> "${test_web_root}/dns/dns_soa_list.php"
custom_hash="$(sha256sum "${test_web_root}/dns/dns_soa_list.php" | awk '{print $1}')"
printf '%s\n' '// foreign post-migration menu' > "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php"
custom_menu_hash="$(sha256sum "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php" | awk '{print $1}')"
run_installer > "${test_root}/repeat-custom.out"

if [[
    "$(sha256sum "${test_web_root}/dns/dns_soa_list.php" | awk '{print $1}')" != "${custom_hash}"
    || "$(sha256sum "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php" | awk '{print $1}')" != "${custom_menu_hash}"
    || -d "${test_interface_root}/dbsdns-backups"
]]; then
    printf '%s\n' 'The normal core-free installer touched or backed up a later foreign core customization.' >&2
    exit 1
fi

# B: The complete known current DBS patch set is backed up and restored exactly.
reset_layout
apply_legacy_fixture dns_soa_list.diff.fixture
apply_legacy_fixture dns_soa_edit.diff.fixture
apply_legacy_fixture dns_a_edit.diff.fixture
apply_legacy_fixture dns_rr_del.diff.fixture
apply_legacy_fixture dns_edit_base.diff.fixture
cp -- "${repository_root}/tests/fixtures/legacy-dbs-core/dbsdns_provider_zones.menu.php.fixture" \
    "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php"

for core_index in "${!core_names[@]}"; do
    actual_hash="$(sha256sum "${test_web_root}/dns/${core_names[$core_index]}" | awk '{print $1}')"

    if [[ "${actual_hash}" != "${current_hashes[$core_index]}" ]]; then
        printf 'Current legacy fixture hash mismatch: %s\n' "${core_names[$core_index]}" >&2
        exit 1
    fi
done

run_installer > "${test_root}/current-migration.out"
assert_original_core 'Current DBS migration did not restore the exact upstream core'
backup_directory="$(find "${test_interface_root}/dbsdns-backups" -mindepth 1 -maxdepth 1 -type d | head -n 1)"

if [[ -z "${backup_directory}" ]]; then
    printf '%s\n' 'Current DBS migration created no core backup.' >&2
    exit 1
fi

for core_index in "${!core_names[@]}"; do
    backup_hash="$(sha256sum "${backup_directory}/dns/${core_names[$core_index]}" | awk '{print $1}')"

    if [[ "${backup_hash}" != "${current_hashes[$core_index]}" ]]; then
        printf 'Current DBS backup is incomplete: %s\n' "${core_names[$core_index]}" >&2
        exit 1
    fi
done

if [[ -e "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php" ]]; then
    printf '%s\n' 'Current DBS migration left the known obsolete native DNS menu behind.' >&2
    exit 1
fi

menu_backup="${backup_directory}/obsolete/dns-menu/dbsdns_provider_zones.menu.php"

if [[ ! -f "${menu_backup}" \
    || "$(sha256sum "${menu_backup}" | awk '{print $1}')" != "${OBSOLETE_DNS_MENU_SHA256}" ]]; then
    printf '%s\n' 'Current DBS migration did not safely back up the known obsolete DNS menu.' >&2
    exit 1
fi

if ! grep -Fq 'restore known DBS changes' "${test_root}/current-migration.out" \
    || ! grep -Fq 'Core integration: none' "${test_root}/current-migration.out"; then
    printf '%s\n' 'Current DBS migration did not report the restore and final state.' >&2
    exit 1
fi

# A known previous release (SOA plus A/AAAA/CNAME/MX base delegation) is also restored.
reset_layout
apply_legacy_fixture dns_soa_list.diff.fixture
apply_legacy_fixture dns_soa_edit.diff.fixture
apply_legacy_fixture dns_edit_base.diff.fixture
apply_legacy_fixture dns_edit_base.current-to-previous.diff.fixture
run_installer > "${test_root}/previous-migration.out"
assert_original_core 'Previous DBS migration did not restore the exact upstream core'

if ! grep -Fq 'previous-dbs-patched' "${test_root}/previous-migration.out"; then
    printf '%s\n' 'The known previous DBS core state was not recognized.' >&2
    exit 1
fi

# D: Unknown pre-migration core changes abort before schema inspection or writes.
reset_layout
printf '%s\n' '// unknown local modification' >> "${test_web_root}/dns/dns_a_edit.php"

if run_installer --dry-run > "${test_root}/unknown.out" 2>&1; then
    printf '%s\n' 'The installer accepted an unknown pre-migration core state.' >&2
    exit 1
fi

if ! grep -Fq 'Unknown ISPConfig DNS core state for dns_a_edit.php' "${test_root}/unknown.out" \
    || [[ -e "${php_marker}" || -e "${test_web_root}/dbsdns" ]]; then
    printf '%s\n' 'Unknown core state did not abort before database and module changes.' >&2
    exit 1
fi

# An unknown file at the historical native-menu path is never removed automatically.
reset_layout
printf '%s\n' '// unrelated native DNS menu' > "${test_web_root}/dns/lib/menu.d/dbsdns_provider_zones.menu.php"

if run_installer --dry-run > "${test_root}/unknown-menu.out" 2>&1; then
    printf '%s\n' 'The installer accepted an unknown native DNS menu artifact.' >&2
    exit 1
fi

if ! grep -Fq 'dns/lib/menu.d/dbsdns_provider_zones.menu.php' "${test_root}/unknown-menu.out" \
    || [[ -e "${php_marker}" || -e "${test_web_root}/dbsdns" ]]; then
    printf '%s\n' 'Unknown native DNS menu state did not abort before database and module changes.' >&2
    exit 1
fi

# Existing schema lifecycle behavior remains intact in dry-run mode.
reset_layout
printf '%s\n' 'missing' > "${schema_state_file}"
run_installer --dry-run > "${test_root}/missing-schema.out"

if ! grep -Fq 'privileged installer would apply' "${test_root}/missing-schema.out" \
    || [[ ! -e "${mariadb_marker}" ]] \
    || ! grep -Fq -- '--database=dbispconfig_test' "${mariadb_marker}"; then
    printf '%s\n' 'Missing-schema dry-run behavior regressed.' >&2
    exit 1
fi

# Existing ciphertext without its external key fails closed and is never replaced automatically.
reset_layout
printf '%s\n' 'configured' > "${secret_state_file}"

if run_installer --dry-run > "${test_root}/missing-key-with-secret.out" 2>&1; then
    printf '%s\n' 'Installer accepted encrypted credentials without their external key.' >&2
    exit 1
fi

if ! grep -Fq 'external key is missing' "${test_root}/missing-key-with-secret.out" \
    || [[ -e "${secret_key_file}" ]]; then
    printf '%s\n' 'Missing credential key did not fail closed before creating a replacement.' >&2
    exit 1
fi

if grep -Eq '(^|[[:space:]])patch([[:space:]]|$)|--fuzz|dbsdns-native-dns\.patch' \
    "${repository_root}/scripts/install.sh"; then
    printf '%s\n' 'The normal installer still contains core patch application logic.' >&2
    exit 1
fi

printf '%s\n' 'Core-free installer, safe one-time restore and idempotency successfully tested.'
