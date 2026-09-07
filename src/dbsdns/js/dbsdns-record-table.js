(function(window, document, $) {
    'use strict';

    var schedule = function(callback) {
        if(typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(callback);
        } else {
            window.setTimeout(callback, 0);
        }
    };

    if(!window.DbsDnsRecordTable) {
        var resizeTimer = null;

        var updateOverflowTitles = function(root) {
            var context = root && root.querySelectorAll ? root : document;
            var values = context.querySelectorAll('.dbsdns-record-table .dbsdns-record-value');

            for(var index = 0; index < values.length; index++) {
                var value = values[index];
                value.removeAttribute('title');

                if(value.scrollWidth > value.clientWidth + 1) {
                    value.setAttribute('title', value.textContent || '');
                }
            }
        };

        var init = function() {
            schedule(function() {
                updateOverflowTitles(document.querySelector('#pageContent') || document);
            });
        };

        window.addEventListener('resize', function() {
            if(resizeTimer !== null) {
                window.clearTimeout(resizeTimer);
            }

            resizeTimer = window.setTimeout(init, 100);
        });

        if($) {
            $(document).ajaxComplete(init);
        }

        if(window.ISPConfig && typeof window.ISPConfig.registerHook === 'function') {
            window.ISPConfig.registerHook('onAfterContentLoad', init);
        }

        window.DbsDnsRecordTable = {
            init: init
        };
    }

    window.DbsDnsRecordTable.init();
})(window, document, window.jQuery);
