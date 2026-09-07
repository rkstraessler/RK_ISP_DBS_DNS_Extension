#!/usr/bin/env bash

set -euo pipefail

# This test exercises the privileged copy path with real Unix credentials.  It
# is intentionally a no-op on developer workstations where root is unavailable;
# the Docker test jobs run it as root in both supported PHP images.
if [[ "$(uname -s 2>/dev/null || true)" != 'Linux' || "$(id -u)" -ne 0 ]]; then
    printf '%s\n' 'Install permissions integration test skipped (Linux root is required).'
    exit 0
fi

test_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repository_root="$(cd -- "${test_directory}/.." && pwd -P)"
test_root="$(mktemp -d)"
fixture_root="${test_root}/repository"
ispconfig_root="${test_root}/ispconfig"
interface_root="${ispconfig_root}/interface"
web_root="${interface_root}/web"
security_root="${ispconfig_root}/security"
module_root="${web_root}/dbsdns"
fake_bin="${test_root}/bin"
php_marker="${test_root}/php-invocations.txt"
db_marker="${test_root}/db-writes.txt"
real_php="$(command -v php)"
real_cp="$(command -v cp)"
real_rm="$(command -v rm)"
real_mkdir="$(command -v mkdir)"
real_chmod="$(command -v chmod)"
real_chown="$(command -v chown)"
real_stat="$(command -v stat)"
real_useradd="$(command -v useradd)"
real_userdel="$(command -v userdel)"

for required_command in awk bash chmod chown cmp cp find grep id ln mkdir mktemp php rm runuser sha256sum stat tr useradd userdel; do
    command -v "${required_command}" >/dev/null 2>&1 \
        || { printf 'Required command not found: %s\n' "${required_command}" >&2; exit 1; }
done

panel_user="dbsdns_test_panel_$$"
panel_group="${panel_user}"
web_user="dbsdns_test_webuser_$$"
user_created=0
web_user_created=0

cleanup() {
    set +e
    if ((web_user_created == 1)); then
        "${real_userdel}" --remove "${web_user}" >/dev/null 2>&1 || true
    fi
    if ((user_created == 1)); then
        "${real_userdel}" --remove "${panel_user}" >/dev/null 2>&1 || true
    fi
    "${real_rm}" -rf -- "${test_root}"
}
trap cleanup EXIT

"${real_useradd}" --system --no-create-home --user-group --shell /usr/sbin/nologin "${panel_user}"
user_created=1
"${real_useradd}" --system --no-create-home --user-group --groups "${panel_group}" --shell /usr/sbin/nologin "${web_user}"
web_user_created=1

"${real_mkdir}" -p -- "${fixture_root}" "${fake_bin}"
"${real_cp}" -a -- "${repository_root}/scripts" "${repository_root}/src" "${repository_root}/migration" "${repository_root}/install" "${fixture_root}/"

# Keep the temporary outer tree traversable for the panel's protected key,
# while making the copied release source itself a root-only parent.
"${real_chmod}" 0755 "${test_root}"
"${real_chmod}" 0700 "${fixture_root}"

# The repository is intentionally unreadable by the panel account.  The
# installer must validate it as root and copy it as root into a safe staging
# area; a panel-owned runuser copy would fail here.
"${real_chown}" -R root:root "${fixture_root}/src/dbsdns"
"${real_chmod}" -R go-rwx "${fixture_root}/src/dbsdns"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_PHP_MARKER:?}"' \
    'if [[ "${1:-}" == "-r" && "${2:-}" == *"sodium_crypto_aead_xchacha20poly1305_ietf_encrypt"* ]]; then exit 0; fi' \
    'if [[ "${1:-}" == "-r" && "${2:-}" == *"class_exists(\"SoapClient\")"* ]]; then exit 0; fi' \
    'case "${1:-}" in' \
    '    */install_schema.php)' \
    '        if [[ " $* " == *" --database-name "* ]]; then printf "%s\n" "dbispconfig_test"; exit 0; fi' \
    '        if [[ " $* " == *" --settings-secret-state "* ]]; then printf "%s\n" "empty"; exit 0; fi' \
    '        if [[ "${DBSDNS_TEST_SCHEMA_STATE:-correct}" == "missing" ]]; then printf "%s\n" "DBS-DNS-Cache-Tabelle fehlt." >&2; exit 10; fi' \
    '        printf "%s\n" "DBS-DNS-Cache- und Settings-Schema sind bereit."; exit 0' \
    '        ;;' \
    '    */install_module_permissions.php|*/uninstall_module_permissions.php)' \
    '        if [[ " $* " == *" --preflight "* ]]; then exit 0; fi' \
    '        if [[ "${DBSDNS_TEST_PERMISSION_STATUS:-0}" -ne 0 ]]; then exit "${DBSDNS_TEST_PERMISSION_STATUS}"; fi' \
    '        printf "%s\n" "DBS-DNS-Modulberechtigungen: 2 aktualisiert, 1 unverändert."; exit 0' \
    '        ;;' \
    'esac' \
    'exec "${DBSDNS_TEST_REAL_PHP:?}" "$@"' > "${fake_bin}/php"
