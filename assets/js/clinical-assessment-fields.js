/**
 * Visit clinical assessment fields — allow unchecking radio options (click again to clear).
 */
(function (global) {
    'use strict';

    function initToggleableClinicalRadios(root) {
        const scope = root instanceof HTMLElement ? root : document;
        scope.querySelectorAll('input[type="radio"].visit-clinical-field').forEach(function (radio) {
            if (radio.dataset.toggleRadioInit === '1') {
                return;
            }
            radio.dataset.toggleRadioInit = '1';

            radio.addEventListener('mousedown', function () {
                this._wasCheckedBeforeClick = this.checked;
            });

            radio.addEventListener('click', function () {
                if (this._wasCheckedBeforeClick) {
                    this.checked = false;
                    this._wasCheckedBeforeClick = false;
                }
            });
        });
    }

    function collectVisitClinicalData(formSelector) {
        const form = document.querySelector(formSelector);
        if (!form) {
            return {};
        }

        const data = {};
        const radioNames = new Set();

        form.querySelectorAll('.visit-clinical-field').forEach(function (field) {
            if (field.type === 'radio') {
                radioNames.add(field.name);
                return;
            }
            if (field.type === 'checkbox') {
                data[field.name] = !!field.checked;
            } else {
                data[field.name] = field.value || '';
            }
        });

        radioNames.forEach(function (name) {
            const checked = form.querySelector(
                'input[type="radio"].visit-clinical-field[name="' + name.replace(/"/g, '\\"') + '"]:checked'
            );
            data[name] = checked ? checked.value : '';
        });

        return data;
    }

    global.initToggleableClinicalRadios = initToggleableClinicalRadios;
    global.collectVisitClinicalData = collectVisitClinicalData;

    document.addEventListener('DOMContentLoaded', function () {
        initToggleableClinicalRadios(document);
    });
    window.addEventListener('page-loaded', function () {
        initToggleableClinicalRadios(document);
    });
})(window);
