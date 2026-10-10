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

            const captureBeforeState = function () {
                radio.dataset.wasCheckedBeforeClick = radio.checked ? '1' : '';
            };
            radio.addEventListener('mousedown', captureBeforeState);

            // Some layouts (e.g. pill-style selectors) visually hide the radio and let a
            // <label for="..."> stand in for it — clicks land on the label, not the input,
            // so mousedown never fires on the input itself. Listen on the label too so the
            // toggle-to-clear behavior keeps working either way.
            if (radio.id) {
                const label = scope.querySelector('label[for="' + radio.id.replace(/"/g, '\\"') + '"]');
                if (label) {
                    label.addEventListener('mousedown', captureBeforeState);
                }
            }

            radio.addEventListener('click', function () {
                if (this.dataset.wasCheckedBeforeClick === '1') {
                    this.checked = false;
                    this.dataset.wasCheckedBeforeClick = '';
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

    // Quick-fill presets for the most common findings, so the usual case is one click and
    // only the exceptions are typed. Each preset only touches the fields it lists (measured
    // values such as B.P. and blood sugar are never guessed), and everything stays editable.
    var CLINICAL_FINDINGS_PRESETS = [
        {
            key: 'normal',
            label: 'Normal findings',
            values: {
                occlusion: 'Class I',
                cl_i_type: 'Normal',
                operation_if_any: 'None',
                gingiva_consistency: 'Firm',
                oral_hygiene: 'Good',
                gingival_color: 'Pink',
                lymph_nodes: 'Not palpable',
                tongue: 'Normal',
                radiographic_interpretation: 'No abnormal radiographic findings.'
            }
        },
        {
            key: 'gingivitis',
            label: 'Gingivitis',
            values: {
                gingiva_consistency: 'Hyper plastic',
                oral_hygiene: 'Fair',
                gingival_color: 'Bright red',
                lymph_nodes: 'Not palpable',
                tongue: 'Coated'
            }
        },
        {
            key: 'poor_hygiene',
            label: 'Poor oral hygiene',
            values: {
                oral_hygiene: 'Bad',
                gingival_color: 'Bright red',
                tongue: 'Coated'
            }
        }
    ];

    function fieldsByName(root, name) {
        return Array.prototype.filter.call(root.querySelectorAll('.visit-clinical-field'), function (field) {
            return field.name === name;
        });
    }

    function applyClinicalFindingsPreset(root, presetKey) {
        if (!(root instanceof HTMLElement)) return false;
        var preset = CLINICAL_FINDINGS_PRESETS.filter(function (p) { return p.key === presetKey; })[0];
        if (!preset) return false;
        Object.keys(preset.values).forEach(function (name) {
            var value = preset.values[name];
            fieldsByName(root, name).forEach(function (field) {
                if (field.type === 'radio') field.checked = field.value === value;
                else if (field.type === 'checkbox') field.checked = !!value;
                else field.value = value;
            });
        });
        return true;
    }

    function clearClinicalFindings(root) {
        if (!(root instanceof HTMLElement)) return;
        root.querySelectorAll('.visit-clinical-field').forEach(function (field) {
            if (field.type === 'radio' || field.type === 'checkbox') field.checked = false;
            else field.value = '';
        });
    }

    global.CLINICAL_FINDINGS_PRESETS = CLINICAL_FINDINGS_PRESETS;
    global.applyClinicalFindingsPreset = applyClinicalFindingsPreset;
    global.clearClinicalFindings = clearClinicalFindings;
    global.initToggleableClinicalRadios = initToggleableClinicalRadios;
    global.collectVisitClinicalData = collectVisitClinicalData;

    document.addEventListener('DOMContentLoaded', function () {
        initToggleableClinicalRadios(document);
    });
    window.addEventListener('page-loaded', function () {
        initToggleableClinicalRadios(document);
    });
})(window);