"${real_chmod}" +x "${fake_bin}/php"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'if [[ "$*" == *"--execute"* ]]; then exit 0; fi' \
    'printf "%s\n" "$*" >> "${DBSDNS_TEST_DB_MARKER:?}"' \
    'exit 0' > "${fake_bin}/mariadb"
"${real_chmod}" +x "${fake_bin}/mariadb"

export DBSDNS_TEST_PHP_MARKER="${php_marker}"
export DBSDNS_TEST_DB_MARKER="${db_marker}"
export DBSDNS_TEST_REAL_PHP="${real_php}"
export DBSDNS_TEST_PERMISSION_STATUS=0
export DBSDNS_TEST_SCHEMA_STATE=correct
export PATH="${fake_bin}:${PATH}"

core_names=(dns_soa_list.php dns_soa_edit.php dns_a_edit.php dns_rr_del.php dns_edit_base.php)

prepare_layout() {
    "${real_rm}" -rf -- "${ispconfig_root}"
    "${real_mkdir}" -p -- "${web_root}/dns/lib/menu.d" "${interface_root}/lib" "${security_root}"

    for core_name in "${core_names[@]}"; do
        "${real_cp}" -- "${fixture_root}/migration/ispconfig-3.3.1p1/upstream/dns/${core_name}" \
            "${web_root}/dns/${core_name}"
    done

    printf "%s\n" "<?php define('ISPC_APP_VERSION', '3.3.1p1');" \
        > "${interface_root}/lib/config.inc.php"
    printf '%s\n' '<?php' > "${interface_root}/lib/app.inc.php"
    "${real_chown}" -R "${panel_user}:${panel_group}" "${interface_root}"
    "${real_chown}" root:"${panel_group}" "${security_root}"
    "${real_chmod}" "${DBSDNS_TEST_NATIVE_DIR_MODE:-750}" "${web_root}/dns"
    "${real_chmod}" "${DBSDNS_TEST_NATIVE_FILE_MODE:-640}" "${web_root}/dns/"*.php
    "${real_chmod}" 0750 "${security_root}"
    "${real_rm}" -f -- "${php_marker}" "${db_marker}"
}

run_installer() {
    bash "${fixture_root}/scripts/install.sh" --web-root "${web_root}" "$@"
}

assert_no_state_change() {
    if [[ -e "${module_root}" || -e "${security_root}/dbsdns/credentials.key" || -e "${db_marker}" ]]; then
        printf '%s\n' 'Failed preflight/staging changed module, credential-key or database state.' >&2
        exit 1
    fi
}

assert_original_core() {
    for core_name in "${core_names[@]}"; do
        cmp -s "${web_root}/dns/${core_name}" "${fixture_root}/migration/ispconfig-3.3.1p1/upstream/dns/${core_name}" \
            || { printf 'Native core file changed: %s\n' "${core_name}" >&2; exit 1; }
    done
}

prepare_layout
run_installer --dry-run > "${test_root}/dry-run.out"
assert_no_state_change

if [[ "$(${real_stat} -c '%U:%G' "${ispconfig_root}")" != 'root:root' ]]; then
    printf '%s\n' 'The ISPConfig install root is not root-owned.' >&2
    exit 1
fi

if runuser --user "${panel_user}" -- test -r "${fixture_root}/src/dbsdns/zone_list.php"; then
    printf '%s\n' 'The panel account unexpectedly read the protected module source.' >&2
    exit 1
fi

