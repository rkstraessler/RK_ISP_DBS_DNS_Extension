(function(window, document, $) {
    'use strict';

    if(!$ || !window.ISPConfig) {
        return;
    }

    if(!window.DbsDnsMutationLock) {
        var state = {
            pending: false,
            root: null,
            region: null,
            requestPath: '',
            request: null,
            disableTimer: null,
            releaseTimer: null
        };

        var currentRoot = function() {
            return document.querySelector('#pageContent .dbsdns-mutation-root');
        };

        var requestPath = function(url) {
            return typeof url === 'string' ? url.split('?')[0] : '';
        };

        var restoreControls = function(root) {
            if(!root) {
                return;
            }

            $(root).find('[data-dbsdns-locked="disabled"]').each(function() {
                this.disabled = false;
                this.removeAttribute('data-dbsdns-locked');
            });
            $(root).find('[data-dbsdns-locked="tabindex"]').each(function() {
                var previousTabIndex = this.getAttribute('data-dbsdns-tabindex');

                if(previousTabIndex === '') {
                    this.removeAttribute('tabindex');
                } else {
                    this.setAttribute('tabindex', previousTabIndex);
                }

                this.removeAttribute('data-dbsdns-tabindex');
                this.removeAttribute('data-dbsdns-locked');
                this.removeAttribute('aria-disabled');
            });
        };

        var finish = function() {
            if(state.disableTimer !== null) {
                window.clearTimeout(state.disableTimer);
            }

            if(state.releaseTimer !== null) {
                window.clearTimeout(state.releaseTimer);
            }

            restoreControls(state.root);

            if(state.root) {
                state.root.classList.remove('is-dbsdns-pending');
            }

            if(state.region) {
                state.region.classList.remove('is-dbsdns-loading');
                state.region.setAttribute('aria-busy', 'false');
                state.region.style.minHeight = '';

                var oldOverlay = state.region.querySelector('.dbsdns-loading-overlay');

                if(oldOverlay) {
                    oldOverlay.setAttribute('aria-hidden', 'true');
                }
            }

            state.pending = false;
            state.root = null;
            state.region = null;
            state.requestPath = '';
            state.request = null;
            state.disableTimer = null;
            state.releaseTimer = null;
        };

        var failSafeRelease = function() {
            state.releaseTimer = null;

            if(!state.pending) {
                return;
            }

            if(state.request && state.request.readyState !== 4) {
                state.releaseTimer = window.setTimeout(failSafeRelease, 15000);
                return;
            }

            finish();
        };

        var disableControls = function() {
            if(!state.pending || !state.root || !document.documentElement.contains(state.root)) {
                return;
            }

            $(state.root).find('button, input:not([type="hidden"]), select, textarea').each(function() {
                if(this.disabled) {
                    return;
                }

                this.disabled = true;
                this.setAttribute('data-dbsdns-locked', 'disabled');
            });
            $(state.root).find('a, th[data-column]').each(function() {
                if(this.getAttribute('aria-disabled') === 'true') {
                    return;
                }

                this.setAttribute('data-dbsdns-tabindex', this.getAttribute('tabindex') || '');
                this.setAttribute('data-dbsdns-locked', 'tabindex');
                this.setAttribute('aria-disabled', 'true');
                this.setAttribute('tabindex', '-1');
            });
        };

        var begin = function(root, region, url) {
            if(state.pending || !root || !region) {
                return false;
            }

            state.pending = true;
            state.root = root;
            state.region = region;
            state.requestPath = requestPath(url);
            region.style.minHeight = Math.ceil(region.getBoundingClientRect().height) + 'px';
            region.classList.add('is-dbsdns-loading');
            region.setAttribute('aria-busy', 'true');
            root.classList.add('is-dbsdns-pending');

            var overlay = region.querySelector('.dbsdns-loading-overlay');

            if(overlay) {
                overlay.setAttribute('aria-hidden', 'false');
            }

            state.disableTimer = window.setTimeout(disableControls, 0);
            state.releaseTimer = window.setTimeout(failSafeRelease, 75000);

            return true;
        };

        var blockEvent = function(event) {
            event.preventDefault();
            event.stopImmediatePropagation();
        };

        var handleClick = function(event) {
            var root = $(event.target).closest('.dbsdns-mutation-root').get(0);

            if(!root) {
                return;
            }

            if(state.pending) {
                blockEvent(event);
                return;
            }

            var deleteControl = $(event.target).closest('[data-dbsdns-delete-action]').get(0);

            if(deleteControl) {
                blockEvent(event);

                if(!window.confirm(deleteControl.getAttribute('data-dbsdns-delete-confirmation') || '')) {
                    return;
                }

                var deleteUrl = deleteControl.getAttribute('data-dbsdns-delete-action') || '';
                var recordsRegion = root.querySelector('[data-dbsdns-region="records"]');

                if(begin(root, recordsRegion, deleteUrl)) {
                    var oldForm = document.getElementById('dbsdnsRecordDeleteForm');

                    if(oldForm && oldForm.parentNode) {
                        oldForm.parentNode.removeChild(oldForm);
                    }

                    var form = document.createElement('form');
                    form.id = 'dbsdnsRecordDeleteForm';
                    form.method = 'post';
                    form.style.display = 'none';

                    var fields = {
                        id: deleteControl.getAttribute('data-dbsdns-delete-id') || '',
                        _csrf_id: deleteControl.getAttribute('data-dbsdns-delete-csrf-id') || '',
                        _csrf_key: deleteControl.getAttribute('data-dbsdns-delete-csrf-key') || ''
                    };

                    Object.keys(fields).forEach(function(name) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = fields[name];
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    window.ISPConfig.submitForm(form.id, deleteUrl);
                }

                return;
            }

            var mutationControl = $(event.target).closest('[data-dbsdns-mutation]').get(0);

            if(!mutationControl) {
                return;
            }

            var mutationName = mutationControl.getAttribute('data-dbsdns-mutation');
            var regionName = mutationName === 'zone-save' ? 'zone-settings' : 'record-form';
            var region = root.querySelector('[data-dbsdns-region="' + regionName + '"]');
            var url = mutationControl.getAttribute('data-form-action') || '';

            if(!begin(root, region, url)) {
                blockEvent(event);
            }
        };

        var handleLockedEvent = function(event) {
            if(
                state.pending
                && state.root
                && $(event.target).closest('.dbsdns-mutation-root').get(0) === state.root
            ) {
                blockEvent(event);
            }
        };

        var init = function() {
            var root = currentRoot();

            if(state.pending && root !== state.root) {
                finish();
            }

            if(root) {
                root.classList.remove('is-dbsdns-pending');
                $(root).find('.dbsdns-blocking-region').removeClass('is-dbsdns-loading').attr('aria-busy', 'false');
                $(root).find('.dbsdns-loading-overlay').attr('aria-hidden', 'true');
            }
        };

        document.addEventListener('click', handleClick, true);
        document.addEventListener('change', handleLockedEvent, true);
        document.addEventListener('keypress', handleLockedEvent, true);
        document.addEventListener('submit', handleLockedEvent, true);

        $(document).ajaxSend(function(event, request, settings) {
            if(state.pending && requestPath(settings && settings.url) === state.requestPath) {
                state.request = request;
            }
        });

        $(document).ajaxComplete(function(event, request, settings) {
            if(!state.pending || requestPath(settings && settings.url) !== state.requestPath) {
                return;
            }

            var deleteForm = document.getElementById('dbsdnsRecordDeleteForm');

            if(deleteForm && deleteForm.parentNode) {
                deleteForm.parentNode.removeChild(deleteForm);
            }

            window.setTimeout(function() {
                var root = currentRoot();

                if(!root || root === state.root) {
                    finish();
                } else {
                    init();
                }
            }, 0);
        });

        if(typeof window.ISPConfig.registerHook === 'function') {
            window.ISPConfig.registerHook('onAfterContentLoad', init);
        }
        window.DbsDnsMutationLock = {
            init: init
        };
    }

    window.DbsDnsMutationLock.init();
})(window, document, window.jQuery);