# An incomplete source tree and a source symlink both fail before any state
# transition.  Restore the source after each case for the remaining checks.
"${real_rm}" -f -- "${fixture_root}/src/dbsdns/zone_list.php"
if run_installer > "${test_root}/incomplete.out" 2>&1; then
    printf '%s\n' 'Installer accepted an incomplete module source tree.' >&2
    exit 1
fi
assert_no_state_change
"${real_cp}" -- "${repository_root}/src/dbsdns/zone_list.php" "${fixture_root}/src/dbsdns/zone_list.php"
"${real_chown}" root:root "${fixture_root}/src/dbsdns/zone_list.php"
"${real_chmod}" 0600 "${fixture_root}/src/dbsdns/zone_list.php"

"${real_rm}" -f -- "${fixture_root}/src/dbsdns/zone_list.php"
ln -s -- zone_view.php "${fixture_root}/src/dbsdns/zone_list.php"
if run_installer > "${test_root}/symlink-source.out" 2>&1; then
    printf '%s\n' 'Installer accepted a symbolic-link module source file.' >&2
    exit 1
fi
assert_no_state_change
"${real_rm}" -f -- "${fixture_root}/src/dbsdns/zone_list.php"
"${real_cp}" -- "${repository_root}/src/dbsdns/zone_list.php" "${fixture_root}/src/dbsdns/zone_list.php"
"${real_chown}" root:root "${fixture_root}/src/dbsdns/zone_list.php"
"${real_chmod}" 0600 "${fixture_root}/src/dbsdns/zone_list.php"

# A failing root copy is a staging failure.  Since staging precedes key and
# database mutations, a fresh installation leaves no state behind.
printf '%s\n' \
    '#!/usr/bin/env bash' \
    'for argument in "$@"; do' \
    '    if [[ "${DBSDNS_TEST_FAIL_STAGE:-0}" -eq 1 && "$argument" == */src/dbsdns/. ]]; then exit 1; fi' \
    'done' \
    "exec ${real_cp} \"\$@\"" > "${fake_bin}/cp"
"${real_chmod}" +x "${fake_bin}/cp"
export DBSDNS_TEST_FAIL_STAGE=1
export DBSDNS_TEST_SCHEMA_STATE=missing
prepare_layout
if run_installer > "${test_root}/stage-failure.out" 2>&1; then
    printf '%s\n' 'Installer accepted a failed staged source copy.' >&2
    exit 1
fi
assert_no_state_change
export DBSDNS_TEST_FAIL_STAGE=0
export DBSDNS_TEST_SCHEMA_STATE=correct
"${real_rm}" -f -- "${fake_bin}/cp"

prepare_layout
assert_original_core
run_installer > "${test_root}/first-install.out"
key_file="${security_root}/dbsdns/credentials.key"
first_key_hash="$(sha256sum "${key_file}" | awk '{print $1}')"

if find "${security_root}" -maxdepth 1 -name '.dbsdns-stage-*' -print -quit | grep -q .; then
    printf '%s\n' 'Successful installation left a private staging workspace behind.' >&2
    exit 1
fi

if [[ "$("${real_stat}" -c '%U:%G' "${security_root}")" != "root:${panel_group}" \
    || "$("${real_stat}" -c '%a' "${security_root}")" != '750' \
    || "$("${real_stat}" -c '%U:%G' "${security_root}/dbsdns")" != "root:${panel_group}" \
    || "$("${real_stat}" -c '%a' "${security_root}/dbsdns")" != '750' \
    || "$("${real_stat}" -c '%U:%G' "${key_file}")" != "root:${panel_group}" \
    || "$("${real_stat}" -c '%a' "${key_file}")" != '640' \
    || "$("${real_stat}" -c '%U:%G' "${module_root}")" != "${panel_user}:${panel_group}" \
    || "$("${real_stat}" -c '%a' "${module_root}")" != '750' ]]; then
    printf '%s\n' 'Installed module root did not receive the expected owner and mode.' >&2
    exit 1
fi

if find "${module_root}" -type d ! -perm 0750 -print -quit | grep -q . \
    || find "${module_root}" -type f ! -perm 0640 -print -quit | grep -q . \
    || find "${module_root}" ! -user "${panel_user}" -print -quit | grep -q . \
    || find "${module_root}" ! -group "${panel_group}" -print -quit | grep -q .; then
    printf '%s\n' 'Installed module files or directories have inconsistent ownership or modes.' >&2
    exit 1
fi

if ! runuser --user "${web_user}" -- id -Gn | tr ' ' '\n' | grep -Fxq "${panel_group}"; then
    printf '%s\n' 'The separate webserver account is not a member of the panel group.' >&2
    exit 1
fi

for readable_file in "${module_root}/zone_list.php" "${module_root}/css/dbsdns-ui.css" "${module_root}/js/dbsdns-record-table.js"; do
    if ! runuser --user "${panel_user}" -- test -r "${readable_file}"; then
        printf 'Panel account cannot read installed file: %s\n' "${readable_file}" >&2
        exit 1
    fi
    if [[ "$("${real_stat}" -c '%G' "${readable_file}")" != "${panel_group}" ]]; then
        printf 'Installed file has the wrong group: %s\n' "${readable_file}" >&2
        exit 1
    fi
    if ! runuser --user "${web_user}" -- test -r "${readable_file}"; then
        printf 'Webserver account cannot read installed file: %s\n' "${readable_file}" >&2
        exit 1
    fi
done

for native_file in "${core_names[@]}"; do
    if [[ "$("${real_stat}" -c '%a' "${web_root}/dns/${native_file}")" != "${DBSDNS_TEST_NATIVE_FILE_MODE:-640}" ]]; then
        printf 'Native DNS file mode changed during installation: %s\n' "${native_file}" >&2
        exit 1
    fi
done
if [[ "$("${real_stat}" -c '%a' "${web_root}/dns")" != "${DBSDNS_TEST_NATIVE_DIR_MODE:-750}" ]]; then
    printf '%s\n' 'Native DNS directory mode was changed during installation.' >&2
    exit 1
fi
assert_original_core

printf '%s\n' 'existing-module-marker' > "${module_root}/existing-marker.txt"
export DBSDNS_TEST_PERMISSION_STATUS=1
if run_installer > "${test_root}/permission-failure.out" 2>&1; then
    printf '%s\n' 'Installer accepted failed module-permission synchronization.' >&2
    exit 1
fi
export DBSDNS_TEST_PERMISSION_STATUS=0

if [[ ! -f "${module_root}/existing-marker.txt" || "$(sha256sum "${key_file}" | awk '{print $1}')" != "${first_key_hash}" ]]; then
    printf '%s\n' 'Existing module rollback did not restore the module and key state.' >&2
    exit 1
fi

run_installer > "${test_root}/repeat-install.out"
assert_original_core
if [[ "$(sha256sum "${key_file}" | awk '{print $1}')" != "${first_key_hash}" ]]; then
    printf '%s\n' 'Repeated installation replaced the external credential key.' >&2
    exit 1
fi

bash "${fixture_root}/scripts/uninstall.sh" --web-root "${web_root}" > "${test_root}/uninstall.out"
if [[ -e "${module_root}" || ! -f "${key_file}" ]]; then
    printf '%s\n' 'Uninstall did not remove only the module and preserve its key.' >&2
    exit 1
fi

run_installer > "${test_root}/reinstall.out"
assert_original_core
if [[ ! -f "${module_root}/zone_list.php" || "$(sha256sum "${key_file}" | awk '{print $1}')" != "${first_key_hash}" ]]; then
    printf '%s\n' 'Reinstallation did not restore the module or preserve the key.' >&2
    exit 1
fi

# Exercise the alternate native layout used by ISPConfig installations with
# world-readable interface directories and files.
export DBSDNS_TEST_NATIVE_DIR_MODE=755
export DBSDNS_TEST_NATIVE_FILE_MODE=644
prepare_layout
assert_original_core
run_installer > "${test_root}/native-755-install.out"
if [[ "$("${real_stat}" -c '%a' "${web_root}/dns")" != '755' \
    || "$("${real_stat}" -c '%a' "${web_root}/dns/dns_soa_list.php")" != '644' \
    || "$("${real_stat}" -c '%a' "${module_root}")" != '755' \
    || "$("${real_stat}" -c '%a' "${module_root}/zone_list.php")" != '644' ]]; then
    printf '%s\n' 'The alternate 755/644 native layout was not preserved for the module.' >&2
    exit 1
fi
assert_original_core

printf '%s\n' 'Privileged install, protected source copy, permissions, rollback and lifecycle integration checks passed.'
